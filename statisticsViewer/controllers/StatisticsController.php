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

require_once(__CA_LIB_DIR__ . '/TaskQueue.php');
require_once(__CA_LIB_DIR__ . '/Configuration.php');
require_once(__CA_MODELS_DIR__ . '/ca_lists.php');
require_once(__CA_MODELS_DIR__ . '/ca_objects.php');
require_once(__CA_MODELS_DIR__ . '/ca_object_representations.php');
require_once(__CA_MODELS_DIR__ . '/ca_locales.php');
require_once(__CA_APP_DIR__ . '/plugins/statisticsViewer/lib/statisticsSQLHandler.php');
require_once(__CA_LIB_DIR__ . '/Browse/ObjectBrowse.php');


class StatisticsController extends ActionController
{
	# -------------------------------------------------------
	protected $opo_config;		// plugin configuration file
	protected $opa_dir_list;	// list of available import directories
	protected $opa_regexes;		// list of available regular expression packages for extracting object idno's from filenames
	protected $opa_regex_patterns;
	protected $opa_locales;
	protected $opa_statistics_xml_files;
	protected $opa_statistics;
	protected $opa_stat;
	protected $opa_id;
	protected $pa_parameters;
	protected $allowed_universes;


	# -------------------------------------------------------
	# Constructor
	# -------------------------------------------------------

	public function __construct(&$po_request, &$po_response, $pa_view_paths = null)
	{
		global $allowed_universes;

		parent::__construct($po_request, $po_response, $pa_view_paths);

		/*if (!$this->request->user->canDoAction('can_use_statistics_viewer_plugin')) {
 				$this->response->setRedirect($this->request->config->get('error_display_url').'/n/3000?r='.urlencode($this->request->getFullUrlPath()));
 				return;
 			}*/

		$this->opo_config = Configuration::load(__CA_APP_DIR__ . '/plugins/statisticsViewer/conf/statisticsViewer.conf');
	}

	# -------------------------------------------------------
	# Local functions
	# -------------------------------------------------------


	# -------------------------------------------------------
	# Functions to render views
	# -------------------------------------------------------

	public function BilanEtatAnnuel()
	{

		$o_data = new Db();

		$query = "SELECT location_id FROM ca_storage_locations where parent_id = 1 and deleted = 0";
		$qr_res = $o_data->query($query);

		$result = array();
		$total = 0;

		while ($qr_res->nextRow()) {
			$objectBrowse = new ObjectBrowse();
			$objectBrowse->addCriteria("storage_location_facet", $qr_res->get('location_id'));
			$objectBrowse->execute();
			$res = $objectBrowse->getResults();

			$result[$qr_res->get('location_id')]["Total"] = 0;
			$result[$qr_res->get('location_id')]["Vu"] = 0;
			$result[$qr_res->get('location_id')]["Non vu"] = 0;
			$result[$qr_res->get('location_id')]["Retour Temporaire"] = 0;
			$result[$qr_res->get('location_id')]["Non récolé"] = 0;

			while ($res->nextHit()) {

				$query2 = "SELECT relation_id, location_id FROM ca_objects_x_storage_locations WHERE object_id = " . $res->get('object_id');
				$relation_res = $o_data->query($query2);
				while ($relation_res->nextRow()) {
					$vt_rel = new ca_objects_x_storage_locations($relation_res->get('relation_id'));
					$vt_loc = new ca_storage_locations($relation_res->get('location_id'));
					$hierarchy = $vt_loc->getHierarchyAncestors();
					$root = end($hierarchy);

					if ($root["NODE"]["location_id"] != $qr_res->get("location_id") && $qr_res->get("location_id") != $relation_res->get("location_id")) {
						continue;
					}
					if ($vt_rel->getWithTemplate('^ca_objects_x_storage_locations.Statut_de_la_localisation') == "Actuelle") {
						$result[$qr_res->get('location_id')]["Total"]++;
						$stat = explode(";", $vt_rel->getWithTemplate("^ca_objects_x_storage_locations.recolement.recol_liste"))[0];
						if ($stat == "Vu") {
							$result[$qr_res->get('location_id')]["Vu"]++;
						} else if ($stat == "Non vu") {
							$result[$qr_res->get('location_id')]["Non vu"]++;
						} else if ($stat == "Retour Temporaire") {
							$result[$qr_res->get('location_id')]["Retour Temporaire"]++;
						} else if ($stat == "Non récolé") {
							$result[$qr_res->get('location_id')]["Non récolé"]++;
						}
					}
				}
			}
			$total += $result[$qr_res->get('location_id')]["Total"];
		}
		$this->view->setVar("Total", $total);
		$this->view->setVar("Result", $result);
		$this->render("bilan_etat_annuel_html.php");
	}

