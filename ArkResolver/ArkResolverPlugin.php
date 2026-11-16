<?php
/* ----------------------------------------------------------------------
 * ArkResolverPlugin.php
 * ----------------------------------------------------------------------
 * Résout les identifiants ARK (ark:/<NAAN>/<nom>) vers la page de détail
 * Pawtucket de la fiche dont l'élément configuré (element_code) contient cet ARK.
 *
 * Point d'entrée : <__CA_URL_ROOT__>/index.php/ArkResolver/Resolve/Index?ark=<nom>
 * (alimenté par une règle de réécriture dans le .htaccess de pawtucket2).
 * ----------------------------------------------------------------------
 */

class ArkResolverPlugin extends BaseApplicationPlugin {
	# -------------------------------------------------------
	public function __construct($ps_plugin_path) {
		$this->description = _t('Résout les ARK vers la fiche publique correspondante');
		$this->opo_config = Configuration::load($ps_plugin_path.'/conf/ArkResolver.conf');
		parent::__construct();
	}
	# -------------------------------------------------------
	public function checkStatus() {
		return array(
			'description' => $this->getDescription(),
			'errors' => array(),
			'warnings' => array(),
			'available' => ((bool)$this->opo_config->get('enabled'))
		);
	}
	# -------------------------------------------------------
	static public function getRoleActionList() {
		return array();
	}
	# -------------------------------------------------------
}
