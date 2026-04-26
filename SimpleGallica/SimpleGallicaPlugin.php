<?php
/* ----------------------------------------------------------------------
 * SimpleGallicaPlugin.php
 * ----------------------------------------------------------------------
 * CollectiveAccess — Cognitio-Fort
 *
 * Plugin d'import Gallica (BnF).
 * Récupère les notices (Dublin Core) depuis l'API Gallica par ARK direct
 * ou via SRU et les importe comme ca_objects, en attachant l'image
 * haute résolution comme représentation primaire.
 *
 * Plugin par idéesculture (www.ideesculture.com), GPL v.3.
 * ----------------------------------------------------------------------
 */

class SimpleGallicaPlugin extends BaseApplicationPlugin {
	# -------------------------------------------------------
	protected $description = 'Plugin d\'import Gallica pour CollectiveAccess';
	# -------------------------------------------------------
	private $opo_config;
	private $ops_plugin_path;
	# -------------------------------------------------------
	public function __construct($ps_plugin_path) {
		$this->ops_plugin_path = $ps_plugin_path;
		$this->description = _t('Import Gallica (BnF)');
		parent::__construct();
		$this->opo_config = Configuration::load($ps_plugin_path . '/conf/SimpleGallica.conf');
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
			'can_use_simple_gallica_plugin' => [
				'label'       => _t('Can use SimpleGallica plugin'),
				'description' => _t('Grants access to the SimpleGallica import plugin (BnF Gallica).'),
			],
		];
	}
	# -------------------------------------------------------
	/**
	 * Insère une entrée "Gallica" dans le menu Import.
	 */
	public function hookRenderMenuBar($pa_menu_bar) {
		if (!($o_req = $this->getRequest())) { return $pa_menu_bar; }
		if (!$o_req->user || !$o_req->user->canDoAction('can_use_simple_gallica_plugin')) {
			return $pa_menu_bar;
		}

		$va_menu_items = (isset($pa_menu_bar['Import']['navigation']) && is_array($pa_menu_bar['Import']['navigation']))
			? $pa_menu_bar['Import']['navigation']
			: [];

		$va_menu_items['gallica'] = [
			'displayName' => 'Gallica',
			'default' => [
				'module'     => 'SimpleGallica',
				'controller' => 'SimpleGallica',
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
