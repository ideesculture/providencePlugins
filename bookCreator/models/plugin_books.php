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

/**
 * Book and section storage.
 *
 * Every statement is prepared and its values bound: Db::query() takes the SQL
 * and an array of values for its placeholders. Column names cannot be bound, so
 * the ones coming from user input are checked against the whitelist below
 * before being written into the statement.
 */
class plugin_books {

	/**
	 * Book columns a form is allowed to write.
	 *
	 * Mirrors the $books_db_structure list built by the constructor, less
	 * book_id, created_on and modified_on: the identity of a row and its
	 * timestamps belong to the model, never to a payload. Kept as a constant
	 * because the static creation and duplication helpers have no instance to
	 * read the constructor list from.
	 */
	const BOOKS_WRITABLE_COLUMNS = array("idno", "title", "subtitle", "description", "theme", "font_pair", "page_format", "cover_pdf", "backcover_pdf", "locale_id");

	/** Columns never taken from a payload, even though they are real columns. */
	const BOOKS_RESERVED_COLUMNS = array("book_id", "created_on", "modified_on");

	protected $book_id;

	// Container for magical setter and getter vars
	private $data;

	// Description vars
	private $books_db_structure;
	private $booksections_db_structure;

	public function __construct($id) {
		// Column lists first, and unconditionally: the previous version returned
		// before setting them when the id was empty, so any later setSection()
		// ran in_array() against null and took the page down with a TypeError.
		$this->books_db_structure = array("book_id", "idno", "title", "subtitle", "description", "theme", "font_pair", "page_format", "cover_pdf", "backcover_pdf", "locale_id", "created_on", "modified_on");
		// Whitelist of writable section columns. Legacy v1 columns (sectiontype,
		// parent_id, object_id, date) are deliberately absent: they were never
		// populated and the v2 code does not use them.
		$this->booksections_db_structure = array("booksection_id", "book_id", "sort", "title", "style", "content", "intro", "set_id", "representation_id", "pages", "first_page", "is_in_summary", "options", "content_hash", "rendered_on");

		if ($id > 0) { $this->load($id); }
	}

	/**
	 * Section columns a submitted form is never allowed to write.
	 *
	 * booksection_id and book_id are real columns, and they were in the
	 * whitelist: a forged post could therefore move a section to another book
	 * or change its primary key. The rendering counters are equally off limits
	 * to a form — only the worker writes them, and it goes through
	 * setSectionInternal().
	 */
	const SECTIONS_FORM_RESERVED = array("booksection_id", "book_id", "pages", "first_page", "content_hash", "rendered_on");

	// Source : http://stackoverflow.com/questions/12330341/php-trying-to-create-dynamic-variables-in-classes/12330428#12330428
	// This is a method to magically set and get variables inside the class, they are actually stored inside the data array container
	// but setter and getter allows them to be used like $my_plugin_books_instance->var1
	public function __get($varName){
		// Avoid using magic getter if we are using a global get, as in var_dump($data)
		if(!$varName) return array("book_id"=>$this->book_id, "data"=>$this->data);

		if(isset($this->{$varName})) return $this->{$varName};

		// Magic getter, $this->varName gets back $this->data["varName"]
		if (!array_key_exists($varName,$this->data)){
			//this attribute is not defined!
			throw new Exception('Unknown book property: '.$varName);
		}
		else return $this->data[$varName];
	}

	public function __set($varName,$value){
		$this->data[$varName] = $value;
	}

