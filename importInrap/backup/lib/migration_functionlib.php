<?php
require_once(__CA_LIB_DIR__."/Search/EntitySearch.php");
require_once(__CA_LIB_DIR__."/Search/PlaceSearch.php");
require_once(__CA_LIB_DIR__."/Search/StorageLocationSearch.php");
require_once(__CA_LIB_DIR__."/Search/ObjectSearch.php");

require_once(__CA_MODELS_DIR__."/ca_places.php");

$pn_locale_id=2;
// ----------------------------------------------------------------------
function getListID($t_list,$list_code,$list_name="") {
	global $pn_locale_id;

	// create vocabulary list record (if it doesn't exist already)
	if (!$t_list->load(array('list_code' => $list_code))) {
		$t_list->setMode(ACCESS_WRITE);
		$t_list->set('list_code', $list_code);
		$t_list->set('is_system_list', 0);
		$t_list->set('is_hierarchical', 1);
		$t_list->set('use_as_vocabulary', 1);
		$t_list->insert();
		
		if ($t_list->numErrors()) {
			print "ERROR: couldn't create ca_list row for $list_code: ".join('; ', $t_list->getErrors())."\n";
			die;
		}
		
		$t_list->addLabel(array('name' => $list_name), $pn_locale_id, null, true);
	}
	$vn_list_id = $t_list->getPrimaryKey();

	return $vn_list_id;	
}
// ----------------------------------------------------------------------

function ExistsItemID($t_list,$vn_list_id,$ps_idno) {
	global $pn_locale_id;

	if($vn_item_id=$t_list->getItemIDFromList($vn_list_id,$ps_idno)) {
		return $vn_item_id;
	} else {
		return false;
	}	
	
}

function getItemID($t_list,$vn_list_id,$pn_type_id,$ps_idno,$libelle,$comment,$pb_is_enabled=1,$pb_is_default = 0, $pn_parent_id=0,$pn_rank = null, $explode_separator_array = null) {
	global $pn_locale_id,$DEBUG;
	
	if ($explode_separator_array) $label_type = $explode_separator_array[1]["label_type"];
	
	if(($vn_item_id = ExistsItemID($t_list,$vn_list_id,$ps_idno)) == false) {
		if ($DEBUG) print "l'item n'existe pas, on va le créer $ps_idno.\n"; 
		$t_item = new ca_list_items();
		$t_item->setMode(ACCESS_WRITE);
		$t_item->set('list_id', $vn_list_id);
		$t_item->set('item_value', $ps_idno);
		$t_item->set('is_enabled', $pb_is_enabled ? 1 : 0);
		$t_item->set('is_default', $pb_is_default ? 1 : 0);
		$t_item->set('parent_id', $pn_parent_id);
		$t_item->set('type_id', $pn_type_id);
		$t_item->set('idno', $ps_idno);
		$t_item->set('access', 1);
		
		
		if (!is_null($pn_rank)) { $t_item->set('rank', $pn_rank); }
		
		$t_item->insert();
		if ($t_item->numErrors()) { 
			$this->errors = array_merge($this->errors, $t_item->errors);
			var_dump($this->errors);die();
			return false;
		}

		//var_dump($t_item);
		if (($explode_separator_array) && ( strpos($libelle,$explode_separator_array[1]["separator"]) > 0) ) {
			// Un séparateur défini et trouvé dans le libellé, on casse selon le séparateur et on crée les titres secondaires avec le bon type
			$libelles = explode($explode_separator_array[1]["separator"],$libelle);
			// Pour chaque libellé individuel, si numéro 0 : libellé principal, si numéro > 0 synonyme
			foreach( $libelles as $key => $value){
				$t_item->addLabel(array('name_singular' => $value , 'name_plural' => $value, 'description' => $comment),$pn_locale_id, ($key == 0 ? null : $label_type) , ($key == 0 ? true : false));
			}
		} else {
			// Pas de séparateur, un seul libellé à traiter en libellé principal
			$t_item->addLabel(array('name_singular' => $libelle, 'name_plural' => $libelle, 'description' => $comment),$pn_locale_id, null, true);									
		}
		if ($t_item->numErrors()) {
			print "PROBLEM WITH ITEM {$ps_idno}: ".join('; ', $t_item->getErrors())."\n";
		}
		$vn_item_id=ExistsItemID($t_list,$vn_list_id,$ps_idno);
	} 
	return $vn_item_id;
	
}

