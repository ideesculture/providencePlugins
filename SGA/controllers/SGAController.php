<?php
/* ----------------------------------------------------------------------
 * plugins/statisticsViewer/controllers/StatisticsController.php :
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2010 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * ----------------------------------------------------------------------
 */


// require_once(__CA_LIB_DIR__.'TaskQueue.php');
// require_once(__CA_LIB_DIR__.'Configuration.php');
require_once(__CA_LIB_DIR__ . '/Search/PlaceSearch.php');
require_once(__CA_LIB_DIR__ . '/Search/EntitySearch.php');
require_once(__CA_APP_DIR__ . "/plugins/SGA/lib/migration_functionlib.php");

// require_once(__CA_LIB_DIR__.'Browse/CollectionBrowse.php');
// require_once(__CA_LIB_DIR__.'Search/ObjectSearch.php');
// require_once(__CA_LIB_DIR__.'Browse/ObjectBrowse.php');
// require_once(__CA_MODELS_DIR__.'/ca_lists.php');
// require_once(__CA_MODELS_DIR__.'/ca_objects.php');
// require_once(__CA_MODELS_DIR__.'/ca_collections.php');
// require_once(__CA_MODELS_DIR__.'/ca_object_representations.php');
// require_once(__CA_MODELS_DIR__.'/ca_locales.php');

// require_once(__CA_LIB_DIR__.'ResultContext.php');

error_reporting(E_ERROR);
ini_set("display_errors", 1);

class SGAController extends ActionController
{
	# -------------------------------------------------------
	protected $opo_config,		// plugin configuration file
		$ops_plugin_name, $ops_plugin_path,
		$ops_user_groups, $opo_result_context;


	# -------------------------------------------------------
	# Constructor
	# -------------------------------------------------------

	public function __construct(&$po_request, &$po_response, $pa_view_paths = null)
	{
		global $allowed_universes;

		parent::__construct($po_request, $po_response, $pa_view_paths);

		$this->ops_plugin_name = "sga";
		$this->ops_plugin_path = __CA_APP_DIR__ . "/plugins/" . $this->ops_plugin_name;

		$vs_conf_file = $this->ops_plugin_path . "/conf/" . $this->ops_plugin_name . ".conf";
		if (is_file($vs_conf_file)) {
			$this->opo_config = Configuration::load($vs_conf_file);
		}

		$va_groups = $this->getRequest()->getUser()->getUserGroups();
		$this->ops_user_groups = [];
		foreach ($va_groups as $group) {
			if (in_array($group["code"], ["gestion", "admin"])) continue;
			$this->ops_user_groups[] = $group["code"];
		}
	}

	# -------------------------------------------------------
	# Functions to render views
	# -------------------------------------------------------

	public function Compare()
	{
		$o_data = new Db();
		$notInBase = false;
		$value = array("Centre Ile de France" => "DIR CIF", "Grand Ouest" => "DIR GO", "Grand Est" => "DIR GE", "Direction régionale Auvergne-Rhône-Alpes" => "DIR ARA", "Hauts-de-France" => "DIR HDF", "Midi-Méditerranée" => "DIR MIDIMED", "Outre-mer" => "DIR NAOM", "Nouvelle Aquitaine" => "DIR NAOM", "Bourgogne-Franche-Comté" => "DIR BFC");
		$different = [];
		$op_id = $this->getRequest()->getParameter("id", pInteger);
		$type = $this->getRequest()->getParameter("type", pString);

		if (!$type) {
			$qr_result = $o_data->query("SELECT * FROM _sga_comodo WHERE id = " . $op_id . "");
			while ($qr_result->nextRow()) {
				$col = new ca_collections();
				$col->load(["idno" => $qr_result->get("idno"), "deleted" => 0]);
				break;
			}
		} else {
			$col = new ca_collections($op_id);
			$qr_result = $o_data->query("SELECT * FROM _sga_comodo WHERE idno = '" . $col->getWithTemplate("^ca_collections.idno") . "'");
		}

		if (!$col->getPrimaryKey()) {
			$notInBase = true;
		} else {
			$op_id = $col->getPrimaryKey();
		}
		$result = $qr_result->getAllRows()[0];
		foreach ($result as $metadata => $data) {
			if ($metadata == "id" || $metadata == "ro_label" || empty($data)) {
				continue;
			}
			$data_to_compare = $col->getWithTemplate("^ca_collections." . $metadata);
			if ($metadata == "inrap_date_planification") {
				$data_to_compare = $col->getWithTemplate("^ca_collections.inrap_date_versement." . $metadata);
			}
			
			if ($metadata == "surface_OA" && (empty($data) && $data_to_compare == 0) && (empty($data_to_compare) && $data == 0)) {
				$data_to_compare = "";
				$data = "";
			}
			if ($metadata == "ro") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.idno</unit>");
			}
			if ($metadata == "sra") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='attribue' restrictToTypes='sra'>^ca_entities.idno</unit>");
			}
			
