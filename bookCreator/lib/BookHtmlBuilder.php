<?php
/* Book Creator plugin for CollectiveAccess
 *
 * Plugin by idéesculture – Gautier MICHELIN
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License v3. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * ----------------------------------------------------------------------
 */

require_once(__CA_LIB_DIR__.'/Configuration.php');
require_once(__CA_MODELS_DIR__.'/ca_sets.php');
require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/ThemeRegistry.php');
require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/TemplateRegistry.php');
require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/RecordLoader.php');
require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/MarkdownRenderer.php');
require_once(__CA_APP_DIR__.'/plugins/bookCreator/models/plugin_books.php');

/**
 * Builds the HTML of a book, or of a single section.
 *
 * Replaces generateHTML(), whose layout logic was a chain of strpos() on the
 * layout name with the Floutier field names written into the PHP. Here the
 * layout drives the rendering through its manifest, and the field mapping is a
 * CollectiveAccess display template resolved by TemplateRegistry — so adapting
 * the module to another client's profile is configuration.
 *
 * The output is the same document for the PDF chain and for the Paged.js
 * preview: same markup, same stylesheets, same injected custom properties.
 * That identity is the whole point — a preview rendered from anything else
 * would prove nothing about the printed result. Nothing that is not headed for
 * the PDF is emitted here; interface chrome belongs to the page that embeds
 * this document in an iframe.
 */
class BookHtmlBuilder {

	/**
	 * Paged.js polyfill, served from the plugin rather than a CDN.
	 *
	 * Bundled locally on purpose: the preview must keep working on an
	 * installation without outbound network access, and a CDN that dies takes
	 * the pagination with it — which is exactly how the previous stylesheet
	 * ended up pointing at a dead font host.
	 *
	 * Path relative to the plugin, resolved against __CA_URL_ROOT__ at render
	 * time. A relative URL cannot work here: the preview is served from
	 * /index.php/bookCreator/Preview/Book/book/12, whose depth differs from
	 * that of a section preview, so any fixed number of ../ is wrong for one of
	 * the two.
	 */
	const PAGEDJS_PATH = '/app/plugins/bookCreator/assets/js/paged.polyfill.js';

	/** @var ThemeRegistry */
	private $theme;

	/** @var TemplateRegistry */
	private $templates;

	/** @var RecordLoader */
	private $loader;

	/** @var MarkdownRenderer */
	private $markdown;

	/** @var string page format code of the book being rendered */
	private $format;

	/** @var string font pair code of the book being rendered */
	private $font_pair;

	/**
	 * Whether media are referenced by local path rather than by URL.
	 *
	 * The PDF chain must not go through the network: WeasyPrint would fetch
	 * every plate over HTTP from the site itself, which fails when the media are
	 * behind authentication, and makes the rendering depend on the front end
	 * being reachable from the worker. A browser preview, on the other hand, can
	 * only use URLs. Set from the preview option in buildDocument().
	 */
	private $use_local_media = true;

	/** Running-head rules collected while building, one per section; see collectRunningHead(). */
	private $running_head_css = [];

	/** Class scoping the section being built, or null when it has no title. */
	private $current_section_class = null;

	/** Whether the last document built carried no content at all; see lastDocumentWasEmpty(). */
	private $last_document_empty = false;

	/** Page blocks emitted for the last document; see lastDocumentPageBlocks(). */
	private $last_document_blocks = 0;

	public function __construct($theme_code = 'default', $format = 'a4-landscape', $font_pair = 'default', $parser = null) {
		$this->theme     = new ThemeRegistry($theme_code);
		$this->templates = new TemplateRegistry($theme_code);
		$this->loader    = new RecordLoader();
		$this->markdown  = new MarkdownRenderer($parser);
		$this->format    = $format;
		$this->font_pair = $font_pair;
	}

	/**
	 * Sets the user whose permissions apply to the records pulled into the book.
	 *
	 * Both callers must supply one: the preview passes the logged-in user, the
	 * worker the user recorded on the job. A caller that forgets it renders
	 * without any check, which is how a private set used to end up in a PDF.
	 *
	 * @param ca_users|null $user
	 */
	public function setAccessUser($user) {
		$this->loader->setAccessUser($user);
	}

	# -------------------------------------------------------
	# Documents
	# -------------------------------------------------------

