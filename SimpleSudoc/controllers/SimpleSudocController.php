<?php
/* ----------------------------------------------------------------------
 * SimpleSudocController.php
 * ----------------------------------------------------------------------
 * CollectiveAccess — Cognitio-Fort
 *
 * Contrôleur du plugin d'import SUDOC (ABES).
 * Utilise l'API SRU publique du SUDOC en demandant des notices UNIMARC
 * (le schéma Dublin Core ne contient pas le PPN, indispensable pour
 * créer une fiche unique).
 *
 * Identifiants acceptés : PPN, ISBN-10/13, ISSN.
 * Modes de recherche : par identifiant ou par texte libre (titre/auteur).
 * ----------------------------------------------------------------------
 */

require_once(__CA_LIB_DIR__ . '/Configuration.php');
require_once(__CA_MODELS_DIR__ . '/ca_objects.php');
require_once(__CA_MODELS_DIR__ . '/ca_locales.php');

class SimpleSudocController extends ActionController {
	# -------------------------------------------------------
	protected $opo_config;

	const SRU_URL = 'https://www.sudoc.abes.fr/cbs/sru';

	# -------------------------------------------------------
	public function __construct(&$po_request, &$po_response, $pa_view_paths = null) {
		parent::__construct($po_request, $po_response, $pa_view_paths);
		$this->opo_config = Configuration::load(__CA_APP_DIR__ . '/plugins/SimpleSudoc/conf/SimpleSudoc.conf');
	}

	# -------------------------------------------------------
	public function Index() {
		$this->view->setVar('max_results', (int)$this->opo_config->get('max_results') ?: 20);
		$this->render('index_html.php');
	}

	# -------------------------------------------------------
	# Search — interrogation SRU SUDOC
	# -------------------------------------------------------
	public function Search() {
		$ps_mode   = $this->request->getParameter('mode',   pString); // 'id' | 'text'
		$ps_search = trim((string)$this->request->getParameter('search', pString));

		if ($ps_search === '') {
			$this->view->setVar('error', _t('Veuillez saisir un identifiant (PPN, ISBN, ISSN) ou des termes de recherche.'));
			$this->render('error_html.php');
			return;
		}

		$records = [];
		$failed  = [];

		if ($ps_mode === 'id') {
			$ids = preg_split('/[\s,;]+/', $ps_search, -1, PREG_SPLIT_NO_EMPTY);
			$first = true;
			foreach ($ids as $id) {
				$index = $this->_detectIdIndex($id);
				if (!$index) { $failed[] = $id; continue; }
				if (!$first) { $this->_rateLimit(); }
				$first = false;
				$clean = preg_replace('/[\s\-]/', '', strtoupper($id));
				$found = $this->_sru($index . '=' . $clean, 1);
				if ($found === false) {
					$this->view->setVar('error', _t('Erreur lors de la communication avec le SUDOC (SRU).'));
					$this->render('error_html.php');
					return;
				}
				if (empty($found)) { $failed[] = $id; continue; }
				$records[] = $found[0];
			}
			if (empty($records)) {
				$this->view->setVar('error', _t('Aucune notice SUDOC trouvée pour : %1', join(', ', $failed)));
				$this->render('error_html.php');
				return;
			}
		} else {
			$max = (int)$this->opo_config->get('max_results') ?: 20;
			$cql = sprintf('(mti all "%1$s" or aut all "%1$s")', addslashes($ps_search));
			$found = $this->_sru($cql, $max);
			if ($found === false) {
				$this->view->setVar('error', _t('Erreur lors de la communication avec le SUDOC (SRU).'));
				$this->render('error_html.php');
				return;
			}
			if (empty($found)) {
				$this->view->setVar('error', _t('Aucun résultat SUDOC pour : %1', htmlspecialchars($ps_search)));
				$this->render('error_html.php');
				return;
			}
			$records = $found;
		}

		// Détection des doublons (PPN déjà présent dans ca_objects.idno)
		$existing = [];
		foreach ($records as $r) {
			if (!empty($r['ppn']) && $this->_ppnExists($r['ppn'])) {
				$existing[] = $r['ppn'];
			}
		}

		$this->view->setVar('records',       $records);
		$this->view->setVar('existing_ppns', $existing);
		$this->view->setVar('mode',          $ps_mode);
		$this->view->setVar('search',        $ps_search);
		$this->render('search_results_html.php');
	}

