<?php
/* ----------------------------------------------------------------------
 * plugins/etatsMTE/controllers/CatalogueController.php
 * ----------------------------------------------------------------------
 * Génération des catalogues MTE (standards et spécifiques)
 * Une fiche par objet, format PDF via le moteur de rendu CA
 * ----------------------------------------------------------------------
 */

require_once(__CA_MODELS_DIR__.'/ca_objects.php');
require_once(__CA_MODELS_DIR__.'/ca_entities.php');
require_once(__CA_MODELS_DIR__.'/ca_lists.php');
require_once(__CA_MODELS_DIR__.'/ca_object_representations.php');
require_once(__CA_MODELS_DIR__.'/ca_locales.php');

error_reporting(E_ERROR);

class CatalogueController extends ActionController {
	# -------------------------------------------------------
	protected $opo_config;
	protected $ops_plugin_name;
	protected $ops_plugin_path;

	// List IDs
	const LIST_DOMAINE   = 157;  // domaine_logement (Catégorie)
	const LIST_TYPE      = 168;  // denomination (Type)
	const LIST_SITE      = 160;  // site_nom1
	const LIST_BATIMENT  = 164;  // site_batiment1
	const LIST_ETAGE     = 163;  // site_etage
	const LIST_CONSTAT   = 114;  // inv_constat (Vu/Non vu/Manquant/Détruit)
	const LIST_RECOLEMENT = 155; // real_O_N (Oui/Non)

	// Relationship type IDs for ca_objects_x_entities
	const REL_DEPOSANT = 172;
	const REL_AUTEUR   = 164;

	// Déposant "MTE" : sa sélection affiche les catalogues spécifiques MTE (4 boutons au lieu de 2)
	const MTE_DEPOSANT_ID = 1394; // Ministère de l'Écologie du Développement Durable

	# -------------------------------------------------------
	public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
		parent::__construct($po_request, $po_response, $pa_view_paths);

		$this->ops_plugin_name = "etatsMTE";
		$this->ops_plugin_path = __CA_APP_DIR__."/plugins/".$this->ops_plugin_name;

