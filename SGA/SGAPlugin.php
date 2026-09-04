<?php
/* ----------------------------------------------------------------------
 * mediaImportPlugin.php : 
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
 
	class SGAPlugin extends BaseApplicationPlugin {
		# -------------------------------------------------------
		protected $description = "Plugin SGA INRAP";
		# -------------------------------------------------------
		private $opo_config;
		private $ops_plugin_path;
		# -------------------------------------------------------
		public function __construct($ps_plugin_path) {
			$this->ops_plugin_path = $ps_plugin_path;
			$this->description = _t("SGA");
			parent::__construct();
			$this->opo_config = Configuration::load($ps_plugin_path.'/conf/sga.conf');
			
		}
		# -------------------------------------------------------
		/**
		 * Override checkStatus() to return true - the statisticsViewerPlugin always initializes ok... (part to complete)
		 */
		public function checkStatus() {
			
			return array(
				'description' => $this->getDescription(),
				'errors' => array(),
				'warnings' => array(),
				'available' => true
			);
		}
		# -------------------------------------------------------
		/**
		 * Insert activity menu
		 */
		public function hookRenderMenuBar($pa_menu_bar) {

			if ($o_req = $this->getRequest()) {
				//$id_accepted = [1, 3];
                //if(!in_array($o_req->user->get("user_id"), $id_accepted)){ return true;}

				if (isset($pa_menu_bar['sga_menu'])) {
					$va_menu_items = $pa_menu_bar['sga_menu']['navigation'];
					if (!is_array($va_menu_items)) { $va_menu_items = array(); }
				} else {
					$va_menu_items = array();
				}


                $va_menu_items[1] = array(
                    'displayName' => "Liste des opérations importées SGA",
                    'requires' => array(), 
                    "default" => array(
                        'module' => 'SGA',
                        'controller' => 'SGA',
                        'action' => 'IndexImporte'
                    )
                );
				$va_menu_items[2] = array(
                    'displayName' => "Liste des opérations non importées SGA",
                    'requires' => array(), 
                    "default" => array(
                        'module' => 'SGA',
                        'controller' => 'SGA',
                        'action' => 'IndexNonImporte'
                    )
                );

                $pa_menu_bar['sga_menu'] = array(
					'displayName' => _t('SGA'),
					'navigation' => $va_menu_items,
					'requires' => array() 
				);
			}

			return $pa_menu_bar;
		}

        # -------------------------------------------------------
		/**
		 * Add plugin user actions
		 */
		static function getRoleActionList() {
			return array();
		}

		# -------------------------------------------------------
		/**
         * Insert into ObjectEditor info (side bar)
         */
        public function hookAppendToEditorInspector(array $va_params = array()) {

			//$id_accepted = [1, 3];
            //if(!in_array($this->getRequest()->user->get("user_id"), $id_accepted)){ return true;}
            $t_item = $va_params["t_item"];

            $vs_table_name = $t_item->tableName();
            $vn_item_id = $t_item->getPrimaryKey();
            $vn_code = $t_item->getTypeCode();
			$value = array("Centre Ile de France" => "DIR CIF", "Grand Ouest" => "DIR GO", "Grand Est" => "DIR GE", "Auvergne-Rhône-Alpes" => "DIR ARA", "Hauts-de-France" => "DIR HDF", "Midi-Méditerranée" => "DIR MIDIMED", "Outre-mer" => "DIR NAOM", "Nouvelle Aquitaine" => "DIR NAOM", "Bourgogne-Franche-Comté" => "DIR BFC");

		
			if (($vs_table_name == "ca_collections")) {
				
				$idno = $t_item->getWithTemplate("^ca_collections.idno");
				$o_data = new Db();
				$qr_results = $o_data->query("select * from _sga_comodo where idno = \"${idno}\";");				
				$result = $qr_results->getAllRows()[0];
				$is_different = false;
				$different = [];
				foreach ($result as $metadata => $data){
					if ($metadata == "id" || $metadata == "ro_label" || empty($data)) {
						continue;
					}
					$data_to_compare = $t_item->getWithTemplate("^ca_collections." . $metadata);
					if ($metadata == "inrap_date_planification") {
						$data_to_compare = $t_item->getWithTemplate("^ca_collections.inrap_date_versement." . $metadata);
					}
					if ($metadata == "surface_OA" && (empty($data) && $data_to_compare == 0) && (empty($data_to_compare) && $data == 0)) {
						$data_to_compare = "";
						$data = "";
					}
					if ($metadata == "ro") {
						$data_to_compare = $t_item->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.idno</unit>");
					}
					if ($metadata == "sra") {
						$data_to_compare = $t_item->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='attribue' restrictToTypes='sra'>^ca_entities.idno</unit>");
					}
					if ($metadata == "dir_inrap") {
						$value = array_flip($value);
						$data_to_compare = $value[$t_item->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='DIR'>^ca_entities.preferred_labels.displayname</unit>")];
					}
					if ($metadata == "prescripteur") {
						$data_to_compare = $t_item->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='suivi_par'>^ca_entities.preferred_labels.displayname</unit>");
					}
					if ($metadata == "autorisation_fouille") {
						$data_to_compare = $t_item->getWithTemplate("^ca_collections.autorisation_fouille.autorisation_num");
					}
					if ($metadata == "autorisation_date") {
						$data_to_compare = $t_item->getWithTemplate("^ca_collections.autorisation_fouille.autorisation_date");
					}
		
					if ($metadata == "dir_adj_st") {
						$data_to_compare = $t_item->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='dast'>^ca_entities.preferred_labels.displayname</unit>");
					}
					if ($metadata == "commune") {
						$data_to_compare = $t_item->getWithTemplate("<unit relativeTo='ca_places'>^ca_places.preferred_labels.name</unit>");
					}
		
					if ($data_to_compare == ";") {
						$data_to_compare = "";
					}
					if ($metadata == "CodeINSEE") {
						$data_to_compare = $t_item->getWithTemplate("<unit relativeTo='ca_places'>^ca_places.idno</unit>");
					}
					if ($metadata == "AnneeDebutTerrain"){
						$data_to_compare =  $t_item->getWithTemplate("^ca_collections.inrap_annee_inter");
					}
					if ($metadata == "NomOpeRattachement"){
						$data_to_compare =  $t_item->getWithTemplate("^ca_collections.inrap_op_rattachement.inrap_op_rattachement_txt");
					}
					if (str_replace(' ', '', strtolower($data)) != str_replace(' ', '', strtolower($data_to_compare))){
						

						array_push($different, [$metadata, $data, $data_to_compare]);
						$is_different=true;
					}
				}
				
				if ($is_different){
					
					$vs_buf = "<div style=\"text-align:center;width:100%;margin:10px 0 20px 0;\">"
                    . "<a id='sgaButton' href='/index.php/SGA/SGA/Compare/id/".$vn_item_id."/type/edit' style='background-color:#1ab3c8;color:white;padding:10px 6px;border-radius:6px;'>"
                    . "Importation donnée du SGA"
                    . "</a></div><div style='height:2px;'></div>";

                	$va_params["caEditorInspectorAppend"] .= $vs_buf;
				}
				
				//var_dump($different);
			
				//die();

			}

            return $va_params;
        }

		
	}
	