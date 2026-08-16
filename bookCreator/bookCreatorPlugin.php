<?php
/* Book Creator plugin for CollectiveAccess
 *
 * Plugin by idéesculture – Gautier MICHELIN
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License v3. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * ----------------------------------------------------------------------
 */
 
	class bookCreatorPlugin extends BaseApplicationPlugin {
		# -------------------------------------------------------
		protected $description = 'Create printed books directly from CollectiveAccess';
		# -------------------------------------------------------
		private $opo_config;
		private $ops_plugin_path;
		# -------------------------------------------------------
		public function __construct($ps_plugin_path) {
			$this->ops_plugin_path = $ps_plugin_path;
			$this->description = _t('Create printed books directly from CollectiveAccess');
			parent::__construct();
			$this->opo_config = Configuration::load($ps_plugin_path.'/conf/bookCreator.conf');
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
				// Hide the menu entry from users the controller would turn away,
				// rather than letting them click through to an access error. Same
				// rule as the controllers: an explicit role grant, or default_access.
				if (!$o_req->user->canDoAction('can_use_book_editor_plugin')
					&& !(bool)$this->opo_config->get('default_access')) {
					return $pa_menu_bar;
				}

				// The book list is the entry point, not the section editor: the
				// plugin now handles several books, so landing straight in the
				// sections of one of them would presuppose which.
				$default_menu_action = array(
					'displayName' => _t('Book'),
					"default" => array(
						'module' => 'bookCreator',
						'controller' => 'Books',
						'action' => 'Index'
					),
					'navigation' => array(
						"Books" => array(
							'displayName' => _t('Books'),
							"default" => array(
								'module' => 'bookCreator',
								'controller' => 'Books',
								'action' => 'Index'
							)
						),
						"Editor" => array(
							'displayName' => _t('Editor'),
							"default" => array(
								'module' => 'bookCreator',
								'controller' => 'Editor',
								'action' => 'Index'
							)
						)
					)
				);
				$pa_menu_bar['bookCreator_menu'] =
					$default_menu_action
				;
			}
			
			return $pa_menu_bar;
		}

		public function hookRenderWidgets($pa_widgets_config)
		{
			$pa_widgets_config["bookCreatorEditorInfo"] = array(
				"domain" => array(
					"module" => "bookCreator",
					"controller" => "Editor"),
				"handler" => array(
					"module" => "bookCreator",
					"controller" => "Editor",
					"action" => 'Info',
					"isplugin" => true),
				"requires" => array(),
				"parameters" => array()
			);
			return $pa_widgets_config;
		}
		# -------------------------------------------------------
		/**
		 * Add plugin user actions
		 */
		static function getRoleActionList() {
			return array(
				'can_use_book_editor_plugin' => array(
						'label' => _t('Can use book creator functions'),
						'description' => _t('User can use all use book creator functions.')
					)
			);
		}
		
	}
?>