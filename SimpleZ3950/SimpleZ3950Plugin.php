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
 
	class SimpleZ3950Plugin extends BaseApplicationPlugin {
		# -------------------------------------------------------
		protected $description = 'Simple Z3950 import plugin for Collectiveaccess';
		# -------------------------------------------------------
		private $opo_config;
		private $ops_plugin_path;
		# -------------------------------------------------------
		public function __construct($ps_plugin_path) {
			$this->ops_plugin_path = $ps_plugin_path;
			$this->description = _t('Simple Z3950 import');
			parent::__construct();
			$conf_file = $ps_plugin_path.'/conf/SimpleZ3950.conf';
			$this->opo_config = Configuration::load($conf_file);
			
			// Note : simple import from conf
			// $this->opo_config->getAssoc('associative_array')
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
				'available' => ((bool)$this->opo_config->get('enabled'))
			);
		}
		# -------------------------------------------------------
		/**
		 * Insert activity menu
		 */	 
		public function hookRenderMenuBar($pa_menu_bar) {
			if ($o_req = $this->getRequest()) {
				//if (!$o_req->user->canDoAction('can_use_media_import_plugin')) { return true; }
				
				if (isset($pa_menu_bar['Import'])) {
					$va_menu_items = $pa_menu_bar['Import']['navigation'];
					if (!is_array($va_menu_items)) { $va_menu_items = array(); }
				} else {
					$va_menu_items = array();
				}

				$va_menu_items["z3950"] = array(
					'displayName' => "Z39.50",
					"default" => [
						'module' => 'SimpleZ3950',
						'controller' => 'SimpleZ3950',
						'action' => 'Index'
					]
				);
				if (isset($pa_menu_bar['Import'])) {
					$pa_menu_bar['Import']['navigation'] = $va_menu_items;
				} else {
					$pa_menu_bar['Import'] = array(
						'displayName' => _t('Import'),
						'navigation' => $va_menu_items
					);
				}
			} 
			
			return $pa_menu_bar;
		}
		# -------------------------------------------------------
		/**
		 * Add plugin user actions
		 */
		static function getRoleActionList() {
			return array(
				'can_use_simple_z3950_plugin' => array(
						'label' => _t('Can use SimpleZ3950 plugin'),
						'description' => _t('Can use SimpleZ3950 plugin')
					)
			);
		}
		
	}
?>