// ----------------------------------------------------------------------
function addObjectSimpleAttribute($t_object,$ATTRIBUTE_CONTENT,$attribute_field,$text_when_empty="") {
	global $pn_locale_id;
	
	if (($ATTRIBUTE_CONTENT=="") && ($text_when_empty !="")) $ATTRIBUTE_CONTENT=$text_when_empty;
	$t_object->addAttribute(array(
			'locale_id' => $pn_locale_id,
			$attribute_field => $ATTRIBUTE_CONTENT
	), $attribute_field);		
	if ($t_object->numErrors()) {
		print "ERROR ADDING $attribute_field TO OBJECT {$ID_NUMBER}: ".join('; ', $t_object->getErrors())."\n";
		return false;
	}
	return true;
}

function getStorageLocationIDfromPartialName($ps_location, $vn_loc_type_id, $ps_location_alternate="") {
	global $pn_locale_id;
	global $VERBOSE;
	global $DEBUG;
	
	$t_loc = new ca_storage_locations();
	$t_label = $t_loc->getLabelTableInstance();
	$location_id="";
	
	$o_data = new Db();
	$qr_c = $o_data->query("
			SELECT location_id
			FROM ca_storage_location_labels
			WHERE left(name,locate(\"|\",name)-1) = ? 
		", $ps_location);
		
	if ($qr_c->nextRow()) {		
		$location_id = (int)$qr_c->get('location_id');
		if ($DEBUG) print "Localisation trouvée ".$location_id."\n";
	} else {
		// pas de résultat trouvé avec une partie du nom, on cherche sur le global
		$location_id = getStorageLocationID($ps_location, $vn_loc_type_id, $ps_location_alternate);
	}
	return $location_id;
	 	
}	

// ----------------------------------------------------------------------
function getStorageLocationID($ps_location, $vn_loc_type_id, $options = []) {
	global $pn_locale_id;
	global $VERBOSE;
	
	$t_loc = new ca_storage_locations();
	$t_loc_search = new StorageLocationSearch();
	/*$results = $t_loc_search->search("ca_storage_locations:'"+$ps_location+"'");
	$i = 0;
	while ($results->nextHit()){
		if($results->get("type_id") == 2773){
			return $results->get("location_id");
			break;
		}
		continue;
	}*/



	if ($t_loc->load(array('idno' => $ps_location, "deleted"=> 0))) {
		
		if ($VERBOSE) print "\tFound Location $ps_location\n";
		$vn_location_id = $t_loc->getPrimaryKey();
		return $vn_location_id;
	}	

	/*if($options["attributes"]) {
		//var_dump($vn_mov_id);die();
		foreach($options["attributes"] as $attribute=>$value) {
			// First remove old values
			$t_loc->removeAttributes($attribute);
			$t_loc->addAttribute($value, $attribute);
		}
		$t_loc->update();
		if($t_loc->numErrors()) {
			var_dump($t_loc->getErrors());
			die();
		}
	}*/
}

function getMovementID($ps_mov, $vn_loc_type_id, $options = []) {
	global $pn_locale_id;
	global $VERBOSE;
	//$VERBOSE=1;
	$t_mov = new ca_movements();
	if (!$t_mov->load(array('idno' => $ps_mov, "deleted"=>0))) {
		if ($VERBOSE) {print "CREATING MOVEMENT $ps_mov\n";}
		// insert location
		$t_mov->setMode(ACCESS_WRITE);
		$t_mov->set('locale_id', $pn_locale_id);
		$t_mov->set('type_id', $vn_loc_type_id);
		$t_mov->set('access', 1);
		$t_mov->set('status', 2);
		$t_mov->set('idno', $ps_mov);
		
		$t_mov->insert();
		
		if ($t_mov->numErrors()) {
			print "ERROR INSERTING movement ($ps_mov): ".join('; ', $t_mov->getErrors())."\n";
			die();
		}
		$t_mov->addLabel(array(
			'name' => $ps_mov
		), 2, null, true);
		if ($t_mov->numErrors()) {
			var_dump($t_mov->getErrors());
			return null;
		}
		
		$vn_mov_id = $t_mov->getPrimaryKey();
	} else {
		if ($VERBOSE) print "\tFound Movement $ps_mov\n";
		$t_mov->setMode(ACCESS_WRITE);
		$t_mov->set('type_id', $vn_loc_type_id);
		$t_mov->update();
		if ($t_mov->numErrors()) {
			var_dump($t_mov->getErrors());
			return null;
		}
		$vn_mov_id = $t_mov->getPrimaryKey();
	}
	//die();
	
	if($options["attributes"]) {
		//var_dump($vn_mov_id);die();
		foreach($options["attributes"] as $attribute=>$value) {
			// First remove old values
			$t_mov->removeAttributes($attribute);
			$t_mov->addAttribute($value, $attribute);
		}
		$t_mov->update();
		if($t_mov->numErrors()) {
			var_dump($t_mov->getErrors());
			die();
		}
	}
	return $vn_mov_id;
}

// ----------------------------------------------------------------------	
function getCollectionID($ps_collection, $ps_collection_idno, $pn_collection_type_id) {
	global $pn_locale_id;
	global $VERBOSE;
	
	$t_loc = new ca_collections();
	$t_label = $t_loc->getLabelTableInstance();
	if (!$t_label->load(array('name' => $ps_collection))) {
		if ($VERBOSE) print "CREATING COLLECTION {$ps_collection}\n";
		// insert collection
		$t_loc->setMode(ACCESS_WRITE);
		$t_loc->set('locale_id', $pn_locale_id);
		$t_loc->set('type_id', $pn_collection_type_id);
		$t_loc->set('access', 1);
		$t_loc->set('status', 2);
		$t_loc->set('idno', $ps_collection_idno);
		
		$t_loc->addAttribute(array(
			'locale_id' => $pn_locale_id,
			'name' => $ps_collection
		), 'name');
		
		$t_loc->insert();
		
		if ($t_loc->numErrors()) {
			print "ERROR INSERTING COLLECTION ($ps_collection): ".join('; ', $t_loc->getErrors())."\n";
			return null;
		}
		$t_loc->addLabel(array(
			'name' => $ps_collection
		), $pn_locale_id, null, true);
		
		
		$vn_collection_id = $t_loc->getPrimaryKey();
	} else {
	
		if ($VERBOSE) print "\t\t Found Collection {$ps_collection}\n";
		$vn_collection_id = $t_label->get('collection_id');
	}
	
	return $vn_collection_id;
}
// ----------------------------------------------------------------------	
function getOccurrenceID($ps_occurrence, $ps_occurrence_idno, $pn_occurrence_type_id) {
	global $pn_locale_id;
	global $VERBOSE;
	$pn_locale_id = 2;
	
	$t_loc = new ca_occurrences();
	$t_label = $t_loc->getLabelTableInstance();
	if (!$t_loc->load(array('idno' => $ps_occurrence, 'deleted'=>0))) {
		if ($VERBOSE) print "CREATING OCCURRENCE {$ps_occurrence}\n";
		// insert occurrence
		$t_loc->setMode(ACCESS_WRITE);
		$t_loc->set('locale_id', $pn_locale_id);
		$t_loc->set('type_id', $pn_occurrence_type_id);
		$t_loc->set('access', 1);
		$t_loc->set('status', 2);
		$t_loc->set('idno', $ps_occurrence_idno);
		
		$t_loc->addAttribute(array(
			'locale_id' => $pn_locale_id,
			'name' => $ps_occurrence
		), 'name');
		
		$t_loc->insert();
		
		if ($t_loc->numErrors()) {
			print "ERROR INSERTING occurrence ($ps_occurrence): ".join('; ', $t_loc->getErrors())."\n";
			return null;
		}
		$t_loc->addLabel(array(
			'name' => $ps_occurrence
		), $pn_locale_id, null, true);
		if ($t_loc->numErrors()) {
			print "ERROR ADDING LABEL occurrence ($ps_occurrence): ".join('; ', $t_loc->getErrors())."\n";
			return null;
		}
		
		$vn_occurrence_id = $t_loc->getPrimaryKey();
	} else {
		if ($VERBOSE) print "\t\t Found occurrence {$ps_occurrence}\n";
		$vn_occurrence_id = $t_loc->getPrimaryKey();
	}
	
	return $vn_occurrence_id;
}

// ----------------------------------------------------------------------	
function getObjectID($ps_object, $ps_object_idno, $pn_object_type_id) {
	global $pn_locale_id;
	global $VERBOSE;
	$pn_locale_id = 2;
	$t_obj = new ca_objects();
	$t_obj->load(["idno" => $ps_object, "deleted" => 0]);
	if (!$t_obj->getPrimaryKey()){
		return false;
	}
	return $t_obj->getPrimaryKey();
}

// ----------------------------------------------------------------------	
function getPlaceID($ps_place, $ps_place_idno, $pn_place_type_id) {
	global $pn_locale_id;
	global $VERBOSE;
	$pn_locale_id = 2;
	
	$t_place = new ca_places();
	$t_label = $t_place->getLabelTableInstance();
	if (!$t_place->load(array('idno' => $ps_place_idno, 'deleted'=>0))) {
		if ($VERBOSE) print "CREATING PLACES {$ps_place}\n";
		// insert occurrence
		$t_place->setMode(ACCESS_WRITE);
		$t_place->set('locale_id', $pn_locale_id);
		$t_place->set('type_id', $pn_place_type_id);
		$t_place->set('access', 1);
		$t_place->set('hierarchy_id', 527);
		$t_place->set('status', 2);
		$t_place->set('idno', $ps_place_idno);
		
		$t_place->addAttribute(array(
			'locale_id' => $pn_locale_id,
			'name' => $ps_place
		), 'name');
		
		$t_place->insert();
		
		if ($t_place->numErrors()) {
			print "ERROR INSERTING PLACES ($ps_place): ".join('; ', $t_place->getErrors())."\n";
			return null;
		}
		$t_place->addLabel(array(
			'name' => $ps_place
		), $pn_locale_id, null, true);
		if ($t_place->numErrors()) {
			print "ERROR ADDING LABEL PLACES ($ps_place): ".join('; ', $t_place->getErrors())."\n";
			return null;
		}
		
		$vn_place_id = $t_place->getPrimaryKey();
	} else {
		if ($VERBOSE) print "\t\t Found places {$ps_place}\n";
		$vn_place_id = $t_place->getPrimaryKey();
	}
	
	return $vn_place_id;
}

function getPlaceIDByName($ps_place, $pn_place_type_id) {
	global $pn_locale_id;
	global $VERBOSE;
	$pn_locale_id = 2;
	
	
	$placeSearch = new PlaceSearch();
	$result = $placeSearch->search($ps_place);
	while ($result->nextHit()){
		if ($result->get("place_id")){
			return $result->get("place_id");
			break;
		}
	}
	
}

// ----------------------------------------------------------------------
function getEntityID($psname) {
	global $pn_locale_id, $vn_date_created, $vn_date_dateUnspecified, $vn_individual, $vn_undefined;
	global $VERBOSE;
	$pn_locale_id = 2;
	
	$entitySeach = new EntitySearch();
	$result = $entitySeach->search("ca_entities:".$psname);
	while ($result->nextHit()){
		$name = explode(" ",$psname);
		$t_entity = new ca_entities($result->get("entity_id"));

		if (stripos($name[0], $t_entity->get("ca_entities.preferred_labels.displayname"))){
			return $result->get("entity_id");
			break;
		}
	}
}

// ----------------------------------------------------------------------	
function getEntityIDByIdno($idno) {
	global $pn_locale_id;
	global $VERBOSE;
	$pn_locale_id = 2;
	
	$t_entity = new ca_entities();
	$t_entity->load(array('idno' => $idno, 'deleted'=>0));
	$vn_entity_id = $t_entity->getPrimaryKey();
	
	
	return $vn_entity_id;
}

function insertRelationEntitiesXPlaces($entity_id,$place_id,$type_id,$rank=1) {
	global $DEBUG;
	
	$o_data = new Db();
	$qr_c = $o_data->query("
			SELECT count(*) c
			FROM ca_entities_x_places
			WHERE entity_id = ? AND place_id = ? AND type_id = ?
		", $entity_id,$place_id,$type_id);
		
	if ($qr_c->nextRow()) {		
		if ($qr_c->get('c') == 0) {
			$o_data->query("INSERT INTO ca_entities_x_places (entity_id,place_id,type_id,rank) SELECT ?,?,?,?",$entity_id,$place_id,$type_id,$rank);
		} else {
			if ($DEBUG) print "Relation déjà présente entité $entity_id - lieu $place_id (".(int)$qr_c->get('c')." fois)\n";
		}
	} else {
		return false;
	}
	return true;
}

function updateObjetLot($object_idno,$lot_id) {
	global $DEBUG;
	
	$o_data = new Db();
	$qr_c = $o_data->query("
			UPDATE ca_objects
			SET lot_id = ?
			WHERE idno = ?
		", $lot_id,$object_idno);
		
	return true;
}


function show_status($done, $total, $size=30) {
	/*
	Copyright (c) 2010, dealnews.com, Inc.
	All rights reserved.
	*/

    static $start_time;

    // if we go over our bound, just ignore it
    if($done > $total) return;

    if(empty($start_time)) $start_time=time();
    $now = time();

    $perc=(double)($done/$total);

    $bar=floor($perc*$size);

    $status_bar="\r[";
    $status_bar.=str_repeat("=", $bar);
    if($bar<$size){
        $status_bar.=">";
        $status_bar.=str_repeat(" ", $size-$bar);
    } else {
        $status_bar.="=";
    }

    $disp=number_format($perc*100, 0);

    $status_bar.="] $disp%  $done/$total";

    $rate = ($now-$start_time)/$done;
    $left = $total - $done;
    $eta = round($rate * $left, 2);

    $elapsed = $now - $start_time;

    $status_bar.= " ".number_format($elapsed)."s reste ".number_format($eta)."s";

    echo "$status_bar";

    //echo "\r";

    // when done, send a newline
    if($done == $total) {
        echo "\n";
    }

}

function cls()
{
    array_map(create_function('$a', 'print chr($a);'), array(27, 91, 72, 27, 91, 50, 74));
}  


function htmlvardump() {
	ob_start(); 
	$var = func_get_args(); 
	call_user_func_array('var_dump', $var); 
	return ob_get_clean();
}

	
function cleanupDate($DATATION) {
	//nettoyage des indications de dates approximatives
	$DATATION=str_replace("?","",$DATATION);
	//remplacement du "vers" à la fin de la date par un ca au début
	$DATATION=preg_replace('/(.*) vers/', 'ca \1', $DATATION);
	//remplacement des dates sous la forme "1920 entre ; 1930 et"
	$DATATION=preg_replace('/(.*) entre ; (.*) et/', '\1-\2', $DATATION);
	//remplacement des dates sous la forme "1920 ; 1930"
	$DATATION=preg_replace('/(\d+) ; (\d+)/', '\1-\2', $DATATION);	
	$DATATION=str_replace("<> ","ca ",$DATATION);
	$DATATION=str_replace("<approximatif> ","ca ",$DATATION);
	$DATATION=str_replace("< ","ca ",$DATATION);
	$DATATION=str_replace("> ","ca ",$DATATION);
	$DATATION=str_replace("<","ca ",$DATATION);
	$DATATION=str_replace(">","ca ",$DATATION);
	$DATATION=str_replace("évent. ","ca ",$DATATION);
	$DATATION=str_replace(".","/",$DATATION);
	// le trim doit être fait avant les preg_replace portant sur toute la chaîne
	$DATATION=trim($DATATION);
	//transformation 1920-30 en 1920-1930
	$DATATION=preg_replace('/^(19|20)(\d{2})-(\d{1,2})$/', '\1\2-\1\3', $DATATION);			
	//transformation des dates (JJ/MM/AAAA) au format américain (MM/JJ/AAAA)
	$DATATION=preg_replace("/^(\d{2})\/(\d{2})\/(\d{4})$/", '\2/\1/\3', $DATATION);
	return $DATATION;
}
?>