<?php
/* ----------------------------------------------------------------------
 * SimpleSudocPlugin.php
 * ----------------------------------------------------------------------
 * CollectiveAccess — Cognitio-Fort
 *
 * Plugin d'import SUDOC (ABES).
 * Récupère les notices Dublin Core depuis l'API SRU publique du SUDOC
 * (par PPN, ISBN, ISSN ou recherche textuelle) et les importe comme
 * ca_objects (par défaut type "revue").
 *
 * Plugin par idéesculture (www.ideesculture.com), GPL v.3.
 * ----------------------------------------------------------------------
 */

class SimpleSudocPlugin extends BaseApplicationPlugin {
	# -------------------------------------------------------
	protected $description = 'Plugin d\'import SUDOC pour CollectiveAccess';
	# -------------------------------------------------------
	private $opo_config;
	private $ops_plugin_path;
	# -------------------------------------------------------
	public function __construct($ps_plugin_path) {
		$this->ops_plugin_path = $ps_plugin_path;
		$this->description = _t('Import SUDOC (ABES)');
		parent::__construct();
		$this->opo_config = Configuration::load($ps_plugin_path . '/conf/SimpleSudoc.conf');
	}
	# -------------------------------------------------------
	public function checkStatus() {
		return [
			'description' => $this->getDescription(),
			'errors'      => [],
			'warnings'    => [],
			'available'   => (bool)$this->opo_config->get('enabled'),
		];
	}
	# -------------------------------------------------------
	static function getRoleActionList() {
		return [
			'can_use_simple_sudoc_plugin' => [
				'label'       => _t('Can use SimpleSudoc plugin'),
				'description' => _t('Grants access to the SimpleSudoc import plugin (ABES SUDOC).'),
			],
		];
	}
	# -------------------------------------------------------
	/**
	 * Insère une entrée "SUDOC" dans le menu Import.
	 */
	public function hookRenderMenuBar($pa_menu_bar) {
		if (!($o_req = $this->getRequest())) { return $pa_menu_bar; }
		if (!$o_req->user || !$o_req->user->canDoAction('can_use_simple_sudoc_plugin')) {
			return $pa_menu_bar;
		}

		$va_menu_items = (isset($pa_menu_bar['Import']['navigation']) && is_array($pa_menu_bar['Import']['navigation']))
			? $pa_menu_bar['Import']['navigation']
			: [];

		$va_menu_items['sudoc'] = [
			'displayName' => 'SUDOC',
			'default' => [
				'module'     => 'SimpleSudoc',
				'controller' => 'SimpleSudoc',
				'action'     => 'Index',
			],
		];

		if (isset($pa_menu_bar['Import'])) {
			$pa_menu_bar['Import']['navigation'] = $va_menu_items;
		} else {
			$pa_menu_bar['Import'] = [
				'displayName' => _t('Import'),
				'navigation'  => $va_menu_items,
			];
		}
		return $pa_menu_bar;
	}
	# -------------------------------------------------------
}
