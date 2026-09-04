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

 	class ListedesversementsController extends ActionController {
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

		public function Index($type="") {
			$location_id = $this->getRequest()->getParameter('location', pInteger);
			if(!$location_id) $location_id = 16007;

            //$this->opo_view->setVar('label', $label);
			$o_data = new Db();
			$sql = "SELECT * FROM ca_movements left join ca_movements_x_storage_locations camxsl on ca_movements.movement_id=camxsl.movement_id WHERE (deleted = 0) AND (ca_movements.type_id = 1796) and camxsl.movement_id is not null and camxsl.location_id= ".$location_id." ORDER BY ca_movements.movement_id LIMIT 300";
			$vt_result = $o_data->query($sql);

			$results = [];
			while($vt_result->nextRow()) {
				$mov_id = $vt_result->get("movement_id");
				$vt_mov = new ca_movements($mov_id);
				
				// ignore lines without date
				if(!$vt_mov->get('ca_movements.inrap_date_versement.inrap_date_versement_date')) continue;

				$results[] = [
					$mov_id, 
					$vt_mov->get('ca_movements.preferred_labels'),
					$vt_mov->getWithTemplate("<unit relativeTo='ca_storage_locations'><l>^ca_storage_locations.idno</unit>"),
					$vt_mov->getWithTemplate('<l>^ca_movements.preferred_labels</l>'),
					$vt_mov->get('ca_movements.inrap_date_versement.inrap_date_versement_date'),
					$vt_mov->get('ca_movements.inrap_date_versement.inrap_date_versement_date'),
					$vt_mov->getWithTemplate("<unit relativeTo='ca_collections'><l>^ca_collections.preferred_labels</l></unit>"),
					$vt_mov->getWithTemplate('^ca_movements.type_versement'),
					$vt_mov->getWithTemplate('^ca_movements.vers_accepte'),
					$vt_mov->getWithTemplate("<unit relativeTo='ca_collections'>^ca_collections.statut_collection</unit>"),
					$vt_mov->getWithTemplate("<unit relativeTo='ca_storage_locations' restrictToRelationshipTypes='arrivee'><l>^ca_storage_locations.idno</unit>"),
					$vt_mov->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='destinataire'>^ca_entities.hierarchy.preferred_labels</unit>"),
				];
			}
			$this->view->setVar("results", $results);
			$vt_location = new ca_storage_locations($location_id);
			$this->view->setVar("location", $vt_location);
			
			$this->render('listedesversements_html.php');
		}
}