			if ($metadata == "dir_inrap") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='DIR'>^ca_entities.preferred_labels.displayname</unit>");
			}
			if ($metadata == "prescripteur") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='suivi_par'>^ca_entities.preferred_labels.displayname</unit>");
			}
			if ($metadata == "autorisation_fouille") {
				$data_to_compare = $col->getWithTemplate("^ca_collections.autorisation_fouille.autorisation_num");
			}
			if ($metadata == "autorisation_date") {
				$data_to_compare = $col->getWithTemplate("^ca_collections.autorisation_fouille.autorisation_date");
			}

			if ($metadata == "dir_adj_st") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='dast'>^ca_entities.preferred_labels.displayname</unit>");
			}
			if ($metadata == "commune") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_places'>^ca_places.preferred_labels.name</unit>");
			}

			if ($data_to_compare == ";") {
				$data_to_compare = "";
			}
			if ($metadata == "CodeINSEE") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_places'>^ca_places.idno</unit>");
			}
			if ($metadata == "AnneeDebutTerrain"){
				$data_to_compare =  $col->getWithTemplate("^ca_collections.inrap_annee_inter");
			}
			if ($metadata == "NomOpeRattachement"){
				$data_to_compare =  $col->getWithTemplate("^ca_collections.inrap_op_rattachement.inrap_op_rattachement_txt");
			}
			if ($metadata == "ro") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.displayname</unit>");
				$data = $result["ro_label"];
			}
			array_push($different, [$metadata, $data, $data_to_compare]);
		}
		$this->view->setVar("value", $different);
		$this->view->setVar("notInBase", $notInBase);
		$this->view->setVar("id", $op_id);
		$this->render("compare_html.php");
	}

	public function IndexImporte($type = "")
	{
		$this->render('index_importe_html.php');
	}
	public function IndexNonImporte($type = "")
	{
		$this->render('index_non_import_html.php');
	}

	public function Importer()
	{
		$op_id = $this->getRequest()->getParameter("id", pInteger);
		$o_data = new Db();
		$qr_result = $o_data->query("SELECT * FROM _sga_comodo WHERE id = " . $op_id . "");
		while ($qr_result->nextRow()) {

			$col = new ca_collections();
			$col->load(["idno" => $qr_result->get("idno"), "deleted" => 0]);
			if (!$col->getPrimaryKey()) {
				$col->setMode(ACCESS_WRITE);
				$col->set(array('access' => 2, "idno" => $qr_result->get("idno"), "status" => 3, "type_id" => 125));
				$col->insert();
			}
			$col->setMode(ACCESS_WRITE);
			if ($qr_result->get("inrap_ancien_code")) {
				$col->removeAttributes("inrap_ancien_code");
				$col->addAttribute(array("inrap_ancien_code" => $qr_result->get("inrap_ancien_code")), "inrap_ancien_code");
			}
			//TODO : inrap_op_rattachement
			if ($qr_result->get("AnneeDebutTerrain")) {
				$col->removeAttributes("inrap_annee_inter");
				$col->addAttribute(["inrap_annee_inter" => $qr_result->get("AnneeDebutTerrain")], "inrap_annee_inter");
			}

			if ($qr_result->get("NomOpeRattachement")){
				$col->removeAttributes("inrap_op_rattachement");
				$col->addAttribute([ "inrap_op_rattachement_txt" =>$qr_result->get("NomOpeRattachement")], "inrap_op_rattachement");
			}

			if ($qr_result->get("inrap_type_op.inrap_type_ope")) {
				$col->removeAttributes("inrap_type_op", ["force"=>true]);
				$col->addAttribute(array("inrap_type_ope" => $qr_result->get("inrap_type_op.inrap_type_ope"), "inrap_axe_analytique" => $qr_result->get("inrap_type_op.inrap_axe_analytique")), "inrap_type_op");
			}

			if ($qr_result->get("oa_number")) {
				$col->removeAttributes("oa_number");
				$col->addAttribute(array("oa_number" => $qr_result->get("oa_number")), "oa_number");
			}

			if ($qr_result->get("ro")) {
				$name_info = explode(',', $qr_result->get("ro_label"));
				$entity_id = getEntityIDByIdno($name_info[1], $name_info[0], $qr_result->get("ro"), 1665);
				$col->removeRelationships("ca_entities", 124);
				$col->addRelationship("ca_entities", $entity_id, 124);
			}

			if ($qr_result->get("centre_op")) {
				$col->removeAttributes("centre_op");
				$col->addAttribute(array("centre_op" => $qr_result->get("centre_op")), "centre_op");
			}

			if ($qr_result->get("commune")) {

				if (!$qr_result->get("CodeINSEE")) {
					$commune = new PlaceSearch();
					$result = $commune->search("ca_places:" . $qr_result->get("commune"));
					while ($result->nextHit()) {
						$name = $result->get("ca_places.preferred_labels.name");
						if (strtolower($name) == strtolower($qr_result->get("commune"))) {
							$col->removeRelationships("ca_places", 235);
							$col->addRelationship("ca_places", $result->get("ca_places.place_id"), 235);
							break;
						}
					}
				} else {
					$vt_place = new ca_places();
					$vt_place->load(["idno" => $qr_result->get("CodeINSEE"), "deleted" => 0]);
					if ($vt_place->getPrimaryKey()){
						$col->removeRelationships("ca_places", 235);
						$col->addRelationship("ca_places", $vt_place->getPrimaryKey(), 235);
						$col->update();
					}
					$col->removeAttributes("code_insee");
					$col->addAttribute(["code_insee" => $qr_result->get("CodeINSEE")], "code_insee");
				}
			}
			if ($qr_result->get("sra")) {
				$sra = new ca_entities();
				$sra->load(["idno" => $qr_result->get("sra"), "deleted" => 0]);
				$sraID = $sra->getPrimaryKey();
				$col->removeRelationships("ca_entities", 121);
				$col->addRelationship("ca_entities", $sraID, 121);
			}

			if ($qr_result->get("lieudit")) {
				$col->removeAttributes("lieudit");
				$col->addAttribute(array("lieudit" => $qr_result->get("lieudit")), "lieudit");
			}

			if ($qr_result->get("parcelle")) {
				$col->removeAttributes("parcelle");
				$col->addAttribute(array("parcelle" => $qr_result->get("parcelle")), "parcelle");
			}

			if ($qr_result->get("prescripteur")) {
				$name_info = explode(',', $qr_result->get("prescripteur"));
				// 04/09/2026 GM : le type d'entité manquait. getEntityID() le déclare obligatoire,
				// et les trois autres appels du fichier passent bien 1665 (personne). En PHP 7
				// l'oubli n'était qu'un avertissement ; depuis le passage en PHP 8.4 du 26/08,
				// c'est un ArgumentCountError fatal — l'import SGA mourait donc entièrement dès
				// qu'une opération portait un prescripteur, sans que la direction soit écrite.
				// Constaté deux fois le 01/09 à 09:07 au journal, sur /SGA/SGA/Compare/id/298606.
				$entity_id = getEntityID($name_info[1], $name_info[0], 1665);
				if ($entity_id != false){
					$col->removeRelationships("ca_entities", 122);
					$col->addRelationship("ca_entities", $entity_id, 122);
				}
				
			}

			if ($qr_result->get("numero_prescription")) {
				$col->removeAttributes("numero_prescription");
				$col->addAttribute(array("numero_prescription" => $qr_result->get("numero_prescription")), "numero_prescription");
			}

			if ($qr_result->get("date_simple")) {
				$col->removeAttributes("date_simple");
				$col->addAttribute(array("date_simple" => $qr_result->get("date_simple")), "date_simple");
			}

			if ($qr_result->get("surface_OA")) {
				$col->removeAttributes("surface_OA");
				$col->addAttribute(array("surface_OA" => floatval($qr_result->get("surface_OA"))), "surface_OA");
			}

			if ($qr_result->get("dir_inrap")) {
				$value = array("Centre Ile de France" => "DIR CIF", "Grand Ouest" => "DIR GO", "Grand Est" => "DIR GE", "Auvergne-Rhône-Alpes" => "DIR ARA", "Hauts-de-France" => "DIR HDF", "Midi-Méditerranée" => "DIR MIDIMED", "Outre-mer" => "DIR NAOM", "Nouvelle Aquitaine" => "DIR NAOM", "Bourgogne-Franche-Comté" => "DIR BFC");
				$entity = new EntitySearch();
				$result = $entity->search("ca_entities:\"" . $value[$qr_result->get("dir_inrap")]);
				while ($result->nextHit()) {
					$name = $result->get("ca_entities.preferred_labels.displayname");
					if ($name == $value[$qr_result->get("dir_inrap")]) {
						$col->removeRelationships("ca_entities", 236);
						$col->addRelationship("ca_entities", $result->get("ca_entities.entity_id"), 236);
						break;
					}
				}
			}

			if ($qr_result->get("dir_adj_st")) {
				$name_info = explode(',', $qr_result->get("dir_adj_st"));
				$entity_id = getEntityID($name_info[1], $name_info[0], 1665, null);
				$col->removeRelationships("ca_entities", 242);
				$col->addRelationship("ca_entities", $entity_id, 242);
			}

			if ($qr_result->get("datesdeterrain.Datedeterrain_date")) {
				$col->removeAttributes("datesdeterrain");
				$col->addAttribute(array("Datedeterrain_date" => $qr_result->get("datesdeterrain.Datedeterrain_date"), "datedeterrain_datefin" => $qr_result->get("datesdeterrain.datedeterrain_datefin")), "datesdeterrain");
			}

			if ($qr_result->get("autorisation_fouille") || $qr_result->get("autorisation_date") != 0) {
				$col->removeAttributes("autorisation_fouille");
				$col->addAttribute(array("autorisation_num" => $qr_result->get("autorisation_fouille"), "autorisation_date" => $qr_result->get("autorisation_date")), "autorisation_fouille");
			}

			if ($qr_result->get("inrap_date_planification")) {
				$col->removeAttributes("inrap_date_planification");
				$col->addAttribute(array("inrap_date_planification_date" => $qr_result->get("inrap_date_planification")), "inrap_date_planification");
			}

			if ($qr_result->get("date_prev_du_rapport") != 0) {
				$col->removeAttributes("date_prev_du_rapport");
				$col->addAttribute(array("date_prev_du_rapport" => $qr_result->get("date_prev_du_rapport")), "date_prev_du_rapport");
			}

			if ($qr_result->get("date_du_rapport") != 0) {
				$col->removeAttributes("date_du_rapport");
				$col->addAttribute(array("date_du_rapport" => $qr_result->get("date_du_rapport")), "date_du_rapport");
			}

			if ($col->numErrors()) {
				var_dump($col->getErrors());
				die();
			}

			$col->update();

			$label = $col->getWithTemplate("^ca_places.preferred_labels.name / ^ca_collections.lieudit / ^ca_collections.inrap_annee_inter%trim=1 / <unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.displayname</unit>");
			$col->removeAllLabels();
			$col->update();
			$col->addLabel(array('name' => $label), 2, null, true);
			$col->update();
		}
		$different = [];
		$result = $qr_result->getAllRows()[0];
		foreach ($result as $metadata => $data) {
			if ($metadata == "id" || $metadata == "ro_label" || empty($data)) {
				continue;
			}
			$data_to_compare = $col->getWithTemplate("^ca_collections." . $metadata);
			if ($metadata == "inrap_date_planification") {
				$data_to_compare = $col->getWithTemplate("^ca_collections.inrap_date_versement." . $metadata);
			}
			if ($metadata == "surface_OA" && (empty($data) && $data_to_compare == 0) && (empty($data_to_compare) && $data == 0)) {
				$data_to_compare = "";
				$data = "";
			}
			if ($metadata == "ro") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.idno</unit>");
			}
			if ($metadata == "sra") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='attribue' restrictToTypes='sra'>^ca_entities.idno</unit>");
			}
			if ($metadata == "dir_inrap") {
				$value = array_flip($value);
				$data_to_compare = $value[$col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='DIR'>^ca_entities.preferred_labels.displayname</unit>")];
			}
			if ($metadata == "prescripteur") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='suivi_par'>^ca_entities.preferred_labels.displayname</unit>");
			}
			if ($metadata == "autorisation_fouille") {
				$data_to_compare = $col->getWithTemplate("^ca_collections.autorisation_fouille.autorisation_num");
			}
			if ($metadata == "autorisation_date") {
				$data_to_compare = $col->getWithTemplate("^ca_collections.autorisation_fouille.autorisation_date");
			}

			if ($metadata == "dir_adj_st") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='dast'>^ca_entities.preferred_labels.displayname</unit>");
			}
			if ($metadata == "commune") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_places'>^ca_places.preferred_labels.name</unit>");
			}

			if ($data_to_compare == ";") {
				$data_to_compare = "";
			}
			if ($metadata == "CodeINSEE") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_places'>^ca_places.idno</unit>");
			}
			if ($metadata == "AnneeDebutTerrain"){
				$data_to_compare =  $col->getWithTemplate("^ca_collections.inrap_annee_inter");
			}
			if ($metadata == "NomOpeRattachement"){
				$data_to_compare =  $col->getWithTemplate("^ca_collections.inrap_op_rattachement.inrap_op_rattachement_txt");
			}
			if (str_replace(' ', '', strtolower($data)) != str_replace(' ', '', strtolower($data_to_compare))) {
				if ($metadata == "ro") {
					$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.displayname</unit>");
					$data = $result["ro_label"];
				}
				array_push($different, [$metadata, $data, $data_to_compare]);
			}
		}
		$opo_app_plugin_manager = new ApplicationPluginManager();
		$opo_app_plugin_manager->hookSaveItem(
			array(
				'id' => $col->getPrimaryKey(),
				'table_num' => $col->tableNum(),
				'table_name' => $col->tableName(),
				'instance' => $col
			)
		);
		$this->view->setVar("error", $different);
		$this->view->setVar("id", $col->getPrimaryKey());
		$this->render("import_html.php");
	}

	public function Update()
	{
		$op_id = $this->getRequest()->getParameter("id", pInteger);
		$col = new ca_collections($op_id);
		$o_data = new Db();
		$qr_result = $o_data->query("SELECT * FROM _sga_comodo WHERE idno = '" . $col->getWithTemplate("^ca_collections.idno") . "'");
		while ($qr_result->nextRow()) {
			$col->setMode(ACCESS_WRITE);
			if ($qr_result->get("inrap_ancien_code") && isset($_POST["inrap_ancien_code"])) {
				$col->removeAttributes("inrap_ancien_code");
				$col->addAttribute(array("inrap_ancien_code" => $qr_result->get("inrap_ancien_code")), "inrap_ancien_code");
			}
			//TODO : inrap_op_rattachement
			if ($qr_result->get("AnneeDebutTerrain") && isset($_POST["AnneeDebutTerrain"])) {
				$col->removeAttributes("inrap_annee_inter");
				$col->addAttribute(["inrap_annee_inter" => $qr_result->get("AnneeDebutTerrain")], "inrap_annee_inter");
			}
			if ($qr_result->get("NomOpeRattachement") && isset($_POST["inrap_op_rattachement"])){
				$col->removeAttributes("inrap_op_rattachement");
				$col->addAttribute([ "inrap_op_rattachement_txt" =>$qr_result->get("NomOpeRattachement")], "inrap_op_rattachement");
			}
			if ($qr_result->get("inrap_type_op.inrap_type_ope") && (isset($_POST["inrap_type_op_inrap_type_ope"]) && !isset($_POST["inrap_type_op_inrap_axe_analytique"]))) {
				$col->removeAttributes("inrap_type_op", ["force"=>true]);
				$col->addAttribute(array("inrap_type_ope" => $qr_result->get("inrap_type_op.inrap_type_ope")), "inrap_type_op");
			}
			if ($qr_result->get("inrap_type_op.inrap_type_ope") && (isset($_POST["inrap_type_op_inrap_type_ope"])||isset($_POST["inrap_type_op_inrap_axe_analytique"]))) {
				$col->removeAttributes("inrap_type_op", ["force"=>true]);
				$col->addAttribute(array("inrap_type_ope" => $qr_result->get("inrap_type_op.inrap_type_ope"), "inrap_axe_analytique" => $qr_result->get("inrap_type_op.inrap_axe_analytique")), "inrap_type_op");
			}
			if ($qr_result->get("inrap_type_op.inrap_type_ope") && (!isset($_POST["inrap_type_op_inrap_type_ope"])&&isset($_POST["inrap_type_op_inrap_axe_analytique"]))) {
				$col->removeAttributes("inrap_type_op", ["force"=>true]);
				$col->addAttribute(array("inrap_axe_analytique" => $qr_result->get("inrap_type_op.inrap_axe_analytique")), "inrap_type_op");
			}

			if ($qr_result->get("oa_number") && isset($_POST["oa_number"])) {
				$col->removeAttributes("oa_number");
				$col->addAttribute(array("oa_number" => $qr_result->get("oa_number")), "oa_number");
			}

			if ($qr_result->get("ro") && isset($_POST["ro"])) {
				$name_info = explode(',', $qr_result->get("ro_label"));
				$entity_id = getEntityIDByIdno($name_info[1], $name_info[0], $qr_result->get("ro"), 1665);
				$col->removeRelationships("ca_entities", 124);
				$col->addRelationship("ca_entities", $entity_id, 124);
			}

			if ($qr_result->get("commune") && isset($_POST["commune"])) {
				if (!$qr_result->get("CodeINSEE")) {
					$commune = new PlaceSearch();
					$result = $commune->search("ca_places:" . $qr_result->get("commune"));
					while ($result->nextHit()) {
						$name = $result->get("ca_places.preferred_labels.name");
						if (strtolower($name) == strtolower($qr_result->get("commune"))) {
							$col->removeRelationships("ca_places", 235);
							$col->addRelationship("ca_places", $result->get("ca_places.place_id"), 235);
							break;
						}
					}
				} else {
					$vt_place = new ca_places();
					$vt_place->load(["idno" => $qr_result->get("CodeINSEE"), "deleted" => 0]);

					if ($vt_place->getPrimaryKey()){
						$col->removeRelationships("ca_places", 235);
						$col->addRelationship("ca_places", $vt_place->getPrimaryKey(), 235);
						
					}
					$col->removeAttributes("code_insee");
					$col->addAttribute(["code_insee" => $qr_result->get("CodeINSEE")], "code_insee");
				}
			}
			if ($qr_result->get("sra") && isset($_POST["sra"])) {
				$sra = new ca_entities();
				$sra->load(["idno" => $qr_result->get("sra"), "deleted" => 0]);
				$sraID = $sra->getPrimaryKey();
				$col->removeRelationships("ca_entities", 121);
				$col->addRelationship("ca_entities", $sraID, 121);
			}

			if ($qr_result->get("lieudit") && isset($_POST["lieudit"])) {
				$col->removeAttributes("lieudit");
				$col->addAttribute(array("lieudit" => $qr_result->get("lieudit")), "lieudit");
			}

			if ($qr_result->get("parcelle") && isset($_POST["parcelle"])) {
				$col->removeAttributes("parcelle");
				$col->addAttribute(array("parcelle" => $qr_result->get("parcelle")), "parcelle");
			}

			if ($qr_result->get("prescripteur") && isset($_POST["prescripteur"])) {
				$name_info = explode(',', $qr_result->get("prescripteur"));
				$entity_id = getEntityID($name_info[1], $name_info[0], 1665, null);
			//	var_dump($entity_id);
			//	die();
				$col->removeRelationships("ca_entities", 122);
				$col->addRelationship("ca_entities", $entity_id, 122);
			}

			if ($qr_result->get("numero_prescription") && isset($_POST["numero_prescription"])) {
				$col->removeAttributes("numero_prescription");
				$col->addAttribute(array("numero_prescription" => $qr_result->get("numero_prescription")), "numero_prescription");
			}

			if ($qr_result->get("date_simple") && isset($_POST["date_simple"])) {
				$col->removeAttributes("date_simple");
				$col->addAttribute(array("date_simple" => $qr_result->get("date_simple")), "date_simple");
			}

			if ($qr_result->get("surface_OA") && isset($_POST["surface_OA"])) {
				$col->removeAttributes("surface_OA");
				$col->addAttribute(array("surface_OA" => floatval($qr_result->get("surface_OA"))), "surface_OA");
			}
			$value = array("Centre Ile de France" => "DIR CIF", "Grand Ouest" => "DIR GO", "Grand Est" => "DIR GE", "Auvergne-Rhône-Alpes" => "DIR ARA", "Hauts-de-France" => "DIR HDF", "Midi-Méditerranée" => "DIR MIDIMED", "Outre-mer" => "DIR NAOM", "Nouvelle Aquitaine" => "DIR NAOM", "Bourgogne-Franche-Comté" => "DIR BFC");
			if ($qr_result->get("dir_inrap") && isset($_POST["dir_inrap"])) {
				$entity = new EntitySearch();

				$vs_search = $value[$qr_result->get("dir_inrap")];

				$result = $entity->search($vs_search);
				
				while ($result->nextHit()) {
					$name = $result->get("ca_entities.preferred_labels.displayname");
					$idno = $result->get("ca_entities.idno");
					var_dump($name);
					var_dump($idno);
					if ($name == $value[$qr_result->get("dir_inrap")] || trim($idno) == trim($value[$qr_result->get("dir_inrap")])) {
						$col->removeRelationships("ca_entities", 236);
						$rel = $col->addRelationship("ca_entities", $result->get("ca_entities.entity_id"), 236 );
						
						$col->update();
						if ($col->numErrors()) {
							var_dump($col->getErrors());
							die();
						}
						break;
					}
				}
			}

			if ($qr_result->get("dir_adj_st")  && isset($_POST["dir_adj_st"])) {
				$name_info = explode(',', $qr_result->get("dir_adj_st"));
				$entity_id = getEntityID($name_info[1], $name_info[0], 1665, null);
				$col->removeRelationships("ca_entities", 242);
				$col->addRelationship("ca_entities", $entity_id, 242);
			}

			if ($qr_result->get("datesdeterrain.Datedeterrain_date") || isset($_POST["datesdeterrain_datedeterrain_datefin"])) {
				$col->removeAttributes("datesdeterrain");
				$col->addAttribute(array("Datedeterrain_date" => $qr_result->get("datesdeterrain.Datedeterrain_date"), "datedeterrain_datefin" => $qr_result->get("datesdeterrain.datedeterrain_datefin")), "datesdeterrain");
			}

			if (($qr_result->get("autorisation_fouille") || $qr_result->get("autorisation_date") != 0) && (isset($_POST["autorisation_fouille"]) || isset($_POST["autorisation_date"]))) {
				$col->removeAttributes("autorisation_fouille");
				$col->addAttribute(array("autorisation_num" => $qr_result->get("autorisation_fouille"), "autorisation_date" => $qr_result->get("autorisation_date")), "autorisation_fouille");
			}

			if ($qr_result->get("inrap_date_planification") && isset($_POST["inrap_date_planification"])) {
				$col->removeAttributes("inrap_date_planification");
				$col->addAttribute(array("inrap_date_planification_date" => $qr_result->get("inrap_date_planification")), "inrap_date_planification");
			}


			if ($qr_result->get("date_prev_du_rapport") != 0 && isset($_POST["date_prev_du_rapport"])) {
				$col->removeAttributes("date_prev_du_rapport");
				$col->addAttribute(array("date_prev_du_rapport" => $qr_result->get("date_prev_du_rapport")), "date_prev_du_rapport");
			}

			if ($qr_result->get("date_du_rapport") != 0 && isset($_POST["date_du_rapport"])) {
				$col->removeAttributes("date_du_rapport");
				$col->addAttribute(array("date_du_rapport" => $qr_result->get("date_du_rapport")), "date_du_rapport");
			}

			if ($qr_result->get("centre_op") && isset($_POST["centre_op"])) {
				$col->removeAttributes("centre_op");
				$col->addAttribute(array("centre_op" => $qr_result->get("centre_op")), "centre_op");
			}

			if ($col->numErrors()) {
				var_dump($col->getErrors());
				die();
			}

			$col->update();
			if ($col->numErrors()) {
				var_dump($col->getErrors());
				die();
			}

			$label = $col->getWithTemplate("^ca_places.preferred_labels.name / ^ca_collections.lieudit / ^ca_collections.inrap_annee_inter%trim=1 / <unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.displayname</unit>");
			$col->removeAllLabels();
			$col->update();
			$col->addLabel(array('name' => $label), 2, null, true);
			$col->update();
		}
		$different = [];
		$result = $qr_result->getAllRows()[0];
		foreach ($result as $metadata => $data) {
			if ($metadata == "id" || $metadata == "ro_label" || empty($data)) {
				continue;
			}
			$data_to_compare = $col->getWithTemplate("^ca_collections." . $metadata);
			if ($metadata == "inrap_date_planification") {
				$data_to_compare = $col->getWithTemplate("^ca_collections.inrap_date_versement." . $metadata);
			}
			if ($metadata == "surface_OA" && (empty($data) && $data_to_compare == 0) && (empty($data_to_compare) && $data == 0)) {
				$data_to_compare = "";
				$data = "";
			}
			if ($metadata == "ro") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.idno</unit>");
			}
			if ($metadata == "sra") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='attribue' restrictToTypes='sra'>^ca_entities.idno</unit>");
			}
			if ($metadata == "dir_inrap") {
				$value = array_flip($value);
				$data_to_compare = $value[$col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='DIR'>^ca_entities.preferred_labels.displayname</unit>")];
			}
			if ($metadata == "prescripteur") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='suivi_par'>^ca_entities.preferred_labels.displayname</unit>");
			}
			if ($metadata == "autorisation_fouille") {
				$data_to_compare = $col->getWithTemplate("^ca_collections.autorisation_fouille.autorisation_num");
			}
			if ($metadata == "autorisation_date") {
				$data_to_compare = $col->getWithTemplate("^ca_collections.autorisation_fouille.autorisation_date");
			}

			if ($metadata == "dir_adj_st") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='dast'>^ca_entities.preferred_labels.displayname</unit>");
			}
			if ($metadata == "commune") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_places'>^ca_places.preferred_labels.name</unit>");
			}
			if ($data_to_compare == ";") {
				$data_to_compare = "";
			}

			if ($metadata == "CodeINSEE") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_places'>^ca_places.idno</unit>");
			}
			if ($metadata == "AnneeDebutTerrain"){
				$data_to_compare =  $col->getWithTemplate("^ca_collections.inrap_annee_inter");
			}
			if ($metadata == "NomOpeRattachement"){
				$data_to_compare =  $col->getWithTemplate("^ca_collections.inrap_op_rattachement.inrap_op_rattachement_txt");
			}
			if (str_replace(' ', '', strtolower($data)) != str_replace(' ', '', strtolower($data_to_compare))) {
				if ($metadata == "ro") {
					$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.displayname</unit>");
					$data = $result["ro_label"];
				}

				array_push($different, [$metadata, $data, $data_to_compare]);
			}
		}

		$opo_app_plugin_manager = new ApplicationPluginManager();
		$opo_app_plugin_manager->hookSaveItem(
			array(
				'id' => $col->getPrimaryKey(),
				'table_num' => $col->tableNum(),
				'table_name' => $col->tableName(),
				'instance' => $col
			)
		);
		$this->view->setVar("error", $different);
		$this->view->setVar("id", $col->getPrimaryKey());
		$this->render("update_html.php");
	}
}
