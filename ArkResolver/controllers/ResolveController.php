<?php
/* ----------------------------------------------------------------------
 * ResolveController.php : résolution ARK -> page de détail
 * ----------------------------------------------------------------------
 * - recherche exacte dans l'élément configuré (pas de moteur de recherche) ;
 * - comparaison sans tenir compte des tirets (le résolveur N2T les retire) ;
 * - 302 vers la fiche si une seule fiche non supprimée et accessible ;
 * - 404 si rien (ou seulement des fiches non accessibles), 410 si seulement
 *   des fiches supprimées, 409 si plusieurs fiches accessibles ;
 * - URL générées via caDetailUrl() (respecte __CA_URL_ROOT__).
 * ----------------------------------------------------------------------
 */

require_once(__CA_LIB_DIR__.'/BasePluginController.php');
require_once(__CA_APP_DIR__.'/helpers/accessHelpers.php');
require_once(__CA_MODELS_DIR__.'/ca_metadata_elements.php');

class ResolveController extends BasePluginController {
	# -------------------------------------------------------
	const MAX_NAME_LENGTH = 128;
	# -------------------------------------------------------
	public function Index() {
		$ark_raw = (string)$this->request->getParameter('ark', pString, 'GET');

		if (!$this->config->get('enabled')) {
			return $this->respondError(404, $ark_raw);
		}

		$naan = trim((string)$this->config->get('naan'));
		$element_code = trim((string)$this->config->get('element_code'));
		$table = trim((string)$this->config->get('table'));

		// Configuration
		$element_id = $element_code ? ca_metadata_elements::getElementID($element_code) : null;
		$table_num = $table ? Datamodel::getTableNum($table) : null;
		$t_instance = $table_num ? Datamodel::getInstanceByTableNum($table_num, true) : null;
		if (!preg_match('!^[0-9]+$!', $naan) || !$element_id || !$t_instance
			|| !$t_instance->hasField('deleted') || !$t_instance->hasField('access')) {
			error_log('ArkResolver: configuration invalide (naan, element_code ou table)');
			return $this->respondError(500, $ark_raw);
		}
		$pk = $t_instance->primaryKey();
		$table = $t_instance->tableName();

		// Validation et normalisation du nom
		$name = self::normalizeName($ark_raw);
		if ($name === null) {
			return $this->respondError(404, $ark_raw);
		}

		// Recherche exacte, tirets ignorés des deux côtés
		$db = new Db();
		$qr = $db->query("
			SELECT DISTINCT t.{$pk} id, t.deleted, t.access
			FROM ca_attribute_values cav
			INNER JOIN ca_attributes a ON a.attribute_id = cav.attribute_id
			INNER JOIN {$table} t ON t.{$pk} = a.row_id
			WHERE cav.element_id = ?
			  AND a.table_num = ?
			  AND REPLACE(TRIM(cav.value_longtext1), '-', '') IN (?, ?, ?)
		", array(
			(int)$element_id, (int)$table_num,
			"ark:/{$naan}/{$name}", "ark:{$naan}/{$name}", $name
		));
		if ($db->numErrors()) {
			error_log('ArkResolver: erreur SQL lors de la résolution');
			return $this->respondError(500, $ark_raw);
		}
		$rows = array();
		while ($qr->nextRow()) {
			$rows[] = array('id' => (int)$qr->get('id'), 'deleted' => (int)$qr->get('deleted'), 'access' => (int)$qr->get('access'));
		}

		$decision = self::decide($rows, caGetUserAccessValues($this->request));
		$ark_label = "ark:/{$naan}/{$name}";

		if (!empty($decision['warning'])) {
			error_log("ArkResolver: {$ark_label} : {$decision['warning']}");
		}