	# -------------------------------------------------------
	# Import — création des ca_objects
	# -------------------------------------------------------
	public function Import() {
		$ps_json    = (string)$this->request->getParameter('records_json', pString);
		$va_records = json_decode($ps_json, true);

		if (empty($va_records) || !is_array($va_records)) {
			$this->view->setVar('error', _t('Aucune notice sélectionnée pour l\'import.'));
			$this->render('error_html.php');
			return;
		}

		$vn_locale_id = (int)$this->opo_config->get('locale_id');
		if (!$vn_locale_id) { $vn_locale_id = ca_locales::getDefaultCataloguingLocaleID(); }
		$vs_default_type = (string)$this->opo_config->get('default_type') ?: 'revue';

		$results = [];
		foreach ($va_records as $rec) {
			$ppn   = isset($rec['ppn'])   ? trim((string)$rec['ppn'])   : '';
			$title = isset($rec['title']) ? trim((string)$rec['title']) : '';
			if (!$title) { $title = '(sans titre)'; }

			if ($ppn === '') {
				$results[] = ['ppn' => '', 'title' => $title, 'status' => 'error', 'message' => _t('PPN manquant')];
				continue;
			}

			if ($this->_ppnExists($ppn)) {
				$results[] = ['ppn' => $ppn, 'title' => $title, 'status' => 'skip', 'message' => _t('Déjà importé (PPN %1)', $ppn)];
				continue;
			}

			$t_object = new ca_objects();
			$t_object->setMode(ACCESS_WRITE);
			$t_object->set('type_id',   $vs_default_type);
			$t_object->set('locale_id', $vn_locale_id);
			$t_object->set('idno',      $ppn);
			$t_object->insert();

			if ($t_object->numErrors()) {
				$results[] = [
					'ppn'     => $ppn,
					'title'   => $title,
					'status'  => 'error',
					'message' => _t('Erreur création fiche : %1', join(', ', $t_object->getErrors())),
				];
				continue;
			}

			$t_object->addLabel(['name' => $title], $vn_locale_id, null, true);

			$attr_map = [
				'auteurs'     => 'creator',
				'date'        => 'date',
				'description' => 'description',
				'editeurs'    => 'publisher',
				'source'      => 'source',
				'motscles'    => 'subject',
				'url_entry'   => 'url',
			];
			foreach ($attr_map as $element_code => $rec_key) {
				$value = isset($rec[$rec_key]) ? trim((string)$rec[$rec_key]) : '';
				if ($value !== '') {
					$t_object->addAttribute(
						[$element_code => $value, 'locale_id' => $vn_locale_id],
						$element_code
					);
				}
			}

			$t_object->update();

			if ($t_object->numErrors()) {
				$results[] = [
					'ppn'     => $ppn,
					'title'   => $title,
					'status'  => 'error',
					'message' => _t('Erreur mise à jour attributs : %1', join(', ', $t_object->getErrors())),
				];
				continue;
			}

			$results[] = [
				'ppn'       => $ppn,
				'title'     => $title,
				'status'    => 'ok',
				'message'   => _t('Fiche créée — id %1', $t_object->getPrimaryKey()),
				'object_id' => $t_object->getPrimaryKey(),
			];
		}

		$this->view->setVar('results', $results);
		$this->render('import_html.php');
	}

	# -------------------------------------------------------
	# Méthodes privées
	# -------------------------------------------------------

	/**
	 * Recherche SRU SUDOC en récupérant des notices UNIMARC.
	 * Reçoit une expression CQL (ex. `ppn=123`, `mti all "guerre"`).
	 * Retourne un tableau de notices "DC-like" parsées (incluant le PPN),
	 * un tableau vide si aucun résultat, ou false en cas d'erreur HTTP.
	 */
	private function _sru($cql, $max) {
		$params = [
			'version'        => '1.1',
			'operation'      => 'searchRetrieve',
			'query'          => $cql,
			'recordSchema'   => 'unimarc',
			'maximumRecords' => $max,
		];
		$url = self::SRU_URL . '?' . http_build_query($params);
		$xml_string = $this->_httpGet($url);
		if ($xml_string === false) { return false; }

		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($xml_string);
		if ($xml === false) { return false; }

		$xml->registerXPathNamespace('srw', 'http://www.loc.gov/zing/srw/');

		$nodes = $xml->xpath('//srw:record/srw:recordData/record');
		if (!is_array($nodes)) { return []; }
		$out = [];
		foreach ($nodes as $rec_node) {
			$rec = $this->_parseUnimarcRecord($rec_node);
			if ($rec) { $out[] = $rec; }
		}
		return $out;
	}

