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
	protected $book_id;

	// Container for magical setter and getter vars
	private $data;

	// Description vars
	private $books_db_structure;
	private $booksections_db_structure;

	public function __construct($id) {
		if(!$id) {
			return false;
		}
		if($id > 0) {
			$this->load($id);
		} else {
			// Insertion mode, with an id = 0, doing stuff here
		}
		$this->books_db_structure = array("book_id", "idno", "title", "subtitle", "description", "theme", "font_pair", "page_format", "cover_pdf", "backcover_pdf", "locale_id", "created_on", "modified_on");
		// Whitelist of writable section columns. Legacy v1 columns (sectiontype,
		// parent_id, object_id, date) are deliberately absent: they were never
		// populated and the v2 code does not use them.
		$this->booksections_db_structure = array("booksection_id", "book_id", "sort", "title", "style", "content", "intro", "set_id", "representation_id", "pages", "first_page", "is_in_summary", "options", "content_hash", "rendered_on");
	}

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
	public function setSection($id, $data) {
		$update_vars = array();
		$values = array();
		if(!isset($data["is_in_summary"])) {
			$data["is_in_summary"]=0;
		}
		foreach($data as $field=>$value) {
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
}
?>