	/**
	 * Full document for a book, or for one of its sections.
	 *
	 * @param int      $book_id
	 * @param int|null $section_id  restricts output to a single section
	 * @param array    $options     first_page => int  start the page counter there,
	 *                              which is how a section rendered on its own keeps
	 *                              the numbering of the whole book;
	 *                              bleed => bool  printer-ready output.
	 */
	public function buildDocument($book_id, $section_id = null, $options = []) {
		// A browser can only follow URLs; the renderer must not need the network.
		$this->use_local_media = !caGetOption('preview', $options, false);

		$book = new plugin_books($book_id);

		// The builder is reusable: the worker keeps one instance for the whole
		// book and calls this once per section.
		$this->running_head_css = [];
		$this->last_document_blocks = 0;

		// One section asked for, one section read. The worker renders a book
		// section by section and built a whole document each time: on a
		// 200-section catalogue that was 200 full reads of the section table to
		// use one row from each — quadratic work in the only loop that matters.
		// A summary section still sees the whole book, because
		// buildSummarySection() loads it for itself.
		$sections = ($section_id !== null)
			? array_filter([$book->getSection((int)$section_id)], 'is_array')
			: $book->getSections();

		$body = '';
		foreach ($sections as $section) {
			$body .= $this->buildSection($section);
		}

		// A set whose works have all disappeared builds nothing, and an empty
		// body still renders as one blank page — bound, folioed and listed in
		// the table of contents. The caller has to be able to tell.
		$this->last_document_empty = (trim(strip_tags($body)) === '' && !preg_match('~<img\b|background-image~i', $body));

		return $this->wrapDocument($body, $options);
	}

	/**
	 * Wraps rendered sections into a standalone HTML document.
	 *
	 * The generated :root block comes first, then the theme stylesheets, so a
	 * stylesheet can always override a token but never the other way round.
	 */
	private function wrapDocument($body, $options = []) {
		$css = $this->theme->buildRootCss($this->format, $this->font_pair, $options);

		// A section rendered alone still has to carry the page number it holds
		// in the finished book: nb_pages and first_page are written by the
		// worker as it goes, and injected back here.
		//
		// The rule must target :first, and carry the first page number itself.
		// Measured against WeasyPrint 69: an unqualified "@page { counter-reset:
		// page N }" applies to every page of the section, which stamps them all
		// with the same folio; and the reset happens after the increment, so
		// N-1 would shift the whole book by one page.
		if (isset($options['first_page']) && (int)$options['first_page'] > 0) {
			$css .= "@page :first { counter-reset: page ".(int)$options['first_page']."; }\n";
		}

		// Running heads, one rule per section built above.
		if (sizeof($this->running_head_css)) {
			$css .= join("\n", $this->running_head_css)."\n";
		}

		$html  = "<!DOCTYPE html>\n<html><head>\n";
		$html .= "<meta charset=\"UTF-8\" />\n";

		// The stylesheets and fonts of a theme are declared relative to its own
		// directory. The two consumers are told where that is in different ways,
		// and mixing them breaks both:
		//   - WeasyPrint receives --base-url, a file:// path, from the worker;
		//   - a browser has to be told inside the document, otherwise the
		//     relative hrefs resolve against the preview URL and all 404.
		//
		// The <base> is therefore emitted for the preview ONLY. WeasyPrint gives
		// a document's own <base> precedence over --base-url, so emitting a web
		// path here would send it looking for file:///app/plugins/... — every
		// stylesheet and every font would fail to load, and it would still exit
		// 0 with a correctly sized but entirely unstyled PDF.
		if (caGetOption('preview', $options, false)) {
			$html .= "<base href=\"".htmlspecialchars($this->themeBaseUrl(), ENT_QUOTES, 'UTF-8')."\" />\n";
		}
		$html .= "<style>\n".$css."</style>\n";

		foreach ($this->theme->getStylesheets() as $sheet) {
			$html .= "<link rel=\"stylesheet\" href=\"".htmlspecialchars($sheet, ENT_QUOTES, 'UTF-8')."\" />\n";
		}

		// Paged.js is the only thing the preview adds, and it is a paginating
		// polyfill rather than content: it does in the browser what WeasyPrint
		// does on the server. Everything else in this document is identical to
		// what is sent to the PDF chain — interface chrome lives in the page
		// that embeds this one in an iframe, never here.
		//
		// Its src is absolute for the same reason as the base above: the depth
		// of the preview URL is not the same for a book and for a section.
		if (caGetOption('preview', $options, false)) {
			$src = __CA_URL_ROOT__.self::PAGEDJS_PATH;
			$html .= "<script src=\"".htmlspecialchars($src, ENT_QUOTES, 'UTF-8')."\"></script>\n";
		}

		$html .= "</head>\n<body>\n".$body."</body>\n</html>\n";
		return $html;
	}

