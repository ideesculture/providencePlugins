<?php
/* ----------------------------------------------------------------------
 * etatsMTEPlugin.php :
 * ----------------------------------------------------------------------
 * Plugin d'etats pour le MTE (Mobiliers Classés)
 * Adapté depuis etatObs (Observatoire) pour le contexte MTE
 * ----------------------------------------------------------------------
 */

	class etatsMTEPlugin extends BaseApplicationPlugin {
		# -------------------------------------------------------
		protected $description = "Plugin Etats MTE";
		# -------------------------------------------------------
		private $opo_config;
		private $ops_plugin_path;
		# -------------------------------------------------------
		public function __construct($ps_plugin_path) {
			$this->ops_plugin_path = $ps_plugin_path;
			$this->description = _t("Etats MTE - Mobiliers Classés");
			parent::__construct();
			$this->opo_config = Configuration::load($ps_plugin_path.'/conf/etatsMTE.conf');
		}
		# -------------------------------------------------------
		/**
		 * Override checkStatus() to return true
		 */
		public function checkStatus() {
			return array(
				'description' => $this->getDescription(),
				'errors' => array(),
				'warnings' => array(),
				'available' => true
			);
		}

		/**
		 * Insert into ObjectEditor info (side bar)
		 */
		public function hookAppendToEditorInspector(array $va_params = array()) {
			$t_item = $va_params["t_item"];

			$vs_table_name = $t_item->tableName();
			$vn_item_id = $t_item->getPrimaryKey();

			if ($vs_table_name == "ca_objects") {
				$vs_url = caNavUrl($this->getRequest(), "etatsMTE", "Generer", "ConstatDetat", array("objet" => $vn_item_id));

				$vs_buf = "<div style=\"text-align:center;width:100%;margin:10px 0 20px 0;\">"
					. "<a href='".$vs_url."' style='background-color:#1ab3c8;color:white;padding:10px 6px;border-radius:6px;text-decoration:none;'>"
					. "Constat d'état"
					. "</a></div>";

				$va_params["caEditorInspectorAppend"] = $va_params["caEditorInspectorAppend"] . "<div style='height:2px;'></div>" . $vs_buf;
			}

			return $va_params;
		}

		# -------------------------------------------------------
		/**
		 * Insert menu bar "Catalogues" with two entries
		 */
		public function hookRenderMenuBar($pa_menu_bar) {
			if ($o_req = $this->getRequest()) {
				$va_menu_items = array();

				$va_menu_items['catalogues_standards'] = array(
					'displayName' => _t('Standard'),
					'default' => array(
						'module' => 'etatsMTE',
						'controller' => 'Catalogue',
						'action' => 'Standards'
					)
				);

				$va_menu_items['catalogues_specifiques'] = array(
					'displayName' => _t('Spécifique'),
					'default' => array(
						'module' => 'etatsMTE',
						'controller' => 'Catalogue',
						'action' => 'Specifiques'
					)
				);

				$pa_menu_bar['etatsMTE_catalogues'] = array(
					'displayName' => _t('Catalogue'),
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
			return array();
		}
	}
