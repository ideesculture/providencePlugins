<?php
/* Book Creator plugin for CollectiveAccess
 *
 * Plugin by idéesculture – Gautier MICHELIN
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License v3. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * ----------------------------------------------------------------------
 */

require_once(__CA_LIB_DIR__.'/Configuration.php');
require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/BookSchemaManager.php');
require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/BookJobModel.php');
require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/BookCsrf.php');
require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/PdfRendererFactory.php');
require_once(__CA_APP_DIR__.'/plugins/bookCreator/models/plugin_books.php');

/**
 * Queues a book generation and reports its progress.
 *
 * The v1 generated inside the HTTP request, with memory_limit raised to 4 GB
 * and progress printed by echo/flush as the sections went by: the browser held
 * a connection open for minutes, a reload started everything again, and a
 * timeout lost the lot. Here the button only queues a job; the CLI worker does
 * the work, and this controller answers the polling.
 *
 * Nothing here renders anything. That separation is what bounds the memory of
 * the web process, and it is also what lets the same generation run from a cron
 * on a plain Providence or from a dedicated pod on Kubernetes.
 */
class GenerationController extends ActionController {
	# -------------------------------------------------------
	protected $opo_config;
	private $opo_jobs;
	# -------------------------------------------------------

	public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
		parent::__construct($po_request, $po_response, $pa_view_paths);

		$this->opo_config = Configuration::load(
			__CA_APP_DIR__.'/plugins/bookCreator/conf/bookCreator.conf'
		);

		if (!$this->userCanUsePlugin()) {
			$this->response->setRedirect(
				$this->request->config->get('error_display_url')
				.'/n/3000?r='.urlencode($this->request->getFullUrlPath())
			);
			return;
		}

		$o_schema = new BookSchemaManager();
		if (!$o_schema->isUsable()) {
			$this->response->setRedirect(caNavUrl($this->request, 'bookCreator', 'Install', 'Index'));
			return;
		}

