<?php
/* ----------------------------------------------------------------------
 * providencePluginUserMenuPlugin.php :
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2010 Whirl-i-Gig
 *
 * This plugin : IdéesCulture 2015-2026 www.ideesculture.com
 *
 * ----------------------------------------------------------------------
 */
	class providencePluginUserMenuPlugin extends BaseApplicationPlugin {
		# -------------------------------------------------------
		protected $description = 'Plugin for CollectiveAccess moving the content of the downside bar to a button';
		# -------------------------------------------------------
			private $opo_config;
			private $ops_plugin_path;
		# -------------------------------------------------------
		public function __construct($ps_plugin_path) {
			$this->ops_plugin_path = $ps_plugin_path;
			$this->description = _t('Deletes the black bar and adds a button on top of the screen to log out and more.');
			parent::__construct();
			$this->opo_config = Configuration::load($ps_plugin_path.'/conf/providencePluginUserMenu.conf');
		}
		# -------------------------------------------------------
		/**
		 * Override checkStatus() to return true - the providencePluginUserMenuPlugin always initializes ok... (part to complete)
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

				// We define the content of a new main menu item
				if (isset($pa_menu_bar['providencePluginUserMenu_menu'])) {
					$va_menu_items = $pa_menu_bar['providencePluginUserMenu_menu']['navigation'];
					if (!is_array($va_menu_items)) { $va_menu_items = array(); }
				} else {
					$va_menu_items = array();
				}

				$va_menu_items["Mes_preferences"] = array(
					'displayName' => _t('My preferences'),
					'default' => array(
						'module' => 'system',
						'controller' => 'Preferences',
						'action' => 'EditUIPrefs'
					)
				);

				$va_menu_items["Deconnexion"] = array(
					'displayName' => _t('Log out'),
					'default' => array(
						'module' => 'system',
						'controller' => 'auth',
						'action' => 'logout'
					)
				);

				// CSS removing the original footer
				MetaTagManager::addLink('stylesheet', __CA_URL_ROOT__."/app/plugins/providencePluginUserMenu/css/providencePluginUserMenu.css", 'text/css');

				// Optional CSS adding a custom footer line at the bottom of the page
				if ((bool)$this->opo_config->get('footer')) {
					MetaTagManager::addLink('stylesheet', __CA_URL_ROOT__."/app/plugins/providencePluginUserMenu/css/providencePluginUserMenuFooter.css", 'text/css');
				}

				$pa_menu_bar['providencePluginUserMenu_menu'] = array(
					'displayName' => _t('User'),
					'navigation' => $va_menu_items
				);
			}

			return $pa_menu_bar;
		}
		# -------------------------------------------------------
		/**
		 * Add plugin user actions
		 */
		static function getRoleActionList() {
			return array(
				'can_use_providencePluginUserMenu_plugin' => array(
					'label' => _t('Can use'),
					'description' => _t('User can use the plugin.')
				)
			);
		}

	}
?>