		$vs_conf_file = $this->ops_plugin_path."/conf/".$this->ops_plugin_name.".conf";
		if(is_file($vs_conf_file)) {
			$this->opo_config = Configuration::load($vs_conf_file);
		}
	}

	# -------------------------------------------------------
	# Index — redirige vers Standards par défaut
	# -------------------------------------------------------
	public function Index() {
		return $this->Standards();
	}

	# -------------------------------------------------------
	# Standards — catalogues standards
	# -------------------------------------------------------
	public function Standards() {
		$this->_loadFilterLists();
		$this->_loadJobVars();
		$this->render('catalogue_standards_html.php');
	}

	# -------------------------------------------------------
	# Specifiques — catalogues spécifiques
	# -------------------------------------------------------
	public function Specifiques() {
		$this->_loadFilterLists();
		$this->_loadJobVars();
		$this->render('catalogue_specifiques_html.php');
	}

	# -------------------------------------------------------
	private function _loadJobVars() {
		$vs_job = $this->getRequest()->getParameter("job", pString);
		$this->view->setVar('current_job', $vs_job);
		$this->view->setVar('check_url', caNavUrl($this->getRequest(), "etatsMTE", "Catalogue", "CheckPDF"));
		$this->view->setVar('check_all_url', caNavUrl($this->getRequest(), "etatsMTE", "Catalogue", "CheckAllPDF"));
	}

	# -------------------------------------------------------
	private function _loadFilterLists() {
		$this->view->setVar('deposants', $this->_getDeposants());
		$this->view->setVar('sites', $this->_getListItems(self::LIST_SITE));
		$this->view->setVar('batiments', $this->_getListItems(self::LIST_BATIMENT));
		$this->view->setVar('etages', $this->_getListItems(self::LIST_ETAGE));
		$this->view->setVar('types', $this->_getListItems(self::LIST_DOMAINE));            // Catégorie (domaine_logement)
		$this->view->setVar('denominations', $this->_getListItems(self::LIST_TYPE));      // Type (denomination)
		// Constat présence du catalogue spécifique : limité à Vu (545) / Non vu (547) /
		// Pièce inaccessible (3764). On exclut -- (546), Manquant (548) et Détruit (549)
		// (ces derniers restent réservés au catalogue des biens disparus).
		$va_constats = $this->_getListItems(self::LIST_CONSTAT);
		$va_constats = array_intersect_key($va_constats, [545 => 1, 547 => 1, 3764 => 1]);
		$this->view->setVar('constats', $va_constats);
		$this->view->setVar('mte_deposant_id', self::MTE_DEPOSANT_ID);
	}

	# -------------------------------------------------------
	# Generate — lance la génération du catalogue PDF en arrière-plan
	# -------------------------------------------------------
	public function Generate() {
		$vs_catalogue_type = $this->getRequest()->getParameter("catalogue_type", pString);
		$vn_deposant_id    = $this->getRequest()->getParameter("deposant", pInteger);
		$vn_site_id        = $this->getRequest()->getParameter("site", pInteger);
		$vn_batiment_id    = $this->getRequest()->getParameter("batiment", pInteger);
		$vn_etage_id       = $this->getRequest()->getParameter("etage", pInteger);
		$vn_type_id        = $this->getRequest()->getParameter("type_domaine", pInteger);
		$vn_denomination_id = $this->getRequest()->getParameter("denomination", pInteger);
		$vn_constat_id     = $this->getRequest()->getParameter("constat", pInteger);
		$vs_date_debut     = $this->getRequest()->getParameter("date_debut", pString);
		$vs_date_fin       = $this->getRequest()->getParameter("date_fin", pString);
		$vn_recole         = $this->getRequest()->getParameter("recole", pInteger);
		$vn_inventorie     = $this->getRequest()->getParameter("inventorie", pInteger);
		$vn_restitue       = $this->getRequest()->getParameter("restitue", pInteger);
		$vn_restaure       = $this->getRequest()->getParameter("restaure", pInteger);
		$vs_objet_mobilier = $this->getRequest()->getParameter("objet_mobilier", pString);

		// Create a unique job ID
		$vs_job_id = 'catalogue_' . date('Ymd_His') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);

		// Job output directory
		$vs_job_dir = __CA_APP_DIR__ . '/tmp/catalogues';
		if (!is_dir($vs_job_dir)) {
			@mkdir($vs_job_dir, 0775, true);
		}

		// Write job parameters to JSON file
		$va_job = [
			'job_id'         => $vs_job_id,
			'ca_base_dir'    => __CA_BASE_DIR__,
			'catalogue_type' => $vs_catalogue_type,
			'deposant'       => $vn_deposant_id,
			'site'           => $vn_site_id,
			'batiment'       => $vn_batiment_id,
			'etage'          => $vn_etage_id,
			'type_domaine'   => $vn_type_id,
			'denomination'   => $vn_denomination_id,
			'constat'        => $vn_constat_id,
			'date_debut'     => $vs_date_debut,
			'date_fin'       => $vs_date_fin,
			'recole'         => $vn_recole,
			'inventorie'     => $vn_inventorie,
			'restitue'       => $vn_restitue,
			'restaure'       => $vn_restaure,
			'objet_mobilier' => $vs_objet_mobilier,
		];

		$vs_job_file = $vs_job_dir . '/' . $vs_job_id . '.json';
		file_put_contents($vs_job_file, json_encode($va_job));

		// Launch background process
		$vs_script = $this->ops_plugin_path . '/generate_pdf.php';
		$vs_log_file = $vs_job_dir . '/' . $vs_job_id . '.log';
		$vs_cmd = 'php ' . escapeshellarg($vs_script) . ' ' . escapeshellarg($vs_job_file) . ' > ' . escapeshellarg($vs_log_file) . ' 2>&1 &';
		exec($vs_cmd);

		// Store the job_id in session for the user
		$va_jobs = isset($_SESSION['etatsMTE_pdf_jobs']) ? $_SESSION['etatsMTE_pdf_jobs'] : [];
		$va_jobs[] = $vs_job_id;
		$_SESSION['etatsMTE_pdf_jobs'] = $va_jobs;

		// Return JSON for AJAX calls
		if ($this->getRequest()->isAjax() || $this->getRequest()->getParameter("ajax", pInteger) || isset($_REQUEST['ajax'])) {
			header('Content-Type: application/json');
			echo json_encode(['status' => 'launched', 'job_id' => $vs_job_id]);
			exit;
		}

		// Fallback: redirect for non-AJAX
		$vs_redirect = 'Standards';
		if (strpos($vs_catalogue_type, 'specifique') === 0) {
			$vs_redirect = 'Specifiques';
		}

		header("Location: " . caNavUrl($this->getRequest(), "etatsMTE", "Catalogue", $vs_redirect, ['job' => $vs_job_id]));
		exit;
	}

	# -------------------------------------------------------
	# CheckPDF — AJAX endpoint, returns JSON status of a job
	# -------------------------------------------------------
	public function CheckPDF() {
		$vs_job_id = $this->getRequest()->getParameter("job_id", pString);

		// Sanitize job_id to prevent path traversal
		$vs_job_id = preg_replace('/[^a-zA-Z0-9_]/', '', $vs_job_id);

		$vs_job_dir = __CA_APP_DIR__ . '/tmp/catalogues';
		$vs_status_file = $vs_job_dir . '/' . $vs_job_id . '.status';

		header('Content-Type: application/json');

		if (!file_exists($vs_status_file)) {
			echo json_encode(['status' => 'unknown']);
		} else {
			$va_status = json_decode(file_get_contents($vs_status_file), true);
			if ($va_status['status'] === 'done') {
				$va_status['download_url'] = caNavUrl($this->getRequest(), "etatsMTE", "Catalogue", "DownloadPDF", ['job_id' => $vs_job_id]);
			}
			echo json_encode($va_status);
		}
		exit;
	}

	# -------------------------------------------------------
	# CheckAllPDF — AJAX endpoint, returns JSON status of all active jobs
	# -------------------------------------------------------
	public function CheckAllPDF() {
		$va_jobs = isset($_SESSION['etatsMTE_pdf_jobs']) ? $_SESSION['etatsMTE_pdf_jobs'] : [];

		$vs_job_dir = __CA_APP_DIR__ . '/tmp/catalogues';
		$va_results = [];

		$va_jobs_to_keep = [];
		foreach ($va_jobs as $vs_job_id) {
			$vs_job_id_safe = preg_replace('/[^a-zA-Z0-9_]/', '', $vs_job_id);
			$vs_status_file = $vs_job_dir . '/' . $vs_job_id_safe . '.status';
			$vs_job_file = $vs_job_dir . '/' . $vs_job_id_safe . '.json';

			if (file_exists($vs_status_file)) {
				$va_status = json_decode(file_get_contents($vs_status_file), true);
				if ($va_status['status'] === 'done') {
					$va_status['download_url'] = caNavUrl($this->getRequest(), "etatsMTE", "Catalogue", "DownloadPDF", ['job_id' => $vs_job_id_safe]);
				}
				$va_status['job_id'] = $vs_job_id_safe;
				$va_results[] = $va_status;
				$va_jobs_to_keep[] = $vs_job_id;
			} elseif (file_exists($vs_job_file)) {
				// Job file exists but status not yet created — process is starting
				$va_results[] = ['job_id' => $vs_job_id_safe, 'status' => 'pending'];
				$va_jobs_to_keep[] = $vs_job_id;
			}
			// If neither file exists, drop from session (stale job)
		}

		// Clean up stale jobs from session
		$_SESSION['etatsMTE_pdf_jobs'] = $va_jobs_to_keep;

		header('Content-Type: application/json');
		echo json_encode($va_results);
		exit;
	}

	# -------------------------------------------------------
	# DownloadPDF — serve a generated PDF file
	# -------------------------------------------------------
	public function DownloadPDF() {
		$vs_job_id = $this->getRequest()->getParameter("job_id", pString);
		$vs_job_id = preg_replace('/[^a-zA-Z0-9_]/', '', $vs_job_id);

		$vs_job_dir = __CA_APP_DIR__ . '/tmp/catalogues';
		$vs_pdf_file = $vs_job_dir . '/' . $vs_job_id . '.pdf';
		$vs_status_file = $vs_job_dir . '/' . $vs_job_id . '.status';

		if (!file_exists($vs_pdf_file)) {
			$this->view->setVar("message", "Le fichier PDF n'existe pas ou a expiré.");
			return $this->render("error_html.php");
		}

		// Read titre from status
		$vs_filename = 'catalogue_mte.pdf';
		if (file_exists($vs_status_file)) {
			$va_status = json_decode(file_get_contents($vs_status_file), true);
			if (!empty($va_status['titre'])) {
				$vs_filename = preg_replace('/[^a-zA-Z0-9àâäéèêëïîôùûüÿçœæ _-]/u', '', $va_status['titre']) . '.pdf';
			}
		}

		header('Content-Type: application/pdf');
		header('Content-Disposition: attachment; filename="' . $vs_filename . '"');
		header('Content-Length: ' . filesize($vs_pdf_file));
		readfile($vs_pdf_file);

		// Clean up: remove job files after download
		@unlink($vs_pdf_file);
		@unlink($vs_status_file);
		@unlink($vs_job_dir . '/' . $vs_job_id . '.json');
		@unlink($vs_job_dir . '/' . $vs_job_id . '.log');

		// Remove from session
		if (isset($_SESSION['etatsMTE_pdf_jobs'])) {
			$_SESSION['etatsMTE_pdf_jobs'] = array_diff($_SESSION['etatsMTE_pdf_jobs'], [$vs_job_id]);
		}

		exit;
	}

	# -------------------------------------------------------
	# Telechargements — liste des catalogues générés
	# -------------------------------------------------------
	public function Telechargements() {
		$vs_job_dir = __CA_APP_DIR__ . '/tmp/catalogues';
		$va_catalogues = [];

		if (is_dir($vs_job_dir)) {
			$va_status_files = glob($vs_job_dir . '/*.status');
			foreach ($va_status_files as $vs_status_file) {
				$vs_job_id = basename($vs_status_file, '.status');
				$va_status = json_decode(file_get_contents($vs_status_file), true);
				if (!$va_status) continue;

				$vs_json_file = $vs_job_dir . '/' . $vs_job_id . '.json';
				$va_params = file_exists($vs_json_file) ? json_decode(file_get_contents($vs_json_file), true) : [];

				$vs_log_file = $vs_job_dir . '/' . $vs_job_id . '.log';
				$vs_log = file_exists($vs_log_file) ? file_get_contents($vs_log_file) : '';

				$vs_pdf_file = $vs_job_dir . '/' . $vs_job_id . '.pdf';
				$vn_pdf_size = file_exists($vs_pdf_file) ? filesize($vs_pdf_file) : 0;

				$va_catalogues[] = [
					'job_id'     => $vs_job_id,
					'status'     => $va_status['status'],
					'titre'      => $va_status['titre'] ?? $va_params['catalogue_type'] ?? $vs_job_id,
					'total'      => $va_status['total'] ?? null,
					'processed'  => $va_status['processed'] ?? null,
					'finished'   => $va_status['finished'] ?? null,
					'started'    => $va_status['started'] ?? null,
					'message'    => $va_status['message'] ?? null,
					'pdf_size'   => $vn_pdf_size,
					'log'        => $vs_log,
					'params'     => $va_params,
					'download_url' => ($va_status['status'] === 'done' && $vn_pdf_size > 0)
						? caNavUrl($this->getRequest(), "etatsMTE", "Catalogue", "DownloadPDF", ['job_id' => $vs_job_id])
						: null,
					'delete_url' => caNavUrl($this->getRequest(), "etatsMTE", "Catalogue", "DeletePDF", ['job_id' => $vs_job_id]),
				];
			}

			// Sort by job_id descending (most recent first — job_id contains timestamp)
			usort($va_catalogues, function($a, $b) {
				return strcmp($b['job_id'], $a['job_id']);
			});
		}

		$this->view->setVar('catalogues', $va_catalogues);
		$this->render('catalogue_telechargements_html.php');
	}

	# -------------------------------------------------------
	# TelechargementsList — JSON endpoint for AJAX polling
	# -------------------------------------------------------
	public function TelechargementsList() {
		$vs_job_dir = __CA_APP_DIR__ . '/tmp/catalogues';
		$va_catalogues = [];

		if (is_dir($vs_job_dir)) {
			$va_status_files = glob($vs_job_dir . '/*.status');
			foreach ($va_status_files as $vs_status_file) {
				$vs_job_id = basename($vs_status_file, '.status');
				$va_status = json_decode(file_get_contents($vs_status_file), true);
				if (!$va_status) continue;

				$vs_json_file = $vs_job_dir . '/' . $vs_job_id . '.json';
				$va_params = file_exists($vs_json_file) ? json_decode(file_get_contents($vs_json_file), true) : [];

				$vs_pdf_file = $vs_job_dir . '/' . $vs_job_id . '.pdf';
				$vn_pdf_size = file_exists($vs_pdf_file) ? filesize($vs_pdf_file) : 0;

				$va_catalogues[] = [
					'job_id'     => $vs_job_id,
					'status'     => $va_status['status'],
					'titre'      => $va_status['titre'] ?? $va_params['catalogue_type'] ?? $vs_job_id,
					'total'      => $va_status['total'] ?? null,
					'processed'  => $va_status['processed'] ?? null,
					'finished'   => $va_status['finished'] ?? null,
					'started'    => $va_status['started'] ?? null,
					'message'    => $va_status['message'] ?? null,
					'pdf_size'   => $vn_pdf_size,
					'download_url' => ($va_status['status'] === 'done' && $vn_pdf_size > 0)
						? caNavUrl($this->getRequest(), "etatsMTE", "Catalogue", "DownloadPDF", ['job_id' => $vs_job_id])
						: null,
					'delete_url' => caNavUrl($this->getRequest(), "etatsMTE", "Catalogue", "DeletePDF", ['job_id' => $vs_job_id]),
				];
			}

			usort($va_catalogues, function($a, $b) {
				return strcmp($b['job_id'], $a['job_id']);
			});
		}

		header('Content-Type: application/json');
		echo json_encode($va_catalogues);
		exit;
	}

	# -------------------------------------------------------
	# DeletePDF — supprimer un catalogue généré
	# -------------------------------------------------------
	public function DeletePDF() {
		$vs_job_id = $this->getRequest()->getParameter("job_id", pString);
		$vs_job_id = preg_replace('/[^a-zA-Z0-9_]/', '', $vs_job_id);

		$vs_job_dir = __CA_APP_DIR__ . '/tmp/catalogues';
		@unlink($vs_job_dir . '/' . $vs_job_id . '.pdf');
		@unlink($vs_job_dir . '/' . $vs_job_id . '.status');
		@unlink($vs_job_dir . '/' . $vs_job_id . '.json');
		@unlink($vs_job_dir . '/' . $vs_job_id . '.log');

		// Remove from session
		if (isset($_SESSION['etatsMTE_pdf_jobs'])) {
			$_SESSION['etatsMTE_pdf_jobs'] = array_values(array_diff($_SESSION['etatsMTE_pdf_jobs'], [$vs_job_id]));
		}

		if ($this->getRequest()->isAjax() || $this->getRequest()->getParameter("ajax", pInteger)) {
			header('Content-Type: application/json');
			echo json_encode(['status' => 'deleted', 'job_id' => $vs_job_id]);
			exit;
		}

		$this->getResponse()->addHeader("Location", caNavUrl($this->getRequest(), "etatsMTE", "Catalogue", "Telechargements"));
	}

	# -------------------------------------------------------
	# NextDeposantNum — AJAX : renvoie le prochain numéro d'inventaire déposant
	#   (max des numinv_deposant de forme <PREFIX>_<n> + 1). Défaut prefix = MTE.
	# -------------------------------------------------------
	public function NextDeposantNum() {
		$ps_prefix = $this->getRequest()->getParameter("prefix", pString);
		if (!$ps_prefix) { $ps_prefix = 'MTE'; }
		$ps_prefix = preg_replace('/[^A-Za-z0-9]/', '', $ps_prefix);

		$o_db = new Db();
		$qr = $o_db->query(
			"SELECT MAX(CAST(SUBSTRING(av.value_longtext1, ".(strlen($ps_prefix) + 2).") AS UNSIGNED)) mx
			 FROM ca_attribute_values av
			 JOIN ca_metadata_elements e ON e.element_id = av.element_id
			 WHERE e.element_code = 'numinv_deposant' AND av.value_longtext1 REGEXP ?",
			['^'.$ps_prefix.'_[0-9]+$']
		);
		$vn_max = 0;
		if ($qr->nextRow()) { $vn_max = (int)$qr->get('mx'); }
		$vn_next = $vn_max + 1;

		header('Content-Type: application/json');
		echo json_encode(['prefix' => $ps_prefix, 'next_int' => $vn_next, 'next' => $ps_prefix.'_'.$vn_next]);
		exit;
	}

	# -------------------------------------------------------
	# Private helpers
	# -------------------------------------------------------
	private function _getDeposants() {
		$o_db = new Db();
		$qr = $o_db->query(
			"SELECT DISTINCT e.entity_id, el.displayname
			 FROM ca_objects_x_entities oxe
			 JOIN ca_entities e ON oxe.entity_id = e.entity_id
			 JOIN ca_entity_labels el ON e.entity_id = el.entity_id
			 WHERE oxe.type_id = ? AND el.is_preferred = 1 AND e.deleted = 0
			 ORDER BY el.displayname",
			self::REL_DEPOSANT
		);
		$va_items = [];
		while($qr->nextRow()) {
			$va_items[$qr->get("entity_id")] = $qr->get("displayname");
		}
		return $va_items;
	}

	private function _getListItems($pn_list_id) {
		$o_db = new Db();
		$qr = $o_db->query(
			"SELECT li.item_id, ll.name_singular
			 FROM ca_list_items li
			 JOIN ca_list_item_labels ll ON li.item_id = ll.item_id
			 WHERE li.list_id = ? AND li.parent_id IS NOT NULL AND ll.is_preferred = 1 AND li.is_enabled = 1
			 ORDER BY ll.name_singular",
			$pn_list_id
		);
		$va_items = [];
		while($qr->nextRow()) {
			$va_items[$qr->get("item_id")] = $qr->get("name_singular");
		}
		return $va_items;
	}

	private function _dateToJulian($ps_date) {
		// Convert dd/mm/yyyy or yyyy-mm-dd to Julian day number for CA date storage
		if (preg_match('!^(\d{1,2})/(\d{1,2})/(\d{4})$!', $ps_date, $va_matches)) {
			return gregoriantojd((int)$va_matches[2], (int)$va_matches[1], (int)$va_matches[3]);
		}
		if (preg_match('!^(\d{4})-(\d{1,2})-(\d{1,2})$!', $ps_date, $va_matches)) {
			return gregoriantojd((int)$va_matches[2], (int)$va_matches[3], (int)$va_matches[1]);
		}
		return 0;
	}
}
?>
