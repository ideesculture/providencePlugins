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

	class exportInrapPlugin extends BaseApplicationPlugin {
		# -------------------------------------------------------
		protected $description = 'CA : exportInrapPlugin';
		# -------------------------------------------------------
			private $opo_config;
			private $ops_plugin_path;
		# -------------------------------------------------------
		public function __construct($ps_plugin_path) {
			$this->ops_plugin_path = $ps_plugin_path;
			$this->description = "exportInrapPlugin";
			parent::__construct();
			$this->opo_config = Configuration::load($ps_plugin_path.'/conf/exportInrap.conf');
		}
		
		/**
		 * Override checkStatus() to return true - the providencePluginUserMenuPlugin always initializes ok... (part to complete)
		 */
		public function checkStatus() {
			return array(
				'description' => $this->getDescription(),
				'errors' => array(),
				'warnings' => array(),
				'available' => 1 //((bool)$this->opo_config->get('enabled'))
			);
		}

		# -------------------------------------------------------
		/**
		 * Add plugin user actions
		 */
		static function getRoleActionList() {
			return array(
			);		
		}
		
		# -------------------------------------------------------
		/**
		 * Add plugin user actions
		 */
		public function hookGetRoleActionList($pa_role_list) {
		
			return $pa_role_list;
		}

		 /**
		 * Insert activity menu
		 */
		public function hookRenderMenuBar($pa_menu_bar) {
			if ($o_req = $this->getRequest()) {
				//if (!$o_req->user->canDoAction('can_use_media_import_plugin')) { return true; }
				$pa_menu_bar["manage"]["navigation"]['exportInrap'] = array(
					'displayName' => $this->opo_config->get('menu_title'),
					"default" => array(
						'module' => 'exportInrap',
						'controller' => 'Export',
						'action' => 'Index'
					)
				);
				//var_dump($pa_menu_bar["find"]["navigation"]);die();
			}

			return $pa_menu_bar;
		}

		public function hookAppendToEditorInspector(array $va_params = array()) {

			$t_item = $va_params["t_item"];
	
			$vs_table_name = $t_item->tableName();
			$vn_item_id = $t_item->getPrimaryKey();
			$vn_code = $t_item->getTypeCode();

			switch ($vs_table_name) {
				case "ca_sets":
					if ($t_item->get("table_num") == 57){
						$vs_url = caNavUrl($this->getRequest(), "exportInrap", "Export", "Index", array("set_id"=>$vn_item_id));
						$va_params["caEditorInspectorAppend"] = $va_params["caEditorInspectorAppend"] . "<div style=\"text-align:center;width:100%;margin:10px 0 20px 0;\"> <span class='put-in-box-button'><a href='$vs_url' class='button'>Catalogue d'objets</a></span></div>";
					}
					break;
				case "ca_occurrences":
					if ($t_item->get("type_id") == 116){
						$vs_url = caNavUrl($this->getRequest(), "exportInrap", "Export", "Index", array("occurrence_id"=>$vn_item_id));
						$va_params["caEditorInspectorAppend"] = $va_params["caEditorInspectorAppend"] . "<div style=\"text-align:center;width:100%;margin:10px 0 20px 0;\"> <a href='$vs_url' class='button' style='background-color:#1ab3c8;color:white;padding:10px 6px;border-radius:6px;'>Catalogue d'objets</a></div>";
					}
					break;
				
				default:
					$vs_url = "";
					break;

			}

			return $va_params;
		}

	}
?>