	public function load($id) {
		$this->book_id = (int)$id;
		$o_data = new Db();
		$qr_result = $o_data->query("
		    SELECT *
		    FROM plugin_books
		    WHERE book_id = ?", array((int)$id));
		if($qr_result && $qr_result->numRows()==1) {
			$qr_result->nextRow();
			foreach($qr_result->getRow() as $field=>$value) {
				$this->{$field} = $value;
			}
			return true;
		}
		return false;
	}

	/**
	 * Book title, or an empty string when the book could not be loaded.
	 *
	 * Goes through $data directly rather than the magic getter, which throws on
	 * an unknown property: a view asking for the title of a deleted book would
	 * otherwise take the whole page down.
	 */
	public function getTitle() {
		return isset($this->data['title']) ? $this->data['title'] : '';
	}

	/**
	 * True when the row was found and loaded.
	 *
	 * A controller has no other safe way to tell a deleted book from a live
	 * one: reading any property of an unloaded book goes through the magic
	 * getter, which throws.
	 */
	public function isLoaded(): bool {
		// load() sets book_id before running its query, so the identifier alone
		// proves nothing; the row contents are the signal. book_id itself never
		// reaches $data, being a declared property assigned directly.
		return ($this->book_id > 0) && is_array($this->data) && sizeof($this->data) > 0;
	}

	/**
	 * One column of the loaded book, or $default when it is not there.
	 *
	 * Same reasoning as getTitle(), generalised: views and controllers read
	 * through this rather than through $book->column, so a book that could not
	 * be loaded renders an empty field instead of taking the page down.
	 */
	public function getField(string $name, $default = '') {
		return (is_array($this->data) && array_key_exists($name, $this->data) && !is_null($this->data[$name]))
			? $this->data[$name]
			: $default;
	}

	/**
	 * Total number of pages of the book, as an integer.
	 *
	 * Sections never rendered hold NULL and are simply skipped by SUM(); a book
	 * whose sections were all rendered to zero page returns 0.
	 */
	public function getNbPages() {
		$o_data = new Db();
		$qr_result = $o_data->query("
		    SELECT SUM(pages) AS nb_pages
		    FROM plugin_booksections
		    WHERE book_id = ?", array($this->book_id));
		if($qr_result && $qr_result->nextRow()) {
			return (int)$qr_result->get("nb_pages");
		}
		return 0;
	}

	public function getSections() {
		$o_data = new Db();
		$qr_result = $o_data->query("
		    SELECT *
		    FROM plugin_booksections
		    WHERE book_id = ? ORDER BY sort", array($this->book_id));
		$result=array();
		if(!$qr_result) { return $result; }
		while($qr_result->nextRow()) {
			$result[] = $qr_result->getRow();
		}
		return $result;
	}

	public function sortSections($data) {
		foreach($data as $section) {
			$this->setSection($section["booksection_id"], $section);
		}
		return true;
	}

	public function getSection($id) {
		$o_data = new Db();
		$qr_result = $o_data->query("
		    SELECT *
		    FROM plugin_booksections
		    WHERE book_id = ? AND booksection_id = ?", array($this->book_id, (int)$id));

		if($qr_result && $qr_result->numRows()==1) {
			$qr_result->nextRow();
			return $qr_result->getRow();
		}
		return false;
	}

	/**
	 * Updates one section from an array of column => value.
	 *
	 * Column names are matched against the whitelist because they cannot be
	 * bound; values go through placeholders, which removes the need for the
	 * quote-escaping the v1 relied on.
	 */
	public function setSection($id, $data, $from_form = true) {
		$update_vars = array();
		$values = array();

		// Columns a form must never reach. The worker passes $from_form = false
		// to write the rendering counters, which are its own business.
		$reserved = $from_form ? self::SECTIONS_FORM_RESERVED : array("booksection_id", "book_id");

		foreach($data as $field=>$value) {
			if(in_array($field, $reserved)) { continue; }
			if(in_array($field, $this->booksections_db_structure)) {
				$update_vars[] = "`".$field."` = ?";
				$values[] = $value;
			}
		}
		if(!sizeof($update_vars)) { return true; }		// nothing writable in the payload

		$values[] = $this->book_id;
		$values[] = (int)$id;

		$o_data = new Db();
		$request = "UPDATE plugin_booksections SET ".implode(", ", $update_vars)." WHERE book_id = ? AND booksection_id = ?";
		$qr_result = $o_data->query($request, $values);
		if($o_data->numErrors()) {
			return $o_data->getErrors();
		}
		return true;
	}

	public function addSection() {
		$o_data = new Db();
		$qr_result = $o_data->query("SELECT MAX(sort) AS max_sort FROM plugin_booksections WHERE book_id = ?", array($this->book_id));
		$sort = 0;
		if($qr_result && $qr_result->nextRow()) {
			$sort = (int)$qr_result->get("max_sort") + 1;
		}
		// booksection_id is left out so the AUTO_INCREMENT does its job.
		$request = "INSERT INTO plugin_booksections (book_id, title, sort, style) VALUES (?, ?, ?, ?)";
		$o_data->query($request, array($this->book_id, _t("Blank page"), $sort, "page-blanche"));
		if($o_data->numErrors()) {
			return $o_data->getErrors();
		}
		return true;
	}

	public function deleteSection($id) {
		$o_data = new Db();
		$request = "DELETE FROM plugin_booksections WHERE book_id = ? AND booksection_id = ?";
		$o_data->query($request, array($this->book_id, (int)$id));
		if($o_data->numErrors()) {
			return $o_data->getErrors();
		}
		return true;
	}

	# -------------------------------------------------------
	# Book level operations
	# -------------------------------------------------------

	/**
	 * Every book, sorted by title, with its section count and its cumulated
	 * page count.
	 *
	 * One statement, deliberately. Counting the sections of each book from the
	 * dashboard loop would issue one query per row, which is exactly what turns
	 * a list of thirty books into thirty-one round trips. The aggregate is
	 * computed once in a derived table and joined back.
	 *
	 * The aggregate sits in a subquery rather than in a GROUP BY over the outer
	 * SELECT so that `b.*` stays legal under ONLY_FULL_GROUP_BY, whatever the
	 * sql_mode of the installation.
	 *
	 * Sections never rendered hold NULL in `pages`; SUM() skips them, and
	 * COALESCE turns the "no section at all" case into 0 rather than NULL.
	 */
	public static function getBooks(): array {
		$o_data = new Db();
		$qr_result = $o_data->query("
		    SELECT b.*,
		           COALESCE(s.nb_sections, 0) AS nb_sections,
		           COALESCE(s.nb_pages, 0) AS nb_pages
		    FROM plugin_books b
		    LEFT JOIN (
		        SELECT book_id, COUNT(*) AS nb_sections, SUM(pages) AS nb_pages
		        FROM plugin_booksections
		        GROUP BY book_id
		    ) s ON s.book_id = b.book_id
		    ORDER BY b.title");

		$result = array();
		if(!$qr_result) { return $result; }
		while($qr_result->nextRow()) {
			$row = $qr_result->getRow();
			$row["nb_sections"] = (int)$row["nb_sections"];
			$row["nb_pages"] = (int)$row["nb_pages"];
			$result[] = $row;
		}
		return $result;
	}

	/**
	 * Inserts a book and returns its identifier.
	 *
	 * Column names come from the constant whitelist, never from the payload:
	 * they cannot be bound, so anything else in $data is simply ignored. Values
	 * all go through placeholders.
	 *
	 * @param array $data column => value
	 * @return int|null the new book_id, or null when the insert failed
	 */
	public static function createBook(array $data): ?int {
		$columns = array();
		$placeholders = array();
		$values = array();

		foreach(self::BOOKS_WRITABLE_COLUMNS as $field) {
			if(!array_key_exists($field, $data)) { continue; }
			$columns[] = "`".$field."`";
			$placeholders[] = "?";
			$values[] = $data[$field];
		}

		// Unix timestamps, like every other date the plugin stores.
		$now = time();
		$columns[] = "`created_on`";  $placeholders[] = "?"; $values[] = $now;
		$columns[] = "`modified_on`"; $placeholders[] = "?"; $values[] = $now;

		$o_data = new Db();
		$request = "INSERT INTO plugin_books (".implode(", ", $columns).") VALUES (".implode(", ", $placeholders).")";
		$o_data->query($request, $values);
		if($o_data->numErrors()) { return null; }

		$new_id = (int)$o_data->getLastInsertID();
		return ($new_id > 0) ? $new_id : null;
	}

	/**
	 * Updates the loaded book from an array of column => value.
	 *
	 * Same shape as setSection(): the whitelist filters the column names, the
	 * values are bound, and the return is either true or the array of database
	 * errors so the caller can show them.
	 *
	 * @param array $data
	 * @return bool|array true on success, false when no book is loaded, or the
	 *                    array of database errors
	 */
	public function save(array $data): bool|array {
		if(!($this->book_id > 0)) { return false; }

		// The constructor builds the whitelist; fall back to the constant for
		// an instance built in insertion mode, where it was never assigned.
		$whitelist = is_array($this->books_db_structure) ? $this->books_db_structure : self::BOOKS_WRITABLE_COLUMNS;

		$update_vars = array();
		$values = array();
		foreach($data as $field=>$value) {
			// book_id, created_on and modified_on are columns of the table, so
			// they are in the whitelist, but they are not editable: a form must
			// not be able to move a book to another id or rewrite its history.
			if(in_array($field, self::BOOKS_RESERVED_COLUMNS)) { continue; }
			if(in_array($field, $whitelist)) {
				$update_vars[] = "`".$field."` = ?";
				$values[] = $value;
			}
		}
		if(!sizeof($update_vars)) { return true; }		// nothing writable in the payload

		$update_vars[] = "`modified_on` = ?";
		$values[] = time();
		$values[] = $this->book_id;

		$o_data = new Db();
		$request = "UPDATE plugin_books SET ".implode(", ", $update_vars)." WHERE book_id = ?";
		$o_data->query($request, $values);
		if($o_data->numErrors()) {
			return $o_data->getErrors();
		}
		return true;
	}

	/**
	 * Deletes a book and its sections.
	 *
	 * plugin_booksections carries no foreign key on book_id, so nothing cascades
	 * and both deletions are issued explicitly. Sections go first: should the
	 * second statement fail, the book is still listed and can be deleted again,
	 * which is recoverable — the reverse order would leave sections nobody can
	 * reach from the interface.
	 *
	 * @return bool false when the id is not usable or a statement failed
	 */
	public static function deleteBook(int $id): bool {
		if($id <= 0) { return false; }

		$o_data = new Db();
		$o_data->query("DELETE FROM plugin_booksections WHERE book_id = ?", array($id));
		if($o_data->numErrors()) { return false; }

		$o_data->query("DELETE FROM plugin_books WHERE book_id = ?", array($id));
		if($o_data->numErrors()) { return false; }

		return true;
	}

	/**
	 * Copies a book and all of its sections under a new title.
	 *
	 * Both copies are INSERT ... SELECT statements: the sections are duplicated
	 * by the database in a single round trip, whatever their number, instead of
	 * being read into PHP and written back one by one.
	 *
	 * pages, first_page, content_hash and rendered_on are reset to NULL on the
	 * copies: a duplicate has rendered nothing yet, and carrying over the page
	 * counts of the original would produce a table of contents and a pagination
	 * that describe a PDF which does not exist.
	 *
	 * @return int|null the new book_id, or null when the source book does not
	 *                  exist or a statement failed
	 */
	public static function duplicateBook(int $id, string $new_title): ?int {
		if($id <= 0) { return null; }

		$o_data = new Db();
		$now = time();

		$o_data->query("
		    INSERT INTO plugin_books
		        (idno, title, subtitle, description, theme, font_pair, page_format, cover_pdf, backcover_pdf, locale_id, created_on, modified_on)
		    SELECT idno, ?, subtitle, description, theme, font_pair, page_format, cover_pdf, backcover_pdf, locale_id, ?, ?
		    FROM plugin_books
		    WHERE book_id = ?", array($new_title, $now, $now, $id));
		if($o_data->numErrors()) { return null; }

		// An unknown source book selects no row, so nothing is inserted and the
		// insert id stays at 0.
		$new_id = (int)$o_data->getLastInsertID();
		if($new_id <= 0) { return null; }

		$o_data->query("
		    INSERT INTO plugin_booksections
		        (book_id, sort, title, style, content, intro, set_id, representation_id, is_in_summary, options, pages, first_page, content_hash, rendered_on)
		    SELECT ?, sort, title, style, content, intro, set_id, representation_id, is_in_summary, options, NULL, NULL, NULL, NULL
		    FROM plugin_booksections
		    WHERE book_id = ?", array($new_id, $id));
		if($o_data->numErrors()) { return null; }

		return $new_id;
	}
}
?>
