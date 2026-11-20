<?php
/* ----------------------------------------------------------------------
 * simpleListEditor
 * ----------------------------------------------------------------------
 * List & list values editor plugin for Providence - CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Plugin by idéesculture (www.ideesculture.com)
 * This plugin is published under GPL v.3. Please do not remove this header
 * and add your credits thereafter.
 *
 * File modified by :
 * ----------------------------------------------------------------------
 */
 
	class simpleListPlugin extends BaseApplicationPlugin {
		# -------------------------------------------------------
		protected $description = 'SimpleListEditor for CollectiveAccess';
		# -------------------------------------------------------
		private $opo_config;
		private $ops_plugin_path;
		# -------------------------------------------------------
		public function __construct($ps_plugin_path) {
			$this->ops_plugin_path = $ps_plugin_path;
			$this->description = _t('Archives plugin');
			parent::__construct();
			$this->opo_config = Configuration::load($ps_plugin_path.'/conf/simpleList.conf');
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
		 * Add plugin user actions
		 */
		static function getRoleActionList() {
			return array(
				'can_use_simplelist_plugin' => array(
						'label' => _t('Can use simplelist functions'),
						'description' => ""
					)
			);
		}
        # -------------------------------------------------------
        /**
         * Insert activity menu
         */
        public function hookRenderMenuBar($pa_menu_bar) {
			//var_dump($pa_menu_bar);die();
			//var_dump($this->opo_config);die();
			$pages = $this->opo_config->get("pages");
			//var_dump($pa_menu_bar);
			//die();
            if ($o_req = $this->getRequest()) {
                //if (!$o_req->user->canDoAction('can_use_media_import_plugin')) { return true; }
				foreach($pages as $page) {
					$page_config = $this->opo_config->get($page);
					$pa_menu_bar[$page_config["menu"]]["navigation"]['simplelist_'.$page] = array(
						'displayName' => $page_config["label"],
						"default" => array(
							'module' => 'simpleList',
							'controller' => 'Editor',
							'action' => 'Thesaurus/page/'.$page
						)
					);
				}
                //var_dump($pa_menu_bar["find"]["navigation"]);die();

            }

            return $pa_menu_bar;
        }
	}
?>