	/** Web URL of the theme directory, with its trailing slash. */
	private function themeBaseUrl() {
		return __CA_URL_ROOT__.'/app/plugins/bookCreator/themes/'.$this->theme->getCode().'/';
	}

	/**
	 * A media path, safe inside a CSS url() written into a style attribute.
	 *
	 * Three nested syntaxes read this string: the HTML attribute, then the CSS
	 * declaration, then the quoted url() token. htmlspecialchars() alone
	 * satisfies the first and breaks the third — it turns an apostrophe into
	 * &#039;, the HTML parser hands a real apostrophe back to the CSS parser,
	 * and that apostrophe closes the url() early. WeasyPrint then reports
	 * "Ignored `background-image`", exits 0, and every plate of the book is
	 * missing from a page that otherwise looks finished. A path like
	 * /home/o'brien/media is enough.
	 *
	 * Percent-encoding settles all three at once: the characters that could end
	 * a token in any of them cannot appear in the output, and both WeasyPrint
	 * and Chromium decode the escapes when they open the file. The slashes are
	 * kept so the path stays a path.
	 */
	private static function cssUrl($url) {
		$encoded = str_replace('%2F', '/', rawurlencode($url));

		// A remote URL keeps its scheme separator; rawurlencode() would have
		// escaped the colon and the double slash.
		return str_replace(['%3A%2F%2F', '%3A'], ['://', ':'], $encoded);
	}

	/**
	 * Where to point an <img> for a representation.
	 *
	 * A local path for the PDF chain, a URL for the browser preview. The
	 * difference matters: with a URL, WeasyPrint fetches every plate over HTTP
	 * from the site it is part of — which turns a rendering into a network
	 * operation, fails outright when the media are behind authentication, and
	 * ties a worker pod to the reachability of the front end.
	 *
	 * The derivative version is configurable rather than frozen: an installation
	 * whose media profile names it differently would otherwise get nothing.
	 */
	private function mediaSource($representation, $version = null) {
		if (!strlen((string)$version)) { $version = $this->mediaVersion(); }

		if ($this->use_local_media) {
			$path = $representation->getMediaPath('media', $version);
			if ($path && is_readable($path)) { return $path; }
			// No derivative on disk: fall back to the URL rather than emitting
			// an empty src, so the plate is at least attempted.
		}
		return $representation->getMediaUrl('media', $version);
	}

	/** Media derivative used for plates, from the plugin configuration. */
	private function mediaVersion() {
		$configured = trim((string)Configuration::load(
			__CA_APP_DIR__.'/plugins/bookCreator/conf/bookCreator.conf'
		)->get('media_version'));

		return strlen($configured) ? $configured : 'page';
	}

	# -------------------------------------------------------
	# Sections
	# -------------------------------------------------------

	/**
	 * Whether the last buildDocument() produced a document with no content.
	 *
	 * Not merely "no text": a full-bleed plate is a legitimate page with no
	 * text at all, so an image reference counts as content. What this reports is
	 * the case where nothing at all was built — typically a set section whose
	 * works were all skipped.
	 */
	public function lastDocumentWasEmpty() {
		return $this->last_document_empty;
	}

	/**
	 * How many pages the last document was built to occupy.
	 *
	 * One block per page is the contract of the layouts: a set section is split
	 * into chunks of items_per_page precisely so that each div is one page. The
	 * worker compares this with the page count of the produced PDF, because the
	 * contract is not enforceable from here — a grid whose cells grow past the
	 * row height pushes its second row onto a page of its own, and the book
	 * silently doubles in length with half of every other page left blank.
	 */
	public function lastDocumentPageBlocks() {
		return $this->last_document_blocks;
	}