		switch ($decision['status']) {
			case 302:
				$url = caDetailUrl($this->request, $table, $decision['id']);
				if (!$url) {
					error_log("ArkResolver: {$ark_label} : impossible de construire l'URL de détail");
					return $this->respondError(500, $ark_raw);
				}
				if (defined('__CA_SITE_HOSTNAME__') && __CA_SITE_HOSTNAME__) {
					$url = __CA_SITE_PROTOCOL__.'://'.__CA_SITE_HOSTNAME__.$url;
				}
				$this->response->setRedirect($url, 302);
				return;
			case 409:
				error_log("ArkResolver: {$ark_label} attribué à plusieurs fiches accessibles (ids ".join(', ', $decision['ids']).")");
				return $this->respondError(409, $ark_raw);
			default:
				return $this->respondError($decision['status'], $ark_raw);
		}
	}
	# -------------------------------------------------------
	/**
	 * Valide le nom ARK reçu et retire les tirets.
	 * Renvoie null si le nom est vide, trop long ou contient des caractères hors ARK.
	 */
	public static function normalizeName($ps_name) {
		$name = rtrim(trim((string)$ps_name), '/');
		if (($name === '') || (strlen($name) > self::MAX_NAME_LENGTH)) { return null; }
		if (!preg_match('!^[0-9A-Za-z=~*+@_$./-]+$!', $name)) { return null; }
		$name = str_replace('-', '', $name);
		return ($name === '') ? null : $name;
	}
	# -------------------------------------------------------
	/**
	 * Décision pure (testable sans HTTP).
	 *
	 * @param array $pa_rows Lignes [id, deleted, access]
	 * @param array|null $pa_access_values Valeurs d'accès autorisées (vide = pas de restriction, convention CA)
	 * @return array ['status' => 302|404|409|410, 'id' => ?, 'ids' => [], 'warning' => ?]
	 */
	public static function decide(array $pa_rows, $pa_access_values) {
		if (!sizeof($pa_rows)) { return array('status' => 404); }

		$restrict = is_array($pa_access_values) && sizeof($pa_access_values);
		$access = $restrict ? array_map('intval', $pa_access_values) : array();

		$live = $public = array();
		$deleted = 0;
		foreach ($pa_rows as $r) {
			if ((int)$r['deleted']) { $deleted++; continue; }
			$live[] = (int)$r['id'];
			if (!$restrict || in_array((int)$r['access'], $access, true)) { $public[] = (int)$r['id']; }
		}
		$public = array_values(array_unique($public));

		if (sizeof($public) > 1) { return array('status' => 409, 'ids' => $public); }
		if (sizeof($public) === 1) {
			$warning = null;
			if ($deleted || (sizeof($live) > 1)) {
				$warning = "redirection vers id {$public[0]} ; autres correspondances ignorées (supprimées : {$deleted}, non accessibles : ".(sizeof($live) - 1).")";
			}
			return array('status' => 302, 'id' => $public[0], 'warning' => $warning);
		}
		if (sizeof($live)) { return array('status' => 404); }	// non accessible = introuvable
		return array('status' => 410);							// uniquement des fiches supprimées
	}
	# -------------------------------------------------------
	private function respondError($pn_code, $ps_ark) {
		$messages = array(
			404 => array('Not Found', _t('Identifiant introuvable'), _t("Aucune fiche publique ne correspond à cet identifiant ARK.")),
			409 => array('Conflict', _t('Identifiant ambigu'), _t("Cet identifiant ARK est attribué à plusieurs fiches. Merci de signaler ce problème à l'institution.")),
			410 => array('Gone', _t('Fiche supprimée'), _t("La fiche correspondant à cet identifiant ARK a été supprimée.")),
			500 => array('Internal Server Error', _t('Erreur'), _t("L'identifiant ARK n'a pas pu être résolu.")),
		);
		if (!isset($messages[$pn_code])) { $pn_code = 404; }
		list($status_text, $title, $message) = $messages[$pn_code];

		$this->response->setHTTPResponseCode($pn_code, $status_text);
		$this->response->addHeader('X-Robots-Tag', 'noindex');
		$this->view->setVar('ark_title', $title);
		$this->view->setVar('ark_message', $message);
		$this->view->setVar('ark_requested', mb_substr((string)$ps_ark, 0, self::MAX_NAME_LENGTH));
		$this->render('ark_error_html.php');
	}
	# -------------------------------------------------------
}
