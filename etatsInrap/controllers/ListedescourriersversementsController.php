<?php
 
	define("__INRAP_TYPE_ID_OP__", 125);

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

 	class ListedescourriersversementsController extends ActionController {
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

 		}

		# -------------------------------------------------------
		# Functions to render views
		# -------------------------------------------------------

		public function Index() {
			$o_data = new Db();
			$sql = "SELECT * FROM ca_occurrences cao 
				left join ca_attributes ca on ca.row_id = cao.occurrence_id
				left join ca_attribute_values cav on cav.attribute_id = ca.attribute_id
				left join ca_occurrence_labels coal on coal.occurrence_id = cao.occurrence_id
				WHERE cao.type_id = 40003 AND cav.element_id = 690 AND cav.value_longtext1 IS NOT NULL";
			$vt_result = $o_data->query($sql);

			$region = $this->opo_request->getParameter("region", pInteger);
			$region_label = $region == 0 ? 'Toutes les régions' : (new ca_places($region))->getWithTemplate('<l>^ca_places.preferred_labels</l>');

			$results = [];
			while($vt_result->nextRow()) {
				$id = $vt_result->get('occurrence_id');
				$vt_occurrence = new ca_occurrences($id);
				$idno = $vt_occurrence->get('ca_occurrences.idno');

				if(empty($idno)) {
					continue;
				}

				// get place region from text field info_lieu_courrier

				$place = new PlaceSearch();
				$place_results = $place->search(trim(strtolower(str_replace(["CRA", "cra"], "", $vt_occurrence->get('ca_occurrences.infos_courrier.info_lieu_courrier')))), [
					'limit' => 1
				]);
				
				while($place_results->nextHit()) {
					$place_id = $place_results->get('place_id');
				}

				$place = new ca_places($place_id);
				$is_region = $region === 0;

				$ancestors = $place->getHierarchyAncestors($place_id);
				foreach($ancestors as $ancestor) {
					$ancestor = array_values($ancestor)[0];
					if($ancestor !== null && $ancestor["type_id"] == 103) {
						$place = new ca_places($ancestor["place_id"]);
						// limit to selected region
						if($region !== 0 && $place->get('place_id') == $region) {
							$is_region = true;
						}
					}
				}

				if($is_region === false) {
					continue;
				}

				// end get place region

				$results[] = [
					$vt_occurrence->getWithTemplate('<l>^ca_occurrences.preferred_labels</l>'),
					$vt_occurrence->get('ca_occurrences.idno'),
					$place->getWithTemplate('<l>^ca_places.preferred_labels</l>'),
					//$vt_occurrence->get('ca_occurrences.infos_courrier.info_lieu_courrier'),
					$vt_occurrence->get('ca_occurrences.infos_courrier.date_info_courrier'),
					$vt_occurrence->getWithTemplate('<unit restrictToRelationshipTypes="signa_inrap" delimiter="<br>" length="5" relativeTo="ca_entities"><l>^ca_entities.preferred_labels</l></unit> <unit restrictToRelationshipTypes="signa_inrap" start="5" length="1" relativeTo="ca_entities">(..^count)</unit>'),
					$vt_occurrence->get('ca_occurrences.oa_number'),
					$vt_occurrence->get('ca_occurrences.numero_prescription'),
					$vt_occurrence->get('ca_occurrences.date_simple'),
					$vt_occurrence->get('ca_occurrences.autorisation_fouille.autorisation_date'),
					$vt_occurrence->get('ca_occurrences.date_du_rapport'),
					$vt_occurrence->getWithTemplate('<unit relativeTo="ca_collections" delimiter="<br/>"><l>^ca_collections.preferred_labels</l></unit>'),
				];
			}

			$results = array_filter($results, function($result) {
				return !empty($result[0]) && !empty($result[1]) && !empty($result[2]);
			});
			
			$this->view->setVar("results", $results);
			$this->view->setVar("region_label", $region_label);
			$this->render('listedescourriersversements_html.php');
		}
}