	/** One section, as one or more divs carrying its layout code as a class. */
	public function buildSection($section) {
		$code = $section['style'];
		$manifest = $this->templates->getTemplate($code);

		$this->collectRunningHead($section);

		// An unknown layout still renders its text rather than disappearing:
		// losing a page silently is worse than losing its layout.
		$type = $manifest ? $manifest['section_type'] : 'text';

		switch ($type) {
			case 'set':
				return $this->buildSetPages($section, $code);
			case 'mixed':
				return $this->buildMixedSection($section, $code);
			case 'summary':
				return $this->buildSummarySection($section, $code);
			default:
				return $this->wrapLayout($code, $this->buildTextContent($section, $code));
		}
	}

	/**
	 * Table of contents, built from the sections themselves.
	 *
	 * In the v1 the table of contents was an ordinary Markdown section, with the
	 * page numbers typed by hand — so inserting a single page anywhere in the
	 * book meant retyping them all, and the printed copy of the Floutier
	 * catalogue carries numbers that were correct on the day they were written.
	 *
	 * Here it is generated: every section flagged is_in_summary contributes its
	 * title and its first_page, which the worker writes as it renders. A section
	 * never rendered has no page number yet and is listed without one rather
	 * than with a wrong one.
	 */
	private function buildSummarySection($section, $code) {
		$book = new plugin_books($section['book_id']);

		$rows = '';
		foreach ($book->getSections() as $entry) {
			if (!$entry['is_in_summary']) { continue; }
			if (!strlen((string)$entry['title'])) { continue; }

			$title = htmlspecialchars($entry['title'], ENT_QUOTES, 'UTF-8');
			$page  = (is_null($entry['first_page']) || (int)$entry['first_page'] < 1)
				? ''
				: (int)$entry['first_page'];

			// The leader is an element of its own rather than a ::after on the
			// entry: a pseudo-element is always the last child and would land
			// after the folio instead of between the title and it.
			$rows .= "<li class=\"summary-entry\">";
			$rows .= "<span class=\"summary-title\">".$title."</span>";
			$rows .= "<span class=\"summary-leader\"></span>";
			$rows .= "<span class=\"summary-page\">".$page."</span>";
			$rows .= "</li>\n";
		}

		$html = $this->buildTextContent($section, $code);
		$html .= "<ul class=\"summary-list\">\n".$rows."</ul>\n";

		return $this->wrapLayout($code, $html);
	}

	/**
	 * Text and plates in a single root, as the stylesheets expect.
	 *
	 * The layouts that mix both are two-track grids: the stylesheet selects
	 * `.layout > .content + .media-bar > .media`. Emitting the text and the
	 * plates as two sibling roots — which is what the naive reading of "mixed"
	 * produces — breaks the grid AND, since the layout class carries
	 * `break-before: page`, sends the text and its plates to two different
	 * pages.
	 *
	 * Which wrappers to emit is declared by the manifest, so the shape of a
	 * layout stays with the layout instead of returning to a switch on its name.
	 */
	private function buildMixedSection($section, $code) {
		$manifest = $this->templates->getTemplate($code);
		$media_container = $manifest['media_container'] ?? 'media-bar';

		$blocks = $this->buildSetItems($section, $code);
		$text = "<div class=\"content\">".$this->markdown->render($section['content'])."</div>\n";

		if (!sizeof($blocks)) { return $this->wrapLayout($code, $text); }

		// These layouts hold a fixed number of plates per page too, and their
		// manifests declare it — the two-plate layouts say so explicitly. Only
		// the first page carries the text; the following ones continue with the
		// remaining plates, exactly as the set layouts do. Emitting all of them
		// in a single bar, as this did, overflows the page and therefore loses
		// pages, which in turn falsifies every folio after it.
		$per_page = $this->getItemsPerPage($code, $section);
		$pages = ($per_page > 0) ? array_chunk($blocks, $per_page) : [$blocks];

		$html = '';
		foreach ($pages as $index => $page_blocks) {
			$bar = "<div class=\"".htmlspecialchars($media_container, ENT_QUOTES, 'UTF-8')."\">\n"
				.join('', $page_blocks)
				."</div>\n";
			$html .= $this->wrapLayout($code, ($index === 0 ? $text : '').$bar);
		}
		return $html;
	}

	/** Wraps rendered content in the div the stylesheets style. */
	private function wrapLayout($code, $content) {
		// Every layout block is meant to occupy exactly one printed page, which
		// is why set sections are chunked into one block per page rather than
		// left to the renderer. Counting them gives the worker something to
		// compare the produced PDF against.
		$this->last_document_blocks++;

		$classes = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
		if ($this->current_section_class) { $classes .= ' '.$this->current_section_class; }

		return "<div class=\"".$classes."\">\n".$content."</div>\n";
	}

