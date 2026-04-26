<?php
/* ----------------------------------------------------------------------
 * SimpleGallicaController.php
 * ----------------------------------------------------------------------
 * CollectiveAccess — Cognitio-Fort
 *
 * Contrôleur du plugin d'import Gallica.
 * Trois actions :
 *   - Index   : formulaire de recherche (mode ARK ou mode texte SRU)
 *   - Search  : interrogation Gallica (SRU ou OAIRecord) → liste de notices
 *   - Import  : création des ca_objects + addRepresentation des images
 * ----------------------------------------------------------------------
 */

require_once(__CA_LIB_DIR__ . '/Configuration.php');
require_once(__CA_MODELS_DIR__ . '/ca_objects.php');
require_once(__CA_MODELS_DIR__ . '/ca_object_representations.php');
require_once(__CA_MODELS_DIR__ . '/ca_locales.php');

class SimpleGallicaController extends ActionController {
	# -------------------------------------------------------
	protected $opo_config;

	const SRU_URL        = 'https://gallica.bnf.fr/SRU';
	const OAI_RECORD_URL = 'https://gallica.bnf.fr/services/OAIRecord';
	const HIGHRES_TPL    = 'https://gallica.bnf.fr/%s/f1.highres';
	const ARK_PREFIX     = 'ark:/12148/';

	# -------------------------------------------------------
	public function __construct(&$po_request, &$po_response, $pa_view_paths = null) {
		parent::__construct($po_request, $po_response, $pa_view_paths);
		$this->opo_config = Configuration::load(__CA_APP_DIR__ . '/plugins/SimpleGallica/conf/SimpleGallica.conf');
	}

	# -------------------------------------------------------
	public function Index() {
		$this->view->setVar('max_results', (int)$this->opo_config->get('max_results') ?: 20);
		$this->render('index_html.php');
	}

	# -------------------------------------------------------
	# Search — interrogation Gallica
	# -------------------------------------------------------
	public function Search() {
		$ps_mode   = $this->request->getParameter('mode',   pString); // 'ark' | 'text'
		$ps_search = trim((string)$this->request->getParameter('search', pString));

		if ($ps_search === '') {
			$this->view->setVar('error', _t('Veuillez saisir un ARK Gallica ou des termes de recherche.'));
			$this->render('error_html.php');
			return;
		}

		$records = [];

		if ($ps_mode === 'ark') {
			// Plusieurs ARKs séparés par espaces / virgules / sauts de ligne
			$arks = preg_split('/[\s,;]+/', $ps_search, -1, PREG_SPLIT_NO_EMPTY);
			$cleaned = [];
			foreach ($arks as $candidate) {
				$ark = $this->_extractArk($candidate);
				if ($ark) { $cleaned[] = $ark; }
			}
			if (empty($cleaned)) {
				$this->view->setVar('error', _t('Aucun ARK Gallica valide trouvé. Format attendu : ark:/12148/... ou URL gallica.bnf.fr.'));
				$this->render('error_html.php');
				return;
			}
			$first = true;
			$failed = [];
			foreach ($cleaned as $ark) {
				if (!$first) { $this->_rateLimit(); }
				$first = false;
				$rec = $this->_oaiRecord($ark);
				if (!$rec) {
					// Fallback SRU : utile pour les ARK de notice catalogue (cb*),
					// non couverts par OAIRecord.
					$this->_rateLimit();
					$rec = $this->_sruByArk($ark);
				}
				if ($rec) {
					// Toujours garder l'ARK saisi par l'utilisateur comme référence
					$rec['ark'] = $ark;
					$records[] = $rec;
				} else {
					$failed[] = $ark;
				}
			}
			if (empty($records)) {
				$this->view->setVar('error', _t('Impossible de récupérer les notices Gallica pour les ARK fournis : %1', join(', ', $failed)));
				$this->render('error_html.php');
				return;
			}
		} else {
			$max = (int)$this->opo_config->get('max_results') ?: 20;
			$records = $this->_sru($ps_search, $max);
			if ($records === false) {
				$this->view->setVar('error', _t('Erreur lors de la communication avec Gallica (SRU). Vérifiez votre connexion.'));
				$this->render('error_html.php');
				return;
			}
			if (empty($records)) {
				$this->view->setVar('error', _t('Aucun résultat Gallica pour : %1', htmlspecialchars($ps_search)));
				$this->render('error_html.php');
				return;
			}
		}

		// Détection des doublons (ARK déjà présent dans ca_objects)
		$existing = [];
		foreach ($records as $r) {
			if (!empty($r['ark']) && $this->_arkExists($r['ark'])) {
				$existing[] = $r['ark'];
			}
		}

		$this->view->setVar('records',      $records);
		$this->view->setVar('existing_arks', $existing);
		$this->view->setVar('mode',         $ps_mode);
		$this->view->setVar('search',       $ps_search);
		$this->render('search_results_html.php');
	}

