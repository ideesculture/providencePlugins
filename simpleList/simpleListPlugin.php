<?php
/* ----------------------------------------------------------------------
 * simpleList plugin
 * ----------------------------------------------------------------------
 * Lightweight list / vocabulary editor plugin for Providence (CollectiveAccess).
 * Renders configured ca_lists as a tree, lets editors browse items and bulk-add
 * new values from a textarea.
 *
 * Plugin by idéesculture (www.ideesculture.com), GPL v.3.
 * ----------------------------------------------------------------------
 */

class simpleListPlugin extends BaseApplicationPlugin {
	# -------------------------------------------------------
	protected $description = 'Simple list & vocabulary editor for Providence';
	# -------------------------------------------------------
	private $opo_config;
	private $ops_plugin_path;
	# -------------------------------------------------------
	public function __construct($ps_plugin_path) {
		$this->ops_plugin_path = $ps_plugin_path;
		$this->description = _t('Simple list & vocabulary editor');
		parent::__construct();
		$this->opo_config = Configuration::load($ps_plugin_path.'/conf/simpleList.conf');
	}
	# -------------------------------------------------------
	public function checkStatus() {
		return [
			'description' => $this->getDescription(),
			'errors' => [],
			'warnings' => [],
			'available' => (bool)$this->opo_config->get('enabled'),
		];
	}
	# -------------------------------------------------------
	static function getRoleActionList() {
		return [
			'can_use_simplelist_plugin' => [
				'label' => _t('Can use simpleList plugin'),
				'description' => _t('Grants access to the simpleList list/vocabulary editor.'),
			],
		];
	}
	# -------------------------------------------------------
	/**
	 * Insert a menu entry for each configured "page" (a page = a labelled set
	 * of ca_lists list_codes to display together).
	 */
	public function hookRenderMenuBar($pa_menu_bar) {
		$pages = $this->opo_config->get('pages');
		if (!is_array($pages)) { return $pa_menu_bar; }

		if (!($o_req = $this->getRequest())) { return $pa_menu_bar; }
		if (!$o_req->user || !$o_req->user->canDoAction('can_use_simplelist_plugin')) {
			return $pa_menu_bar;
		}

		foreach ($pages as $page) {
			$page_config = $this->opo_config->get($page);
			if (!is_array($page_config) || !isset($page_config['menu'])) { continue; }
			$pa_menu_bar[$page_config['menu']]['navigation']['simplelist_'.$page] = [
				'displayName' => $page_config['label'],
				'default' => [
					'module' => 'simpleList',
					'controller' => 'Editor',
					'action' => 'Thesaurus/page/'.$page,
				],
			];
		}
		return $pa_menu_bar;
	}
	# -------------------------------------------------------
}
