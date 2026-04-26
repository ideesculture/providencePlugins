<?php

class searchIdnoPlugin extends BaseApplicationPlugin {
	# -------------------------------------------------------
	private $opo_config;
	private $ops_plugin_path;
	# -------------------------------------------------------
	public function __construct($ps_plugin_path) {
		$this->ops_plugin_path = $ps_plugin_path;
		$this->description = _t("Search ca_objects by their identifier (idno/cote/inventory number) with wildcard support. Invoked via /searchIdno/Do/Search by a search field added to the page header.");
		parent::__construct();

		$conf_file = $ps_plugin_path.'/conf/local/searchIdno.conf';
		if (!file_exists($conf_file)) {
			$conf_file = $ps_plugin_path.'/conf/searchIdno.conf';
		}
		$this->opo_config = Configuration::load($conf_file);
	}
	# -------------------------------------------------------
	public function checkStatus() {
		return array(
			'description' => $this->getDescription(),
			'errors' => array(),
			'warnings' => array(),
			'available' => ((bool) $this->opo_config->get('enabled'))
		);
	}
	# -------------------------------------------------------
	static function getRoleActionList() {
		return array();
	}
}