	/**
	 * Parse un node UNIMARC <record> et le projette sur une structure
	 * DC-like utilisée par les vues et la méthode Import().
	 */
	private function _parseUnimarcRecord($rec) {
		// PPN = controlfield 001
		$ppn = '';
		foreach ($rec->controlfield as $cf) {
			if ((string)$cf['tag'] === '001') { $ppn = trim((string)$cf); break; }
		}

		// Helper pour récupérer les sous-champs d'un datafield donné
		$getSub = function($tag, $code, $first_only = false) use ($rec) {
			$out = [];
			foreach ($rec->datafield as $df) {
				if ((string)$df['tag'] !== (string)$tag) { continue; }
				foreach ($df->subfield as $sf) {
					if ((string)$sf['code'] === (string)$code) {
						$v = trim((string)$sf);
						if ($v !== '') {
							$out[] = $v;
							if ($first_only) { return $out; }
						}
					}
				}
			}
			return $out;
		};

		// Titre : 200$a (+ 200$e sous-titre éventuel)
		$titles_a = $getSub('200', 'a');
		$titles_e = $getSub('200', 'e');
		$title = trim(implode(' — ', array_filter([implode(' / ', $titles_a), implode(' / ', $titles_e)])));

		// Auteurs : on collecte sur 700/701/702 (personnes) et 710/711/712 (collectivités)
		$authors = [];
		foreach (['700', '701', '702', '710', '711', '712'] as $tag) {
			foreach ($rec->datafield as $df) {
				if ((string)$df['tag'] !== $tag) { continue; }
				$a = $b = '';
				foreach ($df->subfield as $sf) {
					if ((string)$sf['code'] === 'a') { $a = trim((string)$sf); }
					if ((string)$sf['code'] === 'b') { $b = trim((string)$sf); }
				}
				$full = trim($a . ($b ? ', ' . $b : ''));
				if ($full !== '') { $authors[] = $full; }
			}
		}

		// Édition : 210$a (lieu), 210$c (éditeur), 210$d (date)
		$pub_a = $getSub('210', 'a');
		$pub_c = $getSub('210', 'c');
		$pub_d = $getSub('210', 'd');
		$publisher = trim(implode(', ', array_filter([
			implode(' ; ', $pub_a),
			implode(' ; ', $pub_c),
		])));
		$date = implode(' / ', $pub_d);

		// Description : 330$a (résumé) et 300$a (notes)
		$desc_330 = $getSub('330', 'a');
		$desc_300 = $getSub('300', 'a');
		$description = trim(implode("\n\n", array_merge($desc_330, $desc_300)));

		// Sujets : 606$a (sujet matière), 610$a (mots-clés libres), 600/601/602/607
		$subjects = [];
		foreach (['606', '607', '610', '600', '601', '602'] as $tag) {
			$subjects = array_merge($subjects, $getSub($tag, 'a'));
		}
		$subject = implode('; ', array_unique(array_filter($subjects)));

		// Source / cote : 035$a (numéro source) — surtout pour info
		$source_035 = $getSub('035', 'a');
		$source = implode('; ', $source_035);

		// URL : 856$u si présent, sinon URL canonique sudoc
		$url_856 = $getSub('856', 'u');
		$url = !empty($url_856) ? $url_856[0] : ($ppn ? 'https://www.sudoc.fr/' . $ppn : '');

		// Type de document UNIMARC : leader[6] = type, leader[7] = niveau bibliographique
		$leader = (string)$rec->leader;
		$type_marc = strlen($leader) > 7 ? substr($leader, 6, 2) : '';

		if ($ppn === '' && $title === '') { return null; }

		return [
			'ppn'         => $ppn,
			'title'       => $title !== '' ? $title : '(sans titre)',
			'creator'     => implode('; ', $authors),
			'date'        => $date,
			'description' => $description,
			'publisher'   => $publisher,
			'subject'     => $subject,
			'source'      => $source,
			'url'         => $url,
			'type'        => $type_marc,
		];
	}

	/**
	 * Détecte le type d'identifiant et renvoie l'index CQL SUDOC associé.
	 * - 8 caractères (7 chiffres + chiffre/X)            → ISSN (`isn`)
	 * - 10 ou 13 caractères (chiffres + X possible final) → ISBN (`isb`)
	 * - 5-9 chiffres (avec X final possible)              → PPN  (`ppn`)
	 * Renvoie null si non reconnu.
	 */
	private function _detectIdIndex($id) {
		$clean = strtoupper(preg_replace('/[\s\-]/', '', $id));
		if (preg_match('/^\d{7}[\dX]$/', $clean)) { return 'isn'; }
		if (preg_match('/^\d{9}[\dX]$/', $clean) || preg_match('/^\d{12}[\dX]$/', $clean)) { return 'isb'; }
		if (preg_match('/^\d{4,9}[\dX]?$/', $clean)) { return 'ppn'; }
		return null;
	}

	private function _ppnExists($ppn) {
		$db = new Db();
		$res = $db->query(
			"SELECT object_id FROM ca_objects WHERE idno = ? AND deleted = 0 LIMIT 1",
			[$ppn]
		);
		return ($res && $res->nextRow());
	}

	private function _rateLimit() {
		$delay = (int)$this->opo_config->get('rate_limit_delay');
		if ($delay < 1) { $delay = 1; }
		sleep($delay);
	}

	private function _httpGet($url) {
		$ua = (string)$this->opo_config->get('user_agent') ?: 'CollectiveAccess/SimpleSudocPlugin';
		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 30);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_USERAGENT, $ua);
			$data = curl_exec($ch);
			$err  = curl_error($ch);
			curl_close($ch);
			return ($err || $data === false) ? false : $data;
		}
		$ctx = stream_context_create(['http' => [
			'timeout'         => 30,
			'user_agent'      => $ua,
			'follow_location' => true,
		]]);
		$data = @file_get_contents($url, false, $ctx);
		return ($data === false) ? false : $data;
	}
}
