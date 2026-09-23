<?php
/**
 * StatutsController.php — consultation des reculs de statut en attente d'arbitrage.
 *
 * 14/09/2026 GM (ticket 8025).
 *
 * Le recalcul nocturne des statuts d'opération refuse d'écrire un statut qui FERAIT RECULER la
 * collection : une collection versée ne doit pas repasser « en attente » parce qu'un calcul de
 * nuit en a décidé ainsi. Ces cas sont donc écartés et consignés dans un fichier, chaque nuit,
 * depuis le 09/09/2026 — environ 93 opérations, dont une trentaine déjà versées.
 *
 * Ce fichier n'était lisible qu'en se connectant au serveur. Il fallait que l'INRAP puisse le
 * consulter depuis Comodo, sans quoi la liste ne serait jamais arbitrée et le recalcul resterait
 * bloqué sur les mêmes cas indéfiniment. D'où cet écran.
 *
 * LECTURE SEULE. Cet écran n'écrit rien et ne décide rien : il montre ce que le programme
 * aurait écrit et ce qu'il a préféré soumettre à un humain. La correction se fait dans la
 * fiche, en cliquant l'identifiant.
 */

require_once(__CA_LIB_DIR__.'/Configuration.php');
error_reporting(E_ERROR);

class StatutsController extends ActionController {

	protected $opo_config, $ops_plugin_name, $ops_plugin_path;

	public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
		parent::__construct($po_request, $po_response, $pa_view_paths);
		$this->ops_plugin_name = "etatsInrap";
		$this->ops_plugin_path = __CA_APP_DIR__."/plugins/".$this->ops_plugin_name;
		$vs_conf_file = $this->ops_plugin_path."/conf/".$this->ops_plugin_name.".conf";
		if (is_file($vs_conf_file)) { $this->opo_config = Configuration::load($vs_conf_file); }
	}

	/** Répertoire des journaux du recalcul de statut. */
	private function repertoireJournaux() {
		return __CA_BASE_DIR__.'/inrap_scripts/log_statut';
	}

	/**
	 * Fichier d'arbitrage le plus récent. On ne se fie pas à la date du jour : si le recalcul
	 * n'a pas tourné cette nuit, mieux vaut montrer la dernière liste connue — en disant sa
	 * date — que d'afficher un écran vide qui laisserait croire qu'il n'y a rien à arbitrer.
	 */
	private function dernierFichier() {
		$va_f = glob($this->repertoireJournaux().'/log_statut_arbitrage_*.csv');
		if (!is_array($va_f) || !sizeof($va_f)) { return null; }
		sort($va_f);
		return array_pop($va_f);
	}

	public function Index() {
		$vs_fichier = $this->dernierFichier();
		$va_lignes = array();
		$vs_date = '';

		if ($vs_fichier) {
			if (preg_match('/_(\d{4}-\d{2}-\d{2})\.csv$/', $vs_fichier, $m)) { $vs_date = $m[1]; }
			// fgetcsv() et non fgets() + str_getcsv() : certains identifiants du parc contiennent
			// un SAUT DE LIGNE — deux numéros collés dans une même cellule de tableur à l'import.
			// Une lecture ligne à ligne coupe alors l'enregistrement en deux et le rend
			// illisible ; fgetcsv() recolle les champs entre guillemets. Constaté le 14/09/2026
			// sur l'opération 41465, « AB1110036801 » + saut de ligne + « AB10045701 ».
			$vr = @fopen($vs_fichier, 'r');
			if ($vr) {
				while (($va_c = fgetcsv($vr, 0, ',', '"')) !== false) {
					// idno, collection_id, statut actuel, statut calculé, commune, direction,
					// auteur du statut actuel (7e colonne, ajoutée le 23/09/2026 — ticket 8000).
					// Les journaux antérieurs n'en portent pas : la colonne est alors vide.
					if (!is_array($va_c) || sizeof($va_c) < 4) { continue; }
					$net = function ($v) { return trim(preg_replace('/\s+/u', ' ', (string)$v)); };
					if ($net($va_c[0]) === '' && !(int)($va_c[1] ?? 0)) { continue; }
					$va_lignes[] = array(
						'idno'      => $net($va_c[0] ?? ''),
						'id'        => (int)($va_c[1] ?? 0),
						'actuel'    => $net($va_c[2] ?? ''),
						'calcule'   => $net($va_c[3] ?? ''),
						'commune'   => $net($va_c[4] ?? ''),
						'direction' => $net($va_c[5] ?? ''),
						'auteur'    => $net($va_c[6] ?? ''),
					);
				}
				fclose($vr);
			}
		}

		// Tri par direction puis commune : les gestionnaires travaillent par périmètre.
		usort($va_lignes, function($a, $b) {
			$c = strcoll($a['direction'], $b['direction']);
			return $c !== 0 ? $c : strcoll($a['commune'], $b['commune']);
		});

		$this->view->setVar('lignes', $va_lignes);
		$this->view->setVar('date_liste', $vs_date);
		$this->view->setVar('fichier', $vs_fichier ? basename($vs_fichier) : '');
		$this->render('statuts_arbitrage_html.php');
	}

	/** Le même contenu en CSV, pour travailler la liste dans un tableur. */
	public function Csv() {
		$vs_fichier = $this->dernierFichier();
		if (!$vs_fichier || !is_readable($vs_fichier)) {
			$this->view->setVar('lignes', array());
			$this->view->setVar('date_liste', '');
			$this->view->setVar('fichier', '');
			return $this->render('statuts_arbitrage_html.php');
		}
		$this->getResponse()->addHeader('Content-Type', 'text/csv; charset=UTF-8');
		$this->getResponse()->addHeader('Content-Disposition', 'attachment; filename="'.basename($vs_fichier).'"');
		$this->getResponse()->sendHeaders();
		// BOM : sans lui, Excel lit l'UTF-8 comme du latin-1 et massacre les accents.
		print "\xEF\xBB\xBF" . '"Identifiant","Fiche","Statut actuel","Statut calculé","Commune","Direction"' . "\n";
		print file_get_contents($vs_fichier);
		exit;
	}
}