	/**
	 * Records the running head of a section, as a CSS rule.
	 *
	 * base.css only sets string(chapter) from the h1 of the three chapter
	 * layouts. Every other layout — plates, catalogue pages, full-bleed images —
	 * carries no h1, so the string stayed empty and those pages printed no
	 * running head at all. Worse, the worker renders each section as a document
	 * of its own: a string set in the previous section cannot carry over, the
	 * way it would in a single continuous document.
	 *
	 * The title is therefore emitted as a literal in a rule of its own, scoped
	 * to this section. A literal rather than content() or attr(): both would
	 * need an element in the flow to hang off, and a literal is plain CSS 2.1
	 * that no renderer can decline.
	 *
	 * Which of the two wins, measured rather than assumed: the margin boxes use
	 * `string(chapter)`, whose default is the `first` value — the first one
	 * assigned on the page. The section div carries this rule and opens the
	 * page, so it is assigned before the h1 of base.css and it is the section
	 * title that prints, on chapter layouts too. Changing that means passing
	 * `start` or `last` to string() in ThemeRegistry, not editing here.
	 */
	private function collectRunningHead($section) {
		$this->current_section_class = null;

		$title = trim((string)$section['title']);
		$id    = (int)$section['booksection_id'];
		if (!strlen($title) || $id <= 0) { return; }

		$this->current_section_class = 'bc-section-'.$id;

		$this->running_head_css[] = '.'.$this->current_section_class
			.' { string-set: chapter "'.self::cssString($title).'"; }';
	}

	/**
	 * A title, as the body of a CSS string literal.
	 *
	 * This string is read by two parsers in turn — the HTML tokeniser that finds
	 * the end of the <style> element, then the CSS parser that reads the literal
	 * — and three separate escapes have been written here, each one enumerating
	 * the characters believed to be dangerous, each one shipped with one
	 * missing:
	 *
	 *   - the first escaped only the quote and the backslash, so a section
	 *     titled "</style><script>" left the stylesheet and ran as script;
	 *   - the second added "<" and ">" and missed U+000C, which CSS Syntax 3
	 *     §4.2 counts as a newline exactly like \n: a form feed pasted into a
	 *     title closed the literal and injected arbitrary CSS — a book silently
	 *     reduced to a 50 mm page with its text set to display:none.
	 *
	 * The list was never going to be complete, so this no longer uses one. Only
	 * ASCII letters and digits are emitted as themselves; every other character
	 * — punctuation, spaces, accented letters, dashes, anything a title may hold
	 * — leaves as a CSS hexadecimal escape, which the parser reads back as that
	 * exact character. Nothing new can slip through, because nothing is being
	 * decided about individual characters any more.
	 *
	 * The space is escaped along with the rest, and that is not zeal. CSS Syntax
	 * 3 §4.3.7 consumes a whitespace character that follows an escape, six
	 * digits or not: leaving spaces literal printed "Le \"Grand\"atelier" for
	 * "Le \"Grand\" atelier", every space swallowed by the escape before it.
	 * Escaping them too means no escape is ever followed by a literal space.
	 */
	private static function cssString($title) {
		$out = '';

		// UTF-8 aware: a byte loop would escape each byte of a letter like "e"
		// with an acute accent separately and produce mojibake.
		foreach (preg_split('//u', $title, -1, PREG_SPLIT_NO_EMPTY) as $char) {
			if (preg_match('~^[A-Za-z0-9]$~', $char)) {
				$out .= $char;
				continue;
			}

			$codepoint = self::codepoint($char);
			// Not valid UTF-8: no code point to emit, and nothing that could be
			// rendered either, so it is dropped rather than passed through.
			if ($codepoint === null) { continue; }

			$out .= sprintf('\\%06X', $codepoint);
		}

		return $out;
	}

	/** Unicode code point of one UTF-8 character, null when undecodable. */
	private static function codepoint($char) {
		$converted = @iconv('UTF-8', 'UCS-4BE', $char);
		if ($converted === false || strlen($converted) < 4) { return null; }

		$unpacked = unpack('N', substr($converted, 0, 4));
		return $unpacked ? $unpacked[1] : null;
	}

