<?php
/* ----------------------------------------------------------------------
 * SimplePubmedPlugin.php
 * ----------------------------------------------------------------------
 * CollectiveAccess — Centre Antipoisons
 *
 * Plugin d'import PubMed (NCBI E-utilities)
 * Récupère les fiches bibliographiques depuis PubMed par PMID ou
 * recherche textuelle et les importe dans CollectiveAccess.
 * ----------------------------------------------------------------------
 */

class SimplePubmedPlugin extends BaseApplicationPlugin {
	# -------------------------------------------------------
	protected $description = 'Plugin d\'import PubMed pour CollectiveAccess';
	# -------------------------------------------------------
	private $opo_config;
	private $ops_plugin_path;
	# -------------------------------------------------------
	public function __construct($ps_plugin_path) {
		$this->ops_plugin_path = $ps_plugin_path;
		$this->description = _t('Import PubMed');
		parent::__construct();
		$this->opo_config = Configuration::load($ps_plugin_path . '/conf/SimplePubmed.conf');
	}
	# -------------------------------------------------------
	public function checkStatus() {
		return array(
			'description' => $this->getDescription(),
			'errors'      => array(),
			'warnings'    => array(),
			'available'   => ((bool)$this->opo_config->get('enabled'))
		);
	}
	# -------------------------------------------------------
	/**
	 * Ajout de l'entrée dans le menu Import
	 */
	public function hookRenderMenuBar($pa_menu_bar) {
		if ($o_req = $this->getRequest()) {

			if (isset($pa_menu_bar['Import'])) {
				$va_menu_items = $pa_menu_bar['Import']['navigation'];
				if (!is_array($va_menu_items)) { $va_menu_items = array(); }
			} else {
				$va_menu_items = array();
			}

			$va_menu_items['pubmed'] = array(
				'displayName' => 'PubMed',
				'default' => array(
					'module'     => 'SimplePubmed',
					'controller' => 'SimplePubmed',
					'action'     => 'Index'
				)
			);

			if (isset($pa_menu_bar['Import'])) {
				$pa_menu_bar['Import']['navigation'] = $va_menu_items;
			} else {
				$pa_menu_bar['Import'] = array(
					'displayName' => _t('Import'),
					'navigation'  => $va_menu_items
				);
			}
		}
		return $pa_menu_bar;
	}
	# -------------------------------------------------------
	static function getRoleActionList() {
		return array(
			'can_use_simple_pubmed_plugin' => array(
				'label'       => _t('Can use SimplePubmed plugin'),
				'description' => _t('Can use SimplePubmed plugin')
			)
		);
	}
}
?>