	# -------------------------------------------------------
	# Import — création des ca_objects
	# -------------------------------------------------------
	public function Import() {
		$ps_json = (string)$this->request->getParameter('records_json', pString);
		$va_records = json_decode($ps_json, true);

		if (empty($va_records) || !is_array($va_records)) {
			$this->view->setVar('error', _t('Aucune notice sélectionnée pour l\'import.'));
			$this->render('error_html.php');
			return;
		}

		$vn_locale_id    = (int)$this->opo_config->get('locale_id');
		if (!$vn_locale_id) { $vn_locale_id = ca_locales::getDefaultCataloguingLocaleID(); }

		$vs_default_type = (string)$this->opo_config->get('default_type') ?: 'iconographie';
		$vb_download     = (bool)$this->opo_config->get('download_image');

		$results = [];
		$first = true;

		foreach ($va_records as $rec) {
			$ark   = isset($rec['ark'])   ? trim((string)$rec['ark'])   : '';
			$title = isset($rec['title']) ? trim((string)$rec['title']) : '';
			if (!$title) { $title = '(sans titre)'; }

			if ($ark === '') {
				$results[] = ['ark' => '', 'title' => $title, 'status' => 'error', 'message' => _t('ARK manquant')];
				continue;
			}

			if ($this->_arkExists($ark)) {
				$results[] = ['ark' => $ark, 'title' => $title, 'status' => 'skip', 'message' => _t('Déjà importé (ARK %1)', $ark)];
				continue;
			}

			// Type d'objet
			$type_code = $this->_resolveTypeCode((string)($rec['type'] ?? ''), $vs_default_type);

			$t_object = new ca_objects();
			$t_object->setMode(ACCESS_WRITE);
			$t_object->set('type_id',   $type_code);          // accepte le code ou l'item_id
			$t_object->set('locale_id', $vn_locale_id);
			$t_object->set('idno',      $ark);
			$t_object->insert();

			if ($t_object->numErrors()) {
				$results[] = [
					'ark'     => $ark,
					'title'   => $title,
					'status'  => 'error',
					'message' => _t('Erreur création fiche : %1', join(', ', $t_object->getErrors())),
				];
				continue;
			}

			$t_object->addLabel(['name' => $title], $vn_locale_id, null, true);

			// Mapping DC → element_codes Cognitio-Fort
			$attr_map = [
				'auteurs'     => 'creator',
				'date'        => 'date',
				'description' => 'description',
				'editeurs'    => 'publisher',
				'source'      => 'source',
				'droits'      => 'rights',
				'motscles'    => 'subject',
				'url_entry'   => 'url',
				'objets_lies' => 'relation',
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
					'ark'     => $ark,
					'title'   => $title,
					'status'  => 'error',
					'message' => _t('Erreur mise à jour attributs : %1', join(', ', $t_object->getErrors())),
				];
				continue;
			}

			$result = [
				'ark'       => $ark,
				'title'     => $title,
				'status'    => 'ok',
				'message'   => _t('Fiche créée — id %1', $t_object->getPrimaryKey()),
				'object_id' => $t_object->getPrimaryKey(),
			];

			// Téléchargement de l'image haute résolution
			if ($vb_download) {
				if (!$first) { $this->_rateLimit(); }
				$first = false;
				$repr_msg = $this->_attachHighresImage($t_object, $ark, $vn_locale_id);
				if ($repr_msg) { $result['message'] .= ' — ' . $repr_msg; }
			}

			$results[] = $result;
		}