	/**
	 * The works of a set, split into one div per printed page.
	 *
	 * This split is not cosmetic. The grid layouts carry `break-inside: avoid`,
	 * and WeasyPrint does not fragment a grid container across pages: a single
	 * div holding twenty works would overflow the page instead of continuing on
	 * the next one. The float-based layout it replaces paginated on its own.
	 * Emitting one complete grid per page keeps the stylesheets unchanged —
	 * each div is exactly one page — and restores the pagination.
	 */
	private function buildSetPages($section, $code) {
		$blocks = $this->buildSetItems($section, $code);
		if (!sizeof($blocks)) { return ''; }

		$per_page = $this->getItemsPerPage($code, $section);
		if ($per_page < 1) {
			// Layout with no declared capacity: leave the flow alone.
			return $this->wrapLayout($code, join('', $blocks));
		}

		$html = '';
		foreach (array_chunk($blocks, $per_page) as $page_blocks) {
			$html .= $this->wrapLayout($code, join('', $page_blocks));
		}
		return $html;
	}

	/**
	 * How many works the layout holds on one page.
	 *
	 * Read from the section options or the manifest default; failing that, from
	 * the layout code itself, which names its capacity ("ensemble-6-par-page").
	 * The fallback matters: it keeps the pagination correct for the seventeen
	 * layouts inherited from v1, whose manifests do not exist yet.
	 */
	private function getItemsPerPage($code, $section) {
		$options = [];
		if (!empty($section['options'])) {
			$decoded = json_decode($section['options'], true);
			if (is_array($decoded)) { $options = $decoded; }
		}

		$declared = $this->templates->getOptionValue($code, 'items_per_page', $options);
		if ($declared !== null && (int)$declared > 0) { return (int)$declared; }

		if (preg_match('/(\d+)-par-page/', $code, $matches)) { return (int)$matches[1]; }

		return 0;
	}

	/**
	 * Editorial content: the section title when the layout shows one, then the
	 * Markdown.
	 *
	 * The title is NOT emitted unconditionally. The v1 printed it only for
	 * chapter layouts, and wrapped the body in .content only there too; on a
	 * title page, a dedication or a colophon the section title is an editing
	 * label, never something to print. Emitting it everywhere would add a
	 * heading to a dozen pages of the Floutier catalogue and make the one-to-one
	 * acceptance run fail for a reason that has nothing to do with the new
	 * chain.
	 *
	 * Which layouts show it is declared by the manifest, so it stops being a
	 * strpos() on the layout name — with a fallback on that very test for the
	 * v1 layouts whose manifest says nothing.
	 */
	private function buildTextContent($section, $code) {
		$manifest = $this->templates->getTemplate($code);

		$html = '';
		if ($this->layoutShowsTitle($code) && strlen((string)$section['title'])) {
			$html .= "<h1>".htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8')."</h1>\n";
		}
		if (strlen((string)$section['intro'])) {
			$html .= "<div class=\"intro\">".$this->markdown->render($section['intro'])."</div>\n";
		}

		$body = $this->markdown->render($section['content']);
		$html .= $this->layoutShowsTitle($code)
			? "<div class=\"content\">".$body."</div>\n"
			: $body;

		// A layout may also pin a single representation, independently of a set.
		$media = $this->buildRepresentationBlock($section, $manifest);

		// Layouts that set the plate beside the text are two-track grids, and
		// the stylesheet selects the two tracks by name. When the manifest
		// declares them, the text is wrapped and the plate placed first — the
		// order the grid expects. Without that, both fall into the same track
		// and the image lands under the text.
		$text_container  = $manifest['text_container'] ?? null;
		$outer_container = $manifest['outer_container'] ?? null;

		if ($text_container) {
			$html = "<div class=\"".htmlspecialchars($text_container, ENT_QUOTES, 'UTF-8')."\">\n".$html."</div>\n";
			$html = $media.$html;
		} else {
			$html .= $media;
		}

		if ($outer_container) {
			$html = "<div class=\"".htmlspecialchars($outer_container, ENT_QUOTES, 'UTF-8')."\">\n".$html."</div>\n";
		}

		return $html;
	}

	/** Whether the layout prints the section title, per its manifest. */
	private function layoutShowsTitle($code) {
		$manifest = $this->templates->getTemplate($code);
		if ($manifest && isset($manifest['show_title'])) {
			return (bool)$manifest['show_title'];
		}
		// v1 behaviour, kept for layouts whose manifest is silent.
		return (strpos($code, 'chapter') !== false);
	}