		$this->opo_jobs = new BookJobModel();
	}

	/** Same rule as the other controllers: an explicit grant, or default_access. */
	private function userCanUsePlugin() {
		if ($this->request->user->canDoAction('can_use_book_editor_plugin')) { return true; }
		return (bool)$this->opo_config->get('default_access');
	}

	# -------------------------------------------------------

	/**
	 * Queues a generation and shows the progress screen.
	 *
	 * submit() returns any pending or running job of the same book instead of
	 * creating a second one, so an impatient double click cannot start two
	 * renderings of a 200-page catalogue.
	 */
	public function Submit() {
		if (!$this->request->isLoggedIn() || !BookCsrf::isValid($this->request)) {
			$this->response->setRedirect(caNavUrl($this->request, 'bookCreator', 'Generation', 'Index', ['book' => (int)$this->request->getParameter('book', pInteger)]));
			return;
		}

		$book_id = (int)$this->request->getParameter('book', pInteger);

		// Refuse early when nothing can render, rather than queueing a job that
		// will fail minutes later: the message names the missing binary.
		$check = (new PdfRendererFactory())->checkAvailability();
		if (!$check['ok']) {
			$this->view->setVar('error', IdC::_t('No PDF renderer is available: %1', join(' / ', $check['reasons'])));
			$this->view->setVar('book_id', $book_id);
			$this->view->setVar('job', null);
			$this->render('generate_html.php');
			return;
		}

		$job_id = $this->opo_jobs->submit($book_id, (int)$this->request->getUserID());

		$this->view->setVar('book_id', $book_id);
		$this->view->setVar('job', $this->opo_jobs->get($job_id));
		$this->render('generate_html.php');
	}

	/**
	 * Cancels the job waiting for this book.
	 *
	 * The way out of a queue nobody is serving: since submit() hands back the
	 * pending job instead of creating another, a book whose worker is not
	 * running would otherwise stay locked on it for ever.
	 */
	public function Cancel() {
		$book_id = (int)$this->request->getParameter('book', pInteger);

		if (!$this->request->isLoggedIn() || !BookCsrf::isValid($this->request)) {
			$this->response->setRedirect(caNavUrl($this->request, 'bookCreator', 'Generation', 'Index', ['book' => $book_id]));
			return;
		}

		// The ACTIVE job, not merely the last one: getForBook() returns the most
		// recent whatever its state, so cancelling right after a finished
		// generation used to answer "already started" about a job that was done.
		$job = $this->opo_jobs->getActiveForBook($book_id);
		$notification = null;
		$error = null;

		if (!is_array($job)) {
			$error = IdC::_t('There is no generation to cancel for this book.');
		} elseif ($this->opo_jobs->cancel((int)$job['job_id'])) {
			$notification = IdC::_t('Generation cancelled. You can start a new one.');
		} else {
			// cancel() only touches a pending job: a running one belongs to a
			// worker that is writing right now, and is left to the reaper.
			$error = IdC::_t('This generation has already started and cannot be cancelled. It will be released if its worker stops.');
		}

		$this->view->setVar('book_id', $book_id);
		$this->view->setVar('job', $this->opo_jobs->getForBook($book_id));
		$this->view->setVar('notification', $notification);
		$this->view->setVar('error', $error);
		$this->render('generate_html.php');
	}

	/** The progress screen alone, without queueing anything. */
	public function Index() {
		$book_id = (int)$this->request->getParameter('book', pInteger);

		$this->view->setVar('book_id', $book_id);
		$this->view->setVar('job', $this->opo_jobs->getForBook($book_id));
		$this->render('generate_html.php');
	}

	/**
	 * Job state as JSON, for the polling.
	 *
	 * Deliberately terse: this is called every couple of seconds while a
	 * catalogue renders. It carries the download URL only once the file exists,
	 * so the interface cannot offer a link to a half-written PDF.
	 */
	public function Status() {
		$book_id = (int)$this->request->getParameter('book', pInteger);
		$job = $this->opo_jobs->getForBook($book_id);

		$payload = ['status' => 'none', 'progress' => 0, 'message' => null, 'url' => null];

		if (is_array($job)) {
			$payload['status']   = $job['status'];
			$payload['progress'] = (int)$job['progress'];
			$payload['message']  = $job['message'];

			if ($job['status'] === 'done' && !empty($job['pdf_path']) && is_readable($job['pdf_path'])) {
				$payload['url'] = caNavUrl($this->request, 'bookCreator', 'Generation', 'Download', ['book' => $book_id]);
			}
		}

		$this->response->addHeader('Content-Type', 'application/json; charset=UTF-8');
		$this->response->sendHeaders();
		print json_encode($payload);
		exit;
	}

	/**
	 * Streams the finished PDF.
	 *
	 * The path is read back from the job rather than taken from the request, so
	 * a crafted parameter cannot walk the filesystem. It is checked against the
	 * configured output directory before anything is sent.
	 */
	public function Download() {
		$book_id = (int)$this->request->getParameter('book', pInteger);

		// A deleted book has no PDF to offer. The check was missing entirely:
		// the controller read the last job of the book_id and served its file,
		// so a catalogue could be downloaded long after it was deleted.
		$book = new plugin_books($book_id);
		if (!$book->isLoaded()) {
			$this->response->setRedirect(caNavUrl($this->request, 'bookCreator', 'Books', 'Index'));
			return;
		}

		$job = $this->opo_jobs->getForBook($book_id);

		if (!is_array($job) || $job['status'] !== 'done' || empty($job['pdf_path'])) {
			$this->view->setVar('error', IdC::_t('This book has not been generated yet.'));
			$this->view->setVar('book_id', $book_id);
			$this->view->setVar('job', $job);
			$this->render('generate_html.php');
			return;
		}

		$path = realpath($job['pdf_path']);
		if (!$path || !is_readable($path) || !$this->isInsideOutputDir($path)) {
			$this->view->setVar('error', IdC::_t('The generated file is no longer available.'));
			$this->view->setVar('book_id', $book_id);
			$this->view->setVar('job', $job);
			$this->render('generate_html.php');
			return;
		}

		$filename = $this->downloadFilename($book, $book_id);

		$this->response->addHeader('Content-Type', 'application/pdf');
		$this->response->addHeader('Content-Disposition', 'attachment; filename="'.$filename.'"');
		$this->response->addHeader('Content-Length', (string)filesize($path));
		$this->response->sendHeaders();

		readfile($path);
		exit;
	}

	# -------------------------------------------------------

	/** True when the file sits in the directory the worker writes to. */
	private function isInsideOutputDir($path) {
		$configured = trim((string)$this->opo_config->get('job_output_dir'));
		if (!strlen($configured)) {
			$configured = __CA_APP_DIR__.'/plugins/bookCreator/tmp';
		}
		$output_dir = realpath($configured);
		return ($output_dir && strpos($path, $output_dir.DIRECTORY_SEPARATOR) === 0);
	}

	/**
	 * Latin letters a book title is likely to carry, and their ASCII form.
	 *
	 * Covers French and its neighbours, which is what these catalogues are
	 * written in. Anything absent is stripped by the filter below, as before.
	 */
	private const TRANSLITERATIONS = [
		'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a',
		'Ç'=>'C','ç'=>'c','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
		'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
		'Ñ'=>'N','ñ'=>'n','Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
		'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U','ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
		'Ý'=>'Y','ý'=>'y','ÿ'=>'y','Æ'=>'AE','æ'=>'ae','Œ'=>'OE','œ'=>'oe','ß'=>'ss','Ø'=>'O','ø'=>'o',
		'—'=>'-','–'=>'-','’'=>"'",
	];

	/** A download name built from the book title, safe for a Content-Disposition. */
	private function downloadFilename($book, $book_id) {
		$title = $book->getTitle();

		// Transliterate before stripping, otherwise every accent and ligature
		// becomes a dash: "Œuvres de Floutier" came out "-uvres-de-Floutier".
		//
		// An explicit table rather than iconv('ASCII//TRANSLIT'): its output
		// depends on the C library, and the same title gives "ete" on glibc and
		// "-'e?t'e" on macOS. A download name has to be the same everywhere.
		$title = strtr($title, self::TRANSLITERATIONS);

		$slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $title);
		$slug = preg_replace('/-{2,}/', '-', (string)$slug);   // "a — b" gave "a---b"
		$slug = trim((string)$slug, '-');
		return (strlen($slug) ? $slug : 'book-'.$book_id).'.pdf';
	}
}