		$this->view->setVar('results', $results);
		$this->render('import_html.php');
	}

	# -------------------------------------------------------
	# Méthodes privées : appels API
	# -------------------------------------------------------

	/**
	 * Recherche SRU Gallica (CQL).
	 * Retourne tableau de notices ou false en cas d'erreur HTTP.
	 */
	private function _sru($query, $max) {
		$cql = sprintf('(gallica all "%s")', addslashes($query));
		$params = [
			'operation'      => 'searchRetrieve',
			'version'        => '1.2',
			'query'          => $cql,
			'maximumRecords' => $max,
			'startRecord'    => 1,
		];
		$url = self::SRU_URL . '?' . http_build_query($params);
		$xml_string = $this->_httpGet($url);
		if ($xml_string === false) { return false; }

		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($xml_string);
		if ($xml === false) { return false; }

		$xml->registerXPathNamespace('srw', 'http://www.loc.gov/zing/srw/');
		$xml->registerXPathNamespace('oai_dc', 'http://www.openarchives.org/OAI/2.0/oai_dc/');
		$xml->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');

		$records = [];
		$nodes = $xml->xpath('//srw:record/srw:recordData/*[local-name()="dc"]');
		if (!is_array($nodes)) { return []; }
		foreach ($nodes as $dc_node) {
			$rec = $this->_parseDcRecord($dc_node);
			if ($rec) { $records[] = $rec; }
		}
		return $records;
	}

	/**
	 * Récupère une notice DC unique via OAIRecord pour un ARK donné.
	 * Retourne null si Gallica renvoie une page d'erreur HTML (typique pour
	 * les ARK cb* qui pointent vers des notices catalogue, non numérisées).
	 */
	private function _oaiRecord($ark) {
		$url = self::OAI_RECORD_URL . '?' . http_build_query(['ark' => $ark]);
		$xml_string = $this->_httpGet($url);
		if ($xml_string === false) { return null; }

		// Gallica renvoie une page HTML "Erreur" (pas du XML) pour certains ARK.
		// On ignore tout ce qui ne ressemble pas à une réponse XML.
		if (stripos(ltrim($xml_string), '<?xml') !== 0) { return null; }

		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($xml_string);
		if ($xml === false) { return null; }

		$xml->registerXPathNamespace('oai_dc', 'http://www.openarchives.org/OAI/2.0/oai_dc/');
		$xml->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');

		$dc_nodes = $xml->xpath('//*[local-name()="dc"]');
		if (!is_array($dc_nodes) || empty($dc_nodes)) { return null; }

		$rec = $this->_parseDcRecord($dc_nodes[0]);
		// Forcer l'ARK fourni en entrée si non détecté dans la notice
		if ($rec && empty($rec['ark'])) { $rec['ark'] = $ark; }
		return $rec;
	}

	/**
	 * Fallback : recherche SRU par ARK quand OAIRecord ne fournit pas la notice
	 * (cas typique des ARK de notice catalogue cb*). On utilise l'index 'gallica'
	 * (full-text) car ni `dc.identifier` ni `arkPress` ne couvrent tous les cas.
	 * Retourne le premier résultat ou null.
	 */
	private function _sruByArk($ark) {
		$records = $this->_sru($ark, 1);
		if (!is_array($records) || empty($records)) { return null; }
		return $records[0];
	}

	/**
	 * Parse un node oai_dc:dc en tableau associatif.
	 * Concatène les répétitions par "; ".
	 */
	private function _parseDcRecord($dc_node) {
		$dc_node->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');

		$collect = function($field) use ($dc_node) {
			$nodes = $dc_node->xpath('dc:' . $field);
			if (!is_array($nodes)) { return []; }
			$out = [];
			foreach ($nodes as $n) {
				$v = trim((string)$n);
				if ($v !== '') { $out[] = $v; }
			}
			return $out;
		};

		$titles      = $collect('title');
		$creators    = $collect('creator');
		$dates       = $collect('date');
		$descs       = $collect('description');
		$publishers  = $collect('publisher');
		$sources     = $collect('source');
		$rights      = $collect('rights');
		$subjects    = $collect('subject');
		$identifiers = $collect('identifier');
		$relations   = $collect('relation');
		$types       = $collect('type');

		// ARK : on cherche dans tous les dc:identifier
		$ark = '';
		$url = '';
		foreach ($identifiers as $id) {
			if ($ark === '' && ($extracted = $this->_extractArk($id))) {
				$ark = $extracted;
			}
			if ($url === '' && stripos($id, 'gallica.bnf.fr') !== false) {
				$url = $id;
			}
		}
		// Si pas d'URL trouvée mais on a un ARK, reconstruire l'URL canonique
		if ($url === '' && $ark !== '') {
			$url = 'https://gallica.bnf.fr/' . $ark;
		}

		if (empty($titles) && $ark === '') { return null; }

		return [
			'ark'         => $ark,
			'url'         => $url,
			'title'       => implode(' — ', $titles),
			'creator'     => implode('; ', $creators),
			'date'        => implode(' / ', $dates),
			'description' => implode("\n\n", $descs),
			'publisher'   => implode('; ', $publishers),
			'source'      => implode('; ', $sources),
			'rights'      => implode(' / ', $rights),
			'subject'     => implode('; ', $subjects),
			'relation'    => implode('; ', $relations),
			'type'        => implode('; ', $types),
		];
	}

	/**
	 * Télécharge l'image highres et l'attache comme représentation.
	 */
	private function _attachHighresImage($t_object, $ark, $locale_id) {
		$tmp_dir = sys_get_temp_dir();
		$url = sprintf(self::HIGHRES_TPL, $ark);

		$ua = (string)$this->opo_config->get('user_agent') ?: 'Mozilla/5.0';
		$ctx = stream_context_create(['http' => [
			'timeout'         => 60,
			'user_agent'      => $ua,
			'follow_location' => true,
			'max_redirects'   => 5,
			'header'          => "Referer: https://gallica.bnf.fr/\r\n",
		]]);

		error_clear_last();
		$data = @file_get_contents($url, false, $ctx);
		if ($data === false) {
			$err = error_get_last();
			$msg = ($err && isset($err['message'])) ? $err['message'] : 'unknown';
			return _t('Image non récupérée (%1)', $msg);
		}

		$ext = 'jpg';
		if (isset($http_response_header) && is_array($http_response_header)) {
			foreach ($http_response_header as $h) {
				if (stripos($h, 'Content-Type:') === 0) {
					if (stripos($h, 'png')  !== false) { $ext = 'png'; }
					elseif (stripos($h, 'tiff') !== false) { $ext = 'tif'; }
					elseif (stripos($h, 'gif')  !== false) { $ext = 'gif'; }
					break;
				}
			}
		}

		$tmp_file = tempnam($tmp_dir, 'gallica_') . '.' . $ext;
		file_put_contents($tmp_file, $data);

		// Dédup MD5 sur ca_object_representations
		$md5 = md5_file($tmp_file);
		$db = new Db();
		$res = $db->query("SELECT representation_id FROM ca_object_representations WHERE md5 = ? LIMIT 1", [$md5]);
		if ($res && $res->nextRow()) {
			@unlink($tmp_file);
			return _t('Image déjà présente (MD5 dédup, repr %1)', $res->get('representation_id'));
		}

		$label = basename(str_replace('/', '_', $ark));
		$repr_id = $t_object->addRepresentation(
			$tmp_file,
			'media',
			$locale_id,
			1,    // status
			1,    // access
			true, // is_primary
			[
				'idno'             => $ark,
				'preferred_labels' => ['name' => $label],
			],
			['matchOn' => ['idno']]
		);
		@unlink($tmp_file);

		if ($t_object->numErrors()) {
			return _t('Erreur représentation : %1', join(', ', $t_object->getErrors()));
		}
		return $repr_id ? _t('image attachée (repr %1)', $repr_id) : _t('image non attachée');
	}

	# -------------------------------------------------------
	# Utilitaires
	# -------------------------------------------------------

	/**
	 * Extrait un ARK Gallica (ark:/12148/...) depuis une chaîne libre.
	 * Accepte URL gallica.bnf.fr ou ARK brut.
	 */
	private function _extractArk($s) {
		if (preg_match('#(ark:/12148/[a-z0-9]+)#i', $s, $m)) {
			return $m[1];
		}
		return null;
	}

	private function _arkExists($ark) {
		$db = new Db();
		$res = $db->query(
			"SELECT object_id FROM ca_objects WHERE idno = ? AND deleted = 0 LIMIT 1",
			[$ark]
		);
		return ($res && $res->nextRow());
	}

	private function _resolveTypeCode($dc_type, $default) {
		$mapping = $this->opo_config->getAssoc('type_mapping');
		if (!is_array($mapping) || $dc_type === '') { return $default; }
		$haystack = mb_strtolower($dc_type);
		foreach ($mapping as $needle => $type_code) {
			if (mb_strpos($haystack, mb_strtolower($needle)) !== false) {
				return $type_code;
			}
		}
		return $default;
	}

	private function _rateLimit() {
		$delay = (int)$this->opo_config->get('rate_limit_delay');
		if ($delay < 3) { $delay = 3; }
		sleep($delay);
	}

	private function _httpGet($url) {
		$ua = (string)$this->opo_config->get('user_agent') ?: 'CollectiveAccess/SimpleGallicaPlugin';
		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 30);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_USERAGENT, $ua);
			curl_setopt($ch, CURLOPT_HTTPHEADER, ['Referer: https://gallica.bnf.fr/']);
			$data = curl_exec($ch);
			$err  = curl_error($ch);
			curl_close($ch);
			return ($err || $data === false) ? false : $data;
		}
		$ctx = stream_context_create(['http' => [
			'timeout'         => 30,
			'user_agent'      => $ua,
			'follow_location' => true,
			'header'          => "Referer: https://gallica.bnf.fr/\r\n",
		]]);
		$data = @file_get_contents($url, false, $ctx);
		return ($data === false) ? false : $data;
	}
}