	/**
	 * The works of a set, one rendered block each, through the merge templates
	 * declared by the layout.
	 *
	 * Returns a list rather than a string so the caller can lay the blocks out
	 * page by page.
	 */
	private function buildSetItems($section, $code) {
		$context = IdC::_t('section %1', $section['booksection_id']);
		$object_template = $this->templates->getMergeTemplate($code, 'object_block');
		$caption_template = $this->templates->getMergeTemplate($code, 'caption');

		// Un jeu peut contenir des REPRÉSENTATIONS et non des oeuvres : c'est le
		// cas des pages de présentation, où la v1 lisait déjà les lignes comme
		// des ca_object_representations. Charger inconditionnellement des
		// ca_objects faisait échouer chaque ligne en silence, et ces pages
		// sortaient sans la moindre image — quinze au total dans ce livre, dont
		// toute la séquence « Vie de Louis Floutier ».
		$table = $this->loader->setItemTable($section['set_id'], $context);
		$representation_template = $this->templates->getMergeTemplate($code, 'representation_block');

		$blocks = [];
		foreach ($this->loader->loadSetItemIDs($section['set_id'], $context) as $row_id) {
			$object = null;

			if ($table === 'ca_object_representations') {
				$representation = $this->loader->load('ca_object_representations', $row_id, $context);
				if (!$representation) { continue; }
			} else {
				$object = $this->loader->load('ca_objects', $row_id, $context);
				if (!$object) { continue; }
				$representation = $this->loader->loadPrimaryRepresentation($object, $context);
			}

			$html = "<div class=\"media\">\n";
			if ($representation) {
				$url = $this->mediaSource($representation);
				if ($url) {
					$html .= "<div class=\"image\" style=\"background-image:url('".self::cssUrl($url)."');\"></div>\n";
				}
				if ($caption_template) {
					$html .= "<p class=\"caption\">".$representation->getWithTemplate($caption_template)."</p>\n";
				}
			}
			if ($object && $object_template) {
				$html .= $object->getWithTemplate($object_template)."\n";
			} elseif (!$object && $representation_template) {
				$html .= $representation->getWithTemplate($representation_template)."\n";
			}
			$html .= "</div>\n";

			$blocks[] = $html;
		}
		return $blocks;
	}

	/**
	 * Single representation pinned on a section, when the layout uses one.
	 *
	 * The wrapper class comes from the manifest: a plate set beside the text
	 * sits in a named grid track, while a full-page plate is a plain media
	 * block. Its caption goes through a merge template like everything else, so
	 * it stays configurable per installation instead of being frozen here.
	 */
	private function buildRepresentationBlock($section, $manifest = null) {
		if (!$section['representation_id']) { return ''; }

		$code = $section['style'];
		$context = IdC::_t('section %1', $section['booksection_id']);
		$representation = $this->loader->load('ca_object_representations', $section['representation_id'], $context);
		if (!$representation) { return ''; }

		// Version de dérivée propre au gabarit, quand il en déclare une. La v1
		// réservait « book » à la planche pleine page et laissait « page » à tout
		// le reste : sur une image qui occupe 165 mm de haut, « page » plafonne à
		// 1000 px, soit 102 ppi — inexploitable à l'impression, là où « book »
		// donne 3600 px. Le choix appartient donc au gabarit, pas à l'instance.
		$url = $this->mediaSource($representation, $manifest['media_version'] ?? null);
		if (!$url) { return ''; }

		$container = $manifest['media_container'] ?? 'media';
		$template  = $this->templates->getMergeTemplate($code, 'representation_block');

		$html  = "<div class=\"".htmlspecialchars($container, ENT_QUOTES, 'UTF-8')."\">\n";
		$html .= "<img src=\"".htmlspecialchars($url, ENT_QUOTES, 'UTF-8')."\" alt=\"\" />\n";
		$html .= $template
			? $representation->getWithTemplate($template)."\n"
			: "<p class=\"caption\">".$representation->get('preferred_labels')."</p>\n";
		$html .= "</div>\n";
		return $html;
	}

	# -------------------------------------------------------
	# Reporting
	# -------------------------------------------------------

	/**
	 * Records left out of the rendering, to be shown beside the preview and at
	 * the end of the generation job — never inside the paginated document.
	 */
	public function getSkippedMessages() { return $this->loader->getSkippedMessages(); }

	public function countSkipped() { return $this->loader->countSkipped(); }
}
