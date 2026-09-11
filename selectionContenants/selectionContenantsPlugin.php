<?php
/* ----------------------------------------------------------------------
 * selectionContenantsPlugin.php
 * ----------------------------------------------------------------------
 * === D2026-0103 — écran de sélection des contenants : début ===
 *
 * Marché INRAP 036SE2025 — D2026-0103 (condition de livraison).
 * Écran de sélection des contenants d'un versement : bouton dans
 * l'inspecteur des fiches mouvement de type « versement », ouvrant une
 * fenêtre popup de sélection (recherche, filtres d'affichage, sélection
 * conservée à travers les filtrages, validation unique).
 *
 * Reprise de l'UI/UX du plugin d'origine app/oldPlugins/BordereauVersement/
 * (décisions Gautier des 09/08 et 12/08/2026), avec les corrections :
 *  - aucun code de type en dur (résolution par code de liste) ;
 *  - pas de CDN externe (la prod INRAP est derrière un pare-feu) ;
 *  - requêtes paramétrées, erreurs gérées, contrôle des droits ;
 *  - le changement de type (« versé ») et les recalculs restent au worker
 *    support/bin/recalc_worker.php via la file _inrap_recalc_queue :
 *    le contrôleur Validate ne fait que poser/retirer les relations
 *    mouvement→objet puis enfiler le versement dans la file.
 *
 * Le popup est déplacé sous <body> avant affichage : précédent connu du
 * D2026-0102 (panneau resté sous l'exposeMask dans le sous-arbre de
 * l'inspecteur → page grisée sans modale, cf. etatsInrapPlugin LOT10).
 * === D2026-0103 — écran de sélection des contenants : fin ===
 * ----------------------------------------------------------------------
 */