	public function RepartitionEnsembleStatut()
	{
		$o_data = new Db();

		$query = "SELECT location_id FROM ca_storage_locations where parent_id = 1 and deleted = 0";
		$qr_res = $o_data->query($query);

		$result = array();
		$total = 0;

		while ($qr_res->nextRow()) {
			$objectBrowse = new ObjectBrowse();
			$objectBrowse->addCriteria("storage_location_facet", $qr_res->get('location_id'));
			$objectBrowse->execute();
			$res = $objectBrowse->getResults();

			$result[$qr_res->get('location_id')]["Total"] = 0;
			$result[$qr_res->get('location_id')]["Actuelle"] = 0;
			$result[$qr_res->get('location_id')]["Terminé"] = 0;

			while ($res->nextHit()) {
				$query2 = "SELECT relation_id, location_id FROM ca_objects_x_storage_locations WHERE object_id = " . $res->get('object_id');
				$relation_res = $o_data->query($query2);
				while ($relation_res->nextRow()) {
					$vt_rel = new ca_objects_x_storage_locations($relation_res->get('relation_id'));
					$vt_loc = new ca_storage_locations($relation_res->get('location_id'));
					$hierarchy = $vt_loc->getHierarchyAncestors();
					$root = end($hierarchy);
					if ($root["NODE"]["location_id"] != $qr_res->get("location_id") && $qr_res->get("location_id") != $relation_res->get("location_id")) {
						continue;
					}
					if ($vt_rel->getWithTemplate('^ca_objects_x_storage_locations.Statut_de_la_localisation') == "Actuelle") {
						$result[$qr_res->get('location_id')]["Actuelle"]++;
					} else if ($vt_rel->getWithTemplate('^ca_objects_x_storage_locations.Statut_de_la_localisation') == "Terminée") {
						$result[$qr_res->get('location_id')]["Terminé"]++;
					}
					$result[$qr_res->get('location_id')]["Total"]++;
				}
			}
			$total += $result[$qr_res->get('location_id')]["Total"];
		}

		$this->view->setVar("Total", $total);
		$this->view->setVar("Result", $result);
		$this->render("repartition_ensemble_statut_html.php");
	}

	public function RepartitionTypeCategorie()
	{
		$o_data = new Db();

		$query = "SELECT clil.name_singular, count(*) as total, GROUP_CONCAT(object_id) as ids
		FROM ca_attribute_values cav 
		LEFT JOIN ca_attributes ca on ca.attribute_id = cav.attribute_id
		LEFT JOIN ca_objects co on co.object_id = ca.row_id
		LEFT JOIN ca_list_item_labels clil on clil.item_id = cav.item_id
		WHERE cav.element_id = 71
		and co.deleted = 0 
		and ca.table_num = 57
		GROUP BY (cav.item_id);";

		$qr_res = $o_data->query($query);
		$result = array();
		$total = 0;
		while ($qr_res->nextRow()) {
			$ids = explode(",", $qr_res->get("ids"));
			$typo = $qr_res->get("name_singular");
			if (empty($typo)){
				$typo = "Non renseigné";
			}
			$result[$typo]["Total"] = $qr_res->get("total");
			$total += $qr_res->get("total");
			foreach ($ids as $id) {
				$vt_obj = new ca_objects($id);
				$vt_cat = $vt_obj->getWithTemplate("^ca_objects.denomination_meta");
				if ($vt_cat == "") {
					$vt_cat = "Non renseigné";
				}
				if (!$result[$typo][$vt_cat]) {
					$result[$typo][$vt_cat] = 1;
				} else {
					$result[$typo][$vt_cat]++;
				}
			}
		}

		$this->view->setVar("Total", $total);
		$this->view->setVar("Result", $result);

		$this->render("repartition_type_categorie_html.php");
	}

