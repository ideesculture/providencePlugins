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
require_once(__CA_APP_DIR__.'/plugins/bookCreator/models/plugin_books.php');
require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/BookSchemaManager.php');
require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/BookCsrf.php');
require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/ThemeRegistry.php');

/**
 * Books dashboard: list, create, edit, duplicate, delete.
 *
 * This controller is what makes the plugin able to hold more than the single
 * book whose id used to be written into the views. Sections stay with
 * EditorController: a book is a container of settings (theme, format,
 * typographic pair, covers), its sections are its content, and the two are
 * edited on different screens.
 *
 * Nothing here redirects after a write. The actions render the list or the
 * editor themselves with a notification, which is the pattern EditorController
 * already follows; a redirect would lose the message, CollectiveAccess having
 * no flash storage available to a plugin.
 */
class BooksController extends ActionController {
	# -------------------------------------------------------
	protected $opo_config;      // plugin configuration file
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

		// The plugin owns its tables and installs them itself: send the user to
		// the installer rather than failing later on a missing column.
		$o_schema = new BookSchemaManager();
		if (!$o_schema->isUsable()) {
			$this->response->setRedirect(caNavUrl($this->request, 'bookCreator', 'Install', 'Index'));
			return;
		}
	}

	/**
	 * Access check. The role action is granted when a role carries it; when no
	 * role does, default_access in bookCreator.conf decides. Shipped at 1, so a
	 * fresh install is usable without touching the CollectiveAccess roles.
	 */
	/**
	 * Whether this request may change anything.
	 *
	 * Creating, deleting and duplicating a book all used to be reachable
	 * without any token, and duplication by a plain link: an image tag on any
	 * page a logged-in editor visited was enough to fire it.
	 */
	private function isTrustedRequest() {
		return $this->request->isLoggedIn() && BookCsrf::isValid($this->request);
	}

	private function userCanUsePlugin() {
		if ($this->request->user->canDoAction('can_use_book_editor_plugin')) { return true; }
		return (bool)$this->opo_config->get('default_access');
	}

	# -------------------------------------------------------
	# Actions
	# -------------------------------------------------------

	/** The dashboard: every book with its counts and its actions. */
	public function Index() {
		$this->renderList();
	}

	/** Edit form. book=0, or no book at all, opens a blank one. */
	public function Edit() {
		$book_id = (int)$this->request->getParameter('book', pInteger);

		if ($book_id > 0) {
			$book = new plugin_books($book_id);
			if (!$book->isLoaded()) {
				$this->renderList(null, _t('This book no longer exists.'));
				return;
			}
			$this->renderEditor($book_id, $this->valuesFromBook($book));
			return;
		}

		$this->renderEditor(0, $this->defaultValues());
	}

	/** Creates or updates, then shows the form again with the result. */
	public function Save() {
		if (!$this->isTrustedRequest()) { $this->renderList(null, _t('Invalid or expired request. Reload the page and try again.')); return; }

		$book_id = (int)$this->request->getParameter('book', pInteger);
		$values  = $this->valuesFromRequest();

		// The title carries the whole interface: a book without one cannot be
		// told apart from another in the list, so it is the single required
		// field. Everything else has a usable default.
		if (!strlen(trim((string)$values['title']))) {
			$this->renderEditor($book_id, $values, null, _t('The book needs a title.'));
			return;
		}

		if ($book_id > 0) {
			$book = new plugin_books($book_id);
			if (!$book->isLoaded()) {
				$this->renderList(null, _t('This book no longer exists.'));
				return;
			}

			$result = $book->save($values);
			if (is_array($result)) {
				$this->renderEditor($book_id, $values, null, implode(' – ', $result));
				return;
			}
			if ($result === false) {
				$this->renderEditor($book_id, $values, null, _t('The book could not be saved.'));
				return;
			}

			// Read the row back so the form shows what is actually stored.
			$book = new plugin_books($book_id);
			$missing = $this->missingCovers($values);
			$this->renderEditor($book_id, $this->valuesFromBook($book), $missing
				? _t('Book saved. These covers were not found in the covers directory: %1', join(', ', $missing))
				: _t('Book saved.'));
			return;
		}

		$new_id = plugin_books::createBook($values);
		if (!$new_id) {
			$this->renderEditor(0, $values, null, _t('The book could not be created.'));
			return;
		}

		$book = new plugin_books($new_id);
		$this->renderEditor($new_id, $this->valuesFromBook($book), _t('Book created.'));
	}

	/**
	 * Deletes a book and its sections, after confirmation.
	 *
	 * The GET shows the confirmation inside the list, so the user still sees
	 * what is about to disappear and how many sections go with it; the POST
	 * does the deletion. Same two-step shape as deleteSection().
	 */
	public function Delete() {
		$book_id = (int)$this->request->getParameter('book', pInteger);

		$book = new plugin_books($book_id);
		if (!$book->isLoaded()) {
			$this->renderList(null, _t('This book no longer exists.'));
			return;
		}

		// The confirmation screen writes nothing, so it needs no token — and
		// demanding one here made the whole feature unreachable, since the link
		// that opens it cannot carry one. The token is required on the POST
		// that actually deletes, which is the step that matters.
		if (!$_POST) {
			$this->renderList(null, null, $book_id);
			return;
		}

		if (!$this->isTrustedRequest()) {
			$this->renderList(null, _t('Invalid or expired request. Reload the page and try again.'));
			return;
		}

		if (!plugin_books::deleteBook($book_id)) {
			$this->renderList(null, _t('The book could not be deleted.'));
			return;
		}
		$this->renderList(_t('Book deleted.'));
	}

	/** Copies a book and all of its sections, under an explicit copy title. */
	public function Duplicate() {
		if (!$this->isTrustedRequest()) { $this->renderList(null, _t('Invalid or expired request. Reload the page and try again.')); return; }

		$book_id = (int)$this->request->getParameter('book', pInteger);

		$book = new plugin_books($book_id);
		if (!$book->isLoaded()) {
			$this->renderList(null, _t('This book no longer exists.'));
			return;
		}

		$new_id = plugin_books::duplicateBook($book_id, _t('%1 (copy)', $book->getTitle()));
		if (!$new_id) {
			$this->renderList(null, _t('The book could not be duplicated.'));
			return;
		}
		$this->renderList(_t('Book duplicated. Its sections were copied; nothing has been rendered yet.'));
	}

	# -------------------------------------------------------
	# Rendering helpers
	# -------------------------------------------------------

	/**
	 * The list view, with an optional message and an optional book awaiting
	 * deletion confirmation.
	 */
	private function renderList($notification=null, $error=null, $confirm_book_id=null) {
		$books = plugin_books::getBooks();

		$this->view->setVar('books', $books);
		$this->view->setVar('themes', ThemeRegistry::getThemes());
		$this->view->setVar('formats_by_theme', $this->formatLabelsByTheme());
		$this->view->setVar('confirm_book_id', $confirm_book_id ? (int)$confirm_book_id : null);
		$this->view->setVar('notification', $notification);
		$this->view->setVar('error', $error);
		$this->render('books_list_html.php');
	}

	/**
	 * The edit form.
	 *
	 * Formats and typographic pairs are read from the theme the book currently
	 * carries: they are declared per theme, so the two lists only make sense
	 * relative to one. A theme just changed in the form takes effect on the
	 * next opening, which the view says explicitly.
	 */
	private function renderEditor($book_id, array $values, $notification=null, $error=null) {
		$registry = new ThemeRegistry(isset($values['theme']) ? $values['theme'] : 'default');

		$this->view->setVar('book_id', (int)$book_id);
		$this->view->setVar('values', $values);
		$this->view->setVar('themes', ThemeRegistry::getThemes());
		$this->view->setVar('formats', $this->labelledCodes($registry->getFormats()));
		$this->view->setVar('font_pairs', $this->labelledCodes($registry->getFontPairs()));
		$this->view->setVar('notification', $notification);
		$this->view->setVar('error', $error);
		$this->render('book_edit_html.php');
	}

	# -------------------------------------------------------
	# Values
	# -------------------------------------------------------

	/**
	 * Blank book.
	 *
	 * The format and the pair are the first ones the default theme declares
	 * rather than the literal column defaults of the schema ('a4-landscape',
	 * 'default'): the registry falls back to its first entry for a code it does
	 * not know, so a book created with a code no theme declares would render
	 * correctly while showing an unknown value in its own settings form.
	 */
	private function defaultValues() {
		$registry = new ThemeRegistry('default');
		$formats = $registry->getFormats();
		$pairs   = $registry->getFontPairs();

		return array(
			'idno'          => '',
			'title'         => '',
			'subtitle'      => '',
			'description'   => '',
			'theme'         => 'default',
			'font_pair'     => sizeof($pairs) ? (string)array_key_first($pairs) : 'default',
			'page_format'   => sizeof($formats) ? (string)array_key_first($formats) : 'a4-landscape',
			'cover_pdf'     => '',
			'backcover_pdf' => '',
		);
	}

	/**
	 * Form values of a loaded book.
	 *
	 * Read through getField(), never through $book->column: the magic getter
	 * throws on anything it does not know, and a column added to the table
	 * after the row was written is exactly that case.
	 */
	private function valuesFromBook(plugin_books $book) {
		$values = array();
		foreach ($this->defaultValues() as $field => $default) {
			$values[$field] = $book->getField($field, $default);
		}
		return $values;
	}

	/**
	 * Form values of the current request.
	 *
	 * Only the fields the form owns are read, so the payload cannot reach a
	 * column the interface does not expose; the model whitelist is the second
	 * line of defence.
	 */
	private function valuesFromRequest() {
		$defaults = $this->defaultValues();

		$values = array();
		foreach (array_keys($defaults) as $field) {
			$values[$field] = (string)$this->request->getParameter($field, pString);
		}

		// An empty theme, format or pair would defeat the registry fallbacks,
		// which key on an unknown code rather than on an empty one.
		foreach (array('theme', 'font_pair', 'page_format') as $field) {
			if (!strlen($values[$field])) { $values[$field] = $defaults[$field]; }
		}

		// Covers are file NAMES, resolved inside the covers directory. Reducing
		// them here rather than at assembly time is what makes the change
		// visible: the v1 stored full paths, and silently keeping one would
		// produce a book without its cover, or bound with a namesake.
		foreach (array('cover_pdf', 'backcover_pdf') as $field) {
			$values[$field] = basename(trim($values[$field]));
		}
		return $values;
	}

	/**
	 * Covers named on the book that are not in the covers directory.
	 *
	 * Returned so the form can say so. A cover that cannot be found is not an
	 * error worth refusing the save for — the file may be uploaded next — but
	 * it must not be discovered on the printed copy either.
	 *
	 * @return string[] the names that resolve to nothing
	 */
	private function missingCovers(array $values) {
		$directory = trim((string)$this->opo_config->get('covers_dir'));
		if (!strlen($directory)) {
			$directory = __CA_APP_DIR__.'/plugins/bookCreator/assets/covers';
		}

		$missing = array();
		foreach (array('cover_pdf', 'backcover_pdf') as $field) {
			$name = trim($values[$field]);
			if ($name === '') { continue; }
			if (!is_readable($directory.'/'.$name)) { $missing[] = $name; }
		}
		return $missing;
	}

	# -------------------------------------------------------
	# Theme lookups
	# -------------------------------------------------------

	/**
	 * Turns a registry array of code => properties into code => display label.
	 *
	 * Formats and typographic pairs carry a 'label' in theme.conf; 'name' is
	 * accepted too, and the code itself is the last resort, so a theme written
	 * without labels still produces a usable list.
	 */
	private function labelledCodes($entries) {
		$labels = array();
		if (!is_array($entries)) { return $labels; }
		foreach ($entries as $code => $properties) {
			$label = $code;
			if (is_array($properties)) {
				if (isset($properties['label'])) { $label = $properties['label']; }
				elseif (isset($properties['name'])) { $label = $properties['name']; }
			}
			$labels[$code] = $label;
		}
		return $labels;
	}

	/**
	 * Format labels of every installed theme, as theme => format => label.
	 *
	 * Built once for the whole list rather than per book: formats are declared
	 * per theme, and an installation holds a handful of themes for any number
	 * of books.
	 */
	private function formatLabelsByTheme() {
		$labels = array();
		foreach (array_keys(ThemeRegistry::getThemes()) as $code) {
			$registry = new ThemeRegistry($code);
			$labels[$code] = $this->labelledCodes($registry->getFormats());
		}
		return $labels;
	}
}