class selectionContenantsPlugin extends BaseApplicationPlugin {
	# -------------------------------------------------------
	protected $description = "Écran de sélection des contenants d'un versement (D2026-0103)";
	# -------------------------------------------------------
	private $opo_config;
	private $ops_plugin_path;
	# -------------------------------------------------------
	public function __construct($ps_plugin_path) {
		$this->ops_plugin_path = $ps_plugin_path;
		$this->description = _t("Sélection des contenants d'un versement (INRAP D2026-0103)");
		parent::__construct();
		$this->opo_config = Configuration::load($ps_plugin_path.'/conf/selectionContenants.conf');
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
	/**
	 * === D2026-0103 — écran de sélection des contenants : début ===
	 * Bouton « Sélection des contenants » dans l'inspecteur des mouvements
	 * de type « versement », et coquille du popup (le contenu est chargé en
	 * AJAX depuis selectionContenants/Selection/SelectObject).
	 *
	 * ⚠️ On CONCATÈNE toujours caEditorInspectorAppend (l'écrasement `=`
	 * de l'ancien code faisait disparaître les boutons des autres plugins).
	 */
	public function hookAppendToEditorInspector(array $va_params = array()) {
		$t_item = $va_params["t_item"] ?? null;
		if (!$t_item) { return $va_params; }

		if (($t_item->tableName() !== "ca_movements")
			|| ($t_item->getTypeCode() !== "versement")) {
			return $va_params;
		}

		$o_req = $this->getRequest();
		if (!$o_req || !$o_req->isLoggedIn() || !$o_req->user->canDoAction('can_edit_ca_movements')) {
			return $va_params;	// pas le droit d'éditer les mouvements → pas de bouton
		}

		// 11/09/2026 GM (ticket 7963, point 9) : tant que la feuille n'est pas enregistrée,
		// elle n'a pas d'identifiant, et le bouton ne peut pas être construit — l'URL de
		// sélection a besoin du movement_id. L'écran restait donc muet, et l'utilisateur
		// cherchait un bouton absent. Laurent ne demande pas de changer ce comportement,
		// il demande qu'on l'explique : on affiche un encart à la place du bouton.
		$vn_movement_id = (int)$t_item->getPrimaryKey();
		if (!$vn_movement_id) {
			$vs_avis = '<div style="width:100%;margin:10px 0 20px 0;padding:10px 12px;'
				. 'background-color:#fff4d5;border-left:4px solid #1ab3c8;border-radius:4px;'
				. 'font-size:12px;line-height:1.45;box-sizing:border-box;">'
				. '<strong>Enregistrez d\'abord la feuille de versement.</strong><br/>'
				. 'Le bouton « Sélection des contenants » et le calendrier de la date de versement '
				. 'apparaîtront ensuite : ils ont besoin du numéro de la feuille, qui est attribué '
				. 'à l\'enregistrement.'
				. '</div>';
			$va_params["caEditorInspectorAppend"] = ($va_params["caEditorInspectorAppend"] ?? '') . $vs_avis;
			return $va_params;
		}

		$vs_url_select = caNavUrl($o_req, "selectionContenants", "Selection", "SelectObject", array("movement_id" => $vn_movement_id));
		$vs_css_url    = __CA_URL_ROOT__."/app/plugins/selectionContenants/assets/css/selectionContenants.css";

		$vs_buf = <<<HTML
<div style="text-align:center;width:100%;margin:10px 0 20px 0;">
	<a href="#" id="caSelContBtn" style="background-color:#1ab3c8;color:white;padding:10px 6px;border-radius:6px;text-decoration:none;display:inline-block;">Sélection des contenants</a>
</div>
<link rel="stylesheet" href="{$vs_css_url}"/>
<div id="caSelContOverlay" style="display:none;"></div>
<div id="caSelContPanel" style="display:none;" role="dialog" aria-modal="true" aria-label="Sélection des contenants">
	<div id="caSelContPanelHead">
		<span id="caSelContPanelTitle">Sélection des contenants du versement</span>
		<a href="#" id="caSelContClose" title="Fermer">&times;</a>
	</div>
	<div id="caSelContPanelContent"><div class="selcont-loading">Chargement de la liste des contenants…</div></div>
</div>
<script type="text/javascript">
jQuery(document).ready(function() {
	// Popup déplacé à la racine du <body> AVANT toute utilisation : posé dans
	// l'inspecteur il resterait sous le voile (précédent D2026-0102 / exposeMask).
	try { jQuery('#caSelContOverlay,#caSelContPanel').appendTo('body'); } catch(e) {}

	var caSelContUrl = '{$vs_url_select}';
	var caSelContLoaded = false;

	function caSelContOpen() {
		jQuery('#caSelContOverlay').show();
		jQuery('#caSelContPanel').show();
		jQuery('body').addClass('selcont-noscroll');
		if (!caSelContLoaded) {
			jQuery('#caSelContPanelContent').load(caSelContUrl, function(resp, status) {
				if (status === 'error') {
					jQuery('#caSelContPanelContent').html('<div class="selcont-error">Le chargement de la liste des contenants a échoué. Fermez la fenêtre et réessayez.</div>');
					return;
				}
				caSelContLoaded = true;
			});
		}
		return false;
	}
	function caSelContCloseFn() {
		jQuery('#caSelContPanel').hide();
		jQuery('#caSelContOverlay').hide();
		jQuery('body').removeClass('selcont-noscroll');
		return false;
	}

	jQuery('#caSelContBtn').on('click', caSelContOpen);
	jQuery('#caSelContClose').on('click', caSelContCloseFn);
	jQuery('#caSelContOverlay').on('click', caSelContCloseFn);
	jQuery(document).on('keydown', function(e) {
		if ((e.key === 'Escape') && jQuery('#caSelContPanel').is(':visible')) { caSelContCloseFn(); }
	});
	// Le contenu AJAX signale sa fermeture volontaire par cet événement.
	jQuery(document).on('selcont:close', caSelContCloseFn);
	// Après validation, le prochain affichage rechargera l'état réel en base.
	jQuery(document).on('selcont:validated', function() { caSelContLoaded = false; });
});
</script>
HTML;

		$va_params["caEditorInspectorAppend"] = ($va_params["caEditorInspectorAppend"] ?? '') . $vs_buf;
		return $va_params;
	}
	/** === D2026-0103 — écran de sélection des contenants : fin === */
	# -------------------------------------------------------
	static function getRoleActionList() {
		return array();
	}
}
