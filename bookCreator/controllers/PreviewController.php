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
require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/BookHtmlBuilder.php');
require_once(__CA_APP_DIR__.'/plugins/bookCreator/models/plugin_books.php');

/**
 * Serves the bare paginated document, for the browser preview.
 *
 * This controller returns the document and nothing else: same markup, same
 * stylesheets, same injected custom properties as the file handed to
 * WeasyPrint, plus the Paged.js polyfill that paginates it in the browser.
 *
 * That austerity is the whole design. The preview is only worth having if it
 * is opposable to the delivered PDF, and anything added to the flow — a badge,
 * a warning, a toolbar — moves the page breaks and reopens an argument about
 * why the two differ. Interface elements therefore live in the workshop page,
 * which embeds this URL in an iframe; the iframe is what makes the isolation
 * structural rather than a matter of discipline, since a theme stylesheet sets
 * @page, body and :root and would otherwise leak both ways.
 *
 * A useful side effect: opening this URL on its own shows exactly what the
 * renderer sees, which is the first thing to look at when a PDF surprises.
 */
class PreviewController extends ActionController {
	# -------------------------------------------------------
	protected $opo_config;
	# -------------------------------------------------------

	public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
		parent::__construct($po_request, $po_response, $pa_view_paths);

		$this->opo_config = Configuration::load(
			__CA_APP_DIR__.'/plugins/bookCreator/conf/bookCreator.conf'
		);

		if (!$this->userCanUsePlugin()) {
			$this->response->setRedirect(
				$this->request->config->get('error_display_url')
				.'/n/3000?r='.urlencode($this->request->getFullUrlPath())
			);
			return;
		}
	}

	/** Same rule as the other controllers: an explicit grant, or default_access. */
	private function userCanUsePlugin() {
		if ($this->request->user->canDoAction('can_use_book_editor_plugin')) { return true; }
		return (bool)$this->opo_config->get('default_access');
	}

	# -------------------------------------------------------

	/** The whole book. */
	public function Book() {
		$this->serve(
			(int)$this->request->getParameter('book', pInteger),
			null
		);
	}

	/** A single section, keeping the folio it holds in the finished book. */
	public function Section() {
		$this->serve(
			(int)$this->request->getParameter('book', pInteger),
			(int)$this->request->getParameter('section', pInteger)
		);
	}

	# -------------------------------------------------------

	/**
	 * Builds and prints the document.
	 *
	 * Rendered outside the CollectiveAccess view layer on purpose: any wrapper
	 * would add markup to a document whose entire value is being identical to
	 * the one that goes to the renderer.
	 */
	private function serve($book_id, $section_id) {
		$book = new plugin_books($book_id);

		$builder = new BookHtmlBuilder(
			$this->bookSetting($book, 'theme', 'default'),
			$this->bookSetting($book, 'page_format', 'a4-landscape'),
			$this->bookSetting($book, 'font_pair', 'default')
		);

		$options = ['preview' => true];

		// A section previewed on its own carries the page number it holds in
		// the book, so the folio shown matches the printed one.
		if ($section_id) {
			$section = $book->getSection($section_id);
			if (is_array($section) && (int)$section['first_page'] > 0) {
				$options['first_page'] = (int)$section['first_page'];
			}
		}

		$html = $builder->buildDocument($book_id, $section_id, $options);

		// The skipped-record count belongs to the workshop page, not here. It
		// travels in a header so the embedding page can read it without the
		// document carrying anything of its own.
		$this->response->addHeader('X-BookCreator-Skipped', (string)$builder->countSkipped());
		$this->response->addHeader('Content-Type', 'text/html; charset=UTF-8');

		// The Markdown of a section is rendered with raw HTML allowed, on
		// purpose: the v1 corpus contains inline markup and refusing it would
		// change the books. But this document is served as text/html on the
		// CollectiveAccess origin, so a <script> or an onerror= typed into a
		// section ran with the session of whoever opened the preview.
		//
		// Rather than enumerating the tags and attributes to strip — the same
		// losing game as escaping a character list — the whole class of
		// execution is refused here. Only scripts served by this origin may run,
		// which is Paged.js and nothing else: inline <script> blocks and every
		// on* handler are dead, whatever they look like, because CSP never
		// grants them without 'unsafe-inline'. Styles stay inline-allowed since
		// the document carries its own generated <style>, and the CSS written
		// into it is escaped at the source.
		//
		// The PDF chain needs none of this: WeasyPrint runs no script at all.
		$this->response->addHeader(
			'Content-Security-Policy',
			"default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
			."img-src 'self' data: blob:; font-src 'self' data:; base-uri 'none'; form-action 'none'"
		);
		$this->response->sendHeaders();

		print $html;
		exit;
	}

	/**
	 * One book setting, with a fallback when the book could not be loaded.
	 *
	 * Goes through getField(), written for exactly this case. The magic getter
	 * is not merely inconvenient here: on a book that does not exist $data is
	 * null, so it reaches array_key_exists(name, null) and raises a TypeError —
	 * which is not an Exception and would escape any catch written for one.
	 */
	private function bookSetting($book, $name, $default) {
		$value = $book->getField($name, $default);
		return strlen((string)$value) ? $value : $default;
	}
}