	public function RepartitionEmplacementType()
	{
		$o_data = new Db();

		$query = "SELECT csl.location_id, csll.`name`
		FROM ca_storage_locations csl
		LEFT JOIN ca_storage_location_labels csll on csl.location_id = csll.location_id
		where deleted = 0 
		and parent_id = 38999";

		$qr_res = $o_data->query($query);
		$result = array();
		$total = 0;
		while ($qr_res->nextRow()) {
			$objectBrowse = new ObjectBrowse();
			$objectBrowse->addCriteria("storage_location_facet", $qr_res->get('location_id'));
			$objectBrowse->execute();
			$res = $objectBrowse->getResults();
			while ($res->nextHit()) {
				$categorie = $res->getWithTemplate("^ca_objects.domaine_meta");
				if (!$categorie){
					continue;
				}
				$result[$qr_res->get("name")]["Total"]++;

				if (!$result[$qr_res->get("name")][$categorie]){
					$result[$qr_res->get("name")][$categorie] = 1;
				}else{
					$result[$qr_res->get("name")][$categorie]++;
				}

			}

			
		}

		$this->view->setVar("Total", $total);
		$this->view->setVar("Result", $result);

		$this->render("repartition_emplacement_type_html.php");
	}

	public function NbContributions() {
		$o_data = new Db();
		$query = 'select ca_users.user_id, concat(user_name, " <small>(", fname, " ", lname,")</small>") as user_name_display, ca_objects.object_id, ca_objects.idno, ca_objects.deleted, ca_objects.type_id, ca_objects.status
		from ca_users 
		left join ca_change_log on ca_change_log.user_id=ca_users.user_id and ca_change_log.logged_table_num=57 
		left join ca_objects on ca_objects.object_id=ca_change_log.logged_row_id 
		where userclass=1 
		and object_id is not null 
		group by ca_change_log.logged_row_id';
		$vt_results = $o_data->query($query);
		$rows = $vt_results->getAllRows();
		$this->view->setVar("rows", $rows);
		$this->render("ensad/nb_contributions_html.php");
	}

	public function NbFichesCataloguees() {
		$o_data = new Db();
		$query = 'select ca_users.user_id, concat(user_name, " <small>(", fname, " ", lname,")</small>") as user_name_display, ca_objects.object_id, ca_objects.idno, ca_objects.deleted, ca_objects.type_id, ca_objects.status
		from ca_users 
		left join ca_change_log on ca_change_log.user_id=ca_users.user_id and ca_change_log.logged_table_num=57 
		left join ca_objects on ca_objects.object_id=ca_change_log.logged_row_id 
		where userclass=0 
		and object_id is not null 
		group by ca_change_log.logged_row_id';
		$vt_results = $o_data->query($query);
		$rows = $vt_results->getAllRows();
		$this->view->setVar("rows", $rows);
		$this->render("ensad/nb_fiches_cataloguees_html.php");
	}

	public function ListeContributions() {
		$user_id = $this->request->getParameter("user_id", pInteger);
		$o_data = new Db();
		$query = 'select ca.user_id, concat(user_name, " <small>(", fname, " ", lname,")</small>") as user_name_display, co.object_id, col.`name` as objectLabel, co.idno, clil.name_singular , co.status
		from ca_users ca
		left join ca_change_log on ca_change_log.user_id=ca.user_id and ca_change_log.logged_table_num=57 
		left join ca_objects co on co.object_id=ca_change_log.logged_row_id
		left join ca_object_labels col on co.object_id = col.object_id
		left join ca_list_item_labels clil on co.type_id = clil.item_id
		where userclass=1 
		and co.object_id is not null 
		and ca.user_id = '.$user_id.'
		and deleted = 0
		group by ca_change_log.logged_row_id';
		$vt_results = $o_data->query($query);
		$rows = $vt_results->getAllRows();
		$this->view->setVar("rows", $rows);
		$this->view->setVar("user_id", $user_id);
		$this->render("ensad/liste_contributions_html.php");
	}
	# ------------------------------------------------------- 				
}
