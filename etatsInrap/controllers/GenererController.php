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
 
	define("__INRAP_TYPE_ID_OP__", 125);

 	// === D2026-0103 §4 — outillage commun d'édition documentaire : début ===
	// Échappement XML et nommage des documents produits — voir lib/inrap0103_documents.php.
	require_once(__CA_APP_DIR__.'/plugins/etatsInrap/lib/inrap0103_documents.php');
	// === D2026-0103 §4 : fin ===
	require_once(__CA_LIB_DIR__.'/TaskQueue.php');
 	require_once(__CA_LIB_DIR__.'/Configuration.php');
	require_once(__CA_LIB_DIR__.'/Search/CollectionSearch.php');
	require_once(__CA_LIB_DIR__.'/Browse/CollectionBrowse.php');
	require_once(__CA_LIB_DIR__.'/Search/ObjectSearch.php');
	require_once(__CA_LIB_DIR__.'/Browse/ObjectBrowse.php');
 	require_once(__CA_MODELS_DIR__.'/ca_lists.php');
 	require_once(__CA_MODELS_DIR__.'/ca_objects.php');
 	require_once(__CA_MODELS_DIR__.'/ca_object_representations.php');
 	require_once(__CA_MODELS_DIR__.'/ca_locales.php');

	require_once(__CA_LIB_DIR__.'/ResultContext.php');

 	error_reporting(E_ERROR);

 	class GenererController extends ActionController {

	/**
	 * Remonte la hiérarchie des emplacements jusqu'au centre de recherche (type
	 * « Centre_de_recherche », item 133), en partant d'un emplacement quelconque.
	 *
	 * Posé le 09/09/2026 (ticket 7999) : les coordonnées du gestionnaire sont portées par le
	 * centre, alors que la relation « entrée de collection » d'une opération pointe souvent sur
	 * un local situé sous ce centre. Sans cette remontée, la lettre de versement sort sans nom
	 * ni téléphone. Sur 10 197 emplacements vivants, 58 seulement portent un gestionnaire.
	 *
	 * Rend l'emplacement de départ si aucun centre n'est trouvé — le comportement est alors
	 * celui d'avant le correctif, jamais pire.
	 */
	private static function remonterAuCentre($emplacement) {
		if (!is_object($emplacement) || !$emplacement->getPrimaryKey()) { return $emplacement; }
		$type_centre = 133;
		$courant = $emplacement;
		$vus = array();				// garde-fou : une hiérarchie circulaire ne doit pas boucler
		while (is_object($courant) && $courant->getPrimaryKey()) {
			$id = (int)$courant->getPrimaryKey();
			if (isset($vus[$id])) { break; }
			$vus[$id] = true;
			if ((int)$courant->get('type_id') === $type_centre) { return $courant; }
			$parent = (int)$courant->get('parent_id');
			if (!$parent) { break; }
			$courant = new ca_storage_locations($parent);
		}
		return $emplacement;
	}
 		# -------------------------------------------------------
  		protected $opo_config,		// plugin configuration file
        $ops_plugin_name, $ops_plugin_path,
		$ops_user_groups, $opo_result_context;


 		# -------------------------------------------------------
 		# Constructor
 		# -------------------------------------------------------

 		public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
 			global $allowed_universes;
 			
 			parent::__construct($po_request, $po_response, $pa_view_paths);

 			$this->ops_plugin_name = "etatsInrap";
 			$this->ops_plugin_path = __CA_APP_DIR__."/plugins/".$this->ops_plugin_name;

 			$vs_conf_file = $this->ops_plugin_path."/conf/".$this->ops_plugin_name.".conf";
 			if(is_file($vs_conf_file)) {
                $this->opo_config = Configuration::load($vs_conf_file);
            }

			$va_groups = $this->getRequest()->getUser()->getUserGroups();
			$this->ops_user_groups = [];
 			foreach($va_groups as $group) {
 				if(in_array($group["code"], ["gestion","admin"])) continue;
				$this->ops_user_groups[] =$group["code"];
			}

 		}

		# -------------------------------------------------------
		# Functions to render widgets
		# -------------------------------------------------------

		public function SearchCollectionsWidget($pa_parameters) {
 			$formName = $this->request->getParameter("_formName", pString);
			$search = $this->request->getParameter("search", pString);
			$type_id = $this->request->getParameter("type_id", pInteger);

			$this->view->setVar('mode_name', _t('search'));
			$this->view->setVar('mode_type_singular', "collection");
			$this->view->setVar('mode_type_plural', "collections");
			$this->view->setVar('table_name', "ca_collections");
			$this->view->setVar('find_type', "basic_search");

			//var_dump($_POST);die();
/*
			array(5) {
				["_formName"]=>
  string(15) "BasicSearchForm"
				["form_timestamp"]=>
  string(10) "1552317153"
				["crsfToken"]=>
  string(64) "e6b05c4296986ceac47540da842f2a8eef474d5546b9720ab7414497e6501a1a"
				["search"]=>
  string(1) "*"
				["type_id"]=>
  string(3) "125"
}
*/
			$this->opo_result_context = new ResultContext($this->getRequest(), "ca_collections", "basic_search");
			$this->opo_result_context->setAsLastFind();
			$this->view->setVar('t_subject', new ca_collections());

			$this->view->setVar('search_history', $this->opo_result_context->getSearchHistory());
			$this->view->setVar('result_context', $this->opo_result_context);
			$va_results_id_list = $this->opo_result_context->getResultList();
			$this->view->setVar('result', (is_array($va_results_id_list) && sizeof($va_results_id_list) > 0) ? caMakeSearchResult("ca_collections", $va_results_id_list) : null);

			$vs_result = $this->render($this->ops_plugin_path.'/views/search_collections_widget_html.php');
			return $vs_result;
		}


		# -------------------------------------------------------
		# Functions to render views
		# -------------------------------------------------------
		public function ListeModele1($type="") {
 			$opo_result = $this->getRequest()->getParameter("opo_result", pString);
 			$this->view->setVar('opo_result', $opo_result);
			$this->render('liste_modele1_html.php');
		}

		public function Index($type="") {
			
			$req = "select distinct(departement.idno) as id from ca_places as communes left join ca_places as departement on communes.parent_id=departement.place_id where communes.type_id=106 and departement.type_id=104";
			$o_data = new Db();
			$qr_result = $o_data->query($req);
 
			$dep_values = [];
			while($qr_result->nextRow()) {
			  $dep_values[$qr_result->get("id")] = $qr_result->get("id");
			}
			$this->view->setvar("deplist", $dep_values);
			$this->render('index_html.php');
		}

 		public function Modele1() {
			// ID métadonnées : Type d'opération > Type d'opération (inrap_type_ope) = 487
			// Valeur : Diagnostic = 1443
			$vs_etat= $this->getRequest()->getParameter("etat", pString);
			$dir = $this->opo_request->getParameter("dir", pString);
			$this->view->setVar("dir", $dir);
			
			$region = $this->opo_request->getParameter("region", pString);
			$old_region = $this->opo_request->getParameter("old_region", pString);
			$departement = $this->opo_request->getParameter("dep", pString);
			if($departement) {
				$place_id=$departement;
			}
			else {
				if($old_region){
					$place_id = $old_region;
				}
				else{
					if ($region){
						$place_id = $region;
					}else{
						$place_id = 0;

					}
				}
			}
			
			$headers = [];
			switch($vs_etat) {
				case "diagnostics_en_cours_d_etude":
					$titre = "Diagnostics en cours";
					$type_ope_cible = 1443;
					$statut_coll_cible = 1553;
					break;
				case "diagnostics_en_attente_de_versement":
					$titre = "Diagnostics en attente de versement";
					$type_ope_cible = 1443;
					$statut_coll_cible = 1554;
					break;
				case "diagnostics_en_garde_pour_inrap":
					$titre = "Diagnostics en garde pour l'Inrap";
					$type_ope_cible = 1443;
					$statut_coll_cible = 1555;
					break;
				case "diagnostics_en_garde_pour_etat":
					$titre = "Diagnostics en garde pour l'Etat  ";
					$type_ope_cible = 1443;
					$statut_coll_cible = 1634;
					break;
				case "diagnostics_non_defini":
					$titre = "Diagnostics Non défini  ";
					$type_ope_cible = 1443;
					$statut_coll_cible = "Non défini";
					break;
				case "fouilles_en_cours_d_etude":
					$titre = "Fouilles en cours";
					$type_ope_cible = 1444;
					$statut_coll_cible = 1553;
					break;
				case "fouilles_en_attente_de_versement":
					$titre = "Fouilles en attente de versement";
					$type_ope_cible = 1444;
					$statut_coll_cible = 1554;
					break;
				case "fouilles_en_garde_pour_inrap":
					$titre = "Ensemble des fouilles en garde pour Inrap";
					$type_ope_cible = 1444;
					$statut_coll_cible = 1555;
					break;
				case "fouilles_en_garde_pour_etat":
					$titre = "Ensemble des fouilles en garde pour Etat  ";
					$type_ope_cible = 1444;
					$statut_coll_cible = 1634;
					break;
				case "fouilles_non_defini":
					$titre = "Ensemble des fouilles non défini";
					$type_ope_cible = 1444;
					$statut_coll_cible = "Non défini";
					break;
				case "fouilles_programmees_en_cours_d_etude":
					$titre = "Ensemble des fouilles programmées en cours d'étude";
					$type_ope_cible = 1650;
					$statut_coll_cible = 1553;
					break;
				case "fouilles_programmees_en_attente_de_versement":
					$titre = "Ensemble des fouilles programmées en attente de versement";
					$type_ope_cible = 1650;
					$statut_coll_cible = 1554;
					break;
				case "fouilles_programmees_en_garde_pour_inrap":
					$titre = "Ensemble des fouilles programmées en garde pour Inrap";
					$type_ope_cible = 1650;
					$statut_coll_cible = 1555;
					break;
				case "fouilles_programmees_en_garde_pour_etat":
					$titre = "Ensemble des fouilles programmées en garde pour Etat  ";
					$type_ope_cible = 1650;
					$statut_coll_cible = 1634;
					break;
				case "fouilles_programmees_non_defini":
					$titre = "Ensemble des fouilles programmées non défini";
					$type_ope_cible = 1650;
					$statut_coll_cible = "Non défini";
					break;
				case "operations_en_cours_d_etude":
					$titre = "Ensemble des opérations en cours d'étude  ";
					$type_ope_cible = 0;
					$statut_coll_cible = 1553;
					break;
				case "operations_en_attente_de_versement":
					$titre = "Ensemble des opérations en attente de versement";
					$type_ope_cible = 0;
					$statut_coll_cible = 1554;
					break;
				case "operations_en_garde_pour_inrap":
					$titre = "Ensemble des opérations en garde pour Inrap  ";
					$type_ope_cible = 0;
					$statut_coll_cible = 1555;
					break;
				case "operations_en_garde_pour_etat":
					$titre = "Ensemble des opérations en garde pour Etat  ";
					$type_ope_cible = 0;
					$statut_coll_cible = 1634;
					break;
				case "operations_non_defini":
					$titre = "Ensemble des opérations non défini";
					$type_ope_cible = 0;
					$statut_coll_cible = "Non défini";
					break;
				case "operations_versees":
					$titre = "Ensemble des opérations versées";
					$type_ope_cible = 0;
					$statut_coll_cible = 1556;
					break;
			}
			$this->view->setVar('etat', $vs_etat);

			//Création du tableau de résultat
			$va_results = [];

			// Récupération des données
			$o_data = new Db();
			$and = "";
			$vs_query = "select collections.collection_id, collections.idno, cav1.element_id, cav1.item_id as item1, cav2.element_id, cav2.item_id as item2
				  from ca_collections collections
				  left join ca_collections parent on parent.collection_id = collections.parent_id
				  left join ca_attributes ca1 on ca1.row_id=collections.collection_id and ca1.element_id=486 
				  left join ca_attribute_values cav1 on cav1.attribute_id=ca1.attribute_id 
				  left join ca_attributes ca2 on ca2.row_id=collections.collection_id and ca2.element_id=449 
				  left join ca_attribute_values cav2 on cav2.attribute_id=ca2.attribute_id 
				  where collections.type_id=".__INRAP_TYPE_ID_OP__." and collections.deleted=0 and cav1.item_id is not null and cav1.element_id=487 
				  and cav2.item_id is not null and cav2.element_id=449
				  and cav1.item_id = ".$type_ope_cible." and cav2.item_id=".$statut_coll_cible;

			//"select collection_id from ca_collections where type_id=".__INRAP_TYPE_ID_OP__." and deleted=0";
			//$qr_result = $o_data->query($vs_query);
			$headers = ["DIR", "Lieu d'entrée de la collection", "Volume de la collection (m3)", "Statut collection", "Code OA", "Type opération", "Code INRAP", "Région", "Numéro dépt.", "Commune", "Lieudit", "RO", "Fin terrain", "Prév. remise du rapport", "Remise du rapport"];
			$va_results[0] = $headers;
			$i=0;
			$vo_coll_browse = new CollectionBrowse();
			if(isset($place_id) && $place_id>0) {
				$vo_coll_browse->addCriteria("lieu_facet", $place_id); // Puy-de-Dôme
			} else {
				if ($dir != "France"){
					$vo_coll_browse->addCriteria("dir_facet", $dir); // GO
				}
			}

			if($type_ope_cible) {
				$vo_coll_browse->addCriteria("type_operation_facet", $type_ope_cible); // Fouilles	
			}
			if ($statut_coll_cible != "Non défini") {
				$vo_coll_browse->addCriteria("statut_operation_facet", $statut_coll_cible); // Remis à l'état	
			} else {
				$vo_coll_browse->addCriteria("is_undefined_facet", "Oui");
			}
			
			$vo_coll_browse->execute();
			$qr_results = $vo_coll_browse->getResults();
			$num_results = $vo_coll_browse->numResults();
		
			//var_dump($num_results);
			//die();
			$this->view->setVar("num_results", $num_results);


			$i = 1;
			while($qr_results->nextHit()) {
				$i++;

				// Récupération des valeurs difficiles d'accès
				$dept = $qr_results->getWithTemplate("^ca_places.hierarchy.idno%maxLevelsFromBottom=3&delimiter=;");
				// ... on a ici 63;BEAUMONT ne garder que la partie avant le ;
				$dept = reset(explode(";", $dept));
				$dept=str_replace("dep","",$dept);
				if(strlen($dept)==1) {
					$dept = "0".$dept;
				}

				//Filtrage par DIR
				$DIR = $qr_results->getWithTemplate("<unit relativeTo='ca_entities' restrictToTypes='DIR'>^ca_entities.preferred_labels.displayname</unit>");
				/*if(!in_array($DIR, $this->ops_user_groups)) {
					continue;
					//die("Argh, dying dying");
				}/

				/*
				*	 Récupération des valeurs dans le tableau
				*/
				$date1 = $qr_results->get("ca_collections.datesdeterrain.Datedeterrain_date.start", ["getDirectDate"=>1]);
				if($date1) {
					$date1 = substr($date1,7,2)."/".substr($date1,5,2)."/".substr($date1,0,4);
				}


				$va_results[$i] = [
					$DIR,
					$qr_results->getWithTemplate("<unit relativeTo='ca_storage_locations' restrictToRelationshipTypes='entree_collection'>^ca_storage_locations.preferred_labels</unit>"), //Lieu entrée
					round((float)$qr_results->get("ca_collections.inrap_volume_total"),4),// Volume
					$qr_results->getWithTemplate("^ca_collections.statut_collection"), // On a déjà la valeur, pas la peine de l'extraire
					$qr_results->get("ca_collections.oa_number"),
					$qr_results->getWithTemplate("^ca_collections.inrap_type_op.inrap_type_ope"), // on a déjà la valeur, pas la peine de l'extraire
					$qr_results->getWithTemplate("<a href='".__CA_URL_ROOT__."/index.php/editor/collections/CollectionEditor/Edit/collection_id/^ca_collections.collection_id' target='_blank'>^ca_collections.idno</a>"),
					$qr_results->getWithTemplate("^ca_places.hierarchy.preferred_labels.name%maxLevelsFromTop=2&delimiter=_➜_"),
					//$qr_results->getWithTemplate("^ca_places.hierarchy.preferred_labels.name%maxLevelsFromTop=3&delimiter=_➜_"),
					$dept,
					$qr_results->getWithTemplate("^ca_places.preferred_labels"),
					$qr_results->get("ca_collections.lieudit"),
					$qr_results->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.displayname</unit>"), //RO
					$date1,
					$qr_results->get("ca_collections.date_prev_du_rapport%format=DD/MM/YYYY"),
					$qr_results->get("ca_collections.date_du_rapport")
				];
					

			}

			$this->view->setVar('results', $va_results);
			$this->view->setVar('titre', $titre);

			$this->render('modele1_html.php');
		}

		/**
		 * 7963 (point 14) — cote en CENTIMÈTRES à partir du décimal normalisé par
		 * CollectiveAccess, sans zéros de queue.
		 *
		 * CollectiveAccess range TOUTE longueur en MÈTRES dans value_decimal1, quelle
		 * que soit l'unité saisie (vérifié en base le 11/09/2026 : « 600 mm » → 0.60).
		 * ×100 → cm. Définition UNIQUE de la conversion : le bordereau (Modele4) s'y
		 * garde sa propre copie (decision GM 11/09/2026 : zero effet de bord).
		 */
		private function cmDepuisMetres_0103($pm_m) {
			return rtrim(rtrim(number_format((float)$pm_m * 100, 2, '.', ''), '0'), '.');
		}
		/**
		 * 7963 (point 14) — colonne « Dimensions » en centimètres.
		 *
		 * Le gabarit ^ca_objects.dimensions.dimensions_* restituait la valeur TELLE
		 * QUE SAISIE, c'est-à-dire en millimètres (constaté le 11/09/2026 : les
		 * valeurs les plus fréquentes du bloc historique `dimensions` sont
		 * « 600 mm », « 400 mm », « 300 mm »…), et sortait donc
		 * « H. 280 mm x L. 432 mm x P. 525 mm ». Le client lit des centimètres.
		 *
		 * Même mécanique que le bordereau : la cote est reprise en mètres puis
		 * convertie par cmDepuisMetres_0103(). Le décimal est obtenu par l'option
		 * `returnAsDecimalMetric` de LengthAttributeValue — procédé du tronc
		 * CollectiveAccess lui-même (app/helpers/displayHelpers.php, datatype 8),
		 * jamais un décodage maison du texte « 600 mm ».
		 *
		 * Deux différences de forme avec le gabarit remplacé, toutes deux voulues :
		 *  - l'unité n'est écrite QU'UNE fois, en fin de cellule ;
		 *  - une cote absente est omise sans laisser de séparateur orphelin (l'ancien
		 *    gabarit sortait « x P. 525 mm » quand seule la profondeur était saisie).
		 * Le bloc `dimensions_cm` n'est volontairement PAS consulté ici : 63 794 des
		 * 64 179 contenants qui le portent portent aussi le bloc historique (relevé du
		 * 11/09/2026), et le consulter ferait changer la valeur affichée pour des
		 * dizaines de milliers de lignes — ce n'est pas ce que demande le ticket.
		 *
		 * @param mixed $po_row ligne de résultat (SearchResult) ou instance ca_objects
		 * @return string ex. « H. 28 x L. 43.2 x P. 52.5 cm » ; '' si aucune cote saisie
		 */
		private function dimensionsEnCm_0103($po_row) {
			$va_cotes = [];
			foreach (['H.' => 'dimensions_height', 'L.' => 'dimensions_width', 'P.' => 'dimensions_depth'] as $vs_lettre => $vs_code) {
				$va_v = $po_row->get('ca_objects.dimensions.'.$vs_code, ['returnAsDecimalMetric' => true, 'returnAsArray' => true]);
				if (!is_array($va_v)) { $va_v = (($va_v === null) || ($va_v === '')) ? [] : [$va_v]; }
				$vm_m = null;
				foreach ($va_v as $vm) { if (($vm !== null) && ($vm !== '')) { $vm_m = $vm; break; } }
				if ($vm_m === null) { continue; }
				$va_cotes[] = $vs_lettre.' '.$this->cmDepuisMetres_0103($vm_m);
			}
			return sizeof($va_cotes) ? join(' x ', $va_cotes).' cm' : '';
		}
		public function Modele2_help() {
			$this->render('modele2_help_html.php');
		}
 		public function Modele2() {
	 		// ID métadonnées : Type d'opération > Type d'opération (inrap_type_ope) = 487
	 		// Valeur : Diagnostic = 1443
	 		$vs_etat= $this->getRequest()->getParameter("etat", pString);
			$vn_collection = $this->getRequest()->getParameter("collection", pInteger);

			$collection = new ca_collections($vn_collection);
			$this->view->setVar("idno", $collection->get("ca_collections.idno"));
			$this->view->setVar("label", $collection->get("ca_collections.preferred_labels.name"));

			$dept = $collection->getWithTemplate("^ca_places.hierarchy.idno%maxLevelsFromBottom=3&delimiter=;");
			// ... on a ici 63;BEAUMONT ne garder que la partie avant le ;
			$dept = reset(explode(";", $dept));

			$headers = [];
	 		switch($vs_etat) {
		 		case "inventaire_global_de_la_collection":
		 			$titre = "Inventaire de la collection";
		 			$headers = [
			 			"Statut collection",
			 			"Code OA",
			 			"Type opération",
			 			"Code INRAP",
                        "Région",
                        "Numéro dépt.",
                        "Commune",
                        "Lieudit",
                        "RO",
                        "Numéro d'inventaire",
                        "Unité d'enregistrement",
                        "Numéro d'isolation",
						"Nombre de reste",
                        "Quantification du mobilier",
                        "Type d'objet",
                        "Identification",
                        "Description scientifique",
                        "Matériaux",
                        "Dimensions",
                        "Numéro du contenant",
                        "Emplacements liés",
                        "Présence Inventaire BAM",
                        "Présence Inventaire DOC",
                        "Date de vérification"
		 			];
		 			break;
		 			
		 	}
	 		$this->view->setVar('etat', $vs_etat);

	 		//Création du tableau de résultat
	 		$va_results = [];
			$va_debug = [];


            $va_results[0] = $headers;
            $i=0;

			$vo_obj_browse = new ObjectBrowse();
			$vo_obj_browse->addCriteria("collection_facet", $vn_collection); // GO
			$vo_obj_browse->execute();
			$qr_results = $vo_obj_browse->getResults();
			$num_results = $vo_obj_browse->numResults();
			//var_dump($num_results);
			//die();

			while($qr_results->nextHit()) {
				$object_type = $qr_results->getWithTemplate("^ca_objects.type_id");

				// IF NE PAS mobilier, doc, prel, ignorer et passer ensuite
				if(!in_array(strtolower($object_type), ["mobilier","document", "mobilier versé", "document versé"])) {
					continue;
				}
	            $i++;
				// Récupération des valeurs difficiles d'accès
				$va_debug[$i] = new ca_collections($collection->get("ca_collections.idno"));
				$contenant = new ca_objects($qr_results->getWithTemplate("<unit relativeTo='ca_objects_x_objects'>^ca_objects.object_id</unit>"));
				$test[$i] = $contenant->getWithTemplate("^ca_storage_locations.hierarchy.preferred_labels.name%delimiter=_➔_ <ifdef='ca_storage_locations.idno'>(^ca_storage_locations.idno)</ifdef></unit>");


				/*
				 *	 Récupération des valeurs dans le tableau 
				 */
				$va_results[$i] = [
					$collection->getWithTemplate("^ca_collections.statut_collection"),
					$collection->get("ca_collections.oa_number"),
					$collection->getWithTemplate("^ca_collections.inrap_type_op.inrap_type_ope"),
					$collection->get("ca_collections.idno"),
					$collection->getWithTemplate("^ca_places.hierarchy.preferred_labels.name%maxLevelsFromTop=2&delimiter=_➜_"),
					$dept,
					$collection->getWithTemplate("^ca_places.preferred_labels"),
					$collection->getWithTemplate("^ca_collections.lieudit"),
					$qr_results->getWithTemplate("<unit relativeTo='ca_collections'><unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.displayname</unit></unit>"), //RO
					$qr_results->getWithTemplate("^ca_objects.idno"),
					$qr_results->getWithTemplate("^ca_objects.inrap_unite_enregistrement"),
					$qr_results->getWithTemplate("^ca_objects.inrap_numero_isolation"),
					$qr_results->getWithTemplate("^ca_objects.fragments_mobilier"),
					$qr_results->getWithTemplate("^ca_objects.quantification_mobilier"),
					$qr_results->getWithTemplate("^ca_objects.type_id"),
					$qr_results->getWithTemplate("^ca_objects.type_mobilier"),
					$qr_results->getWithTemplate("^ca_objects.description"),
					$qr_results->getWithTemplate("^ca_objects.inrap_materiaux"),
					$this->dimensionsEnCm_0103($qr_results),	// 7963 (point 14) — en cm, plus en mm bruts
					$qr_results->getWithTemplate("<unit relativeTo='ca_objects_x_objects'>^ca_objects.idno</unit>"),
					//$qr_results->getWithTemplate("<unit relativeTo='ca_collections'>^ca_storage_locations.preferred_labels.name <ifdef='ca_storage_locations.idno'>(^ca_storage_locations.idno)</ifdef></unit>"),
					$contenant->getWithTemplate("^ca_storage_locations.hierarchy.preferred_labels.name%delimiter=_➔_ <ifdef='ca_storage_locations.idno'>(^ca_storage_locations.idno)</ifdef></unit>"),
					$collection->getWithTemplate("^ca_collections.presence_inventaire_BAM"),
					$collection->getWithTemplate("^ca_collections.presence_inventaire_DOC"),
					$collection->getWithTemplate("^ca_collections.date_verification")
				];
				unset($contenant);
			}
			$this->view->setVar("test", $test);
			$this->view->setvar("debug", $va_debug);
			$this->view->setVar('results', $va_results);
			$this->view->setVar('titre', $titre);
			$this->view->setVar("num_results", $i);
	 		$this->render('modele2_html.php');
 		}
 		public function Modele2b_help() {
			$this->render('modele2b_help_html.php');
		}
 		public function Modele2b() {
	 		// ID métadonnées : Type d'opération > Type d'opération (inrap_type_ope) = 487
	 		// Valeur : Diagnostic = 1443
	 		$vs_etat= $this->getRequest()->getParameter("etat", pString);
			$vn_collection = $this->getRequest()->getParameter("collection", pInteger);

			$collection = new ca_collections($vn_collection);
			$this->view->setVar("idno", $collection->get("ca_collections.idno"));
			$this->view->setVar("label", $collection->get("ca_collections.preferred_labels.name"));

			$dept = $collection->getWithTemplate("^ca_places.hierarchy.idno%maxLevelsFromBottom=3&delimiter=;");
			// ... on a ici 63;BEAUMONT ne garder que la partie avant le ;
			$dept = reset(explode(";", $dept));

			$headers = [];
	 		switch($vs_etat) {
		 		case "inventaire_des_contenants":
		 			$titre = "Inventaire global de la collection : contenants liés";
		 			$headers = [
			 			"Statut collection",
			 			"Code OA",
			 			"Type opération",
			 			"Code INRAP",
                        "Région",
                        "Numéro dépt.",
                        "Commune",
                        "Lieudit",
                        "RO",
                        "Numéro du contenant",
                        "Type de contenant",
                        "Description",
                        "Matériaux",
                        "Dimensions",
                        "Volume du contenant",
                        "Emplacements liés"
		 			];
		 			break;
		 			
		 	}
	 		$this->view->setVar('etat', $vs_etat);

	 		//Création du tableau de résultat
	 		$va_results = [];


            $va_results[0] = $headers;
            $i=0;

			$vo_obj_browse = new ObjectBrowse();
			$vo_obj_browse->addCriteria("collection_facet", $vn_collection); // GO
			$vo_obj_browse->execute();
			$qr_results = $vo_obj_browse->getResults();
			$num_results = $vo_obj_browse->numResults();
			//var_dump($num_results);
			//die();

			while($qr_results->nextHit()) {
				$object_type = $qr_results->getWithTemplate("^ca_objects.type_id");
				
				// IF type parmi mobilier, document, prelevement on ignore
				if(in_array(strtolower($object_type), ["mobilier","document","mobilier versé", "document versé"])) {
					continue;
				}
	            $i++;
				// Récupération des valeurs difficiles d'accès

				/*
				 *	 Récupération des valeurs dans le tableau 
				 */
				$va_results[$i] = [
					$collection->getWithTemplate("^ca_collections.statut_collection"),
					$collection->get("ca_collections.oa_number"),
					$collection->getWithTemplate("^ca_collections.inrap_type_op.inrap_type_ope"),
					$collection->get("ca_collections.idno"),
					$collection->getWithTemplate("^ca_places.hierarchy.preferred_labels.name%maxLevelsFromTop=2&delimiter=_➜_"),
					$dept,
					$collection->getWithTemplate("^ca_places.preferred_labels"),
					$collection->getWithTemplate("^ca_collections.lieudit"),
					$qr_results->getWithTemplate("<unit relativeTo='ca_collections'><unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.displayname</unit></unit>"), //RO
					$qr_results->getWithTemplate("^ca_objects.idno"),
					$qr_results->getWithTemplate("^ca_objects.type_id"),
					$qr_results->getWithTemplate("^ca_objects.description"),
					$qr_results->getWithTemplate("^ca_objects.inrap_materiaux"),
					$this->dimensionsEnCm_0103($qr_results),	// 7963 (point 14) — en cm, plus en mm bruts
					round(tofloat($qr_results->getWithTemplate("^ca_objects.volume_caisse")),4),
					$qr_results->getWithTemplate("^ca_storage_locations.preferred_labels.name <ifdef='ca_storage_locations.idno'>(^ca_storage_locations.idno)</ifdef>")
				];
			}
			$this->view->setVar('results', $va_results);
			$this->view->setVar('titre', $titre);
			$this->view->setVar("num_results", $i);
	 		$this->render('modele2b_html.php');
 		}
 		public function Modele3() {
	 		// ID métadonnées : Type d'opération > Type d'opération (inrap_type_ope) = 487
	 		// Valeur : Diagnostic = 1443
	 		$vs_etat= $this->getRequest()->getParameter("etat", pString);
			$vn_collection = $this->getRequest()->getParameter("collection", pInteger);
			if(!$vn_collection) {
				return "<H1>Error</H1><p>Le paramètre collection est obligatoire.</p>";
			}

	 		$headers = [];
	 		$headers = [ "Code OA", "Région", "Numéro dépt.", "Commune", "Lieudit", "Identifant"];
	 		switch($vs_etat) {
		 		case "etiquette_temporaire":
		 			$titre = "Étiquette temporaire";
		 			$type_ope_cible = "";
		 			$statut_coll_cible = "";
		 			break;
		 		case "etiquette_définitive_conditionnement":
		 			$titre = "Étiquette définitive conditionnement";
		 			$type_ope_cible = "";
		 			$statut_coll_cible = "";
		 			break;
		 		case "etiquette_définitive_contenant":
		 			$titre = "Étiquette définitive contenant";
		 			$type_ope_cible = "";
		 			$statut_coll_cible = "";
		 			break;
		 	}
	 		$this->view->setVar('etat', $vs_etat);

	 		//Création du tableau de résultat
	 		$va_results = [];

	 		// Récupération des données
	 		$o_data = new Db();
	 		$and = "";
	 		$vs_query = "select coll.collection_id from ca_objects_x_collections caoc left join ca_collections coll on caoc.collection_id=coll.collection_id and coll.type_id=".__INRAP_TYPE_ID_OP__." left join ca_objects obj on obj.object_id=caoc.object_id where coll.deleted=0 and obj.deleted=0";
            $qr_result = $o_data->query($vs_query);
            $va_results[0] = $headers;
            $i=0;

			$vo_coll_search = new CollectionSearch();
			$vo_coll_search->doSearch("ca_collections.collection_id:".$vn_collection, $qr_result);

			$num_results = $vo_coll_search->numResults();

			while($qr_result->nextRow()) {
	            $i++;
	            // Chargement de la collection cible
				$collection = new ca_collections($qr_result->get('collection_id'));
				// Récupération des valeurs difficiles d'accès
				$dept = $collection->getWithTemplate("^ca_places.hierarchy.idno%maxLevelsFromBottom=3&delimiter=;");
				// ... on a ici 63;BEAUMONT ne garder que la partie avant le ;
				$dept = reset(explode(";", $dept));

				//Filtrage par DIR
				$DIR = $collection->getWithTemplate("<unit relativeTo='ca_entities' restrictToTypes='DIR'>^ca_entities.preferred_labels.displayname</unit>");
				if(!in_array($DIR, $this->ops_user_groups)) {
					continue;
					//die("Argh, dying dying");
				}

				/*
				 *	 Récupération des valeurs dans le tableau 
				 */
				$va_results[$i] = [
					$collection->get("ca_collections.parent.idno"),
                    $collection->getWithTemplate("^ca_places.hierarchy.preferred_labels.name%maxLevelsFromTop=1&delimiter=_➜_"),
                    $collection->getWithTemplate("^ca_places.hierarchy.preferred_labels.name%maxLevelsFromTop=2&delimiter=_➜_"),
                    $collection->getWithTemplate("^ca_places.preferred_labels"),
                    $collection->getWithTemplate("^ca_collections.lieudit"),
                    $collection->getWithTemplate("^ca_objects.idno")
				];
			}
			$this->view->setVar('results', $va_results);
			$this->view->setVar('titre', $titre);

	 		$this->render('modele3_html.php');
 		}
 		public function Modele4_help() {
			$this->render('modele4_help_html.php');
		} 
 		public function Modele4() {
	 		// ID métadonnées : Type d'opération > Type d'opération (inrap_type_ope) = 487
	 		// Valeur : Diagnostic = 1443
	 		$vs_etat= $this->getRequest()->getParameter("etat", pString);
	 		$vn_mvt_id = $this->getRequest()->getParameter("mouvement", pString);
            $vs_export = $this->getRequest()->getParameter("export", pString);
	 		if(!$vn_mvt_id) {
		 		$this->view->setVar("message", "Cet état nécessite de sélectionner une fiche mouvement.");
		 		return $this->render("error_html.php");
	 		}
	 		
	 		//$vs_dest= $this->getRequest()->getParameter("format", pString);
	 		//var_dump($vs_dest);die();
	 		
	 		$headers = [];
	 		switch($vs_etat) {
		 		case "bordereau_de_versement":
		 			$titre = "Bordereau de versement";
		 			$headers = [
			 			"Code INRAP",
			 			"Code OA",
			 			"Région",
			 			"Numéro dépt.",
			 			"Commune",
			 			"Lieudit",
			 			"Responsable d'opération",
			 			"Bien archeologique mobilier contenant nombre",
			 			"bien archeologique mobilier volume hors contenant",
			 			"bien archeologique volume m3",
			 			"documentation scientifique(oui/non)",
			 			"documentation scientifique contenant nombre",
			 			"documentation scientifique hors contenant nombre",
			 			"documentation scientifique m/l",
						"nb de contenant numerique",
			 			"responsable versement nom",
			 			"responsable versement prénom",
			 			"sra nom (destinataire)",
			 			"sra prenom (destinataire)",
			 			"Présence Inventaire BAM",
                        "Présence Inventaire DOC",
                        "Date de vérification",
						"idno",
						"Date de versement",
						"Listes"
		 			];
		 			break;
		 			
		 	}
	 		$this->view->setVar('etat', $vs_etat);

	 		//Création du tableau de résultat
	 		$va_results = [];

	 		// Récupération des données
	 		$o_data = new Db();
	 		$and = "";
	 		$vs_query = "select ca_collections.collection_id, ca_movements.movement_id from ca_movements_x_collections left join ca_collections on ca_movements_x_collections.collection_id=ca_collections.collection_id left join ca_movements on ca_movements_x_collections.movement_id=ca_movements.movement_id where ca_collections.deleted=0 and ca_movements.movement_id=".$vn_mvt_id;
            $qr_result = $o_data->query($vs_query);
            $va_results[0] = $headers;

            // === D2026-0103 §4 — compteurs restreints aux contenants versés : début ===
            // Exigence : le §4 de l'expression de besoin 20260622 porte SEPT fois la mention
            // « Fiche opération liée/bordereau de versement. Attention que ceux sélectionnés, ».
            // Elle vise les sept compteurs de la page opération : Bien archéologique mobilier
            // (Contenant(s), Hors contenant(s), Volume m3, Poids en kg), Documentation
            // scientifique (Contenant(s)), Documentation numérique (Contenant(s), Volume Mo).
            //
            // Défaut corrigé : ces sept valeurs étaient lues sur l'OPÉRATION entière
            // (op_nb_contenants, op_nb_hors_contenants, inrap_volume_total, poids_bam_ope,
            // op_doc_sci_contenants, comptage des contenants numériques de l'opération, somme
            // de inrap_volume_num_mo sur l'opération), alors que les trois listes imprimées
            // juste en dessous étaient, elles, filtrées sur le versement. Le document se
            // contredisait : versement 13140 → « Bien archéologique mobilier / Contenant(s) : 3 »
            // avec une liste mobilier vide, les trois contenants rattachés étant de la
            // documentation. Compteurs et listes reposent désormais sur LA MÊME population.
            //
            // Rattachement réel d'un contenant à un versement — établi en base :
            //   table `ca_movements_x_objects` (mouvement ↔ objet), type de relation 16
            //   « est lié à » (seul type présent sur cette table). C'est elle qu'alimente le
            //   bloc « Contenants versés » de l'écran de saisie du versement (placement 558,
            //   écran 132, bundle ca_objects), restreint aux SIX types de contenants
            //   ci-dessous. Le rattachement à l'opération reste `ca_objects_x_collections` :
            //   un compteur de page opération est donc l'intersection des deux.
            //
            // Types retenus = exactement les six du bloc de saisie (restrict_to_types du
            // placement 558) et exactement ceux des trois listes déjà en place :
            $va_types_mob = [28,39988];    // contenant mobilier / contenant mobilier versé
            $va_types_doc = [30,39987];    // contenant doc / contenant doc versé
            $va_types_num = [1886,39989];  // contenant numérique / contenant numérique versé
            // Les autres types rattachés historiquement à des versements (document versé,
            // mobilier, mobilier versé, prélèvement, document — 271 rattachements au 07/08/2026)
            // n'entrent dans AUCUN compteur : ce ne sont pas des contenants mais leur contenu
            // (ex. versement 5170 → 13 « document versé » = pièces documentaires ; versement
            // 6045 → 1 « mobilier versé » = une hache logée dans « caisse4-sac42 »). Les
            // compter en « Contenant(s) » ferait double emploi avec le contenant qui les porte,
            // et les ferait diverger des trois listes, qui sont filtrées sur les mêmes six
            // types. Ils restent en base, intacts.
            $va_types_verses_0103 = array_merge($va_types_mob, $va_types_doc, $va_types_num);

            $va_mvt_objects = [];
            $qr_mo = $o_data->query("SELECT DISTINCT mo.object_id
                FROM ca_movements_x_objects mo
                JOIN ca_objects o ON o.object_id = mo.object_id AND o.deleted = 0
                 AND o.type_id IN (".join(',', $va_types_verses_0103).")
                WHERE mo.movement_id=".(int)$vn_mvt_id);
            while($qr_mo->nextRow()) { $va_mvt_objects[] = (int)$qr_mo->get('object_id'); }

            // === D2026-0103 §4 — repli sur l'opération versée : début ===
            // ARBITRAGE CLIENT (août 2026). Le lot précédent avait supprimé tout repli : un
            // versement sans aucun contenant rattaché sortait à zéro, listes vides. Le client
            // a tranché autrement, sur la base du constat suivant (reproduit en base ce jour) :
            //
            //   · 7 709 versements (type 1796, non supprimés) n'ont AUCUNE ligne dans
            //     ca_movements_x_objects ; parmi eux 4 961 (64 %) ne portent QUE des opérations
            //     déjà au statut « Collection versée », 2 548 aucune opération versée,
            //     198 aucune opération liée non supprimée, 2 partiellement.
            //     (Les chiffres du client — 4 960 / 2 589 / 157 / 3 — sont les mêmes à ceci près
            //     qu'ils comptent aussi les opérations supprimées ; le total 7 709 est identique.
            //     L'édition, elle, ne voit que les opérations non supprimées : ce sont les
            //     valeurs ci-dessus qui font foi ici.)
            //
            // Raisonnement retenu par le client : si l'opération est VERSÉE, c'est que TOUT a
            // été versé. L'absence de sélection ne veut pas dire « rien n'a été versé » mais
            // « il n'y avait pas lieu de sélectionner » — la sélection contenant par contenant
            // n'existait pas à l'époque de ces versements. Le repli sur l'opération entière est
            // donc EXACT dans ce cas, et non approximatif. Si l'opération n'est pas versée, la
            // sélection reste à faire : zéro et listes vides, comme au lot précédent.
            //
            // RÈGLE MISE EN ŒUVRE :
            //   1. Déclencheur au niveau du VERSEMENT : aucun contenant des six types rattaché
            //      (`$va_mvt_objects` vide). Dès qu'un seul contenant est rattaché, le
            //      comportement du lot précédent s'applique tel quel, sans aucune exception —
            //      y compris pour une opération du versement dont l'intersection est vide :
            //      c'est alors une sélection délibérée qui porte sur une autre opération.
            //   2. Appréciation au niveau de l'OPÉRATION : un bordereau multi-opérations produit
            //      une page par opération liée ; le statut est lu sur CHAQUE opération. Une
            //      opération versée et une non versée dans le même versement sont donc traitées
            //      différemment, page par page.
            //
            // Statut d'opération — vérifié en base, non supposé :
            //   élément `statut_collection` = element_id 449 (datatype 3 = liste, list_id 152),
            //   porté par ca_attributes.table_num = 13 (ca_collections).
            //   « Collection versée » = ca_list_items.item_id 1556 (idno « versée ») de la
            //   liste 152 ; les sept autres items sont 1553 en cours d'étude, 1554 en attente
            //   de versement, 1555 en garde pour l'INRAP, 1634 en garde pour Etat,
            //   39977 en cours de versement, 40125 en suspens, 1552 racine.
            //   15 847 opérations portent l'item 1556.
            // Test retenu : EXISTE-T-IL une valeur 1556 sur l'opération. 28 opérations portent
            // deux attributs statut_collection : 23 du couple (valeur vide, 1556), 4 du couple
            // (1556, 1556), 1 avec deux valeurs vides. Dans les 23 premiers cas l'attribut vide
            // porte le plus petit attribute_id — prendre « le premier attribut » renverrait donc
            // du vide sur 23 opérations pourtant versées. L'existence est le test juste.
            $va_ops_versees_0103 = [];
            $qr_sv = $o_data->query("SELECT DISTINCT mc.collection_id
                FROM ca_movements_x_collections mc
                JOIN ca_collections c ON c.collection_id = mc.collection_id AND c.deleted = 0
                JOIN ca_attributes a ON a.table_num = 13 AND a.element_id = 449 AND a.row_id = c.collection_id
                JOIN ca_attribute_values v ON v.attribute_id = a.attribute_id
                 AND v.element_id = 449 AND v.item_id = 1556
                WHERE mc.movement_id = ".(int)$vn_mvt_id);
            while($qr_sv->nextRow()) { $va_ops_versees_0103[(int)$qr_sv->get('collection_id')] = true; }

            // Vrai quand le repli doit s'appliquer À CETTE opération : versement sans aucun
            // contenant rattaché ET opération au statut « Collection versée ».
            $vb_repliOperationVersee_0103 = function($pn_collection_id) use ($va_mvt_objects, $va_ops_versees_0103) {
                return (!sizeof($va_mvt_objects)) && isset($va_ops_versees_0103[(int)$pn_collection_id]);
            };
            // === D2026-0103 §4 : fin ===

            // === D2026-0103 §4 — contenu des listes de contenants : début ===
            // Exigence (§4 de l'expression de besoin 20260622, tableau des listes) :
            //   · « Liste contenant mobilier — liste Numéro de contenant / matière-classe :
            //      N° de contenant (idno) +/+ matière/classe (rubriques en cours de création) »
            //   · « Liste contenant documentation — liste Numéro de contenant / contenu :
            //      Numéro de contenant (idno) +/+ Référence (ca_objects.preferred_labels) »
            //   · « Liste contenant numérique — … » : même construction que la documentation.
            //
            // Défaut corrigé : les trois listes ne restituaient QUE `ca_object_labels.name`,
            // c'est-à-dire le libellé calculé du contenant
            // (« F102954 / Strasbourg / Place Saint-Thomas, rue Jean-Sturm / CEP / 015972-128 »).
            // Ni le numéro de contenant en tête de ligne, ni la matière/classe n'apparaissaient.
            // Chaque ligne s'écrit désormais « <idno> / <complément> », le complément valant
            // « matière - classe » pour le mobilier et le libellé préféré pour les deux autres.
            //
            // SOURCE DU COMPLÉMENT, par famille :
            //   mobilier      → élément `inrap_materiaux` porté par le contenant (ca_objects)
            //   documentation → `ca_object_labels.name` (libellé préféré) = « Référence » du §4
            //   numérique     → idem documentation
            //
            // ┌ DÉPENDANCE AU D2026-0101 ────────────────────────────────────────────────────┐
            // │ Le §4 note lui-même « rubriques en cours de création » : la matière/classe    │
            // │ relève du devis D2026-0101, pas de celui-ci. Ce lot la CONSOMME, il ne la     │
            // │ crée pas et ne modifie ni l'élément, ni la liste, ni les données.             │
            // │ État constaté sur cette instance le 07/08/2026 :                              │
            // │   élément `inrap_materiaux` = element_id 436, datatype 3 (liste), list_id 154 │
            // │   liste 154 `INRAP_mat__riaux`, hiérarchique, 185 items sur TROIS niveaux     │
            // │   sous la racine : 9 Matière > 39 Classe > 136 Précision.                     │
            // │ Sur une instance où le 0101 n'aurait pas été déployé (élément absent, ou      │
            // │ liste restée à plat), la liste mobilier sortirait sans complément — d'où la   │
            // │ résolution de l'élément PAR SON CODE ci-dessous plutôt qu'en dur, et le repli │
            // │ silencieux sur « idno seul » qui garde le document lisible.                   │
            // └──────────────────────────────────────────────────────────────────────────────┘
            //
            // Mise en forme d'une valeur avant impression. Quatre nettoyages, dans cet ordre :
            //
            //  1. `strip_tags` — les libellés d'objets sont stockés en fragments HTML ; 153
            //     libellés de contenants portent des balises ou des entités (ex. object_id
            //     123 « … Cahiers d'enregistrement des tranchées n°5 à 7<br />… »).
            //  2. `html_entity_decode` APRÈS le strip_tags (jamais avant : décoder d'abord
            //     fabriquerait des balises qui seraient ensuite supprimées). Sans lui, le
            //     bordereau imprimait « TC : céramique (sacs 1 &gt; 20) » au lieu de
            //     « (sacs 1 > 20) » — versement 16763, 8 contenants. La vue ré-échappe
            //     ensuite proprement pour le XML (htmlspecialchars, ENT_XML1).
            //  3. Retours à la ligne et caractères de contrôle → une espace. Une ligne de liste
            //     doit rester UNE ligne : un « \n » resté dans un libellé casserait la règle
            //     « une ligne par contenant », et les caractères de contrôle C0 sont interdits
            //     dans un document OOXML — ils rendraient le XML invalide.
            //  4. Rognage des blancs de tête et de fin. `trim()` ne suffit pas : 2 853
            //     contenants ont un idno qui se termine par une ESPACE INSÉCABLE (U+00A0, ex.
            //     object_id 125522 « 2212612-DOC-1␣ »), que trim() laisse passer et qui
            //     produirait « 2212612-DOC-1  / … », avec un blanc parasite avant le séparateur.
            //     Rognage par expression régulière UTF-8 et non par liste d'octets : trim() sur
            //     les octets \xC2\xA0 couperait le second octet d'un « à » (\xC3\xA0) et
            //     casserait l'encodage — donc le XML du document.
            //
            // Tout ceci ne touche QUE la valeur imprimée : rien n'est réécrit en base.
            $vs_pourImpression_0103 = function($ps_val) {
                $vs = strip_tags((string)$ps_val);
                $vs = html_entity_decode($vs, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $vs = (string)preg_replace('/[\x{0000}-\x{001F}\x{007F}]+/u', ' ', $vs);
                return (string)preg_replace('/^[\s\x{00A0}\x{202F}\x{2007}\x{FEFF}]+|[\s\x{00A0}\x{202F}\x{2007}\x{FEFF}]+$/u', '', $vs);
            };
            //
            // Résolution de l'élément et de sa liste — jamais d'identifiant en dur.
            $vn_el_materiaux_0103 = null; $vn_list_materiaux_0103 = null;
            $qr_elmat = $o_data->query("SELECT element_id, list_id FROM ca_metadata_elements
                WHERE element_code = 'inrap_materiaux' AND datatype = 3 AND list_id IS NOT NULL");
            if ($qr_elmat->nextRow()) {
                $vn_el_materiaux_0103   = (int)$qr_elmat->get('element_id');
                $vn_list_materiaux_0103 = (int)$qr_elmat->get('list_id');
            }

            // Arbre de la liste des matériaux, chargé une seule fois (185 items) : la remontée
            // se fait ensuite en mémoire, sans requête par contenant — une liste de plusieurs
            // centaines de lignes ne coûte donc que deux requêtes.
            $va_arbreMateriaux_0103 = [];
            if ($vn_list_materiaux_0103) {
                $qr_li = $o_data->query("SELECT i.item_id, i.parent_id,
                        COALESCE(NULLIF(TRIM(l.name_singular), ''), NULLIF(TRIM(l.name_plural), ''), i.idno) AS nom
                    FROM ca_list_items i
                    LEFT JOIN ca_list_item_labels l ON l.item_id = i.item_id AND l.is_preferred = 1
                    WHERE i.list_id = ".$vn_list_materiaux_0103." AND i.deleted = 0");
                while($qr_li->nextRow()) {
                    $va_arbreMateriaux_0103[(int)$qr_li->get('item_id')] = [
                        'parent' => (int)$qr_li->get('parent_id'),
                        'nom'    => $vs_pourImpression_0103($qr_li->get('nom'))
                    ];
                }
            }

            // Remontée « matière / classe » depuis une valeur saisie à N'IMPORTE QUEL niveau.
            // Méthode : on remonte la chaîne des parents jusqu'au nœud racine de la liste (le
            // seul item sans parent), puis on lit les DEUX premiers échelons sous cette racine.
            //   valeur au niveau Matière   → chaîne [racine, Matière]                  → « Matière »
            //   valeur au niveau Classe    → chaîne [racine, Matière, Classe]          → « Matière - Classe »
            //   valeur au niveau Précision → chaîne [racine, Matière, Classe, Précis.] → « Matière - Classe »
            // Le procédé ne présume donc pas de la profondeur : un quatrième niveau ajouté plus
            // tard par le 0101 continuerait de rendre la même matière et la même classe.
            // La Précision n'est pas imprimée : le §4 demande « matière/classe », pas la
            // précision. Le compteur de sécurité (20) protège d'un éventuel cycle en base.
            $vs_matiereClasse_0103 = function($pn_item_id) use (&$va_arbreMateriaux_0103) {
                $va_chaine = []; $vn_id = (int)$pn_item_id; $vn_garde = 0;
                while ($vn_id && isset($va_arbreMateriaux_0103[$vn_id]) && $vn_garde++ < 20) {
                    array_unshift($va_chaine, $va_arbreMateriaux_0103[$vn_id]['nom']);
                    $vn_id = $va_arbreMateriaux_0103[$vn_id]['parent'];
                }
                // $va_chaine[0] est la racine technique de la liste (« Root node for … ») : écartée.
                $vs_mat = isset($va_chaine[1]) ? trim($va_chaine[1]) : '';
                $vs_cla = isset($va_chaine[2]) ? trim($va_chaine[2]) : '';
                if ($vs_mat === '') { return ''; }
                return ($vs_cla === '') ? $vs_mat : $vs_mat.' - '.$vs_cla;
            };

            // Complément « matière/classe » pour un lot de contenants, en une requête.
            // Un contenant peut porter PLUSIEURS matériaux (35 306 contenants portent 41 110
            // valeurs) : les couples matière/classe sont dédoublonnés — deux précisions d'une
            // même classe ne produisent qu'une entrée — et joints par « , ».
            $vs_complementMatiere_0103 = function($pa_object_ids) use ($o_data, $vn_el_materiaux_0103, $vs_matiereClasse_0103) {
                $va_out = [];
                if (!$vn_el_materiaux_0103 || !sizeof($pa_object_ids)) { return $va_out; }
                $qr = $o_data->query("SELECT a.row_id, v.item_id
                    FROM ca_attributes a
                    JOIN ca_attribute_values v ON v.attribute_id = a.attribute_id
                     AND v.element_id = ".$vn_el_materiaux_0103." AND v.item_id IS NOT NULL
                    WHERE a.table_num = 57 AND a.element_id = ".$vn_el_materiaux_0103."
                      AND a.row_id IN (".join(',', $pa_object_ids).")
                    ORDER BY a.row_id, a.attribute_id");
                $va_par_objet = [];
                while($qr->nextRow()) {
                    $vn_o  = (int)$qr->get('row_id');
                    $vs_mc = $vs_matiereClasse_0103((int)$qr->get('item_id'));
                    if ($vs_mc === '') { continue; }
                    if (!isset($va_par_objet[$vn_o])) { $va_par_objet[$vn_o] = []; }
                    if (!in_array($vs_mc, $va_par_objet[$vn_o])) { $va_par_objet[$vn_o][] = $vs_mc; }
                }
                foreach($va_par_objet as $vn_o => $va_v) { $va_out[$vn_o] = join(', ', $va_v); }
                return $va_out;
            };
            // === D2026-0103 §4 : fin ===

            // Listes : filtrées sur les contenants rattachés au versement.
            // === D2026-0103 §4 — contenu des listes de contenants : début ===
            // `$ps_complement` : 'materiau' pour la liste mobilier, 'reference' pour les listes
            // documentation et numérique.
            // `$pb_repli` (lot précédent, inchangé) : sans aucun contenant rattaché au
            // versement, repli sur tous les contenants de l'opération si elle est versée,
            // listes vides sinon.
            $vs_getContenants = function($pn_collection_id, $pa_types, $pb_repli = false, $ps_complement = 'reference') use ($o_data, $va_mvt_objects, $vs_complementMatiere_0103, $vs_pourImpression_0103) {
                if (!sizeof($va_mvt_objects) && !$pb_repli) { return []; }
                $vs_filter = sizeof($va_mvt_objects) ? " AND o.object_id IN (".join(',', $va_mvt_objects).")" : "";
            // `o.idno` s'ajoute à la sélection : c'est le « Numéro de contenant » du §4, qui
            // ouvre désormais chaque ligne. Le tri passe de `ol.name` à `o.idno` pour que la
            // liste soit ordonnée sur la valeur qu'elle affiche en tête — trier sur un libellé
            // qui n'est plus imprimé (cas du mobilier) donnerait un ordre incompréhensible.
                $qr = $o_data->query("SELECT o.object_id, o.idno, ol.name
                    FROM ca_objects o
                    JOIN ca_objects_x_collections oc ON oc.object_id = o.object_id
                    LEFT JOIN ca_object_labels ol ON ol.object_id = o.object_id AND ol.is_preferred = 1
                    WHERE oc.collection_id = ".(int)$pn_collection_id."
                      AND o.deleted = 0 AND o.type_id IN (".join(',', $pa_types).")".$vs_filter."
                    ORDER BY o.idno, ol.name");
                // Indexation par object_id : `ca_objects_x_collections` peut porter plusieurs
                // lignes pour un même couple objet/opération, et un objet plusieurs libellés
                // préférés (un par locale). Sans cela un contenant s'imprimerait deux fois et
                // la liste ne collerait plus au compteur, qui est en DISTINCT.
                $va_rows = [];
                while($qr->nextRow()) {
                    $vn_o = (int)$qr->get('object_id');
                    if (isset($va_rows[$vn_o])) { continue; }
                    $va_rows[$vn_o] = [
                        'idno' => $vs_pourImpression_0103($qr->get('idno')),
                        'nom'  => $vs_pourImpression_0103($qr->get('name'))
                    ];
                }
                if (!sizeof($va_rows)) { return []; }

                $va_comp = ($ps_complement === 'materiau') ? $vs_complementMatiere_0103(array_keys($va_rows)) : [];

                // === D2026-0103 §4 — évolution 12/08/2026, listes de contenants en tableau : début ===
                // La closure rend désormais des LIGNES STRUCTURÉES et non plus des chaînes
                // formatées : la même sélection (requête, dédoublonnage, repli — tout ce qui
                // précède est inchangé) alimente à la fois les colonnes texte historiques
                // [42..44] (vue HTML) et les tableaux Word [51..53]. La mise en forme
                // « idno / complément » a déménagé dans $vs_ligneContenant_0103 ci-dessous,
                // à l'identique, commentaires compris.
                $va_out = [];
                foreach($va_rows as $vn_o => $va_r) {
                    $va_out[] = [
                        'object_id' => $vn_o,
                        'idno'      => $va_r['idno'],
                        'nom'       => $va_r['nom'],
                        'comp'      => ($ps_complement === 'materiau')
                            ? (isset($va_comp[$vn_o]) ? $va_comp[$vn_o] : '')
                            : $va_r['nom'],
                    ];
                }
                return $va_out;
                // === D2026-0103 §4 — évolution 12/08/2026, listes de contenants en tableau : fin ===
            };
            // === D2026-0103 §4 : fin ===

            // === D2026-0103 §4 — évolution 12/08/2026, listes de contenants en tableau : début ===
            // Demande client du 12/08/2026 (Gautier), POSTÉRIEURE à l'expression de besoin
            // 20260622 : les trois listes de contenants du bordereau deviennent des TABLEAUX
            // Word. Les colonnes sont calées sur les champs normalisés de l'« Inventaire des
            // contenants » du Référentiel national relatif au rapport d'opération
            // d'archéologie préventive (Ministère de la Culture, octobre 2025, ch. III §4) :
            //   1. Code OA                — idno de l'opération de la page (ca_collections.idno),
            //                               demandé explicitement ainsi le 12/08. La métadonnée
            //                               oa_number (colonne [1], en-tête « Code OA » du
            //                               tableau HTML) reste disponible si le client préfère
            //                               le numéro OA au code Inrap : un identifiant à changer.
            //   2. Identifiant contenant  — ca_objects.idno (`identifiant_contenant`), inchangé.
            //   3. Type-taille            — champ normalisé `type-taille_contenant`, voir l'ordre
            //                               des sources ci-dessous.
            //   4. Poids (kg)             — champ normalisé `poids_contenant`.
            //   5. Matière/classe         — remontée existante du D2026-0101, INCHANGÉE
            //                               ($vs_complementMatiere_0103 ci-dessus, y compris son
            //                               séparateur : le « & » multi-valeurs du référentiel
            //                               national est réservé au futur export tableur vers
            //                               l'État, hors périmètre — arbitrage du 12/08).
            //   6. Nature prélèvement     — SEULEMENT si l'information existe (voir plus bas).
            //   7. Catégorie de documentation — idem.
            // Familles documentation et numérique : la « Référence » (libellé préféré,
            // ca_object_labels.name) du CDC d'origine remplace la matière/classe ; taille et
            // poids n'y font colonne QUE s'ils sont renseignés sur au moins un contenant de la
            // liste — constaté en base le 12/08/2026 : 111 contenants documentation sur 15 042
            // portent une dimension et 4 un poids, 0 des 15 391 contenants numériques n'en
            // porte. Des colonnes structurellement vides n'informeraient personne.
            //
            // SOURCES DE « Type-taille », par ordre de priorité — choix documenté :
            //  1. élément `referentiel` (résolu PAR SON CODE ; ici element_id 541, liste 167,
            //     71 items). C'est la forme la plus fidèle au champ normalisé, qui COMBINE type
            //     et taille (« BAC-600x400x319 », « SEAU-10L », « SAC » — cf. les exemples du
            //     référentiel national : « caisse norme Europe 40x30x12 », « seau 1 l »).
            //     Couverture constatée : 187 639 contenants mobilier du parc, et 1 930 des
            //     1 932 contenants du versement 3518 — dont les blocs dimensions sont vides
            //     (15 valeurs historiques, 0 en cm). Les cotes de ces désignations internes
            //     sont en MILLIMÈTRES : la valeur est imprimée VERBATIM, sans conversion ni
            //     unité inventée — c'est pourquoi l'en-tête de colonne ne porte pas « (cm) »,
            //     l'unité étant portée par la cellule pour les sources 2 et 3.
            //  2. bloc `dimensions_cm` (conteneur : dimensions_depth_cm Longueur,
            //     dimensions_width_cm Largeur, dimensions_height_cm Hauteur), saisi en cm.
            //  3. bloc historique `dimensions` (dimensions_depth/width/height, saisi en mm).
            //     Pour 2 et 3, value_decimal1 est normalisé en MÈTRES par CollectiveAccess
            //     (vérifié en base : « 60 cm » → 0.60, « 400 mm » → 0.40) : ×100 → cm, au
            //     format LxlxH du référentiel national avec l'unité en cellule
            //     (« 60x40x23.5 cm »). Cote(s) manquante(s) : les cotes présentes sont
            //     préfixées (« L 60 x h 23.5 cm ») pour ne pas mimer un LxlxH complet.
            //
            // POIDS : `dimensions_poids_cm2` (« Poids kg », bloc cm) prioritaire, repli sur
            // `dimensions_poids` (bloc historique) ; value_decimal1 normalisé en kg (vérifié :
            // « 9,15 g » → 0.00915). Choix INRAP (12/08/2026) : poids toujours indiqué dès
            // qu'il est saisi, par dérogation à la règle « > 12 kg » de l'inventaire des
            // contenants du référentiel national. Cellule vide seulement si aucun poids saisi.
            //
            // COLONNES FUTURES (6 et 7) — les éléments n'existent pas encore en base, vérifié
            // le 12/08/2026 : aucun élément de métadonnée ne porte ces codes.
            // `type_prelevement_terrain` (texte) et `inrap_categorie` (valeurs constatées :
            // « Céramique indéterminée »… — c'est une matière) sont d'AUTRES notions : écartés.
            // Résolution par LISTE DE CODES CANDIDATS : le jour où l'élément est créé sous l'un
            // de ces codes, la colonne apparaît sans nouveau développement — et seulement si au
            // moins un contenant de la liste porte une valeur. Aujourd'hui : jamais, c'est voulu.
            // Vocabulaires attendus (référentiel national, ch. III §4) :
            //   `nature_prelevement`      : texte libre — de quoi est constitué le prélèvement :
            //                               « charbon, sédiment, échantillons d'objets… » ;
            //   `categorie_documentation` : vocabulaire fermé — « document écrit alpha-numérique
            //                               et base de données / document graphique / document
            //                               photographique et audiovisuel / moulage /
            //                               autre document ».
            $va_codesNature_0103   = ['nature_prelevement', 'inrap_nature_prelevement', 'nature_du_prelevement'];
            $va_codesCategDoc_0103 = ['categorie_documentation', 'inrap_categorie_documentation', 'categorie_de_documentation'];
            $va_codesTaille_0103 = [
                'referentiel',
                'dimensions_depth_cm', 'dimensions_width_cm', 'dimensions_height_cm',
                'dimensions_depth', 'dimensions_width', 'dimensions_height',
                'dimensions_poids_cm2', 'dimensions_poids',
            ];

            // Résolution de tous les éléments PAR LEUR CODE, en une requête — jamais d'id en dur.
            $va_elsTbl_0103 = []; // element_code => ['id','datatype','list']
            $qr_elt = $o_data->query("SELECT element_id, element_code, datatype, list_id
                FROM ca_metadata_elements
                WHERE element_code IN ('".join("','", array_merge($va_codesTaille_0103, $va_codesNature_0103, $va_codesCategDoc_0103))."')");
            while($qr_elt->nextRow()) {
                $va_elsTbl_0103[(string)$qr_elt->get('element_code')] = [
                    'id'       => (int)$qr_elt->get('element_id'),
                    'datatype' => (int)$qr_elt->get('datatype'),
                    'list'     => (int)$qr_elt->get('list_id'),
                ];
            }
            // Premier code candidat existant pour chacune des deux colonnes futures.
            $vs_elNature_0103 = '';
            foreach ($va_codesNature_0103 as $vs_c)   { if (isset($va_elsTbl_0103[$vs_c])) { $vs_elNature_0103 = $vs_c; break; } }
            $vs_elCategDoc_0103 = '';
            foreach ($va_codesCategDoc_0103 as $vs_c) { if (isset($va_elsTbl_0103[$vs_c])) { $vs_elCategDoc_0103 = $vs_c; break; } }

            // Libellés des éléments de type liste (le `referentiel` aujourd'hui ; nature et
            // catégorie s'ils sont créés en liste) : chargés UNE fois, consultés en mémoire —
            // même procédé que l'arbre des matériaux ci-dessus.
            $va_itemsTbl_0103 = []; // item_id => libellé imprimable
            $va_listsTbl_0103 = [];
            foreach (array_merge(['referentiel'], $va_codesNature_0103, $va_codesCategDoc_0103) as $vs_c) {
                if (isset($va_elsTbl_0103[$vs_c]) && $va_elsTbl_0103[$vs_c]['datatype'] === 3 && $va_elsTbl_0103[$vs_c]['list']) {
                    $va_listsTbl_0103[] = $va_elsTbl_0103[$vs_c]['list'];
                }
            }
            if (sizeof($va_listsTbl_0103)) {
                $qr_itt = $o_data->query("SELECT i.item_id,
                        COALESCE(NULLIF(TRIM(l.name_singular), ''), NULLIF(TRIM(l.name_plural), ''), i.idno) AS nom
                    FROM ca_list_items i
                    LEFT JOIN ca_list_item_labels l ON l.item_id = i.item_id AND l.is_preferred = 1
                    WHERE i.list_id IN (".join(',', array_unique($va_listsTbl_0103)).") AND i.deleted = 0");
                while($qr_itt->nextRow()) {
                    $va_itemsTbl_0103[(int)$qr_itt->get('item_id')] = $vs_pourImpression_0103($qr_itt->get('nom'));
                }
            }

            // Toutes les valeurs utiles d'un lot de contenants, en UNE requête par liste —
            // même contrainte de volume que la remontée matière (1 932 contenants sur 312
            // pages pour le versement 3518). Le filtre porte sur v.element_id : les cotes
            // sont des SOUS-ÉLÉMENTS des conteneurs dimensions/dimensions_cm, alors que
            // a.element_id est celui du conteneur racine.
            $va_valeursTbl_0103 = function($pa_object_ids) use ($o_data, &$va_elsTbl_0103, &$va_itemsTbl_0103, $vs_pourImpression_0103) {
                $va_out = [];
                if (!sizeof($va_elsTbl_0103) || !sizeof($pa_object_ids)) { return $va_out; }
                $va_id2code = [];
                foreach ($va_elsTbl_0103 as $vs_c => $va_e) { $va_id2code[$va_e['id']] = $vs_c; }
                $qr = $o_data->query("SELECT a.row_id, v.element_id, v.value_longtext1, v.value_decimal1, v.item_id
                    FROM ca_attributes a
                    JOIN ca_attribute_values v ON v.attribute_id = a.attribute_id
                     AND v.element_id IN (".join(',', array_keys($va_id2code)).")
                    WHERE a.table_num = 57 AND a.row_id IN (".join(',', array_map('intval', $pa_object_ids)).")
                    ORDER BY a.row_id, a.attribute_id");
                while($qr->nextRow()) {
                    $vn_o = (int)$qr->get('row_id');
                    $vn_e = (int)$qr->get('element_id');
                    if (!isset($va_id2code[$vn_e])) { continue; }
                    $vs_code = $va_id2code[$vn_e];
                    $vn_item = (int)$qr->get('item_id');
                    $vm_d    = $qr->get('value_decimal1');
                    if ($vn_item) {
                        // valeur de liste : libellé imprimable, multi-valeurs dédoublonnées
                        $vs_v = isset($va_itemsTbl_0103[$vn_item]) ? $va_itemsTbl_0103[$vn_item] : '';
                        if ($vs_v === '') { continue; }
                        if (!isset($va_out[$vn_o][$vs_code]) || !is_array($va_out[$vn_o][$vs_code])) { $va_out[$vn_o][$vs_code] = []; }
                        if (!in_array($vs_v, $va_out[$vn_o][$vs_code])) { $va_out[$vn_o][$vs_code][] = $vs_v; }
                    } elseif ($vm_d !== null && $vm_d !== '') {
                        // mesure (longueur en mètres, poids en kg) : PREMIÈRE valeur retenue
                        if (!isset($va_out[$vn_o][$vs_code])) { $va_out[$vn_o][$vs_code] = (float)$vm_d; }
                    } else {
                        // texte libre (forme attendue de `nature_prelevement`)
                        $vs_v = $vs_pourImpression_0103($qr->get('value_longtext1'));
                        if ($vs_v === '') { continue; }
                        if (!isset($va_out[$vn_o][$vs_code]) || !is_array($va_out[$vn_o][$vs_code])) { $va_out[$vn_o][$vs_code] = []; }
                        if (!in_array($vs_v, $va_out[$vn_o][$vs_code])) { $va_out[$vn_o][$vs_code][] = $vs_v; }
                    }
                }
                return $va_out;
            };

            // Cote en cm (value_decimal1 est en mètres), sans zéros de queue.
            $vs_cm_0103 = function($pm_m) {
                return rtrim(rtrim(number_format((float)$pm_m * 100, 2, '.', ''), '0'), '.');
            };
            // « Type-taille » d'un contenant, selon l'ordre de priorité documenté en tête de bloc.
            $vs_tailleTbl_0103 = function($va_v) use ($vs_cm_0103) {
                if (!empty($va_v['referentiel'])) { return join(', ', $va_v['referentiel']); }
                foreach ([
                    ['dimensions_depth_cm', 'dimensions_width_cm', 'dimensions_height_cm'],
                    ['dimensions_depth', 'dimensions_width', 'dimensions_height'],
                ] as $va_bloc) {
                    $va_lettres = ['L', 'l', 'h'];
                    $va_pres = []; $vb_complet = true;
                    foreach ($va_bloc as $vn_i => $vs_code) {
                        if (isset($va_v[$vs_code])) { $va_pres[$vn_i] = $vs_cm_0103($va_v[$vs_code]); }
                        else { $vb_complet = false; }
                    }
                    if (!sizeof($va_pres)) { continue; }
                    if ($vb_complet) { return join('x', $va_pres).' cm'; }
                    $va_p = [];
                    foreach ($va_pres as $vn_i => $vs_val) { $va_p[] = $va_lettres[$vn_i].' '.$vs_val; }
                    return join(' x ', $va_p).' cm';
                }
                return '';
            };
            // « Poids (kg) » d'un contenant — toujours indiqué dès qu'il est saisi (choix INRAP
            // du 12/08/2026, par dérogation à la règle « > 12 kg » du référentiel national).
            $vs_poidsTbl_0103 = function($va_v) {
                $vm = null;
                if (isset($va_v['dimensions_poids_cm2']))  { $vm = $va_v['dimensions_poids_cm2']; }
                elseif (isset($va_v['dimensions_poids'])) { $vm = $va_v['dimensions_poids']; }
                if ($vm === null) { return ''; }
                return rtrim(rtrim(number_format((float)$vm, 5, '.', ''), '0'), '.');
            };

            // Ligne texte historique « idno / complément » : conservée POUR LA VUE HTML
            // (modele4_html.php imprime les colonnes [42..44] telles quelles). Logique
            // STRICTEMENT identique à celle qu'elle remplace dans $vs_getContenants.
            $vs_ligneContenant_0103 = function($va_r) {
                // Le séparateur « / » n'est posé que si les DEUX parties existent : un
                // contenant sans matière (le cas majoritaire en base, cf. compte rendu)
                // sort « 2212612-4 » et non « 2212612-4 / » ni « 2212612-4 / - ».
                $vs_l = $va_r['idno']; $vs_c = $va_r['comp'];
                if ($vs_l !== '' && $vs_c !== '') { $vs_l .= ' / '.$vs_c; }
                elseif ($vs_l === '')             { $vs_l = $vs_c; }
                // Contenant sans idno ET sans complément (117 contenants en base n'ont pas
                // d'idno) : on retombe sur le libellé préféré plutôt que d'omettre la ligne,
                // sinon la liste compterait moins d'entrées que le compteur affiché au-dessus.
                if ($vs_l === '') { $vs_l = $va_r['nom']; }
                return $vs_l;
            };
            $vs_lignesTexte_0103 = function($va_rows) use ($vs_ligneContenant_0103) {
                $va_l = [];
                foreach ($va_rows as $va_r) {
                    $vs_l = $vs_ligneContenant_0103($va_r);
                    if ($vs_l !== '') { $va_l[] = '- '.$vs_l; }
                }
                return join('sautdeligne', $va_l);
            };

            // Données d'UN tableau Word : lignes + drapeaux de présence des colonnes
            // facultatives, appréciés PAR LISTE (une colonne n'apparaît que si au moins un
            // contenant de la liste porte la valeur — règle demandée pour les colonnes
            // futures, appliquée aussi à taille/poids des familles doc/num). La composition
            // du XML est dans la vue (modele4_docx.php) ; ici, uniquement des données déjà
            // nettoyées par $vs_pourImpression_0103.
            $va_tableauContenants_0103 = function($va_rows, $ps_famille, $ps_oa) use ($va_valeursTbl_0103, $vs_tailleTbl_0103, $vs_poidsTbl_0103, $vs_elNature_0103, $vs_elCategDoc_0103) {
                $va_t = ['contenants_0103' => 1, 'fam' => $ps_famille, 'oa' => (string)$ps_oa,
                         'rows' => [], 'has' => ['taille' => false, 'poids' => false, 'nature' => false, 'categ' => false]];
                if (!sizeof($va_rows)) { return $va_t; }
                $va_ids = [];
                foreach ($va_rows as $va_r) { $va_ids[] = (int)$va_r['object_id']; }
                $va_vals = $va_valeursTbl_0103($va_ids);
                foreach ($va_rows as $va_r) {
                    $va_v = isset($va_vals[(int)$va_r['object_id']]) ? $va_vals[(int)$va_r['object_id']] : [];
                    $vs_taille = $vs_tailleTbl_0103($va_v);
                    $vs_poids  = $vs_poidsTbl_0103($va_v);
                    $vs_nature = ($vs_elNature_0103 !== ''   && !empty($va_v[$vs_elNature_0103])   && is_array($va_v[$vs_elNature_0103]))   ? join(', ', $va_v[$vs_elNature_0103])   : '';
                    $vs_categ  = ($vs_elCategDoc_0103 !== '' && !empty($va_v[$vs_elCategDoc_0103]) && is_array($va_v[$vs_elCategDoc_0103])) ? join(', ', $va_v[$vs_elCategDoc_0103]) : '';
                    if ($vs_taille !== '') { $va_t['has']['taille'] = true; }
                    if ($vs_poids  !== '') { $va_t['has']['poids']  = true; }
                    if ($vs_nature !== '') { $va_t['has']['nature'] = true; }
                    if ($vs_categ  !== '') { $va_t['has']['categ']  = true; }
                    $va_t['rows'][] = [
                        'idno'   => $va_r['idno'],
                        'ref'    => $va_r['nom'],   // « Référence » du CDC d'origine (doc/num)
                        'taille' => $vs_taille,
                        'poids'  => $vs_poids,
                        'mat'    => ($ps_famille === 'mob') ? $va_r['comp'] : '',
                        'nature' => $vs_nature,
                        'categ'  => $vs_categ,
                    ];
                }
                return $va_t;
            };
            // === D2026-0103 §4 — évolution 12/08/2026, listes de contenants en tableau : fin ===

            // Compteurs restreints, calculés pour UNE opération liée : intersection
            // « contenants du versement » ∩ « contenants de l'opération ».
            // Les agrégats reprennent à l'identique les formules de prepopulateInrap qui
            // produisent les métadonnées d'opération correspondantes, de sorte qu'un versement
            // portant TOUS les contenants d'une opération retrouve exactement le chiffre
            // d'avant (contrôle fait sur les versements 8515, 15821, 6045) :
            //   · volume m3        : SUM(value_decimal1) de `volume_caisse` (élément 435, table 57)
            //   · volume numérique : SUM(value_decimal1) de `inrap_volume_num_mo` (élément 749,
            //                        stocké en octets) / 1024000 → Mo
            //   · poids kg         : SUM(value_decimal1) de `dimensions_poids` (élément 99),
            //                        seule mesure de poids portée par un contenant. Le §4 note
            //                        lui-même « pas de nom car inclus dans une demande non
            //                        traitée » : la métadonnée d'opération `poids_bam_ope` est
            //                        déclarative et ne peut pas être restreinte. À confirmer.
            // === D2026-0103 §4 — repli sur l'opération versée : début ===
            // `$pb_repli` vrai (versement sans aucun contenant rattaché + opération versée) :
            // la population devient l'ENSEMBLE des contenants de l'opération. Les agrégats sont
            // recalculés sur cette population, et non relus dans les métadonnées d'opération :
            // c'est la même population, mais sommée en direct — donc jamais périmée, et cohérente
            // au chiffre près avec les trois listes imprimées juste en dessous (le seul écart
            // possible avec la métadonnée est l'arrondi : 4 décimales ici, 3 dans prepopulateInrap).
            $vs_compteursVerses_0103 = function($pn_collection_id, $pb_repli = false) use ($o_data, $vn_mvt_id, $va_types_mob, $va_types_doc, $va_types_num, $va_types_verses_0103) {
                $va_c = ['nb_mob'=>0, 'nb_doc'=>0, 'nb_num'=>0, 'vol_mob'=>null, 'poids_mob'=>null, 'octets_num'=>null];
                // DISTINCT : ca_objects_x_collections peut porter plusieurs lignes pour un même
                // couple objet/opération ; sans lui, volumes et poids seraient comptés deux fois.
                $vs_sel = $pb_repli
                    ? "SELECT DISTINCT o.object_id, o.type_id
                    FROM ca_objects o
                    JOIN ca_objects_x_collections oc ON oc.object_id = o.object_id
                     AND oc.collection_id = ".(int)$pn_collection_id."
                    WHERE o.deleted = 0 AND o.type_id IN (".join(',', $va_types_verses_0103).")"
                    : "SELECT DISTINCT o.object_id, o.type_id
                    FROM ca_movements_x_objects mo
                    JOIN ca_objects o ON o.object_id = mo.object_id AND o.deleted = 0
                     AND o.type_id IN (".join(',', $va_types_verses_0103).")
                    JOIN ca_objects_x_collections oc ON oc.object_id = o.object_id
                     AND oc.collection_id = ".(int)$pn_collection_id."
                    WHERE mo.movement_id = ".(int)$vn_mvt_id;
            // === D2026-0103 §4 : fin ===

                $qr = $o_data->query("SELECT s.type_id, COUNT(*) AS nb FROM (".$vs_sel.") s GROUP BY s.type_id");
                while($qr->nextRow()) {
                    $vn_t = (int)$qr->get('type_id'); $vn_n = (int)$qr->get('nb');
                    if (in_array($vn_t, $va_types_mob))      { $va_c['nb_mob'] += $vn_n; }
                    elseif (in_array($vn_t, $va_types_doc))  { $va_c['nb_doc'] += $vn_n; }
                    elseif (in_array($vn_t, $va_types_num))  { $va_c['nb_num'] += $vn_n; }
                }

                $qr = $o_data->query("SELECT s.type_id, v.element_id, SUM(v.value_decimal1) AS somme
                    FROM (".$vs_sel.") s
                    JOIN ca_attributes a ON a.table_num = 57 AND a.row_id = s.object_id
                    JOIN ca_attribute_values v ON v.attribute_id = a.attribute_id
                     AND v.element_id IN (435, 99, 749)
                    GROUP BY s.type_id, v.element_id");
                while($qr->nextRow()) {
                    $vn_t = (int)$qr->get('type_id'); $vn_e = (int)$qr->get('element_id');
                    $vm = $qr->get('somme');
                    if ($vm === null || $vm === '') { continue; }
                    // null conservé tant qu'aucune mesure n'existe : « non mesuré » ne doit pas
                    // s'imprimer « 0 » sur un acte de décharge.
                    if ($vn_e == 435 && in_array($vn_t, $va_types_mob)) { $va_c['vol_mob']    = (float)$va_c['vol_mob']    + (float)$vm; }
                    if ($vn_e == 99  && in_array($vn_t, $va_types_mob)) { $va_c['poids_mob']  = (float)$va_c['poids_mob']  + (float)$vm; }
                    if ($vn_e == 749 && in_array($vn_t, $va_types_num)) { $va_c['octets_num'] = (float)$va_c['octets_num'] + (float)$vm; }
                }
                return $va_c;
            };
            // === D2026-0103 §4 : fin ===

            // === D2026-0103 §4 — mapping des métadonnées : début ===
            // Le tableau du §4 de l'expression de besoin 20260622 associe à chaque champ du
            // bordereau un « Nom métadonnée Comodo ». Il est appliqué tel quel PARTOUT où il est
            // cohérent. TROIS correspondances ne le sont pas et sont documentées ici plutôt
            // qu'appliquées en silence : le chiffre imprimé sur un acte de décharge signé par le
            // SRA engage l'Inrap, on ne le change pas sans trace. Chacune est à confirmer par le
            // client ; l'écart mesuré sur le parc figure dans LOT9_compte_rendu_dev.md.
            //
            //  (1) « Bien archéologique mobilier – Contenant(s) »
            //      §4 : « Nb total de contenants (automatique) (ca_collections.inrap_nb_total_contenants) »
            //      RETENU : le comptage restreint aux contenants MOBILIER versés (lot précédent).
            //      Pourquoi la métadonnée nommée ne peut pas convenir — trois raisons vérifiées
            //      en base ce jour, aucune supposée :
            //        a. inrap_nb_total_contenants est produit par prepopulateInrapPlugin.php
            //           avec la condition « type_id = 30 OR type_id = 28 », soit contenants
            //           DOCUMENTAIRES + contenants MOBILIER confondus. La ligne du masque dit
            //           « Bien archéologique MOBILIER ». La ligne « Documentation scientifique –
            //           Contenant(s) », deux lignes plus bas, compte déjà les contenants
            //           documentaires : 6 030 couples (versement, opération) sur 10 386 verraient
            //           de la documentation comptée DEUX FOIS sur le même bordereau.
            //        b. la même condition ignore les types « versé » (39988 contenant mobilier
            //           versé, 39987 contenant doc versé). Un contenant bascule en « versé »
            //           précisément au moment du versement : la métadonnée retombe alors à zéro.
            //           643 couples imprimeraient 0 contenant mobilier alors que le versement en
            //           porte au moins un, et la liste juste en dessous les énumérerait.
            //           Cas extrême : versement 1052 / opération 21987 → 328 contenants mobilier
            //           réellement versés, inrap_nb_total_contenants = 3.
            //        c. c'est une métadonnée de l'OPÉRATION ENTIÈRE. Or la ligne du §4 porte
            //           elle-même la remarque « Fiche opération liée/bordereau de versement.
            //           Attention que ceux sélectionnés, ». Le document se contredirait.
            //      Effet mesuré si la lettre du §4 était appliquée : 7 483 des 10 386 pages
            //      changeraient de valeur (72 %), amplitude -605 à +1 072.
            //
            //  (2) « Bien archéologique mobilier – Poids en kg »
            //      §4 : « Attention pas de nom car inclus dans une demande non traitrée ».
            //      Le document ne nomme donc AUCUNE métadonnée. RETENU : la somme de
            //      `dimensions_poids` (élt 99) sur les contenants mobilier versés, posée au lot
            //      précédent. La seule autre candidate, `poids_bam_ope` (élt 738), porte UNE
            //      valeur dans toute la base (« 0,73 g », opération 59164, hors de tout
            //      versement) : elle imprimerait vide sur 10 386 pages sur 10 386.
            //      Couverture réelle de la source retenue : 37 pages sur 10 386 (0,36 %).
            //      La valeur est en kilogrammes (value_decimal1 des attributs de type poids est
            //      normalisé en kg par CollectiveAccess), ce qui correspond à l'intitulé.
            //
            //  (3) « Documentation numérique – Volume Mo »
            //      §4 : « Documentation scientifique - poids (ca_collections.docsci_ope_poids) ».
            //      RETENU : la somme de `inrap_volume_num_mo` (élt 749) sur les contenants
            //      NUMÉRIQUES versés, convertie en Mo (lot précédent).
            //      Pourquoi la métadonnée nommée ne peut pas convenir :
            //        · `docsci_ope_poids` est de datatype 9 (POIDS) : sa valeur s'imprime avec
            //          son unité de masse (« 0,73 g », « 12 kg »). La imprimer dans une case
            //          intitulée « Volume Mo » donnerait un poids sous un en-tête de volume.
            //        · elle décrit la documentation SCIENTIFIQUE (papier), pas la documentation
            //          NUMÉRIQUE, qui est l'objet de la ligne.
            //        · elle est vide sur la totalité de la base : 255 attributs, zéro valeur.
            //      Effet sur le parc, dans un sens comme dans l'autre : NUL aujourd'hui. Les deux
            //      sources sont vides sur les 10 386 pages (`inrap_volume_num_mo` n'existe que sur
            //      un seul contenant de la base, rattaché à aucun versement). L'arbitrage ne porte
            //      donc que sur ce qui s'imprimera quand la donnée sera saisie.
            //
            // Les correspondances du §4 jugées cohérentes sont, elles, appliquées : « Lieu de
            // === D2026-0103 §4 — exigence #19, département des arrondissements : début ===
            // Table des DÉPARTEMENTS du référentiel géographique, chargée une seule fois
            // (102 lignes) et consultée en mémoire dans la boucle : le calcul du département
            // ne coûte aucune requête supplémentaire par page.
            //
            // Le type « département » est résolu PAR SON CODE (`ca_list_items.idno` = 'dept'
            // dans la liste `place_types`) et jamais par un identifiant en dur : sur une autre
            // instance, le type_id 104 constaté ici pourrait en désigner un autre.
            // Si le type est introuvable, le tableau reste vide et le calcul historique
            // s'applique tel quel — le bordereau ne se dégrade pas.
            $va_departements_0103 = [];
            $qr_dep_0103 = $o_data->query("SELECT p.place_id, p.idno
                FROM ca_places p
                JOIN ca_list_items li ON li.item_id = p.type_id AND li.deleted = 0
                JOIN ca_lists l ON l.list_id = li.list_id AND l.list_code = 'place_types'
                WHERE li.idno = 'dept' AND p.deleted = 0");
            while($qr_dep_0103->nextRow()) {
                $va_departements_0103[(int)$qr_dep_0103->get('place_id')] = (string)$qr_dep_0103->get('idno');
            }
            // === D2026-0103 §4 : fin ===

            // Versement » et les deux « Nom pour l'affichage (displayname) » ci-dessous.
            // === D2026-0103 §4 : fin ===

            $i=0;
            while($qr_result->nextRow()) {
            // === D2026-0103 §4 — édition des versements à nombreuses opérations : début ===
            // Exigence #43. Le bordereau produit une page par opération liée ; la boucle
            // ci-dessous tourne donc 312 fois pour le versement 3518. Tout ce qui ne dépend
            // que du VERSEMENT y était refait à chaque tour. Le raisonnement complet et les
            // mesures sont en tête de 0103_edition_grands_versements.php.
            //
            // Rien de ce qui dépend de l'OPÉRATION n'est mis en cache : les compteurs
            // restreints et les trois listes de contenants doivent être recalculés à chaque
            // page, sous peine de changer le document.
            $movement = null;
            $va_cache_mvt_0103 = [];
            // (b) gabarits de niveau VERSEMENT : même objet, même gabarit, même résultat.
            $vs_mvt_0103 = function($ps_tpl) use (&$movement, &$va_cache_mvt_0103) {
                if (!$movement) { return ''; }
                if (!array_key_exists($ps_tpl, $va_cache_mvt_0103)) {
                    $va_cache_mvt_0103[$ps_tpl] = $movement->getWithTemplate($ps_tpl);
                }
                return $va_cache_mvt_0103[$ps_tpl];
            };
            // (c) entités du répertoire (Direction Inrap, SRA) : une instance par entity_id.
            $va_cache_ent_0103 = [];
            $vt_entite_0103 = function($pn_entity_id) use (&$va_cache_ent_0103) {
                $vn_id = (int)$pn_entity_id;
                if (!isset($va_cache_ent_0103[$vn_id])) { $va_cache_ent_0103[$vn_id] = new ca_entities($vn_id); }
                return $va_cache_ent_0103[$vn_id];
            };
            // === D2026-0103 §4 — édition des versements à nombreuses opérations : fin ===
	            $i++;
	            // Chargement de la collection cible
				$collection = new ca_collections($qr_result->get('collection_id'));

				// Filtrer sur le type d'opération, si pas bon, on passe
				$type_ope = $collection->get("ca_collections.inrap_type_op.inrap_type_ope", ["convertCodesToDisplayText"=>1]);
	            //if(!$type_ope) continue;
				
				// Filtrer sur le statut, si pas bon, on passe
				$statut_coll = $collection->get("ca_collections.statut_collection", ["convertCodesToDisplayText"=>1]);

				// === D2026-0103 §4 — édition des versements à nombreuses opérations : début ===
				// (a) le versement est le MÊME sur toutes les lignes (la requête filtre sur
				// un movement_id unique) : il était rechargé une fois par opération, soit
				// 312 fois pour le versement 3518. La garde est conservée pour rester juste
				// si la requête d'alimentation venait à changer.
				if (!$movement || (int)$movement->getPrimaryKey() !== (int)$qr_result->get('movement_id')) {
					$movement = new ca_movements($qr_result->get('movement_id'));
					$va_cache_mvt_0103 = [];
				}
				// === D2026-0103 §4 — édition des versements à nombreuses opérations : fin ===

				// Récupération des valeurs difficiles d'accès
				$dept = $collection->getWithTemplate("^ca_places.hierarchy.idno%maxLevelsFromBottom=3&delimiter=;");
				$region = $collection->getWithTemplate("^ca_places.hierarchy.preferred_labels.name%maxLevelsFromTop=2&delimiter=_➜_");
				if ($region == "Anciennes communes"){
					$commune = $collection->getWithTemplate("^ca_places.idno");
					//Deux premiers caractères de commune
					$dept = substr($commune, 0, 2);
					$vt_dep = new ca_places();
					$vt_dep->load(["idno" => $dept, "deleted" => 0]);
					if ($vt_dep->getPrimaryKey()){
						$region = $vt_dep->getWithTemplate("^ca_places.hierarchy.preferred_labels.name%maxLevelsFromTop=1&delimiter=_➜_");
					}
				}
				// ... on a ici 63;BEAUMONT ne garder que la partie avant le ;
				$dept = reset(explode(";", $dept));
				$dept = str_replace("dep","",$dept);

				// === D2026-0103 §4 — exigence #19, département des arrondissements : début ===
				// RÉSERVE R14. La case « Département » de la page 2 imprimait le code INSEE de la
				// commune sur 90 pages du parc : Lyon 5 → « 69123 » au lieu de « 69 ».
				//
				// CAUSE, établie en base et non supposée. Le calcul ci-dessus prend l'avant-dernier
				// échelon de la hiérarchie du lieu (`maxLevelsFromBottom=3` puis `reset()`), c'est-à-dire
				// LE PARENT DU LIEU DE L'OPÉRATION. Cela suppose que le parent d'une commune est
				// toujours un département. Le référentiel `ca_places` ne le garantit pas :
				//   · Lyon 5      : Auvergne-rhône-alpes > Rhône-alpes > Rhône(69) > Lyon(69123) > Lyon 5
				//                   — le parent est la COMMUNE-MÈRE, d'où « 69123 » ;
				//   · Marseille Ne : même construction sous Marseille(13055) ;
				//   · Paris Ne     : les arrondissements parisiens sont, EUX, rattachés directement au
				//                   département 75 — ils étaient donc déjà justes ;
				//   · opération rattachée à un département et non à une commune (6 collections) :
				//                   le parent est la RÉGION, d'où « region21 », « region11 ».
				// La profondeur varie par ailleurs d'une région à l'autre (région fusionnée >
				// ancienne région > département) : compter les échelons ne peut pas être juste.
				//
				// CORRECTIF. On ne compte plus les échelons : on cherche dans la chaîne ascendante
				// du lieu le premier élément qui EST un département, et on imprime son `idno`.
				// `^ca_places.hierarchy.place_id` rend la même chaîne, dans le même ordre et avec le
				// même délimiteur, que le `^ca_places.hierarchy.idno` utilisé ci-dessus : pour une
				// opération rattachée à plusieurs communes, c'est donc le département de la PREMIÈRE
				// qui est retenu, exactement comme le faisait le `reset()` historique.
				//
				// Pourquoi pas les deux premiers caractères du code INSEE : la règle demanderait
				// trois exceptions (Corse « 2A »/« 2B », outre-mer à trois chiffres « 971 »…« 976 »,
				// et Saint-Pierre-et-Miquelon dont le référentiel de cette instance porte le code
				// non standard « 97502 »), et elle inventerait un code au lieu de restituer celui du
				// référentiel. Lire l'`idno` du département rend la valeur exacte dans tous les cas,
				// sans cas particulier.
				//
				// Repli : si aucun département n'est trouvé dans la chaîne (opération sans lieu,
				// « Anciennes communes », commune 97501 rattachée directement à sa région), la
				// valeur historique est conservée telle quelle — y compris celle que produit la
				// branche « Anciennes communes » ci-dessus. Aucune page aujourd'hui juste ne change.
				if (sizeof($va_departements_0103)) {
					foreach (explode(";", (string)$collection->getWithTemplate("^ca_places.hierarchy.place_id%delimiter=;")) as $vs_pid_0103) {
						$vn_pid_0103 = (int)$vs_pid_0103;
						if ($vn_pid_0103 && isset($va_departements_0103[$vn_pid_0103])) {
							$dept = $va_departements_0103[$vn_pid_0103];
							break;
						}
					}
				}
				// === D2026-0103 §4 : fin ===

				//Filtrage par DIR
				// === D2026-0103 §4 — édition des versements à nombreuses opérations : début ===
				// (d) valeur JAMAIS utilisée dans Modele4() : son seul consommateur, le
				// filtrage par groupe ci-dessous, est commenté depuis avant ce devis.
				// La calculer coûtait une traversée de relation vers le répertoire PAR
				// OPÉRATION. Décommenter le filtrage suppose de rétablir la ligne d'origine :
				// $DIR = $collection->getWithTemplate("<unit relativeTo='ca_entities' restrictToTypes='DIR'>^ca_entities.preferred_labels.displayname</unit>");
				$DIR = '';
				// === D2026-0103 §4 — édition des versements à nombreuses opérations : fin ===
				/*if(!in_array($DIR, $this->ops_user_groups)) {
					continue;
					//die("Argh, dying dying");
				}*/

				// === D2026-0103 §4 — compteurs restreints aux contenants versés : début ===
				// Le comptage des contenants numériques de l'OPÉRATION est supprimé : il est
				// remplacé plus bas par le comptage restreint aux contenants numériques versés.
				$nb_contenant_num = 0;
				// === D2026-0103 §4 : fin ===


				$allContenants = $collection->getWithTemplate("<unit relativeTo='ca_objects' delimiter='sautdeligne' restrictToTypes='numerique, contenant_num_verse, contenant, contenant_mob_verse, contenant_intermediaire, contenant_doc_verse'>- ^ca_objects.preferred_labels</unit>");

				// --- M0103-B (036SE2025) : somme structurée du volume numérique (Mo) ---
				// === D2026-0103 §4 — compteurs restreints aux contenants versés : début ===
				// La somme portait sur les contenants numériques de l'OPÉRATION ; elle est
				// recalculée plus bas sur les seuls contenants numériques versés.
				// Sept compteurs, une seule population : les contenants rattachés à CE versement
				// et à CETTE opération. Le détail du calcul et la justification des types retenus
				// figurent en tête de Modele4().
				// === D2026-0103 §4 — repli sur l'opération versée : début ===
				// Le repli s'apprécie ICI, dans la boucle sur les opérations liées : une page par
				// opération, donc un verdict par opération. Un versement mêlant une opération
				// versée et une non versée sort une page renseignée et une page à zéro.
				$vb_repli_0103 = $vb_repliOperationVersee_0103($qr_result->get('collection_id'));
				$va_cpt_0103 = $vs_compteursVerses_0103($qr_result->get('collection_id'), $vb_repli_0103);
				// === D2026-0103 §4 : fin ===

				// === D2026-0103 §4 — « BAM Contenant(s) » : métadonnée du §4, restreinte : début ===
				// Exigence #30. Le §4 désigne pour cette case la métadonnée
				// `ca_collections.inrap_nb_total_contenants` — « Nb total de contenants » —
				// et ajoute sur la même ligne « attention que ceux sélectionnés ».
				//
				// Cette métadonnée est produite par prepopulateInrapPlugin.php (l. 624, 807) :
				//     count(o.object_id) where (o.type_id=30 or o.type_id=28)
				// soit les contenants MOBILIER + les contenants DOCUMENTATION de l'opération
				// ENTIÈRE. Sa transposition restreinte au périmètre du bordereau est donc la
				// somme des deux ventilations déjà calculées sur la population sélectionnée
				// (versement ∩ opération), repli compris : nb_mob + nb_doc.
				//
				// Les sous-types « versé » (contenant mobilier versé, contenant doc versé)
				// sont INCLUS, alors que la métadonnée d'origine les ignore. Sans cette
				// réparation, 3 984 pages du parc imprimeraient « 0 » au-dessus d'une liste
				// de contenants non vide (jusqu'à 328 lignes) : un contenant bascule en
				// « versé » au moment même du versement, c'est-à-dire sur les fiches que ce
				// bordereau imprime. Ce serait le défaut central (#37) réintroduit.
				// Avec elle, sur 10 384 pages sur 10 384, le compteur est exactement égal à
				// « Liste contenant mobilier » + « liste contenant documentation » imprimées
				// au bas de la même page : le lecteur peut le vérifier sur le document.
				//
				// CONTREPARTIE ASSUMÉE (décision client, « conformité à la lettre, quitte à
				// corriger ensuite ») : sur 4 797 pages cette case dépasse la seule liste
				// mobilier de la longueur de la liste documentation, laquelle est donc
				// comptée aussi dans « Documentation scientifique / Contenant(s) ».
				// La correction propre relève du §4 lui-même (désigner `op_nb_contenants`).
				//
				// `$vn_ba_cont_0103` reste le comptage MOBILIER STRICT : il n'imprime plus,
				// mais il gouverne l'arbre de décision de « Hors contenant(s) » (#31), dont
				// la branche « famille mobilier vide » perdrait son sens sans lui.
				$vn_ba_cont_0103      = (int)$va_cpt_0103['nb_mob'];
				$vn_ba_cont_meta_0103 = (int)$va_cpt_0103['nb_mob'] + (int)$va_cpt_0103['nb_doc'];
				// === D2026-0103 §4 — « BAM Contenant(s) » : métadonnée du §4, restreinte : fin ===

				// [8] BAM Hors contenant(s) — `op_nb_hors_contenants` est une saisie déclarative
				// de l'opération (59 valeurs réellement renseignées en base) : un bien « hors
				// contenant » n'étant par construction pas un contenant, il n'est pas sélectionnable
				// dans le bloc « Contenants versés » et n'a aucun équivalent par contenant. Il ne
				// peut donc pas être restreint. Il est neutralisé dès que la famille mobilier du
				// versement est vide, pour respecter la règle « les compteurs des autres familles
				// sont à zéro » ; sinon la valeur déclarée de l'opération est reprise, faute de
				// mieux. Point signalé au client (il faudrait soit un bloc de sélection dédié,
				// soit une saisie portée par le versement).
				// === D2026-0103 §4 — repli sur l'opération versée : début ===
				// Sous repli, l'opération est versée dans son intégralité : la valeur déclarée de
				// l'opération est alors EXACTE, y compris quand l'opération ne porte aucun
				// contenant mobilier (des biens hors contenant sans caisse, c'est le cas normal).
				// Elle est donc reprise sans neutralisation.
				// === D2026-0103 §4 — « Hors contenant(s) » : sélection partielle : début ===
				// Trois situations, une seule écriture chacune. Le raisonnement complet est en
				// tête de 0103_bam_hors_contenants.php ; en résumé : `op_nb_hors_contenants` est
				// une saisie déclarative portée par l'OPÉRATION, sans aucune contrepartie par
				// contenant — aucun bien hors contenant n'est rattachable à un versement — donc
				// impossible à restreindre au périmètre du bordereau.
				$vs_ba_hcont_decl_0103 = $collection->getWithTemplate("^ca_collections.op_nb_hors_contenants");
				if ($vb_repli_0103) {
					// (1) Opération versée en totalité : la déclaration DÉCRIT le périmètre du
					// bordereau. On la reprend — c'est le seul cas où elle est exacte.
					$vs_ba_hcont_0103 = $vs_ba_hcont_decl_0103;
				} elseif (!$vn_ba_cont_0103) {
					// (2) Aucun contenant mobilier de cette opération dans ce versement : la
					// famille mobilier est vide, le compteur suit ses voisins et vaut zéro.
					$vs_ba_hcont_0103 = '0';
				} elseif (((int)$vs_ba_hcont_decl_0103) === 0) {
					// (3a) Déclaration absente ou nulle : rien à restreindre, rien à taire.
					// Cette branche laisse le document RIGOUREUSEMENT inchangé sur tout le parc
					// existant — c'est elle qui garantit la non-régression.
					$vs_ba_hcont_0103 = $vs_ba_hcont_decl_0103;
				} else {
					// (3b) SÉLECTION PARTIELLE — le versement ne porte qu'une partie des
					// contenants mobilier de l'opération, et l'opération déclare des biens hors
					// contenant. La valeur déclarée porte sur l'OPÉRATION ENTIÈRE : l'imprimer
					// ici affirmerait un nombre qui n'est pas celui du bordereau, et le
					// réimprimerait à l'identique sur chaque versement partiel de la même
					// opération. Imprimer « 0 » serait tout aussi faux. On n'affirme rien.
					// C'est le point d'arbitrage remonté au client (§4, exigence #31).
					$vs_ba_hcont_0103 = '';
				}
				// === D2026-0103 §4 — « Hors contenant(s) » : sélection partielle : fin ===
				// === D2026-0103 §4 : fin ===

				// [9] BAM Volume m3 — somme de `volume_caisse` sur les contenants mobilier versés.
				// Arrondi à 4 décimales : convention déjà en place sur ce champ.
				$vn_ba_vol_0103 = ($va_cpt_0103['vol_mob'] === null) ? '0' : round((float)$va_cpt_0103['vol_mob'], 4);

				// [38] BAM Poids en kg — somme de `dimensions_poids` sur les mêmes contenants.
				$vs_poids_bam_0103 = ($va_cpt_0103['poids_mob'] === null || (float)$va_cpt_0103['poids_mob'] <= 0)
					? '' : rtrim(rtrim(number_format((float)$va_cpt_0103['poids_mob'], 3, '.', ''), '0'), '.').' kg';

				// [11] Documentation scientifique Contenant(s) — restriction de op_doc_sci_contenants.
				$vn_doc_cont_0103 = (int)$va_cpt_0103['nb_doc'];

				// [12] et [13] — « hors contenant » et métrage linéaire de la documentation ne sont
				// pas imprimés sur le masque (aucune réserve ${...} correspondante) mais alimentent
				// le tableau HTML intermédiaire ; ils sont neutralisés avec leur famille, pour que
				// cet écran ne contredise pas le document.
				// === D2026-0103 §4 — repli sur l'opération versée : début ===
				// Même raisonnement que pour [8] : sous repli, la déclaration de l'opération est
				// exacte et n'est pas neutralisée.
				$vs_doc_hcont_0103 = ($vb_repli_0103 || $vn_doc_cont_0103) ? $collection->getWithTemplate("^ca_collections.op_doc_sci_hors_contenants") : '0';
				$vs_doc_lin_0103   = ($vb_repli_0103 || $vn_doc_cont_0103) ? $collection->getWithTemplate("^ca_collections.op_doc_sci_lineaire") : '0';
				// === D2026-0103 §4 : fin ===

				// [14] Documentation numérique Contenant(s) — remplace le comptage sur l'opération.
				$nb_contenant_num = (int)$va_cpt_0103['nb_num'];

				// [45] Documentation numérique Volume Mo — somme de `inrap_volume_num_mo` (octets)
				// sur les contenants numériques versés, convertie en Mo comme le fait
				// prepopulateInrap pour `inrap_volume_num_total_mo` (division par 1024000).
				$vol_num_mo = ($va_cpt_0103['octets_num'] === null) ? 0 : ((float)$va_cpt_0103['octets_num'] / 1024000);
				$vol_num_mo = ($vol_num_mo > 0) ? rtrim(rtrim(number_format($vol_num_mo, 2, '.', ''), '0'), '.').' Mo' : '';
				// === D2026-0103 §4 : fin ===

				// === D2026-0103 §4 — signataires et adresse de la page 1 : début ===
				// §4 page 1 : « Inrap – Direction », « Acceptation et décharge – Service Régional de
				// l'Archéologie » et « [Adresse DIR] ». La source annoncée par le §4 est
				// ca_storage_locations (« A venir ») ; le client a autorisé le repli « sinon répertoire
				// en attendant ». C'est ce repli qui est posé ici.
				//
				// Ces trois informations ne sont PAS portées par le versement : ca_movements_x_entities
				// ne connaît que mover / destinataire / authorizer / conservation — aucune relation DIR.
				// Elles ne sont donc lisibles que sur l'OPÉRATION LIÉE (ca_entities_x_collections) :
				//   · DIR : relation « DIR » (type 236) vers une entité de type DIR (88)
				//   · SRA : entité de type « sra » (92), portée par la relation « attribue » (121)
				//           → restrictToTypes, et surtout PAS restrictToRelationshipTypes='sra',
				//             qui ne ramènerait rien (aucune relation ne porte ce code sur table 21).
				//
				// Le gabarit d'adresse est celui des courriers « En suspens » du D2026-0102
				// (enSuspensDonnees / $vs_adresse_tpl) : repris à l'identique, y compris le séparateur
				// « ,sautdeligne », que la vue traduit en saut de ligne OOXML. Le code du 0102 n'est
				// pas modifié : seul son procédé est réemployé.
				$vs_adresse_tpl_0103 = "^ca_entities.address.address1<ifdef code='ca_entities.address.address2'>,sautdeligne^ca_entities.address.address2</ifdef><ifdef code='ca_entities.address.postalcode|ca_entities.address.city|ca_entities.address.country'>,sautdeligne^ca_entities.address.postalcode ^ca_entities.address.city ^ca_entities.address.country</ifdef>";
				// La relation « DIR » (236) ne porte pas QUE des directions : sur l'opération 11279 elle
				// rattache aussi « CRA Bègles » (entité de type cra). Filtrer la seule relation ferait
				// sortir une direction ET une adresse composites. On restreint donc relation ET type
				// d'entité, et on ne retient que le PREMIER identifiant, en résolvant l'entité pour que
				// le nom et l'adresse proviennent du même enregistrement du répertoire. Ce mode de
				// sélection d'un unique porteur lié est celui du CRA dans enSuspensDonnees (D2026-0102).
				$va_dir_ids_0103 = array_filter(array_map('trim', explode(';', (string)$collection->getWithTemplate("<unit relativeTo='ca_entities' restrictToTypes='DIR' restrictToRelationshipTypes='DIR' delimiter=';'>^ca_entities.entity_id</unit>"))));
				$vt_dir_0103 = $vt_entite_0103((int)array_shift($va_dir_ids_0103));
				$vs_dir_nom_0103 = $vt_dir_0103->getPrimaryKey() ? (string)$vt_dir_0103->getWithTemplate("^ca_entities.preferred_labels.displayname") : '';
				$vs_dir_adr_0103 = $vt_dir_0103->getPrimaryKey() ? (string)$vt_dir_0103->getWithTemplate($vs_adresse_tpl_0103) : '';
				// SRA : le type d'entité fait foi (la relation porteuse est « attribue », pas « sra »).
				// « SRA_Grand Est » → « SRA Grand Est » : même normalisation que NOM_SRA du 0102.
				$va_sra_ids_0103 = array_filter(array_map('trim', explode(';', (string)$collection->getWithTemplate("<unit relativeTo='ca_entities' restrictToTypes='sra' delimiter=';'>^ca_entities.entity_id</unit>"))));
				$vt_sra_0103 = $vt_entite_0103((int)array_shift($va_sra_ids_0103));
				$vs_sra_svc_0103 = $vt_sra_0103->getPrimaryKey() ? str_replace('_', ' ', (string)$vt_sra_0103->getWithTemplate("^ca_entities.preferred_labels.displayname")) : '';
				// === D2026-0103 §4 : fin ===

				// === D2026-0103 §4 — mapping des métadonnées : début ===
				// « Lieu de Versement » (ligne du masque, page 2).
				// Le §5.1 nomme la source sans ambiguïté : « Emplacement d'arrivée
				// (ca_storage_locations) » → nom du champ souhaité « Lieu de versement ».
				// C'est donc une donnée du VERSEMENT, pas de l'opération.
				//
				// Ce qui existait : `^ca_collections.lieu_versement`, une métadonnée de
				// l'OPÉRATION. Deux défauts cumulés, l'un de source, l'autre de chemin :
				//   · le chemin est incomplet — `lieu_versement` (élt 626) est un SOUS-ÉLÉMENT
				//     du conteneur `resume_versement` (élt 623) ; sans le conteneur, le gabarit
				//     ne résout rien. Vérifié : la case « Lieu de Versement » sort VIDE sur la
				//     totalité des bordereaux du parc, y compris ceux dont l'opération porte
				//     bien la métadonnée. Le changement de source ne peut donc rien casser :
				//     il n'y a aujourd'hui aucune valeur imprimée à perdre.
				//   · même corrigé (`^ca_collections.resume_versement.lieu_versement`), il
				//     resterait faux : la métadonnée est multivaluée et cumule l'historique des
				//     lieux de l'opération — opération 35897 → « CCE-Sélestat;SRA - Strasbourg »,
				//     opération 22209 → trois valeurs dont « [VIDE] ». Un bordereau réclame LE
				//     lieu d'arrivée de CE versement, pas la liste des dépôts fréquentés par
				//     l'opération.
				//
				// Source retenue : relation `arrivee` (type 245) du versement vers
				// ca_storage_locations — celle qu'alimente le champ « Emplacement d'arrivée »
				// de l'écran de saisie. 7 805 des 10 386 pages du parc (75,1 %) reçoivent
				// désormais une valeur, contre 0 auparavant ; aucune n'en perd.
				// Le libellé préféré de l'emplacement est imprimé, pas son chemin hiérarchique :
				// c'est ce que le masque attend sur une ligne (« SRA - Strasbourg »).
				// 9 versements portent DEUX emplacements d'arrivée : les deux sont imprimés,
				// séparés par « ; » — masquer le second serait un choix arbitraire.
				// Valeur constante pour tout le bordereau (le versement est unique) ; elle est
				// calculée dans la boucle par symétrie avec les autres lectures de $movement.
				$vs_lieu_vers_0103 = trim((string)$vs_mvt_0103("<unit relativeTo='ca_storage_locations' restrictToRelationshipTypes='arrivee' delimiter='; '>^ca_storage_locations.preferred_labels.name</unit>"));

				// « Inrap – Nom, prénom » et « Acceptation et décharge – Nom, Prénom » (page 1).
				// Le §4 nomme quatre fois « Nom pour l'affichage (displayname) » — deux fois dans
				// le tableau du bordereau, deux fois au §5.1 (« Répertoire : Nom pour l'affichage
				// (displayname) »). La correspondance est cohérente : elle est appliquée.
				//
				// Ce qui existait : `preferred_labels.surname` et `preferred_labels.forename`
				// lus SÉPARÉMENT puis recollés par le masque (« ${RESP_VERS_NOM} ${RESP_VERS_PRENOM} »).
				// Deux défauts que le displayname corrige :
				//   · 849 versements du parc désignent un agent dont le `surname` est vide alors
				//     que le displayname est complet : le bordereau n'imprimait que le prénom
				//     (versement 9488 → « Manon » au lieu de « VALLEE Manon »).
				//   · 953 versements portent plusieurs « mover » et 3 520 plusieurs
				//     « destinataire ». Les deux listes étant concaténées séparément, les noms et
				//     les prénoms se retrouvaient dans deux énumérations distinctes :
				//     versement 1052 → « THOMAS; RODIER Emilie ; Clémence ». Le displayname,
				//     joint par « ; », rend « THOMAS, Emilie; RODIER, Clémence ».
				// Le displayname est renseigné sur les 9 834 libellés d'entité de la base : le
				// champ ne peut pas devenir vide là où il était rempli.
				//
				// Les colonnes [15] à [18] (surname / forename) sont CONSERVÉES telles quelles :
				// elles alimentent le tableau HTML intermédiaire, dont les en-têtes annoncent
				// « nom » et « prénom » séparés. Seul le document Word bascule sur le displayname,
				// via les deux colonnes ajoutées en fin de tableau.
				$vs_nom_inrap_0103 = trim((string)$vs_mvt_0103("<unit relativeTo='ca_entities' restrictToRelationshipTypes='mover' delimiter='; '>^ca_entities.preferred_labels.displayname</unit>"));
				$vs_nom_sra_0103   = trim((string)$vs_mvt_0103("<unit relativeTo='ca_entities' restrictToRelationshipTypes='destinataire' delimiter='; '>^ca_entities.preferred_labels.displayname</unit>"));
				// === D2026-0103 §4 : fin ===

				// === D2026-0103 §4 — évolution 12/08/2026, listes de contenants en tableau : début ===
				// Les trois listes sont calculées UNE seule fois par page, sous forme de lignes
				// structurées : les colonnes texte historiques [42..44] (vue HTML, rendu
				// inchangé) et les tableaux Word [51..53] en dérivent. Mêmes requêtes de
				// sélection qu'avant ; seul surcoût, la lecture groupée dimensions/poids/
				// référentiel — une requête par liste non vide.
				$va_rows_mob_0103 = $vs_getContenants($qr_result->get('collection_id'), $va_types_mob, $vb_repli_0103, 'materiau');
				$va_rows_doc_0103 = $vs_getContenants($qr_result->get('collection_id'), $va_types_doc, $vb_repli_0103, 'reference');
				$va_rows_num_0103 = $vs_getContenants($qr_result->get('collection_id'), $va_types_num, $vb_repli_0103, 'reference');
				// === D2026-0103 §4 — évolution 12/08/2026, listes de contenants en tableau : fin ===

				/*
				 *	 Récupération des valeurs dans le tableau 
				 */
				$va_results[$i] = [
					$collection->get("ca_collections.idno"), // Code INRAP
					$collection->getWithTemplate("^ca_collections.oa_number"), // Code OA
					$region, //"Région",
					$dept, // Dép
					$collection->getWithTemplate("^ca_places.preferred_labels"), // Commune
					$collection->getWithTemplate("^ca_collections.lieudit"), // Lieudit
					$collection->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.displayname</unit>"), //RO
					// === D2026-0103 §4 — compteurs restreints aux contenants versés : début ===
					$vn_ba_cont_meta_0103,  // [7]  nb de bam contenants — inrap_nb_total_contenants restreint (#30)
					$vs_ba_hcont_0103, // [8]  nb de bam hors contenants — cf. note ci-dessus
					$vn_ba_vol_0103,   // [9]  volume m3 — somme sur les contenants mobilier versés
					// === D2026-0103 §4 : fin ===
					$collection->getWithTemplate("^ca_collections.op_doc_sci"), // [10] Doc sci oui/non
					// === D2026-0103 §4 — compteurs restreints aux contenants versés : début ===
					$vn_doc_cont_0103,  // [11] Doc sci cont nb — contenants documentation versés
					$vs_doc_hcont_0103, // [12] doc sci h.cont nb — non imprimé au masque
					$vs_doc_lin_0103,   // [13] doc sci m/l — non imprimé au masque
					// === D2026-0103 §4 : fin ===
					$nb_contenant_num,
					$vs_mvt_0103("<unit relativeTo='ca_entities' restrictToRelationshipTypes='mover'>^ca_entities.preferred_labels.surname</unit>"), // responsable versement nom
					$vs_mvt_0103("<unit relativeTo='ca_entities' restrictToRelationshipTypes='mover'>^ca_entities.preferred_labels.forename </unit>"), // responsable versement prénom
					$vs_mvt_0103("<unit relativeTo='ca_entities' restrictToRelationshipTypes='destinataire'>^ca_entities.preferred_labels.surname</unit>"), // destinataire nom
					$vs_mvt_0103("<unit relativeTo='ca_entities' restrictToRelationshipTypes='destinataire'>^ca_entities.preferred_labels.forename</unit>"),  // destinataire prénom
					$collection->getWithTemplate("^ca_collections.presence_inventaire_BAM"),
					$collection->getWithTemplate("^ca_collections.presence_inventaire_DOC"),
					$collection->getWithTemplate("^ca_collections.verif_mob_versement.verif_mob_vers_date"),
					$vs_mvt_0103("^ca_movements.idno"),
					$vs_mvt_0103("^ca_movements.inrap_date_versement.inrap_date_versement_date"),
					$vs_mvt_0103("^ca_movements.inrap_date_versement.nature_versement_previsionelle"),
					$vs_mvt_0103("^ca_movements.inrap_date_versement.nature_versement_previ_b"),
					$vs_mvt_0103("^ca_movements.inrap_date_versement.nature_versement_previ_c"),
					str_replace("&", "et", strip_tags($allContenants)),
					$vs_mvt_0103("<unit relativeTo='ca_entities' restrictToRelationshipTypes='mover'>^ca_entities.precision_entite</unit>"), // responsable versement nom
					$vs_mvt_0103("<unit relativeTo='ca_entities' restrictToRelationshipTypes='destinataire'>^ca_entities.precision_entite</unit>"), // destinataire nom
					$collection->getWithTemplate("^ca_collections.verif_doc_vers.verif_doc_vers_date"),
					/* --- M0103-B : champs additionnels bordereau --- */
					$type_ope, // [31] Type d'intervention
					$collection->getWithTemplate("^ca_collections.datesdeterrain.Datedeterrain_date"), // [32] debut terrain
					$collection->getWithTemplate("^ca_collections.datesdeterrain.datedeterrain_datefin"), // [33] fin terrain
					$collection->getWithTemplate("^ca_collections.numero_prescription"), // [34] N prescription
					$collection->getWithTemplate("^ca_collections.date_simple"), // [35] date prescription
					$collection->getWithTemplate("^ca_collections.autorisation_fouille.autorisation_num"), // [36] N designation
					$collection->getWithTemplate("^ca_collections.autorisation_fouille.autorisation_date"), // [37] date designation
					// === D2026-0103 §4 — compteurs restreints aux contenants versés : début ===
					$vs_poids_bam_0103, // [38] poids BAM kg — somme sur les contenants mobilier versés
					// === D2026-0103 §4 : fin ===
					$collection->getWithTemplate("^ca_collections.docsci_ope_poids"), // [39] poids doc sci kg
					// === D2026-0103 §4 — mapping des métadonnées : début ===
					$vs_lieu_vers_0103, // [40] Lieu de Versement — emplacement d'ARRIVÉE du versement (§5.1)
					// === D2026-0103 §4 : fin ===
					$collection->getWithTemplate("^ca_collections.inrap_annee_inter"), // [41] annee operation
					// === D2026-0103 §4 — contenu des listes de contenants : début ===
					// Chaque ligne : « - <idno> / <complément> ». Complément = matière/classe
					// pour le mobilier, libellé préféré (la « Référence » du §4) pour les deux
					// autres. Le filtrage sur le versement et le repli sur l'opération versée
					// sont ceux du lot précédent, inchangés.
					//
					// `str_replace("&","et")` est retiré des trois lignes : il datait d'avant
					// l'échappement XML posé au lot 1 (modele4_docx.php, htmlspecialchars avec
					// ENT_XML1) et transformait « Photos & plans » en « Photos et plans » dans
					// 527 libellés de contenants. L'esperluette est désormais échappée en
					// « &amp; » et s'imprime telle qu'elle est saisie.
					// === D2026-0103 §4 — évolution 12/08/2026, listes de contenants en tableau (dérivation) : début ===
					// Même sortie texte qu'avant, dérivée des lignes structurées calculées plus haut.
					$vs_lignesTexte_0103($va_rows_mob_0103),  // [42] liste mobilier : idno / matière - classe
					$vs_lignesTexte_0103($va_rows_doc_0103), // [43] liste documentation : idno / référence
					$vs_lignesTexte_0103($va_rows_num_0103), // [44] liste numérique : idno / référence
					// === D2026-0103 §4 — évolution 12/08/2026, listes de contenants en tableau (dérivation) : fin ===
					// === D2026-0103 §4 : fin ===
					$vol_num_mo, // [45] volume numerique (Mo) - somme structuree inrap_volume_num_mo
					// === D2026-0103 §4 — signataires et adresse de la page 1 : début ===
					$vs_dir_nom_0103, // [46] Direction Inrap (page 1) — repli répertoire
					$vs_sra_svc_0103, // [47] Service Régional de l'Archéologie (page 1) — repli répertoire
					$vs_dir_adr_0103, // [48] adresse de la direction (page 1) — lignes séparées par « ,sautdeligne »
					// === D2026-0103 §4 : fin ===
					// === D2026-0103 §4 — mapping des métadonnées : début ===
					// Colonnes AJOUTÉES en fin de tableau, pour ne décaler aucun index existant
					// ni aucune colonne du tableau HTML intermédiaire.
					$vs_nom_inrap_0103, // [49] « Inrap – Nom, prénom » (page 1) — displayname du répertoire
					$vs_nom_sra_0103    // [50] « Acceptation et décharge – Nom, Prénom » — displayname
					// === D2026-0103 §4 : fin ===
				];

				// === D2026-0103 §4 — évolution 12/08/2026, listes de contenants en tableau : début ===
				// Données structurées des trois tableaux Word — colonnes AJOUTÉES en fin de
				// tableau, et en export docx SEULEMENT : modele4_html.php imprime chaque
				// colonne telle quelle, un tableau PHP y sortirait « Array ». Le Code OA de
				// la colonne 1 est l'idno de l'opération de la page ([0]), nettoyé par
				// $vs_pourImpression_0103 (2 853 idno du parc finissent par une espace
				// insécable, cf. le bloc de nettoyage ci-dessus).
				if ($vs_export == "docx") {
					$vs_oa_page_0103 = $vs_pourImpression_0103($va_results[$i][0]);
					$va_results[$i][51] = $va_tableauContenants_0103($va_rows_mob_0103, 'mob', $vs_oa_page_0103);
					$va_results[$i][52] = $va_tableauContenants_0103($va_rows_doc_0103, 'doc', $vs_oa_page_0103);
					$va_results[$i][53] = $va_tableauContenants_0103($va_rows_num_0103, 'num', $vs_oa_page_0103);
				}
				// === D2026-0103 §4 — évolution 12/08/2026, listes de contenants en tableau : fin ===
			}
			//var_dump(strip_tags($allContenants));die();
			$this->view->setVar('results', $va_results);
			$this->view->setVar('titre', $titre);
            if(!$vs_export) {
	 		    $this->render('modele4_html.php');
            } elseif($vs_export == "docx") {
                $result = $this->render('modele4_docx.php');
                print $result;
                die();
                
            }
 		}
 		
 		public function Modele4b() {
	 		// ID métadonnées : Type d'opération > Type d'opération (inrap_type_ope) = 487
	 		// Valeur : Diagnostic = 1443
	 		$vs_etat= $this->getRequest()->getParameter("etat", pString);
	 		$vn_mvt_id = $this->getRequest()->getParameter("mouvement", pString);
            $vs_export = $this->getRequest()->getParameter("export", pString);
	 		if(!$vn_mvt_id) {
		 		$this->view->setVar("message", "Cet état nécessite de sélectionner une fiche mouvement.");
		 		return $this->render("error_html.php");
	 		}
	 		
	 		//$vs_dest= $this->getRequest()->getParameter("format", pString);
	 		//var_dump($vs_dest);die();
	 		
	 		$headers = [];
	 		switch($vs_etat) {
		 		case "bordereau_de_mouvement":
		 			$titre = "Bordereau de mouvement";
		 			$headers = [
			 			"Code INRAP",
			 			"Code OA",
			 			"Région",
			 			"Commune",
			 			"Lieudit",
			 			"Responsable d'opération",
			 			"motif du mouvement",
			 			"précisions du motif du mouvement",
			 			"date de départ",
			 			"date prévisionnelle de retour",
			 			"responsable mouvement",
			 			"transport réalisé par",
			 			"personne destinataire",
			 			"organisme destinataire",
			 			"description",
			 			"note",
			 			"liste objets",
						"Référence dans comodo",
						"Numéro du devis",
						"Date du devis", 
						"Emplacement de départ",
						"Numéro de Téléphone",
						"Adresse Destinataire",
						"Adresse Centre de départ",
						"Collection liée",
						"Demande d'ordre de mission"
			 			//"coordonnées du destinataire"
		 			];
		 			break;
		 			
		 	}
	 		$this->view->setVar('etat', $vs_etat);

			 



	 		//Création du tableau de résultat
	 		$va_results = [];

	 		// Récupération des données
            $va_results[0] = $headers;

			$movement = new ca_movements($vn_mvt_id);
			// On récupère l'adresse et le téléphone
			$entities_destinataire = $movement->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='destinataire'>^ca_entities.entity_id</unit>");
			$entities_destinataire = explode(";", $entities_destinataire);
			foreach ($entities_destinataire as $entity_id){
				$entity = new ca_entities($entity_id);
				$tel = $entity->getWithTemplate("^ca_entities.telephone.numero ^ca_entities.telephone.type_numero");
				$adresse = $entity->getWithTemplate("^ca_entities.address.address1 <ifdef code='ca_entities.address.address2'>, ^ca_entities.address.address2</ifdef><ifdef code='ca_entities.address.postalcode|ca_entities.address.city|ca_entities.address.country'>, ^ca_entities.address.postalcode ^ca_entities.address.city ^ca_entities.address.country</ifdef>");
				if (!empty($tel) || !empty($address)){
					break;
				}
			}


			$objets = $movement->getWithTemplate("<unit relativeTo='ca_objects' delimiter='&&'>^ca_objects.object_id</unit>");
			$objets = explode("&&", $objets);
			foreach ($objets as $obj_id){
				$vt_obj = new ca_objects($obj_id);
				$objetsList[$vt_obj->getWithTemplate("^ca_collections.collection_id")][] = $vt_obj->getWithTemplate("^ca_objects.preferred_labels.name");
			}
			$objetsListText = "";
			$collectionsLinked = "";
			foreach ($objetsList as $collection_id => $objets){
				$collection = new ca_collections($collection_id);
				$collectionsLinked .= $collection->getWithTemplate("^ca_places.preferred_labels.name / ^ca_collections.lieudit / ^ca_collections.inrap_annee_inter%trim=1 / Code Inrap : ^ca_collections.idno / Code OA : ^ca_collections.oa_number /<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.displayname</unit>").'sautdeligne';
				// === D2026-0103 §4 — échappement des libellés de contenants : début ===
				// KO-1 : ces libellés étaient seulement débarrassés de leur HTML puis
				// assemblés avec du balisage OOXML. 535 libellés de contenants du parc
				// portent une esperluette nue : injectés tels quels, ils produisent un
				// word/document.xml mal formé. Ils ne peuvent pas être échappés dans la
				// vue, puisque la chaîne assemblée contient elle-même des balises :
				// l'échappement se fait donc ici, valeur par valeur.
				$objets = array_map(function($e){
					return inrap_0103_xml($e, true);
				}, $objets);
				// === D2026-0103 §4 : fin ===
				// === D2026-0103 §4 — retours à la ligne des listes de contenants : début ===
				// Même défaut de rendu que celui corrigé sur le bordereau de versement : le
				// « <w:br/> » posé ici finit à l'intérieur du <w:t> de ${LISTE_OBJETS}, où ni Word
				// ni LibreOffice ne le prennent en compte — les objets s'impriment en un pavé
				// continu. Le saut doit fermer l'élément de texte courant puis en rouvrir un.
				// Procédé identique à enSuspensClean() ci-dessous.
				$vs_br_objets = '</w:t><w:br/><w:t xml:space="preserve"> - ';
				$objetsListText .= "Objets de l'opération : ".inrap_0103_xml($collection->getWithTemplate("^ca_collections.preferred_labels.name"), true)."  ".str_replace("sautdeligne", $vs_br_objets, $vs_br_objets.implode("sautdeligne", $objets));
				// === D2026-0103 §4 : fin ===
			}

			if (empty($objets[0])){
				$collectionsLinked = $movement->getWithTemplate("<unit relativeTo='ca_collections' delimiter='sautdeligne'>^ca_places.preferred_labels.name / ^ca_collections.lieudit / ^ca_collections.inrap_annee_inter%trim=1 / Code Inrap : ^ca_collections.idno / Code OA : ^ca_collections.oa_number /<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.displayname</unit></unit>");
				
			}
			$odremission = "";
			if ($movement->getWithTemplate("^ca_movements.demande_ordre_mission.ordre_mission") == "oui"){
				$odremission = $movement->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='mover'>^ca_entities.preferred_labels.forename ^ca_entities.preferred_labels.surname</unit>")." peut bénéficier d’un ordre de mission à demander selon les informations ci-dessus à la direction territoriale. Le code d’imputation et d’affectation est : ".$movement->getWithTemplate("^ca_movements.demande_ordre_mission.code_imputation");
			}

			/*
			 *	 Récupération des valeurs dans le tableau 
			 */
			$va_results[1] = [
				$movement->getWithTemplate("<unit relativeTo='ca_collections'>^ca_collections.idno</unit>"), //"Code inrap"
				$movement->getWithTemplate("<unit relativeTo='ca_collections'>^ca_collections.oa_number</unit>"), //"Code OA",
				$movement->getWithTemplate("<unit relativeTo='ca_collections'>^ca_places.hierarchy.preferred_labels.name%maxLevelsFromTop=2&delimiter=_➜_</unit>"), //"Région",
				$movement->getWithTemplate("<unit relativeTo='ca_collections'>^ca_places.preferred_labels</unit>"), //"Commune",
				$movement->getWithTemplate("<unit relativeTo='ca_collections'>^ca_collections.lieudit</unit>"), //"Lieudit",
				$movement->getWithTemplate("<unit relativeTo='ca_collections'><unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.displayname</unit></unit>"), //"Responsable d'opération",
				$movement->getWithTemplate("^ca_movements.movement_reason"), //"Motif du mouvement",
				$movement->getWithTemplate("^ca_movements.inrap_mvt_precisions"), //"Précisions du motif du mouvement",
				$movement->getWithTemplate("^ca_movements.dates_mouvement.mvt_date_debut"), //"Date départ",
				$movement->getWithTemplate("^ca_movements.dates_mouvement.date_prev_retour"), //"Date prévisionnelle de retour",
				$movement->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='authorizer'>^ca_entities.preferred_labels.forename ^ca_entities.preferred_labels.surname <ifdef code='ca_entities.precision_entite' restrictToRelationshipTypes='authorizer'>(^ca_entities.precision_entite)</ifdef></unit>"),//"responsable mouvement",
				$movement->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='mover'>^ca_entities.preferred_labels.forename ^ca_entities.preferred_labels.surname <ifdef code='ca_entities.precision_entite' restrictToRelationshipTypes='mover'>(^ca_entities.precision_entite)</ifdef></unit>"),//"Personne ayant réalisé le transport",
				$movement->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='destinataire' restrictToTypes='ind'>^ca_entities.preferred_labels.forename ^ca_entities.preferred_labels.surname <ifdef code='ca_entities.precision_entite' restrictToRelationshipTypes='destinataire' restrictToTypes='ind'>(^ca_entities.precision_entite)</ifdef></unit>"),//"destinataire",
				$movement->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='destinataire' excludeTypes='ind'>^ca_entities.preferred_labels.forename ^ca_entities.preferred_labels.surname<ifdef code='ca_entities.precision_entite' restrictToRelationshipTypes='destinataire' excludeTypes='ind'>(^ca_entities.precision_entite)</ifdef></unit></unit>"),//"organisme destinataire",
				//$movement->getWithTemplate("<unit relativeTo='ca_storage_locations' restrictToRelationshipTypes='arrivee'>^ca_storage_locations.preferred_labels</unit>"),//"organisme destinataire"
				$movement->getWithTemplate("^ca_movements.description"),//"description"
				$movement->getWithTemplate("^ca_movements.movement_note"),//"note"
				$objetsListText,
				$movement->getWithTemplate("^ca_movements.idno"),//"IDNO du mouvement"
				$movement->getWithTemplate('^ca_movements.num_devis'),//"Numéro du devis"
				$movement->getWithTemplate("^ca_movements.date_devis"),//"Date du devis"
				$movement->getWithTemplate("<unit relativeTo='ca_storage_locations' restrictToType='Centre_de_recherche' restrictToRelationshipTypes='depart'>^ca_storage_locations.preferred_labels</unit>"),
				$tel,
				$adresse,
				$movement->getWithTemplate("<unit relativeTo='ca_storage_locations' restrictToType='Centre_de_recherche' restrictToRelationshipTypes='depart'>^ca_storage_locations.address.address1 <ifdef code='ca_storage_locations.address.address2'>, ^ca_storage_locations.address.address2</ifdef><ifdef code='ca_storage_locations.address.postalcode|ca_storage_locations.address.city|ca_storage_locations.address.country'>, ^ca_storage_locations.address.postalcode ^ca_storage_locations.address.city ^ca_storage_locations.address.country</ifdef></unit>"),
				$collectionsLinked, 
				$odremission
			];

			$this->view->setVar('results', $va_results);
			$this->view->setVar('titre', $titre);
            if(!$vs_export) {
				//die("ici1");
				$this->render('modele4b_html.php');
			} elseif($vs_export == "docx") {
				

                $result = $this->render('modele4b_docx.php');
                print $result;
                die();
            }
 		}
 		 		
  		public function Modele6_help() {
			$this->render('modele6_help_html.php');
		}  		
 		public function Modele6() {
	 		error_reporting(E_ERROR);
	 		ini_set("display_errors",1);
	 		// ID métadonnées : Type d'opération > Type d'opération (inrap_type_ope) = 487
	 		// Valeur : Diagnostic = 1443
	 		$vs_etat= $this->getRequest()->getParameter("etat", pString);
	 		$vn_occ_id = $this->getRequest()->getParameter("expo", pString);
            $vs_export = $this->getRequest()->getParameter("export", pString);
	 		if(!$vn_occ_id) {
		 		$this->view->setVar("message", "Cet état nécessite de sélectionner une fiche exposition.");
		 		return $this->render("error_html.php");
	 		}

			$titre = "Condition de prêt objet exposition simple";
			$headers = ["Titre",
						"Lieu",
						"Du",
						"Au",
						"Emprunteur",
						"Signataire Emprunteur",
						"Signataire Inrap",
						"Commissaire",
						"Qualité signataire Inrap",
						"Qualité signataire emprunteur"
						];
	 		$this->view->setVar('etat', $vs_etat);

	 		//Création du tableau de résultat
	 		$va_results = [];
	 		$va_results[] = $headers;

            // Chargement de la collection cible
			$expo = new ca_occurrences($vn_occ_id);
			/** On ajoute dans le tableau les objets liée dans un tableau */
			$object_list = explode(";", $expo->getWithTemplate("<unit relativeTo='ca_objects'>^ca_objects.object_id</unit>"));
			$obj_data =[];
			foreach ($object_list as $obj){
				$obj = new ca_objects($obj);
				$temp = [
					$obj->getWithTemplate("^ca_objects.type_mobilier"),
					$obj->getWithTemplate("^ca_objects.inrap_periode_chrono_site"),
					$obj->getWithTemplate("^ca_objects.inrap_materiaux"),
					$obj->getWithTemplate("<ifdef code='ca_objects.dimensions.dimensions_height'>H. ^ca_objects.dimensions.dimensions_height x </ifdef><ifdef code='ca_objects.dimensions.dimensions_width'>L. ^ca_objects.dimensions.dimensions_width</ifdef><ifdef code='ca_objects.dimensions.dimensions_depth'> x P. ^ca_objects.dimensions.dimensions_depth</ifdef>"),
					$obj->getWithTemplate("^ca_objects.quantification_mobilier"),
					$obj->getWithTemplate("^ca_objects.fragments_mobilier"),
					$obj->getWithTemplate("^ca_objects.description"),
					$obj->getWithTemplate("^ca_objects.inrap_valeur_assurance"),
					$obj->getWithTemplate("<unit relativeTo='ca_places'>^ca_places.hierarchy.preferred_labels.name%maxLevelsFromTop=2&delimiter=_,_</unit><ifdef code='ca_object.lieudit'>, ^ca_objects.lieudit</ifdef>"),
					$obj->getWithTemplate("<unit relativeTo='ca_collections'>^ca_collections.idno</unit>"),
					$obj->getWithTemplate("^ca_objects.object_id"),
					$obj->getWithTemplate("^ca_objects.idno"),
					$obj->getWithTemplate("^ca_objects.inrap_numero_isolation"),
					$obj->getWithTemplate("^ca_object_representations.media.large.path")
				];
				array_push($obj_data, $temp);
			}
			/*
			 *	 Récupération des valeurs dans le tableau 
			 */
			$va_results[] = [
				$expo->getWithTemplate("<unit relativeTo='ca_occurrences' restrictToTypes='exposition' delimiter=', '>^ca_occurrences.preferred_labels.name</unit>"), //nom
				$expo->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='expo_lieu' delimiter=', '>^ca_entities.preferred_labels.displayname <ifdef code='ca_entities.address.city'>(^ca_entities.address.city)</ifdef></unit>"), // lieu
				$expo->getWithTemplate("<unit relativeTo='ca_occurrences' restrictToTypes='exposition'>^ca_occurrences.exhibitionBeginDate</unit>"), //du
				$expo->getWithTemplate("<unit relativeTo='ca_occurrences' restrictToTypes='exposition'>^ca_occurrences.exhibitionEndDate</unit>"), //au
				$expo->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='emprunteur'>^ca_entities.preferred_labels.displayname</unit>"), // emprunteur
				$expo->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='signa_emprunteur'>^ca_entities.preferred_labels.surname ^ca_entities.preferred_labels.forename</unit>"), // signataire emprunteur
				$expo->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='signa_inrap'>^ca_entities.preferred_labels.surname ^ca_entities.preferred_labels.forename</unit>"), // signa_inrap
				$expo->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='commissaire' delimiter=', '>^ca_entities.preferred_labels.surname ^ca_entities.preferred_labels.forename</unit>"), // commissaire
				$expo->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='signa_inrap'>^ca_entities.precision_entite</unit>"), // signa_inrap
				$expo->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='signa_emprunteur'>^ca_entities.precision_entite</unit>"), // signataire emprunteur
				$obj_data
			];

			

			$this->view->setVar('results', $va_results);
			$this->view->setVar('titre', $titre);
			$timestamp = time();
			$path = __CA_APP_DIR__.'/plugins/etatsInrap/tmp';
			file_put_contents($path."/".$timestamp.".json", json_encode($va_results));
			$this->view->setVar("timestamp", $timestamp);
	 		
	 		if(!$vs_export) {
	 		    $this->render('modele6_html.php');
            } elseif($vs_export == "docx") {
                $this->render('modele6_docx.php');
            }
 		}
 		
 		public function Modele7() {
	 		// ID métadonnées : Type d'opération > Type d'opération (inrap_type_ope) = 487
	 		// Valeur : Diagnostic = 1443
	 		$vs_etat= $this->getRequest()->getParameter("etat", pString);
	 		$headers = [];
	 		switch($vs_etat) {
		 		case "liste_objets_cadre_exposition":
		 			$titre = "Liste des objets dans cadre exposition";
		 			$type_ope_cible = "Diagnostic";
		 			$statut_coll_cible = "Collection en cours d'étude";
		 			$headers = [
		 				"Statut collection",
			 			"Code OA",
			 			"Type opération",
			 			"Code INRAP",
                        	"Région",
						"Numéro dépt.",
                        "Commune",
                        "Lieudit",
                        "RO",
                        "Fin terrain",
                        "Prév. remise du rapport",
                        "Remise du rapport"
		 			];
		 			break;
		 			
		 	}
	 		$this->view->setVar('etat', $vs_etat);

	 		//Création du tableau de résultat
	 		$va_results = [];

	 		// Récupération des données
	 		$o_data = new Db();
	 		$and = "";
	 		$vs_query = "select collection_id from ca_collections where type_id=".__INRAP_TYPE_ID_OP__." and deleted=0";
            $qr_result = $o_data->query($vs_query);
            $va_results[0] = $headers;
            $i=0;
            while($qr_result->nextRow()) {
	            $i++;
	            // Chargement de la collection cible
				$collection = new ca_collections($qr_result->get('collection_id'));

				// Filtrer sur le type d'opération, si pas bon, on passe
				$type_ope = $collection->get("ca_collections.inrap_type_op.inrap_type_ope", ["convertCodesToDisplayText"=>1]);
	            //if(!$type_ope) continue;
				//if($type_ope != $type_ope_cible) continue;
				
				// Filtrer sur le statut, si pas bon, on passe
				$statut_coll = $collection->get("ca_collections.statut_collection", ["convertCodesToDisplayText"=>1]);

				if(!$statut_coll) continue;
				if($statut_coll != $statut_coll_cible) continue;

				//Filtrage par DIR
				$DIR = $collection->getWithTemplate("<unit relativeTo='ca_entities' restrictToTypes='DIR'>^ca_entities.preferred_labels.displayname</unit>");
				if(!in_array($DIR, $this->ops_user_groups)) {
					continue;
					//die("Argh, dying dying");
				}

				/*
				 *	 Récupération des valeurs dans le tableau 
				 */
				$va_results[$i] = [
					$statut_coll, // On a déjà la valeur, pas la peine de l'extraire
					$collection->get("ca_collections.parent.idno"),
					$type_ope, // on a déjà la valeur, pas la peine de l'extraire
					$collection->get("ca_collections.idno"),
                    $collection->getWithTemplate("^ca_places.hierarchy.preferred_labels.name%maxLevelsFromTop=2&delimiter=_➜_"),
                    "Numéro dépt.",
                    $collection->getWithTemplate("^ca_places.preferred_labels"),
                    $collection->getWithTemplate("^ca_collections.lieudit"),
                    $collection->getWithTemplate("^ca_entities.preferred_labels.name"),
                    $collection->getWithTemplate("^ca_collections.datesdeterrain.Datedeterrain_date"),
                    "Prév. remise du rapport",
                    $collection->getWithTemplate("^ca_collections.date_du_rapport")
				];
			}
			$this->view->setVar('results', $va_results);
			$this->view->setVar('titre', $titre);

	 		$this->render('modele7_html.php');
 		}
 		public function Modele8() {
	 		// ID métadonnées : Type d'opération > Type d'opération (inrap_type_ope) = 487
	 		// Valeur : Diagnostic = 1443
	 		$vs_etat= $this->getRequest()->getParameter("etat", pString);
	 		$headers = [];
	 		switch($vs_etat) {
		 		case "lettres_fonction_mouvement":
		 			$titre = "Lettres en fonction mouvement";
		 			$type_ope_cible = "Diagnostic";
		 			$statut_coll_cible = "Collection en cours d'étude";
		 			$headers = [
			 			"Statut collection",
			 			"Code OA",
			 			"Type opération",
			 			"Code INRAP",
                        "Région",
                        "Numéro dépt.",
                        "Commune",
                        "Lieudit",
                        "RO",
                        "Fin terrain",
                        "Prév. remise du rapport",
                        "Remise du rapport"
		 			];
		 			break;
		 			
		 	}
	 		$this->view->setVar('etat', $vs_etat);

	 		//Création du tableau de résultat
	 		$va_results = [];

	 		// Récupération des données
	 		$o_data = new Db();
	 		$and = "";
	 		$vs_query = "select collection_id from ca_collections where type_id=".__INRAP_TYPE_ID_OP__." and deleted=0";
            $qr_result = $o_data->query($vs_query);
            $va_results[0] = $headers;
            $i=0;
            while($qr_result->nextRow()) {
	            $i++;
	            // Chargement de la collection cible
				$collection = new ca_collections($qr_result->get('collection_id'));

				// Filtrer sur le type d'opération, si pas bon, on passe
				$type_ope = $collection->get("ca_collections.inrap_type_op.inrap_type_ope", ["convertCodesToDisplayText"=>1]);
	            //if(!$type_ope) continue;
				//if($type_ope != $type_ope_cible) continue;
				
				// Filtrer sur le statut, si pas bon, on passe
				$statut_coll = $collection->get("ca_collections.statut_collection", ["convertCodesToDisplayText"=>1]);

				if(!$statut_coll) continue;
				if($statut_coll != $statut_coll_cible) continue;

				//Filtrage par DIR
				$DIR = $collection->getWithTemplate("<unit relativeTo='ca_entities' restrictToTypes='DIR'>^ca_entities.preferred_labels.displayname</unit>");
				if(!in_array($DIR, $this->ops_user_groups)) {
					continue;
					//die("Argh, dying dying");
				}

				/*
				 *	 Récupération des valeurs dans le tableau 
				 */
				$va_results[$i] = [
					$statut_coll, // On a déjà la valeur, pas la peine de l'extraire
					$collection->get("ca_collections.parent.idno"),
					$type_ope, // on a déjà la valeur, pas la peine de l'extraire
					$collection->get("ca_collections.idno"),
                    $collection->getWithTemplate("^ca_places.hierarchy.preferred_labels.name%maxLevelsFromTop=2&delimiter=_➜_"),
                    "Numéro dépt.",
                    $collection->getWithTemplate("^ca_places.preferred_labels"),
                    $collection->getWithTemplate("^ca_collections.lieudit"),
                    $collection->getWithTemplate("^ca_entities.preferred_labels.name"),
                    $collection->getWithTemplate("^ca_collections.datesdeterrain.Datedeterrain_date"),
                    "Prév. remise du rapport",
                    $collection->getWithTemplate("^ca_collections.date_du_rapport")
				];
			}
			$this->view->setVar('results', $va_results);
			$this->view->setVar('titre', $titre);

	 		$this->render('modele8_html.php');
 		}

		public function Modele9() {
	 		// ID métadonnées : Type d'opération > Type d'opération (inrap_type_ope) = 487
	 		// Valeur : Diagnostic = 1443
	 		$vs_etat= $this->getRequest()->getParameter("etat", pString);
	 		$headers = [];
	 		switch($vs_etat) {
		 		case "stock_centre":
		 			$titre = "Stock centre";
		 			$type_ope_cible = "Diagnostic";
		 			$statut_coll_cible = "Collection en cours d'étude";
		 			$headers = [
			 			"Statut collection",
			 			"Code OA",
			 			"Type opération",
			 			"Code INRAP",
                        "Région",
                        "Numéro dépt.",
                        "Commune",
                        "Lieudit",
                        "RO",
                        "Fin terrain",
                        "Prév. remise du rapport",
                        "Remise du rapport"
		 			];
		 			break;
		 			
		 	}
	 		$this->view->setVar('etat', $vs_etat);

	 		//Création du tableau de résultat
	 		$va_results = [];

	 		// Récupération des données
	 		$o_data = new Db();
	 		$and = "";
	 		$vs_query = "select collection_id from ca_collections where type_id=".__INRAP_TYPE_ID_OP__." and deleted=0";
            $qr_result = $o_data->query($vs_query);
            $va_results[0] = $headers;
            $i=0;
            while($qr_result->nextRow()) {
	            $i++;
	            // Chargement de la collection cible
				$collection = new ca_collections($qr_result->get('collection_id'));

				// Filtrer sur le type d'opération, si pas bon, on passe
				$type_ope = $collection->get("ca_collections.inrap_type_op.inrap_type_ope", ["convertCodesToDisplayText"=>1]);
	            //if(!$type_ope) continue;
				//if($type_ope != $type_ope_cible) continue;
				
				// Filtrer sur le statut, si pas bon, on passe
				$statut_coll = $collection->get("ca_collections.statut_collection", ["convertCodesToDisplayText"=>1]);

				if(!$statut_coll) continue;
				if($statut_coll != $statut_coll_cible) continue;

				//Filtrage par DIR
				$DIR = $collection->getWithTemplate("<unit relativeTo='ca_entities' restrictToTypes='DIR'>^ca_entities.preferred_labels.displayname</unit>");
				if(!in_array($DIR, $this->ops_user_groups)) {
					continue;
					//die("Argh, dying dying");
				}

				/*
				 *	 Récupération des valeurs dans le tableau 
				 */
				$va_results[$i] = [
					$statut_coll, // On a déjà la valeur, pas la peine de l'extraire
					$collection->get("ca_collections.parent.idno"),
					$type_ope, // on a déjà la valeur, pas la peine de l'extraire
					$collection->get("ca_collections.idno"),
                    $collection->getWithTemplate("^ca_places.hierarchy.preferred_labels.name%maxLevelsFromTop=2&delimiter=_➜_"),
                    "Numéro dépt.",
                    $collection->getWithTemplate("^ca_places.preferred_labels"),
                    $collection->getWithTemplate("^ca_collections.lieudit"),
                    $collection->getWithTemplate("^ca_entities.preferred_labels.name"),
                    $collection->getWithTemplate("^ca_collections.datesdeterrain.Datedeterrain_date"),
                    "Prév. remise du rapport",
                    $collection->getWithTemplate("^ca_collections.date_du_rapport")
				];
			}
			$this->view->setVar('results', $va_results);
			$this->view->setVar('titre', $titre);

	 		$this->render('modele9_html.php');
 		}

		 public function Modele10() {
			// ID métadonnées : Type d'opération > Type d'opération (inrap_type_ope) = 487
			// Valeur : Diagnostic = 1443
			
			//Création du tableau de résultat
			$va_results = [];

			// Récupération des données
			$vt_occ = new ca_occurrences($this->getRequest()->getParameter("courrier_id", pInteger));
			$vt_col = new ca_collections($vt_occ->getWithTemplate("^ca_collections.collection_id"));
			$entree_id = $vt_col->getWithTemplate("<unit relativeTo='ca_storage_locations' restrictToTypes='CRA' restrictToRelationshipTypes='entree_collection'>^ca_storage_locations.location_id</unit>");
			$entree = new ca_storage_locations(explode(";",$entree_id)[0]);

			$va_results = [
				$vt_col->getWithTemplate("^ca_collections.idno"),
				$vt_occ->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='suivi_par'>^ca_entities.preferred_labels.displayname</unit>"),
				$vt_col->getWithTemplate("<unit relativeTo='ca_places' restrictToRelationshipTypes='operation'>^ca_places.preferred_labels</unit> ^ca_collections.lieudit"),
				$vt_occ->getWithTemplate("^ca_occurrences.oa_number"),
				$vt_col->getWithTemplate("^ca_collections.inrap_type_op.inrap_type_ope"),
				$vt_occ->getWithTemplate("^ca_occurrences.numero_prescription"),
				$vt_occ->getWithTemplate("^ca_occurrences.date_simple"),
				$vt_occ->getWithTemplate("^ca_occurrences.date_du_rapport"),
				$vt_col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.forename ^ca_entities.preferred_labels.surname</unit>"),
				$vt_occ->getWithTemplate("^ca_occurrences.autorisation_fouille.autorisation_num"),
				$vt_occ->getWithTemplate("^ca_occurrences.autorisation_fouille.autorisation_date"),
				$vt_occ->getWithTemplate("^ca_occurrences.date_fin_garde"),
				$vt_occ->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='DAST'> ^ca_entities.preferred_labels.forename ^ca_entities.preferred_labels.surname</unit>"),
				$vt_occ->getWithTemplate("^ca_occurrences.idno"), 
				$vt_occ->getWithTemplate("<ifdef code='ca_occurrences.infos_courrier.info_lieu_courrier'>^ca_occurrences.infos_courrier.info_lieu_courrier</ifdef>"), 
				$vt_occ->getWithTemplate("<ifdef code='ca_occurrences.infos_courrier.date_info_courrier'>le ^ca_occurrences.infos_courrier.date_info_courrier</ifdef>"), 
				$vt_col->getWithTemplate("<unit relativeTo='ca_storage_locations' restrictToRelationshipTypes='entree_collection'>^ca_storage_locations.preferred_labels</unit>"),
				$entree->getWithTemplate("<unit relativeTo='ca_entities_x_storage_locations' restrictToRelationshipTypes='gestionnaire'>^ca_entities.preferred_labels.forename ^ca_entities.preferred_labels.surname</unit>"),
				$entree->getWithTemplate("<unit relativeTo='ca_entities_x_storage_locations' restrictToRelationshipTypes='gestionnaire'>^ca_entities.telephone.numero</unit>"),
				$entree->getWithTemplate("<unit relativeTo='ca_entities_x_storage_locations' restrictToRelationshipTypes='gestionnaire'>^ca_entities.email</unit>"),
				$vt_col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='DIR'>^ca_entities.preferred_labels.displayname</unit>"),
				$vt_col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='DIR'>^ca_entities.address.address1<ifdef code='ca_entities.address.address2'>,sautdeligne^ca_entities.address.address2</ifdef><ifdef code='ca_entities.address.postalcode|ca_entities.address.city|ca_entities.address.country'>,sautdeligne^ca_entities.address.postalcode ^ca_entities.address.city ^ca_entities.address.country</unit>"),
				$vt_col->getWithTemplate("<unit relativeTo='ca_entities' restrictToTypes='sra'>^ca_entities.preferred_labels.displayname</unit>"),
				$vt_col->getWithTemplate("<unit relativeTo='ca_entities' restrictToTypes='sra'>^ca_entities.address.address1<ifdef code='ca_entities.address.address2'>,sautdeligne^ca_entities.address.address2</ifdef><ifdef code='ca_entities.address.postalcode|ca_entities.address.city|ca_entities.address.country'>,sautdeligne^ca_entities.address.postalcode ^ca_entities.address.city ^ca_entities.address.country</unit>"),
				$vt_occ->getWithTemplate("^ca_occurrences.idno")




			];

			
			
			$this->view->setVar('results', $va_results);

			$this->render('modele10_docx.php');
		}

		public function Modele11() {
			// ID métadonnées : Type d'opération > Type d'opération (inrap_type_ope) = 487
			// Valeur : Diagnostic = 1443
			
			//Création du tableau de résultat
			$va_results = [];

			// Récupération des données
			$vt_occ = new ca_occurrences($this->getRequest()->getParameter("courrier_id", pInteger));
			
			
			$vt_col = new ca_collections($vt_occ->getWithTemplate("^ca_collections.collection_id"));
			$entree_id = $vt_col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='lieu_entree'>^ca_entities.entity_id</unit>");
			$entree = new ca_entities($entree_id);

			$va_results = [
				$vt_col->getWithTemplate("^ca_collections.idno"),
				$vt_col->getWithTemplate("^ca_collections.preferred_labels.name"),
				$vt_occ->getWithTemplate("^ca_occurrences.oa_number"),
				$vt_col->getWithTemplate("^ca_collections.inrap_type_op.inrap_type_ope"),
				$vt_occ->getWithTemplate("^ca_occurrences.contenu_lettre.contenu_lettre_objet"), 
				$vt_occ->getWithTemplate("^ca_occurrences.contenu_lettre.contenu_lettre_ouverture"), 
				nl2br($vt_occ->getWithTemplate("^ca_occurrences.contenu_lettre.contenu_lettre_texte")), 
				$vt_occ->getWithTemplate("^ca_occurrences.contenu_lettre.contenu_lettre_cloture"), 
				$vt_occ->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='signa_inrap'>^ca_entities.preferred_labels.forename ^ca_entities.preferred_labels.surname</unit>"),
				$vt_occ->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='signa_inrap'>^ca_entities.precision_entite</unit>"),
				$vt_occ->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='destinataire'>^ca_entities.preferred_labels.forename ^ca_entities.preferred_labels.surname</unit>"),
				$vt_occ->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='destinataire'>^ca_entities.address.address1<ifdef code='ca_entities.address.address2'>,sautdeligne^ca_entities.address.address2</ifdef><ifdef code='ca_entities.address.postalcode|ca_entities.address.city|ca_entities.address.country'>,sautdeligne^ca_entities.address.postalcode ^ca_entities.address.city ^ca_entities.address.country</ifdef></unit>"),
				$vt_occ->getWithTemplate("^ca_occurrences.idno"), 
				$vt_occ->getWithTemplate("<ifdef code='ca_occurrences.infos_courrier.info_lieu_courrier'>^ca_occurrences.infos_courrier.info_lieu_courrier</ifdef>"), 
				$vt_occ->getWithTemplate("<ifdef code='ca_occurrences.infos_courrier.date_info_courrier'>le ^ca_occurrences.infos_courrier.date_info_courrier</ifdef>"),
				$entree->getWithTemplate("<unit relativeTo='ca_entities_x_entities' restrictToRelationshipTypes='gestionnaire'>^ca_entities.preferred_labels.forename ^ca_entities.preferred_labels.surname</unit>"),
				$entree->getWithTemplate("<unit relativeTo='ca_entities_x_entities' restrictToRelationshipTypes='gestionnaire'>^ca_entities.telephone.numero</unit>"),
				$entree->getWithTemplate("<unit relativeTo='ca_entities_x_entities' restrictToRelationshipTypes='gestionnaire'>^ca_entities.email</unit>"),
				$vt_col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='DIR'>^ca_entities.preferred_labels.displayname</unit>"),
				$vt_col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='DIR'>^ca_entities.address.address1<ifdef code='ca_entities.address.address2'>,sautdeligne^ca_entities.address.address2</ifdef><ifdef code='ca_entities.address.postalcode|ca_entities.address.city|ca_entities.address.country'>,sautdeligne^ca_entities.address.postalcode ^ca_entities.address.city ^ca_entities.address.country</ifdef></unit>"),


			];

			
			
			$this->view->setVar("occurrence_id", $this->getRequest()->getParameter("courrier_id", pInteger));
			$this->view->setVar('results', $va_results);

			$this->render('modele11_docx.php');
		}

		public function Modele12() {
			// ID métadonnées : Type d'opération > Type d'opération (inrap_type_ope) = 487
			// Valeur : Diagnostic = 1443

			// LETTRE DEMANDE DE VERSEMENT
			
			//Création du tableau de résultat
			$va_results = [];

			// Récupération des données
			$vt_occ = new ca_occurrences($this->getRequest()->getParameter("courrier_id", pInteger));
			
			$collections_id = explode(";", $vt_occ->getWithTemplate("^ca_collections.collection_id"));

			$col_0 = new ca_collections($collections_id[0]);
			// 09/09/2026 (ticket 7999) : la restriction portait sur restrictToTypes='CRA', or « CRA »
			// n'est pas un code de type d'emplacement — les codes réels sont Centre_de_recherche,
			// building, epi, trav__e, tablette, cce, sra, musee. Le filtre était donc inerte et on
			// retenait le premier emplacement venu, souvent un local (« Montauban_Dépôt », type
			// building) au lieu de son centre (« CRA Montauban »). Les coordonnées du gestionnaire,
			// portées par le centre, ressortaient vides.
			// On ne peut pas simplement corriger le code de type : la relation entree_collection
			// pointe légitimement sur le local, et filtrer sur Centre_de_recherche ne rendrait rien.
			// On garde donc l'emplacement d'entrée tel quel pour le libellé du lieu, et on remonte
			// la hiérarchie jusqu'au centre pour y chercher le gestionnaire.
			$entree_id = $col_0->getWithTemplate("<unit relativeTo='ca_storage_locations' restrictToRelationshipTypes='entree_collection'>^ca_storage_locations.location_id</unit>");
			$entree = new ca_storage_locations(explode(";",$entree_id)[0]);
			$entree_cra = self::remonterAuCentre($entree);



			$va_infos_courrier = [
				"idno" => $vt_occ->get("idno"),
				"lieu" => $vt_occ->getWithTemplate("<ifdef code='ca_occurrences.infos_courrier.info_lieu_courrier'>^ca_occurrences.infos_courrier.info_lieu_courrier</ifdef>"),
				"date" => $vt_occ->getWithTemplate("<ifdef code='ca_occurrences.infos_courrier.date_info_courrier'>le ^ca_occurrences.infos_courrier.date_info_courrier</ifdef>"),
				"signataire" => $vt_occ->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='signa_inrap'>^ca_entities.preferred_labels.forename ^ca_entities.preferred_labels.surname</unit>"),
				"qualite_signataire" => $vt_occ->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='signa_inrap'>^ca_entities.precision_entite</unit>"),
				"nom_gestionnaire" => $entree_cra->getWithTemplate("<unit relativeTo='ca_entities_x_storage_locations' restrictToRelationshipTypes='gestionnaire'>^ca_entities.preferred_labels.forename ^ca_entities.preferred_labels.surname</unit>"),
				"tel_gestionnaire" => $entree_cra->getWithTemplate("<unit relativeTo='ca_entities_x_storage_locations' restrictToRelationshipTypes='gestionnaire'>^ca_entities.telephone.numero</unit>"),
				"mail_gestionnaire" => $entree_cra->getWithTemplate("<unit relativeTo='ca_entities_x_storage_locations' restrictToRelationshipTypes='gestionnaire'>^ca_entities.email</unit>"),
				"dir_nom" => $col_0->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='DIR'>^ca_entities.preferred_labels.displayname</unit>"),
				"dir_adresse" => $col_0->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='DIR'>^ca_entities.address.address1<ifdef code='ca_entities.address.address2'>,sautdeligne^ca_entities.address.address2</ifdef><ifdef code='ca_entities.address.postalcode|ca_entities.address.city|ca_entities.address.country'>,sautdeligne^ca_entities.address.postalcode ^ca_entities.address.city ^ca_entities.address.country</ifdef></unit>"),
				"dast_nom" => $col_0->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='DAST'>^ca_entities.preferred_labels.forename ^ca_entities.preferred_labels.surname</unit>"),
				"sra_nom" => $col_0->getWithTemplate("<unit relativeTo='ca_entities' restrictToTypes='sra'>^ca_entities.preferred_labels.displayname</unit>"),
				"sra_adresse" => $col_0->getWithTemplate("<unit relativeTo='ca_entities' restrictToTypes='sra'>^ca_entities.address.address1<ifdef code='ca_entities.address.address2'>,sautdeligne^ca_entities.address.address2</ifdef><ifdef code='ca_entities.address.postalcode|ca_entities.address.city|ca_entities.address.country'>,sautdeligne^ca_entities.address.postalcode ^ca_entities.address.city ^ca_entities.address.country</ifdef></unit>"),
				"lieu_entree" => $entree->getWithTemplate("^ca_storage_locations.preferred_labels"),
			];
			$this->view->setVar('InfosCourrier', $va_infos_courrier);
			print $this->render('modele12_docx.php');


			$infosCols = [];
			foreach ($collections_id as $key=>$col_id){
				$vt_col = new ca_collections($col_id);
				$nb_contenant_num = explode(';',$vt_col->getWithTemplate("<unit relativeTo='ca_objects' delimiter=';' restrictToTypes='numerique, contenant_num_verse'>^ca_objects.idno</unit>"));
				$nb_contenant_num = sizeof($nb_contenant_num);
				if (empty($nb_contenant_num[0])){
					$nb_contenant_num = 0;
				}


				$allContenants = $vt_col->getWithTemplate("<ifdef code='ca_objects' restrictToTypes='contenant, contenant_mob_verse'>Contenants Mobilier : sautdelignesautdeligne <unit relativeTo='ca_objects' restrictToTypes='contenant, contenant_mob_verse' delimiter='sautdeligne'>- ^ca_objects.preferred_labels</unit></ifdef><ifdef code='ca_objects' restrictToTypes='contenant_intermediaire, contenant_doc_verse'>sautdelignesautdeligneContenants Documentation : sautdelignesautdeligne <unit relativeTo='ca_objects' restrictToTypes='contenant_intermediaire, contenant_doc_verse' delimiter='sautdeligne'>- ^ca_objects.preferred_labels</unit></ifdef><ifdef code='ca_objects' restrictToTypes='numerique, contenant_num_verse'>sautdelignesautdeligneContenants Numérique : sautdelignesautdeligne <unit relativeTo='ca_objects' restrictToTypes='numerique, contenant_num_verse' delimiter='sautdeligne'>- ^ca_objects.preferred_labels</unit></ifdef>");

				$infosCols = [
					$vt_col->getWithTemplate("<unit relative='ca_places'>^ca_places.hierarchy.preferred_labels.name%maxLevelsFromBottom=3&delimiter=_/_</unit> / ^ca_collections.lieudit"),
					$vt_col->getWithTemplate("^ca_collections.inrap_type_op.inrap_type_ope"),
					$vt_col->getWithTemplate("^ca_collections.idno"),
					$vt_col->getWithTemplate("^ca_collections.oa_number"),
					$vt_col->getWithTemplate("^ca_collections.inrap_annee_inter"),
					$vt_col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='suivi_par'>^ca_entities.preferred_labels.forename ^ca_entities.preferred_labels.surname</unit>"),
					$vt_col->getWithTemplate("^ca_collections.numero_prescription"),
					$vt_col->getWithTemplate("^ca_collections.date_simple"),
					$vt_col->getWithTemplate("^ca_collections.date_du_rapport"),
					$vt_col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.forename ^ca_entities.preferred_labels.surname</unit>"),
					$vt_col->getWithTemplate("^ca_collections.autorisation_fouille.autorisation_num"),
					$vt_col->getWithTemplate("^ca_collections.autorisation_fouille.autorisation_date"),
					$vt_col->getWithTemplate("^ca_collections.op_nb_contenants"), // nb de bam contenants 
					$vt_col->getWithTemplate("^ca_collections.op_nb_hors_contenants"), //nb de bam hors contenants 
					round(tofloat($vt_col->getWithTemplate("^ca_collections.inrap_volume_total")),4), //inrap  volume m3
					$vt_col->getWithTemplate("^ca_collections.op_doc_sci"), // Doc sci oui/non
					$vt_col->getWithTemplate("^ca_collections.op_doc_sci_contenants"), // Doc sci cont nb
					$vt_col->getWithTemplate("^ca_collections.op_doc_sci_hors_contenants"), // doc sci h.cont nb
					$vt_col->getWithTemplate("^ca_collections.op_doc_sci_lineaire"), // doc sci m/l
					$allContenants


				];
				$this->view->setVar("partie", $key);
				$this->view->setVar('InfosCols', $infosCols);
				$this->view->setVar('idnoCourrier', $vt_occ->get("idno"));
				print $this->render('modele12_sp_docx.php');
			}

			// === D2026-0103 §4 — fusion des documents Word : début ===
			// Remplace `exec("pagemerger -b …")` : même technique (AltChunk), sans binaire externe.
			$vs_rep     = __CA_APP_DIR__."/plugins/etatsInrap/tmp/".$vt_occ->get("idno")."/";
			$vs_sortie  = $vs_rep."lettre_versement.docx";
			$va_entrees = [$vs_rep."lettre_versement_base.docx"];
			foreach ($collections_id as $key => $col_id) {
				$va_entrees[] = $vs_rep."lettre_versement_".$key.".docx";
			}
			if (!$this->fusionnerDocx($vs_sortie, $va_entrees, true)) {
				$this->view->setVar('message', "La lettre de versement n'a pas pu être assemblée : "
					."l'un des documents intermédiaires est absent ou illisible.");
				$this->render('error_html.php');
				return;
			}
			// === D2026-0103 §4 : fin ===
			header("Location: /app/plugins/etatsInrap/tmp/".$vt_occ->get("idno")."/lettre_versement.docx");
			

		}

		



		# >>> LOT10 EN_SUSPENS BEGIN
		# ------------------------------------------------------------------
		# LOT 10 — Courriers types du statut « En suspens », édités DEPUIS LA FICHE OPÉRATION
		# CDC INRAP 202606116 §6.4 / §8.1 / §8.2 / §9.1 / §9.2 — marché 036SE2025, D2026-0102
		#
		# REMPLACE le mécanisme du LOT 7 (édition depuis une occurrence courrier).
		# Décision client du 06/08/2026 : « Placer les boutons au niveau de la collection, et ne plus
		# créer d'occurrence en base : l'INRAP estime ne pas avoir besoin de ce stockage, et préfère
		# conserver les versions complétées uniquement dans le bloc "En suspens — courrier complété
		# et signé". »
		#
		# Point d'entrée : /etatsInrap/Generer/Modele13 (ou Modele14) avec les paramètres de la
		# fenêtre modale ouverte depuis l'inspecteur de la fiche opération (ca_collections, type 125) :
		#   collection_id   int    fiche opération
		#   destinataire    string saisie libre AUTORISÉE (autocomplétion sur les personnes physiques)
		#   dest_id         int    entity_id si le destinataire a été choisi dans la liste (0 sinon)
		#   lieu            string saisie libre
		#   date            string jj/mm/aaaa (sélecteur de date jQuery UI)
		#   signataire_id   int    entity_id — saisie libre INTERDITE : SEUL cet identifiant fait foi
		#
		# AUCUNE ÉCRITURE EN BASE : ces actions ne font que lire la fiche opération et produire le .docx.
		# ------------------------------------------------------------------

		/**
		 * Catalogue des phrases de motifs de la Lettre 1 (§9.1 + propositions IdéesCulture
		 * pour les motifs 2 et 4, qui n'ont pas de phrase rédigée au §9.1).
		 * L'ordre du tableau est l'ordre d'apparition des puces dans le courrier (ordre §6.2)
		 * ET l'ordre des numéros de motif dans la référence du courrier.
		 * %VOLUME% est remplacé par le volume m³ calculé automatiquement (élément inrap_volume_total).
		 */
		static function enSuspensCataloguePhrases() {
			return [
				'en_suspens_m1_rapport'     => "le responsable d’opération n’est plus présent dans les effectifs de l’Inrap et aucune passation de dossier n’a pu être assurée",
				'en_suspens_m2_bam'         => "les biens archéologiques mobiliers issus de cette opération, d’un volume de %VOLUME% m³, n’ont pas encore fait l’objet d’un versement à l’État",
				'en_suspens_m3_doc'         => "la documentation scientifique associée à cette opération est incomplète et ne permet pas de réaliser un rapport",
				'en_suspens_m4_miseenforme' => "la collection ne peut être versée en l’état, faute d’inventaire technique et de conditionnement conformes aux normes en vigueur",
				'en_suspens_m5_incomplete'  => "la collection est incomplète, une partie des éléments ayant déjà été transmise au Service régional de l’archéologie",
				// Motif 6 « Documentation absente » (LOT 11, élément 769) : reprend la PREMIÈRE puce
				// du §9.1, restée jusqu'ici inatteignable faute de motif correspondant — c'est la
				// première ligne du tableau de décision du §8.2. À distinguer du motif 3, qui vise
				// une documentation existante mais non versée ; ici il n'y a rien à verser.
				'en_suspens_m6_docabsente'  => "la documentation scientifique associée à cette opération est absente malgré nos recherches",
			];
		}

		/**
		 * Nettoyage d'une valeur avant injection dans le .docx :
		 * strip_tags puis échappement XML, enfin restitution des sauts de ligne Word.
		 * Retourne "" (et non "${VAR}") quand la source est absente.
		 */
		private function enSuspensClean($ps_val) {
			$vs = trim(strip_tags((string)$ps_val));
			$vs = htmlspecialchars($vs, ENT_QUOTES | ENT_XML1, 'UTF-8');
			// saut de ligne Word : il doit fermer le <w:t> courant, sinon Word et LibreOffice
			// ignorent le <w:br/> (défaut latent des lettres existantes, cf. Modele10/11/12).
			$vs = str_replace(',sautdeligne', 'sautdeligne', $vs);   // pas de virgule en fin de ligne d'adresse
			return str_replace('sautdeligne', '</w:t><w:br/><w:t xml:space="preserve">', $vs);
		}

		/**
		 * Normalisation d'un segment de la référence de courrier : ASCII strict, sans espace ni
		 * ponctuation, pour rester lisible aussi bien dans le corps du courrier que dans un nom de
		 * fichier. Les espaces et apostrophes deviennent « _ », les accents sont repliés sur leur
		 * lettre de base, les séparateurs multiples sont réduits. Le « - » est réservé au séparateur
		 * de premier niveau entre les quatre éléments de la référence : il est donc évacué ici.
		 */
		private function enSuspensSlug($ps_val) {
			$vs = trim((string)$ps_val);
			if ($vs === '') { return ''; }
			$vs_tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $vs);
			if ($vs_tr !== false && $vs_tr !== '') { $vs = $vs_tr; }
			$vs = preg_replace('/[\'`^~"]/', '', $vs);        // résidus de //TRANSLIT (l'Elogette → lElogette)
			$vs = preg_replace('/[^A-Za-z0-9]+/', '_', $vs);  // tout le reste (espace, virgule, -, /) → _
			$vs = trim($vs, '_');
			return preg_replace('/_+/', '_', $vs);
		}

		/**
		 * Référence de courrier — FORMAT IMPOSÉ PAR LE CLIENT (06/08/2026), calculé À LA VOLÉE :
		 *
		 *     code opération INRAP - lieu-dit - motif - date [ - n° de tirage ]
		 *
		 * ex. : D115316-Coeur_de_Ville_Phase_2-M1_M3-20260806
		 *       D115316-Coeur_de_Ville_Phase_2-M1_M3-20260806-2   (2e courrier de l'opération)
		 *
		 * Séparateur de premier niveau : « - » ; à l'intérieur d'un segment : « _ » (cf. enSuspensSlug),
		 * afin que les éléments restent distinguables à l'œil comme à l'analyse.
		 *
		 * >>> LOT12 COMPTEUR — CINQUIÈME SEGMENT, CONDITIONNEL
		 * Les quatre premiers segments ne décrivent qu'un ÉTAT de l'opération : deux tirages faits
		 * le même jour, sur la même opération, dans le même état, produisaient exactement la MÊME
		 * référence. Une référence citée dans un échange ultérieur ne désignait donc pas un
		 * courrier, mais une classe de courriers.
		 * Le numéro de tirage vient de la métadonnée de comptage `en_suspens_courrier_seq`
		 * (cf. enSuspensCompteurLire) et n'est ajouté QU'À PARTIR DU DEUXIÈME COURRIER : le
		 * premier conserve la référence historique, inchangée, comme demandé.
		 * <<< LOT12 COMPTEUR
		 *
		 * @param string $ps_idno    code opération INRAP (ca_collections.idno)
		 * @param string $ps_lieudit lieu-dit de l'opération
		 * @param string $ps_motif   segment « motif » déjà constitué (M1M3, CST…)
		 * @param string $ps_date_iso date de la modale au format AAAAMMJJ
		 * @param int    $pn_tirage  rang de ce courrier dans l'opération (1 = premier, sans suffixe)
		 */
		private function enSuspensReference($ps_idno, $ps_lieudit, $ps_motif, $ps_date_iso, $pn_tirage = 1) {
			$va_seg = [];
			// 1. code opération INRAP — jamais omis : sans lui la référence ne désigne plus rien
			$vs = $this->enSuspensSlug($ps_idno);
			$va_seg[] = ($vs !== '') ? $vs : 'SANS_CODE';
			// 2. lieu-dit — segment OMIS s'il est absent (plutôt qu'un « -- » illisible)
			$vs = $this->enSuspensSlug($ps_lieudit);
			if ($vs !== '') { $va_seg[] = $vs; }
			// 3. motif
			$vs = $this->enSuspensSlug($ps_motif);
			if ($vs !== '') { $va_seg[] = $vs; }
			// 4. date de la modale (et non la date du jour)
			$vs = preg_replace('/[^0-9]/', '', (string)$ps_date_iso);
			if ($vs !== '') { $va_seg[] = $vs; }
			// 5. n° de tirage — LOT 12 : ajouté seulement à partir du 2e courrier de l'opération.
			//    Un compteur absent, nul ou négatif (élément non déployé, opération n'ayant jamais
			//    servi) retombe donc exactement sur la référence antérieure au lot.
			$vn_tirage = (int)$pn_tirage;
			if ($vn_tirage >= 2) { $va_seg[] = (string)$vn_tirage; }
			return join('-', $va_seg);
		}

		# >>> LOT12 COMPTEUR BEGIN
		# ------------------------------------------------------------------
		# LOT 12 — COMPTEUR DE TIRAGES DES COURRIERS « En suspens »
		# CDC INRAP 202606116 §6.4 — demande client du 06/08/2026, D2026-0102, marché 036SE2025
		#
		# SUPPORT : élément de métadonnée `en_suspens_courrier_seq` (entier), restreint à
		# ca_collections / type 125 « Opération », placé sur AUCUN écran, exclu des affichages,
		# des formulaires de recherche, des tris et des impressions par ses réglages
		# (canBeUsedInDisplay / canBeUsedInSearchForm / canBeUsedInSort / canMakePDF = 0).
		# Créé par scratchpad/lot12_compteur_courriers.php.
		#
		# PORTÉE : UNE SEULE valeur par opération, TOUS COURRIERS CONFONDUS (Lettre 1 et Lettre 2).
		# Un compteur par type de lettre aurait suffi tant que le segment « motif » distingue les
		# deux lettres (M… d'un côté, CST de l'autre), mais cette garantie est indirecte : elle
		# repose sur une propriété d'un AUTRE segment, qu'un futur courrier pourrait casser. Avec un
		# compteur unique, le n-ième courrier d'une opération porte le rang n et aucun autre : deux
		# courriers d'une même opération ne peuvent pas porter la même référence, quels que soient
		# leur type, leur date et l'état des motifs. C'est aussi la lecture littérale de la demande
		# (« une métadonnée portée par l'opération ») et la seule qui donne au numéro un sens
		# lisible : « le n-ième courrier envoyé pour cette opération ».
		#
		# MOMENT DE L'INCRÉMENT : APRÈS production effective du .docx, jamais avant.
		# Incrémenter d'abord aurait garanti l'unicité même en cas de clics simultanés, mais une
		# génération interrompue (gabarit illisible, disque plein, fiche opération introuvable)
		# aurait consommé un numéro : le compteur aurait annoncé un courrier de plus qu'il n'en
		# existe, et la série des références aurait présenté un trou impossible à expliquer au
		# client. On préfère l'inverse : le compteur ne compte QUE des courriers réellement
		# produits ; un échec ne laisse aucune trace et le tirage suivant reprend le même numéro,
		# ce qui est exact puisque aucun document n'est sorti.
		#
		# ÉCRITURE : ciblée sur ca_attributes / ca_attribute_values, SANS update() sur la fiche
		# opération. Un update() sur une ca_collections de type 125 déclenche la réindexation, le
		# journal des modifications et la date de dernière modification de la fiche — un simple
		# téléchargement de courrier ne doit rien faire de tout cela, et surtout pas donner à
		# croire qu'un utilisateur a modifié l'opération.
		# ------------------------------------------------------------------

		/**
		 * Code de la métadonnée de comptage. Un seul endroit à changer.
		 */
		const EN_SUSPENS_COMPTEUR_CODE = 'en_suspens_courrier_seq';

		/**
		 * element_id de la métadonnée de comptage, ou 0 si elle n'est pas déployée.
		 * TOLÉRANCE VOULUE : si l'élément n'existe pas (script de configuration non passé), tout le
		 * dispositif s'efface — compteur toujours nul, aucune écriture, référence identique à celle
		 * d'avant le lot. Aucune erreur n'est levée : un courrier doit pouvoir sortir.
		 */
		private function enSuspensCompteurElementId() {
			require_once(__CA_MODELS_DIR__.'/ca_metadata_elements.php');
			$vn_id = (int)ca_metadata_elements::getElementID(self::EN_SUSPENS_COMPTEUR_CODE);
			return ($vn_id > 0) ? $vn_id : 0;
		}

		/**
		 * Nombre de courriers « En suspens » DÉJÀ produits pour cette opération.
		 *
		 * Retourne 0 pour une opération qui n'a jamais servi (aucun attribut) comme pour toutes
		 * les opérations existantes au moment du déploiement : le premier tirage se comporte alors
		 * exactement comme un premier tirage, sans suffixe et sans erreur.
		 *
		 * @param int $pn_collection_id
		 * @return int  0 si aucun courrier n'a encore été produit
		 */
		private function enSuspensCompteurLire($pn_collection_id) {
			$vn_el = $this->enSuspensCompteurElementId();
			if (!$vn_el || ((int)$pn_collection_id <= 0)) { return 0; }
			$o_db = new Db();
			$qr = $o_db->query("
				SELECT v.value_integer1
				FROM ca_attributes a
				INNER JOIN ca_attribute_values v ON v.attribute_id = a.attribute_id AND v.element_id = a.element_id
				WHERE a.table_num = 13 AND a.row_id = ? AND a.element_id = ?
				ORDER BY a.attribute_id
				LIMIT 1
			", [(int)$pn_collection_id, $vn_el]);
			if (!$qr || !$qr->nextRow()) { return 0; }
			$vn_val = (int)$qr->get('value_integer1');
			return ($vn_val > 0) ? $vn_val : 0;
		}

		/**
		 * Enregistre le rang du courrier qui VIENT D'ÊTRE PRODUIT.
		 *
		 * Appelée après le rendu, et seulement si la vue a effectivement écrit le .docx : la vue
		 * en dépose le chemin dans la variable `lettreFichierGenere`. Si le fichier n'est pas là,
		 * ou vide, rien n'est écrit — le compteur ne compte que des courriers réels.
		 *
		 * L'écriture ne passe PAS par ca_collections::update() (cf. en-tête du bloc) :
		 *  - première valeur : ca_attributes::addAttribute(), qui crée proprement l'attribut et sa
		 *    valeur (et purge au passage le cache d'attributs de la requête) ;
		 *  - valeurs suivantes : UPDATE ciblé, conditionné par « value_integer1 < nouvelle valeur »,
		 *    de sorte que deux générations concurrentes ne puissent jamais faire RECULER le compteur.
		 *
		 * @param int $pn_collection_id
		 * @param int $pn_tirage rang du courrier produit (celui utilisé dans la référence)
		 * @return bool vrai si le compteur a été porté à $pn_tirage
		 */
		private function enSuspensCompteurEnregistrer($pn_collection_id, $pn_tirage) {
			$vn_el = $this->enSuspensCompteurElementId();
			if (!$vn_el || ((int)$pn_collection_id <= 0) || ((int)$pn_tirage < 1)) { return false; }

			// Preuve que le courrier a bien été produit : la vue dépose le chemin du .docx écrit.
			$vs_fichier = (string)$this->view->getVar('lettreFichierGenere');
			if (($vs_fichier === '') || !@file_exists($vs_fichier) || (@filesize($vs_fichier) < 1)) { return false; }

			$o_db = new Db();
			$qr = $o_db->query("
				SELECT a.attribute_id
				FROM ca_attributes a
				WHERE a.table_num = 13 AND a.row_id = ? AND a.element_id = ?
				ORDER BY a.attribute_id
				LIMIT 1
			", [(int)$pn_collection_id, $vn_el]);

			if ($qr && $qr->nextRow()) {
				$vn_attribute_id = (int)$qr->get('attribute_id');
				$o_db->query("
					UPDATE ca_attribute_values
					SET value_longtext1 = ?, value_integer1 = ?
					WHERE attribute_id = ? AND element_id = ? AND (value_integer1 IS NULL OR value_integer1 < ?)
				", [(string)(int)$pn_tirage, (int)$pn_tirage, $vn_attribute_id, $vn_el, (int)$pn_tirage]);
			} else {
				require_once(__CA_MODELS_DIR__.'/ca_attributes.php');
				$t_attr = new ca_attributes();
				$t_attr->setMode(ACCESS_WRITE);
				$t_attr->addAttribute(13, (int)$pn_collection_id, $vn_el,
					[self::EN_SUSPENS_COMPTEUR_CODE => (int)$pn_tirage]);
				if ($t_attr->numErrors()) { return false; }
			}
			return true;
		}
		# <<< LOT12 COMPTEUR END

		/**
		 * Normalise la date saisie dans la modale.
		 * @return array [ 'affichage' => 'jj/mm/aaaa', 'compact' => 'AAAAMMJJ' ]
		 *         Chaînes vides si la saisie est inexploitable (la modale la rend obligatoire).
		 */
		private function enSuspensDate($ps_date) {
			$vs = trim((string)$ps_date);
			$va_m = null;
			if (preg_match('!^([0-9]{1,2})/([0-9]{1,2})/([0-9]{4})$!', $vs, $va_m)) {
				list(, $vn_j, $vn_m, $vn_a) = $va_m;
			} elseif (preg_match('!^([0-9]{4})-([0-9]{1,2})-([0-9]{1,2})$!', $vs, $va_m)) {
				list(, $vn_a, $vn_m, $vn_j) = $va_m;
			} else {
				return ['affichage' => '', 'compact' => ''];
			}
			if (!checkdate((int)$vn_m, (int)$vn_j, (int)$vn_a)) { return ['affichage' => '', 'compact' => '']; }
			return [
				'affichage' => sprintf('%02d/%02d/%04d', $vn_j, $vn_m, $vn_a),
				'compact'   => sprintf('%04d%02d%02d', $vn_a, $vn_m, $vn_j),
			];
		}

		/**
		 * Rassemble les variables de fusion (§6.4) pour les deux lettres « En suspens ».
		 *
		 * TOUTES les variables « métier » (nom d'opération, responsable, centre de recherches,
		 * SRA, adresse de la direction, motifs, volume) proviennent de la FICHE OPÉRATION : elles
		 * sont inchangées par rapport au LOT 7, seule leur racine change ($vt_col au lieu de
		 * l'occurrence). Les QUATRE variables qui venaient de l'occurrence courrier
		 * (destinataire, lieu, date, signataire) viennent désormais de la fenêtre modale.
		 *
		 * @param string $ps_motif_ref segment « motif » de la référence (calculé par l'appelant)
		 * @param int    $pn_tirage   rang de ce courrier dans l'opération (LOT 12 ; 1 = premier)
		 * @return array|null
		 */
		private function enSuspensDonnees($ps_motif_ref = '', $pn_tirage = 1) {
			$o_req = $this->getRequest();

			$vn_collection_id = (int)$o_req->getParameter('collection_id', pInteger);
			$vt_col = new ca_collections($vn_collection_id);
			if (!$vt_col->getPrimaryKey()) { return null; }

			// ---------------------------------------------------------------
			// Les quatre saisies de la fenêtre modale
			// ---------------------------------------------------------------
			// Destinataire : SAISIE LIBRE AUTORISÉE — on retient le texte tel qu'il a été tapé,
			// que l'utilisateur l'ait ou non choisi dans le répertoire.
			$vs_destinataire = trim(strip_tags((string)$o_req->getParameter('destinataire', pString)));
			$vn_dest_id      = (int)$o_req->getParameter('dest_id', pInteger);
			// Lieu : saisie libre.
			$vs_lieu         = trim(strip_tags((string)$o_req->getParameter('lieu', pString)));
			// Date : sélecteur de date.
			$va_date         = $this->enSuspensDate($o_req->getParameter('date', pString));
			// Signataire : SAISIE LIBRE INTERDITE — seul l'entity_id transmis par le callback
			// « select » de l'autocomplétion fait foi. Le texte tapé n'est jamais lu ici : une
			// valeur non choisie dans la liste n'arrive donc pas jusqu'au document.
			$vn_signataire_id = (int)$o_req->getParameter('signataire_id', pInteger);
			$vs_signataire_nom = $vs_signataire_fonction = '';
			if ($vn_signataire_id > 0) {
				$vt_sig = new ca_entities($vn_signataire_id);
				// on ne retient que les personnes physiques du répertoire (type « ind »)
				if ($vt_sig->getPrimaryKey() && ($vt_sig->getTypeCode() === 'ind')) {
					$vs_signataire_nom      = trim($vt_sig->getWithTemplate("^ca_entities.preferred_labels.forename ^ca_entities.preferred_labels.surname"));
					$vs_signataire_fonction = (string)$vt_sig->getWithTemplate("^ca_entities.precision_entite");
				}
			}

			// ---------------------------------------------------------------
			// Sources de la fiche opération (inchangées / LOT 7)
			// ---------------------------------------------------------------
			// centre de recherches (CRA) : entrée de collection + gestionnaire (coordonnées GDC)
			$va_cra_ids = array_filter(array_map('trim', explode(';', (string)$vt_col->getWithTemplate("<unit relativeTo='ca_storage_locations' restrictToTypes='CRA' restrictToRelationshipTypes='entree_collection'>^ca_storage_locations.location_id</unit>"))));
			$vt_cra = new ca_storage_locations((int)array_shift($va_cra_ids));

			$vs_adresse_tpl = "^ca_entities.address.address1<ifdef code='ca_entities.address.address2'>,sautdeligne^ca_entities.address.address2</ifdef><ifdef code='ca_entities.address.postalcode|ca_entities.address.city|ca_entities.address.country'>,sautdeligne^ca_entities.address.postalcode ^ca_entities.address.city ^ca_entities.address.country</ifdef>";

			// --- formule d'appel (§9.1/§9.2 : deux variantes) — AUCUNE SOURCE DÉDIÉE EN BASE.
			//     Déduite de la civilité du destinataire saisi dans la modale : « prefix » de
			//     l'entité s'il a été choisi dans le répertoire, sinon amorce du texte libre
			//     (« Mme »/« Madame »). À défaut, variante masculine, première du §9.1.
			$vs_prefix = '';
			if ($vn_dest_id > 0) {
				$vt_dest = new ca_entities($vn_dest_id);
				if ($vt_dest->getPrimaryKey()) { $vs_prefix = (string)$vt_dest->getWithTemplate("^ca_entities.preferred_labels.prefix"); }
			}
			if (trim($vs_prefix) === '') { $vs_prefix = $vs_destinataire; }
			$vs_prefix = mb_strtolower(trim(strip_tags($vs_prefix)));
			$vs_formule = (preg_match('/^(mme|madame)\b/', $vs_prefix))
				? "Madame la Conservatrice régionale de l’archéologie,"
				: "Monsieur le Conservateur régional de l’archéologie,";

			$vs_idno    = (string)$vt_col->get('ca_collections.idno');
			$vs_lieudit = trim(strip_tags((string)$vt_col->getWithTemplate("^ca_collections.lieudit")));

			$va_lettre = [
				// n° de courrier : référence calculée à la volée (format imposé par le client),
				// suffixée du n° de tirage à partir du 2e courrier de l'opération (LOT 12)
				// — cf. enSuspensReference().
				'IDNO_LETTRE'          => $this->enSuspensReference($vs_idno, $vs_lieudit, $ps_motif_ref, $va_date['compact'], $pn_tirage),
				'CODE_IDNO'            => $vs_idno,
				'NOM_OP'               => trim($vt_col->getWithTemplate("<unit relativeTo='ca_places' restrictToRelationshipTypes='operation'>^ca_places.preferred_labels</unit> ^ca_collections.lieudit")),
				'NUM_OA'               => $vt_col->getWithTemplate("^ca_collections.oa_number"),
				'OP_TYPE'              => $vt_col->getWithTemplate("^ca_collections.inrap_type_op.inrap_type_ope"),
				'NOM_RO'               => $vt_col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.forename ^ca_entities.preferred_labels.surname</unit>"),
				'NOM_CRA'              => $vt_cra->getPrimaryKey() ? $vt_cra->getWithTemplate("^ca_storage_locations.preferred_labels") : '',
				'NOM_SRA'              => str_replace('_', ' ', (string)$vt_col->getWithTemplate("<unit relativeTo='ca_entities' restrictToTypes='sra'>^ca_entities.preferred_labels.displayname</unit>")),
				'ADRESSE_SRA'          => $vt_col->getWithTemplate("<unit relativeTo='ca_entities' restrictToTypes='sra'>".$vs_adresse_tpl."</unit>"),
				// --- les quatre saisies de la modale ---
				'DESTINATAIRE'         => $vs_destinataire,
				'LIEU_LETTRE'          => $vs_lieu,
				'DATE_LETTRE'          => ($va_date['affichage'] !== '') ? 'le ' . $va_date['affichage'] : '',
				'SIGNATAIRE_NOM'       => $vs_signataire_nom,
				'SIGNATAIRE_FONCTION'  => $vs_signataire_fonction,
				// --- suite des sources fiche opération ---
				'NOM_GDC'              => $vt_cra->getPrimaryKey() ? $vt_cra->getWithTemplate("<unit relativeTo='ca_entities_x_storage_locations' restrictToRelationshipTypes='gestionnaire'>^ca_entities.preferred_labels.forename ^ca_entities.preferred_labels.surname</unit>") : '',
				'TEL_GDC'              => $vt_cra->getPrimaryKey() ? $vt_cra->getWithTemplate("<unit relativeTo='ca_entities_x_storage_locations' restrictToRelationshipTypes='gestionnaire'>^ca_entities.telephone.numero</unit>") : '',
				'EMAIL_GDC'            => $vt_cra->getPrimaryKey() ? $vt_cra->getWithTemplate("<unit relativeTo='ca_entities_x_storage_locations' restrictToRelationshipTypes='gestionnaire'>^ca_entities.email</unit>") : '',
				'DIR_NOM'              => $vt_col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='DIR'>^ca_entities.preferred_labels.displayname</unit>"),
				'DIR_ADRESSE'          => $vt_col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='DIR'>".$vs_adresse_tpl."</unit>"),
				'FORMULE_APPEL'        => $vs_formule,
			];
			foreach ($va_lettre as $vs_k => $vs_v) { $va_lettre[$vs_k] = $this->enSuspensClean($vs_v); }

			return [
				'lettre'        => $va_lettre,
				'reference'     => $va_lettre['IDNO_LETTRE'],
				'collection_id' => (int)$vt_col->getPrimaryKey(),
				'code_op'       => $vs_idno,
			];
		}

		/**
		 * Motifs de blocage cochés sur la FICHE OPÉRATION (§6.2 / §6.4).
		 *
		 * PIÈGE : une case DÉCOCHÉE est enregistrée avec la valeur « non » (item 1537), et non
		 * avec une absence de valeur. Le test doit donc être une ÉGALITÉ STRICTE à « oui » ;
		 * un test de présence ferait apparaître les 5 puces à chaque fois.
		 *
		 * @return array [ 'phrases' => [...], 'numeros' => [1,3], 'bloc' => bool ]
		 */
		private function enSuspensMotifs($pn_collection_id) {
			$vt_col = new ca_collections((int)$pn_collection_id);
			if (!$vt_col->getPrimaryKey()) { return ['phrases' => [], 'numeros' => [], 'bloc' => false]; }

			$vs_volume = trim(strip_tags((string)$vt_col->getWithTemplate("^ca_collections.inrap_volume_total")));
			if ($vs_volume === '') { $vs_volume = '0'; }

			$va_phrases = []; $va_numeros = []; $vb_bloc = false; $vn_rang = 0;
			foreach (self::enSuspensCataloguePhrases() as $vs_code => $vs_phrase) {
				$vn_rang++;
				$va_vals = $vt_col->get("ca_collections.en_suspens.".$vs_code, ['returnAsArray' => true, 'convertCodesToIdno' => true]);
				if (!is_array($va_vals)) { $va_vals = ($va_vals === null) ? [] : [$va_vals]; }
				$vb_coche = false;
				foreach ($va_vals as $vs_val) {
					if (trim((string)$vs_val) !== '') { $vb_bloc = true; }
					if (mb_strtolower(trim((string)$vs_val)) === 'oui') { $vb_coche = true; }
				}
				if (!$vb_coche) { continue; }
				$va_phrases[] = str_replace('%VOLUME%', $vs_volume, $vs_phrase);
				$va_numeros[] = $vn_rang;
			}
			// ponctuation §9.1 : « ; » entre les puces, « . » sur la dernière
			$vn_nb = count($va_phrases);
			foreach ($va_phrases as $vn_i => $vs_p) {
				$va_phrases[$vn_i] = $this->enSuspensClean($vs_p) . (($vn_i === $vn_nb - 1) ? '.' : ' ;');
			}
			return ['phrases' => $va_phrases, 'numeros' => $va_numeros, 'bloc' => $vb_bloc];
		}

		/**
		 * Modele13 — Lettre 1 « Impossibilité de remise de rapport » (§9.1)
		 * Éditée depuis la fiche opération, via la fenêtre modale de l'inspecteur.
		 */
		public function Modele13() {
			$vn_collection_id = (int)$this->getRequest()->getParameter('collection_id', pInteger);
			$va_m = $this->enSuspensMotifs($vn_collection_id);

			// --------------------------------------------------------------
			// Contrôle « zéro motif » — CONSERVÉ (§6.4, §9.1, §8.2), adapté au nouveau point
			// d'entrée : le refus porte désormais sur la fiche opération elle-même.
			// Sans aucun motif coché, le courrier sortirait avec « En effet : » suivi de rien.
			// --------------------------------------------------------------
			if (!count($va_m['phrases'])) {
				$vt_col = new ca_collections($vn_collection_id);
				$vs_op  = htmlspecialchars((string)$vt_col->get('ca_collections.idno'), ENT_QUOTES, 'UTF-8');
				if (!$vt_col->getPrimaryKey()) {
					$vs_msg = "Fiche opération introuvable : la Lettre 1 ne peut pas être éditée.";
				} elseif (!$va_m['bloc']) {
					$vs_msg = "Cette opération" . ($vs_op !== '' ? " ($vs_op)" : "") . " ne porte aucun bloc « En suspens » : "
						. "renseignez la mise en suspens de l’opération et cochez au moins un motif de blocage avant d’éditer ce courrier.";
				} else {
					$vs_msg = "Aucun motif de blocage n’est coché sur cette opération" . ($vs_op !== '' ? " ($vs_op)" : "") . " : "
						. "cochez au moins un motif avant d’éditer ce courrier.";
				}
				$vs_msg .= "<br/>Les motifs se cochent sur cette même fiche, écran « Suivi des courriers SRA », "
					. "bloc « En suspens ». La Lettre 1 les énumère un à un : sans motif coché, elle serait "
					. "éditée avec la phrase « En effet : » suivie d’aucun motif (CDC §6.4 et §9.1).";
				$this->view->setVar("message", $vs_msg);
				return $this->render("error_html.php");
			}

			// Segment « motif » de la référence : numéros de TOUS les motifs cochés, dans l'ordre
			// du §6.2, joints par « _ » (M1, M1_M3, M1_M2_M4_M5). Un même état de l'opération
			// produit toujours la même référence, et deux états différents ne peuvent pas se
			// confondre. Le « _ » est indispensable : joints sans séparateur, les motifs 1 et 3
			// donneraient « M13 », impossible à distinguer d'un éventuel motif n° 13 (le LOT 11
			// vient d'ajouter un 6ᵉ motif : la liste n'est pas figée).
			$vs_motif_ref = 'M' . join('_M', $va_m['numeros']);

			// LOT 12 — rang de ce courrier dans l'opération : nombre de courriers déjà produits + 1.
			// Le rang 1 ne produit aucun suffixe ; il est enregistré APRÈS le rendu, et seulement
			// si le .docx a réellement été écrit.
			$vn_tirage = $this->enSuspensCompteurLire($vn_collection_id) + 1;

			$va_d = $this->enSuspensDonnees($vs_motif_ref, $vn_tirage);
			if (!$va_d) { print "Fiche opération introuvable."; return; }

			$this->view->setVar('lettre', $va_d['lettre']);
			$this->view->setVar('motifs', $va_m['phrases']);
			$this->view->setVar('idnoCourrier', $va_d['reference']);
			$this->render('modele13_docx.php');
			$this->enSuspensCompteurEnregistrer($vn_collection_id, $vn_tirage);   // LOT 12
		}

		/**
		 * Modele14 — Lettre 2 « Responsable absent, sollicitation du CST » (§9.2)
		 * Les deux cas du §9.2 sont d'office donnés (puces en dur dans le gabarit).
		 * Éditée depuis la fiche opération, via la fenêtre modale de l'inspecteur.
		 */
		public function Modele14() {
			// La Lettre 2 ne repose sur AUCUN motif de blocage (§9.2) : le segment « motif » de la
			// référence porte la mention fixe « CST » (sollicitation du contrôle scientifique et
			// technique), qui est l'objet même de cette lettre. Il n'est jamais omis, sans quoi une
			// Lettre 2 et une Lettre 1 sans motif partageraient la même référence.
			// LOT 12 — même compteur que la Lettre 1 : il est porté par l'opération, tous courriers
			// confondus. Une Lettre 2 tirée après une Lettre 1 est donc le 2e courrier de
			// l'opération et porte « -2 », même s'il s'agit de la première Lettre 2.
			$vn_collection_id = (int)$this->getRequest()->getParameter('collection_id', pInteger);
			$vn_tirage = $this->enSuspensCompteurLire($vn_collection_id) + 1;

			$va_d = $this->enSuspensDonnees('CST', $vn_tirage);
			if (!$va_d) { print "Fiche opération introuvable."; return; }
			$this->view->setVar('lettre', $va_d['lettre']);
			$this->view->setVar('idnoCourrier', $va_d['reference']);
			$this->render('modele14_docx.php');
			$this->enSuspensCompteurEnregistrer($vn_collection_id, $vn_tirage);   // LOT 12
		}
		# <<< LOT10 EN_SUSPENS END

		// === D2026-0103 §4 — fusion des documents Word (remplace l'outil externe `pagemerger`) : début ===
		/**
		 * Fusionne plusieurs .docx en un seul, selon la technique employée par l'outil
		 * `pagemerger` (github.com/tymbaca/pagemerger) qu'il remplace : chaque document
		 * ajouté est embarqué TEL QUEL dans l'archive, et référencé par une balise
		 * `w:altChunk`. C'est Word qui opère la fusion à l'ouverture, ce qui préserve
		 * intégralement styles, images et mise en page — aucune transformation n'est
		 * appliquée au contenu.
		 *
		 * Motif du remplacement : `pagemerger` est un utilitaire C# absent du serveur, dont
		 * l'installation imposerait un environnement .NET sur chaque instance. La même
		 * technique tient en quelques dizaines de lignes ici, sans dépendance externe.
		 *
		 * ⚠️ `w:altChunk` est résolu par Word à l'ouverture du document. LibreOffice le
		 * gère mal : un document fusionné peut y paraître incomplet alors qu'il est
		 * correct. Toute vérification doit donc se faire sous Word.
		 *
		 * @param string $ps_sortie  chemin du fichier à produire
		 * @param array  $pa_entrees chemins des documents à fusionner, dans l'ordre ; le
		 *                           premier sert de document de base
		 * @param bool   $pb_saut    insérer un saut de page avant chaque document ajouté
		 * @return bool true si le document a été produit
		 */
		private function fusionnerDocx($ps_sortie, $pa_entrees, $pb_saut = true) {
			$pa_entrees = array_values(array_filter((array)$pa_entrees, function($p) { return $p && file_exists($p); }));
			if (!sizeof($pa_entrees)) { return false; }

			// un seul document : rien à fusionner, on recopie
			if (sizeof($pa_entrees) === 1) { return @copy($pa_entrees[0], $ps_sortie); }

			if (($ps_sortie !== $pa_entrees[0]) && !@copy($pa_entrees[0], $ps_sortie)) { return false; }

			$o_zip = new ZipArchive();
			if ($o_zip->open($ps_sortie) !== true) { return false; }

			$vs_doc   = $o_zip->getFromName('word/document.xml');
			$vs_rels  = $o_zip->getFromName('word/_rels/document.xml.rels');
			$vs_types = $o_zip->getFromName('[Content_Types].xml');
			if (($vs_doc === false) || ($vs_rels === false) || ($vs_types === false)) { $o_zip->close(); return false; }

			$vs_ajouts = '';
			for ($vn_i = 1; $vn_i < sizeof($pa_entrees); $vn_i++) {
				$vs_part = 'afchunk'.$vn_i.'.docx';
				$vs_rid  = 'rIdAltChunk'.$vn_i;

				$o_zip->addFile($pa_entrees[$vn_i], 'word/'.$vs_part);

				// relation vers la pièce embarquée
				if (strpos($vs_rels, 'Id="'.$vs_rid.'"') === false) {
					$vs_rels = str_replace('</Relationships>',
						'<Relationship Id="'.$vs_rid.'" '
						.'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/aFChunk" '
						.'Target="'.$vs_part.'"/></Relationships>', $vs_rels);
				}

				// déclaration du type de contenu de la pièce
				if (strpos($vs_types, '/word/'.$vs_part) === false) {
					$vs_types = str_replace('</Types>',
						'<Override PartName="/word/'.$vs_part.'" '
						.'ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document"/></Types>',
						$vs_types);
				}

				if ($pb_saut) { $vs_ajouts .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>'; }
				$vs_ajouts .= '<w:altChunk r:id="'.$vs_rid.'"/>';
			}

			// `w:altChunk` doit être fils de `w:body`, et précéder la mise en page finale
			// (`w:sectPr`) lorsqu'elle est présente, sinon Word refuse le document.
			$vn_pos = strripos($vs_doc, '<w:sectPr');
			if ($vn_pos !== false) { $vs_doc = substr($vs_doc, 0, $vn_pos).$vs_ajouts.substr($vs_doc, $vn_pos); }
			else { $vs_doc = str_replace('</w:body>', $vs_ajouts.'</w:body>', $vs_doc); }

			$o_zip->addFromString('word/document.xml', $vs_doc);
			$o_zip->addFromString('word/_rels/document.xml.rels', $vs_rels);
			$o_zip->addFromString('[Content_Types].xml', $vs_types);
			$o_zip->close();

			return file_exists($ps_sortie);
		}
		// === D2026-0103 §4 : fin ===


 	}
	 function tofloat($num) {
		$dotPos = strrpos($num, '.');
		$commaPos = strrpos($num, ',');
		$sep = (($dotPos > $commaPos) && $dotPos) ? $dotPos : 
			((($commaPos > $dotPos) && $commaPos) ? $commaPos : false);
	   
		if (!$sep) {
			return floatval(preg_replace("/[^0-9]/", "", $num));
		} 
	
		return floatval(
			preg_replace("/[^0-9]/", "", substr($num, 0, $sep)) . '.' .
			preg_replace("/[^0-9]/", "", substr($num, $sep+1, strlen($num)))
		);
	}
 ?>
