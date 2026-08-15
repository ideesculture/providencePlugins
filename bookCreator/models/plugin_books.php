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
 *
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
		$this->books_db_structure = array("book_id", "idno", "title", "description");
		$this->booksections_db_structure = array("booksection_id", "book_id", "sectiontype", "parent_id", "sort", "title", "style", "content", "intro", "date", "set_id", "representation_id", "object_id", "pages", "first_page", "is_in_summary");
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
			throw new Exception('.....');
		}
		else return $this->data[$varName];
	}

	public function __set($varName,$value){
		$this->data[$varName] = $value;
	}

	public function load($id) {
		$this->book_id = $id;
		$o_data = new Db();
		$qr_result = $o_data->query("
		    SELECT * 
		    FROM plugin_books 
		    WHERE book_id = ".$id);
		if($qr_result->numRows()==1) {
			$qr_result->nextRow();
			foreach($qr_result->getRow() as $field=>$value) {
				$this->{$field} = $value;
			}
		} else {
			return false;
		}
	}
	
	public function getNbPages() {
		$o_data = new Db();
		
		$qr_result = $o_data->query("
		    SELECT SUM(pages) 
		    FROM plugin_booksections 
		    WHERE book_id = ".$this->book_id." ORDER BY sort");
		$result=array();
		if($qr_result->numRows()==1) {
			$qr_result->nextRow();
			return $qr_result->getRow();
		} else {
			return false;
		}
	}

	public function getSections() {
		$o_data = new Db();
		$qr_result = $o_data->query("
		    SELECT * 
		    FROM plugin_booksections 
		    WHERE book_id = ".$this->book_id." ORDER BY sort");
		$result=array();
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
		    WHERE book_id = ".$this->book_id." AND booksection_id = ".$id." ORDER BY sort");
			
		$result=array();
		if($qr_result->numRows()==1) {
			$qr_result->nextRow();
			return $qr_result->getRow();
		} else {
			return false;
		}
	}

	public function setSection($id, $data) {
		$update_vars = array();
		if(!isset($data["is_in_summary"])) {
			$data["is_in_summary"]=0;
		}
		foreach($data as $field=>$value) {
			if(in_array($field, $this->booksections_db_structure)) {
				$update_vars[] = $field."=\"".str_replace('"','&quot;',$value)."\"";
			}
		}
		$o_data = new Db();
		$request = "UPDATE plugin_booksections SET ".implode(", ", $update_vars)." WHERE book_id = ".$this->book_id." AND booksection_id = ".$id;
		$qr_result = $o_data->query($request);
		if($qr_result->errors) {
			return $qr_result->errors;
		} else {
			return true;
		}
	}

	public function addSection() {
		$o_data = new Db();
		$request = "SELECT MAX(sort) as \"max\" FROM plugin_booksections WHERE book_id=".$this->book_id;
		$qr_result = $o_data->query($request);
		$qr_result->nextRow();
		$sort = (int) $qr_result->get("max") + 1;
		$request = "INSERT INTO plugin_booksections (book_id, booksection_id, title, sort, style) VALUES (".$this->book_id.",0, \"Page blanche\", ".$sort.", \"page-blanche\")";
		$qr_result = $o_data->query($request);
		if($qr_result->errors) {
			return $qr_result->errors;
		} else {
			return true;
		}
	}

	public function deleteSection($id) {
		$o_data = new Db();
		$request = "DELETE FROM plugin_booksections WHERE book_id = ".$this->book_id." AND booksection_id = ".$id;
		$qr_result = $o_data->query($request);
		if($qr_result->errors) {
			return $qr_result->errors;
		} else {
			return true;
		}
	}
}
?>