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
	 */
	const PAGEDJS_URL = '../../../assets/js/paged.polyfill.js';

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

	public function __construct($theme_code = 'default', $format = 'a4-landscape', $font_pair = 'default', $parser = null) {
		$this->theme     = new ThemeRegistry($theme_code);
		$this->templates = new TemplateRegistry($theme_code);
		$this->loader    = new RecordLoader();
		$this->markdown  = new MarkdownRenderer($parser);
		$this->format    = $format;
		$this->font_pair = $font_pair;
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
		$book = new plugin_books($book_id);

		$body = '';
		foreach ($book->getSections() as $section) {
			if ($section_id !== null && (int)$section['booksection_id'] !== (int)$section_id) { continue; }
			$body .= $this->buildSection($section);
		}

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

		$html  = "<!DOCTYPE html>\n<html><head>\n";
		$html .= "<meta charset=\"UTF-8\" />\n";
		$html .= "<style>\n".$css."</style>\n";

		foreach ($this->theme->getStylesheets() as $sheet) {
			$html .= "<link rel=\"stylesheet\" href=\"".htmlspecialchars($sheet, ENT_QUOTES, 'UTF-8')."\" />\n";
		}

		// Paged.js is the only thing the preview adds, and it is a paginating
		// polyfill rather than content: it does in the browser what WeasyPrint
		// does on the server. Everything else in this document is identical to
		// what is sent to the PDF chain — interface chrome lives in the page
		// that embeds this one in an iframe, never here.
		if (caGetOption('preview', $options, false)) {
			$html .= "<script src=\"".self::PAGEDJS_URL."\"></script>\n";
		}

		$html .= "</head>\n<body>\n".$body."</body>\n</html>\n";
		return $html;
	}

	# -------------------------------------------------------
	# Sections
	# -------------------------------------------------------

	/** One section, as one or more divs carrying its layout code as a class. */
	public function buildSection($section) {
		$code = $section['style'];
		$manifest = $this->templates->getTemplate($code);

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
		$media = '';
		if (sizeof($blocks)) {
			$media = "<div class=\"".htmlspecialchars($media_container, ENT_QUOTES, 'UTF-8')."\">\n"
				.join('', $blocks)
				."</div>\n";
		}

		$text = "<div class=\"content\">".$this->markdown->render($section['content'])."</div>\n";

		return $this->wrapLayout($code, $text.$media);
	}

	/** Wraps rendered content in the div the stylesheets style. */
	private function wrapLayout($code, $content) {
		return "<div class=\"".htmlspecialchars($code, ENT_QUOTES, 'UTF-8')."\">\n".$content."</div>\n";
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
		$context = _t('section %1', $section['booksection_id']);
		$object_template = $this->templates->getMergeTemplate($code, 'object_block');
		$caption_template = $this->templates->getMergeTemplate($code, 'caption');

		$blocks = [];
		foreach ($this->loader->loadSetItemIDs($section['set_id'], $context) as $object_id) {
			$object = $this->loader->load('ca_objects', $object_id, $context);
			if (!$object) { continue; }

			$representation = $this->loader->loadPrimaryRepresentation($object, $context);

			$html = "<div class=\"media\">\n";
			if ($representation) {
				$url = $representation->getMediaUrl('media', 'page');
				if ($url) {
					$html .= "<div class=\"image\" style=\"background-image:url('".htmlspecialchars($url, ENT_QUOTES, 'UTF-8')."');\"></div>\n";
				}
				if ($caption_template) {
					$html .= "<p class=\"caption\">".$representation->getWithTemplate($caption_template)."</p>\n";
				}
			}
			if ($object_template) {
				$html .= $object->getWithTemplate($object_template)."\n";
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
		$context = _t('section %1', $section['booksection_id']);
		$representation = $this->loader->load('ca_object_representations', $section['representation_id'], $context);
		if (!$representation) { return ''; }

		$url = $representation->getMediaUrl('media', 'page');
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
