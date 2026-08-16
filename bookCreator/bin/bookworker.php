#!/usr/bin/env php
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

/**
 * bookworker.php — background PDF generation worker.
 *
 * Claims jobs from `plugin_book_jobs` (see lib/BookJobModel.php), renders the
 * book one section at a time, records progress after each of them, assembles
 * the final PDF and closes the job. Nothing is rendered inside an HTTP request
 * any more: the web side only submits a job and polls its status.
 *
 * One render at a time, always. That single rule is what bounds peak memory at
 * roughly 65 MB with the WeasyPrint driver, whatever the size of the book, and
 * is why the loop below never forks and never renders two sections in
 * parallel. Scaling is done by running more workers, not by widening one.
 *
 * The same file serves both deployments, with no assumption about either:
 *   - classic Providence: a cron entry with --max-runtime, one run per minute;
 *   - Kubernetes: a long running Deployment, no --max-runtime, SIGTERM on pod
 *     shutdown returning the current job to the queue.
 *
 * See bin/README.md for installation and for diagnosing a stuck job.
 */

# -------------------------------------------------------
# Exit codes. A CLI worker reports through its status, never through die().
# -------------------------------------------------------
const BOOKWORKER_EXIT_OK        = 0;   // ran to completion, whatever the queue held
const BOOKWORKER_EXIT_USAGE     = 1;   // bad option, or --help
const BOOKWORKER_EXIT_BOOTSTRAP = 2;   // CollectiveAccess could not be loaded
const BOOKWORKER_EXIT_RUNTIME   = 3;   // at least one job failed, or the queue is unreachable

# Progress budget: rendering owns 0-90%, assembly the rest. finish() writes 100.
const BOOKWORKER_RENDER_BUDGET = 90;

/**
 * Shutdown state, shared with the signal handlers.
 *
 * Handlers only ever set a flag: they must stay free of database and file
 * access, since they can fire in the middle of anything. The loop reads the
 * flag at its checkpoints, between sections, which is where stopping is safe.
 */
$g_bookworker = [
	'stop_requested' => false,
	'current_job_id' => 0,
	'jobs'           => null,   // BookJobModel, once bootstrapped
	'verbose'        => false,
];

# -------------------------------------------------------
# Output
# -------------------------------------------------------

/** Informational line, stdout, only with --verbose so cron stays silent on success. */
function bookworker_log(string $message): void {
	global $g_bookworker;
	if (!$g_bookworker['verbose']) { return; }
	fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n");
}

/** Error line, stderr, always shown: this is what cron mails and what kubectl logs shows. */
function bookworker_error(string $message): void {
	fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $message . "\n");
}

function bookworker_usage(): void {
	fwrite(STDOUT, <<<TXT
Usage: php bookworker.php [options]

Renders queued bookCreator jobs (table plugin_book_jobs), one section at a time.

Options:
  --providence=PATH   Path to the Providence root, the directory holding setup.php.
                      Defaults to \$BOOKCREATOR_PROVIDENCE_HOME, then to
                      \$COLLECTIVEACCESS_HOME, then to the first parent directory
                      of the plugin that contains setup.php.
  --max-runtime=N     Stop claiming new jobs after N seconds and exit. 0 (default)
                      runs until stopped. Use --max-runtime=55 for a per-minute cron.
  --once              Process at most one job, then exit. Exits immediately when the
                      queue is empty.
  --job=N             Process job N and nothing else. The job must still be pending.
  --sleep=N           Seconds to wait when the queue is empty (default 5).
  --reap-after=N      Requeue jobs left running for more than N seconds by a dead
                      worker (default 3600). 0 disables reaping.
  --verbose           Log every step to stdout. Errors always go to stderr.
  --help              This text.

Exit codes: 0 ok, 1 usage, 2 CollectiveAccess bootstrap failure, 3 job failure.

TXT);
}

# -------------------------------------------------------
# Command line
# -------------------------------------------------------

/**
 * Parses argv into an options array.
 *
 * Deliberately hand written: getopt() cannot be told to reject an unknown
 * option, and a worker silently ignoring a mistyped --max-runtime would run
 * for ever under a cron that expects it to stop.
 *
 * @return array{options: array, error: ?string}
 */
function bookworker_parse_args(array $argv): array {
	$options = [
		'providence'  => null,
		'max_runtime' => 0,
		'once'        => false,
		'job'         => 0,
		'sleep'       => 5,
		'reap_after'  => 3600,
		'verbose'     => false,
		'help'        => false,
	];

	foreach (array_slice($argv, 1) as $argument) {
		// No short aliases: caUtils already gives -h a different meaning
		// (-h=<hostname>), and a worker that mistakes a hostname for a help
		// request in a multi-database cron would be silently doing nothing.
		if ($argument === '--help')    { $options['help'] = true; continue; }
		if ($argument === '--once')    { $options['once'] = true; continue; }
		if ($argument === '--verbose') { $options['verbose'] = true; continue; }

		if (!preg_match('/^--([a-z\-]+)=(.*)$/', $argument, $matches)) {
			return ['options' => $options, 'error' => "unknown option: {$argument}"];
		}
		[, $name, $value] = $matches;

		switch ($name) {
			case 'providence':
				$options['providence'] = rtrim($value, '/');
				break;
			case 'max-runtime':
				if (!ctype_digit($value)) { return ['options' => $options, 'error' => '--max-runtime expects a number of seconds']; }
				$options['max_runtime'] = (int)$value;
				break;
			case 'job':
				if (!ctype_digit($value) || (int)$value < 1) { return ['options' => $options, 'error' => '--job expects a job id']; }
				$options['job'] = (int)$value;
				break;
			case 'sleep':
				if (!ctype_digit($value)) { return ['options' => $options, 'error' => '--sleep expects a number of seconds']; }
				$options['sleep'] = max(1, (int)$value);
				break;
			case 'reap-after':
				if (!ctype_digit($value)) { return ['options' => $options, 'error' => '--reap-after expects a number of seconds']; }
				$options['reap_after'] = (int)$value;
				break;
			default:
				return ['options' => $options, 'error' => "unknown option: --{$name}"];
		}
	}

	return ['options' => $options, 'error' => null];
}

# -------------------------------------------------------
# CollectiveAccess bootstrap
# -------------------------------------------------------

/**
 * Locates the Providence root, the directory holding setup.php.
 *
 * No path is ever hard coded: the plugin is installed under
 * <providence>/app/plugins/bookCreator on a classic install, but lives in an
 * image layer, a mount or a symlink elsewhere. Resolution order, most explicit
 * first: --providence, then BOOKCREATOR_PROVIDENCE_HOME (specific to this
 * worker), then COLLECTIVEACCESS_HOME (the variable caUtils already uses, so a
 * host configured for caUtils needs nothing more), then a walk up the parent
 * directories of this file.
 *
 * @return array{root: ?string, error: ?string}
 */
function bookworker_resolve_providence_root(?string $from_option): array {
	$candidates = [];
	if ($from_option !== null && $from_option !== '') { $candidates['--providence'] = $from_option; }

	foreach (['BOOKCREATOR_PROVIDENCE_HOME', 'COLLECTIVEACCESS_HOME'] as $variable) {
		$value = getenv($variable);
		if (is_string($value) && $value !== '') { $candidates[$variable] = rtrim($value, '/'); }
	}

	$rejected = [];
	foreach ($candidates as $origin => $path) {
		if (is_file($path . '/setup.php')) { return ['root' => $path, 'error' => null]; }
		$rejected[] = "{$origin}={$path}";
	}

	// An explicit path that holds no setup.php is a configuration mistake, and
	// silently falling back to a directory the operator did not name would
	// generate the book against the wrong database.
	if ($rejected) {
		return ['root' => null, 'error' => 'no setup.php under the given Providence root(s): ' . join(', ', $rejected)];
	}

	// Walk up from bin/, which on a classic install reaches
	// <providence>/app/plugins/bookCreator/bin -> <providence> in four steps.
	$directory = __DIR__;
	while ($directory !== '' && $directory !== '/' && $directory !== '.') {
		if (is_file($directory . '/setup.php')) { return ['root' => $directory, 'error' => null]; }
		$parent = dirname($directory);
		if ($parent === $directory) { break; }
		$directory = $parent;
	}

	return ['root' => null, 'error' =>
		'could not find the Providence setup.php. Pass --providence=/path/to/providence, '
		. 'or set BOOKCREATOR_PROVIDENCE_HOME (or COLLECTIVEACCESS_HOME) in the worker environment.'];
}

# -------------------------------------------------------
# PDF chain — single integration point
# -------------------------------------------------------

/** Raised at a stop checkpoint; requeues the job instead of failing it. */
class BookWorkerInterrupted extends RuntimeException {}

/**
 * Bridge to the PDF chain, which is written separately (plan 2, lots A and D).
 *
 * Everything the worker does around rendering — claiming, progress, failure
 * handling, signals, assembly bookkeeping — is complete and testable without
 * it. The three methods below are the whole contract, and wiring the real
 * classes means replacing their bodies, nothing else in this file.
 *
 * Expected wiring against the classes of lot A (PdfRendererInterface,
 * WeasyPrintRenderer, RenderOptions, PdfAssembler) plus the HTML builder:
 *
 *   isAvailable()   -> require the renderer files, instantiate the driver named
 *                      by conf/bookCreator.conf (renderer = weasyprint |
 *                      gotenberg) and return $renderer->isAvailable() &&
 *                      $assembler->isAvailable(), so a host missing weasyprint
 *                      or qpdf fails the job with getUnavailableReason()
 *                      instead of half rendering it.
 *   renderSection() -> $html_path = (new BookHtmlBuilder($book))->writeSection($section, $work_dir);
 *                      $opts = (new RenderOptions(...))->withFirstPageNumber($page_offset);
 *                      $result = $renderer->render($html_path, $pdf_path, $opts);
 *                      if(!$result->success) { throw new RuntimeException($result->errorMessage); }
 *                      return ['path' => $result->pdfPath,
 *                              'pages' => (int)($result->pageCount
 *                                        ?? $assembler->countPages($result->pdfPath))];
 *   assemble()      -> $assembler->concat([cover, ...sections, back cover], $output_path)
 *                      and throw $assembler->getLastError() when it returns false.
 *
 * renderSection() returns ['path' => absolute PDF path, 'pages' => int]. The
 * page count feeds the cumulated first_page of the following sections, so a
 * driver that cannot count pages must return 0 rather than guess. Writing
 * nb_pages / first_page back onto the section rows belongs to the section
 * model, and is done by the caller of this bridge once that model exposes it.
 */
final class BookWorkerRenderBridge {

	/**
	 * True once a usable PDF driver and assembler are installed.
	 *
	 * Returns false while nothing is wired, which makes every job fail fast
	 * with an explicit message rather than produce an empty PDF.
	 */
	/** @var PdfRendererFactory|null built once per worker process */
	private static $factory = null;

	private static function factory(): PdfRendererFactory {
		if (self::$factory === null) { self::$factory = new PdfRendererFactory(); }
		return self::$factory;
	}

	public static function isAvailable(): bool {
		$check = self::factory()->checkAvailability();
		return (bool)$check['ok'];
	}

	/** Why the chain cannot run, for the message carried by a failed job. */
	public static function unavailableReason(): string {
		$check = self::factory()->checkAvailability();
		return join(' / ', $check['reasons']);
	}

	/**
	 * Renders one section to its own PDF.
	 *
	 * @param array $book row of plugin_books
	 * @param array $section row of plugin_booksections
	 * @param int $page_offset first page number of this section in the finished book
	 * @param string $work_dir writable directory for this job
	 * @return array{path: string, pages: int}
	 */
	public static function renderSection(array $book, array $section, int $page_offset, string $work_dir): array {
		$section_id = (int)$section['booksection_id'];

		$builder = new BookHtmlBuilder(
			$book['theme'] ?? 'default',
			$book['page_format'] ?? 'a4-landscape',
			$book['font_pair'] ?? 'default'
		);

		// first_page makes the section carry the folio it holds in the finished
		// book, even though it is rendered on its own.
		$html = $builder->buildDocument((int)$book['book_id'], $section_id, ['first_page' => $page_offset]);

		$html_path = $work_dir . '/section-' . $section_id . '.html';
		if (@file_put_contents($html_path, $html) === false) {
			throw new RuntimeException("could not write {$html_path}");
		}

		$pdf_path = $work_dir . '/section-' . $section_id . '.pdf';
		$factory  = self::factory();

		// The base URL must be the theme directory: the stylesheets and fonts
		// referenced by the document are relative to it.
		$options = $factory->makeRenderOptions()
			->withFirstPageNumber($page_offset)
			->withBaseUrl(ThemeRegistry::themesPath() . '/' . ($book['theme'] ?? 'default') . '/');

		$result = $factory->makeRenderer()->render($html_path, $pdf_path, $options);

		if (!$result->success) {
			throw new RuntimeException($result->errorMessage ?? 'rendering failed');
		}

		// WeasyPrint exits 0 with a hole in the page when an image is missing,
		// so a successful render still has to be inspected: a lost plate would
		// otherwise only surface on the printed copy.
		foreach ($result->warnings as $warning) {
			bookworker_error("section {$section_id}: {$warning}");
		}
		foreach ($builder->getSkippedMessages() as $skipped) {
			bookworker_error("section {$section_id}: {$skipped}");
		}

		$pages = $result->pageCount;
		if ($pages === null) {
			$pages = $factory->makeAssembler()->countPages($pdf_path);
		}

		return ['path' => $pdf_path, 'pages' => (int)$pages];
	}

	/**
	 * Concatenates cover, section PDFs and back cover into the delivered file.
	 *
	 * @param array $book row of plugin_books
	 * @param array $section_pdfs absolute paths, in reading order
	 * @param string $output_path absolute path of the PDF to produce
	 */
	public static function assemble(array $book, array $section_pdfs, string $output_path): void {
		// Covers stay static PDFs supplied by the client, as they always were:
		// they are designed in a page layout application, not composed here.
		$parts = [];
		if (!empty($book['cover_pdf']) && is_readable($book['cover_pdf'])) {
			$parts[] = $book['cover_pdf'];
		}
		$parts = array_merge($parts, $section_pdfs);
		if (!empty($book['backcover_pdf']) && is_readable($book['backcover_pdf'])) {
			$parts[] = $book['backcover_pdf'];
		}

		if (!sizeof($parts)) {
			throw new RuntimeException('nothing to assemble: no section produced a PDF');
		}

		$assembler = self::factory()->makeAssembler();
		if (!$assembler->concat($parts, $output_path)) {
			throw new RuntimeException($assembler->getLastError() ?? 'assembly failed');
		}
	}
}

# -------------------------------------------------------
# Job processing
# -------------------------------------------------------

/**
 * Directories the worker writes to.
 *
 * Read from the plugin configuration when the keys are present, so a host can
 * send the output to a shared volume, and falling back to the tmp/ directory
 * the plugin already ships. Both are created if missing; failing to create
 * them fails the job with a message an operator can act on.
 *
 * @return array{work: string, output: string}
 */
function bookworker_directories(string $plugin_dir): array {
	$config  = Configuration::load($plugin_dir . '/conf/bookCreator.conf');
	$work    = trim((string)$config->get('job_work_dir'));
	$output  = trim((string)$config->get('job_output_dir'));

	if ($work === '')   { $work = $plugin_dir . '/tmp'; }
	if ($output === '') { $output = $plugin_dir . '/tmp'; }

	foreach ([$work, $output] as $directory) {
		if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
			throw new RuntimeException("Directory {$directory} does not exist and could not be created.");
		}
		if (!is_writable($directory)) {
			throw new RuntimeException("Directory {$directory} is not writable by the worker user.");
		}
	}

	return ['work' => rtrim($work, '/'), 'output' => rtrim($output, '/')];
}

/**
 * Renders one job from end to end.
 *
 * Sections are rendered in `sort` order, one at a time, and progress is
 * written after each of them: on a 200-page catalogue this is the only thing
 * the editor sees moving, so it happens even when the section itself was
 * trivial. The cumulated page count is carried along the loop, since the page
 * number a section starts on depends on everything rendered before it.
 *
 * Returns the absolute path of the produced PDF. Any failure throws, and the
 * caller turns it into a failed job.
 */
function bookworker_process_job(array $job, BookJobModel $jobs, string $plugin_dir): string {
	global $g_bookworker;

	if (!BookWorkerRenderBridge::isAvailable()) {
		// The reason names the missing binary and how to install it, which is
		// what an operator reading a failed job actually needs.
		throw new RuntimeException(
			'No PDF renderer is available on this host: '
			.BookWorkerRenderBridge::unavailableReason()
			.' See bin/README.md.'
		);
	}

	$directories = bookworker_directories($plugin_dir);

	// plugin_books exposes the whole row only through its magic getter called
	// with no property name; every named access throws on a book that could
	// not be loaded, which a deleted book would trigger on the first field.
	$book = new plugin_books($job['book_id']);
	$book_row = $book->__get(null);
	if (!isset($book_row['data']) || !is_array($book_row['data']) || !$book_row['data']) {
		throw new RuntimeException("Book {$job['book_id']} does not exist any more.");
	}
	$book_data = $book_row['data'];

	$sections = $book->getSections();
	if ($job['job_type'] === BookJobModel::TYPE_SECTION && $job['section_id']) {
		// Single section job: the page offset of a lone section is unknown, so
		// it is rendered starting at page 1 and only used as a proof.
		$sections = array_values(array_filter(
			$sections,
			static fn(array $section): bool => (int)$section['booksection_id'] === (int)$job['section_id']
		));
		if (!$sections) {
			throw new RuntimeException("Section {$job['section_id']} does not belong to book {$job['book_id']}.");
		}
	}
	if (!$sections) {
		throw new RuntimeException("Book {$job['book_id']} has no section to render.");
	}

	$total = sizeof($sections);
	$section_pdfs = [];
	$page_offset = 1;
	$done = 0;

	$jobs->updateProgress($job['job_id'], 0, _t('Rendering %1 sections', $total));

	foreach ($sections as $section) {
		// Stop checkpoint. Between two sections nothing is half written, so the
		// job can go back to the queue and be restarted cleanly by the next
		// worker; see the signal handlers.
		if ($g_bookworker['stop_requested']) {
			throw new BookWorkerInterrupted('Stopped after ' . $done . '/' . $total . ' sections');
		}

		$rendered = BookWorkerRenderBridge::renderSection($book_data, $section, $page_offset, $directories['work']);
		$section_pdfs[] = $rendered['path'];
		$page_offset += max(0, (int)$rendered['pages']);
		$done++;

		// Progress after EVERY section, not every n sections: the editor is
		// watching a bar that must not stall on a long chapter.
		$percent = (int)floor(($done / $total) * BOOKWORKER_RENDER_BUDGET);
		$jobs->updateProgress(
			$job['job_id'],
			$percent,
			_t('Section %1 of %2 rendered', $done, $total)
		);
		bookworker_log("job {$job['job_id']}: section {$done}/{$total} rendered ({$percent}%)");
	}

	$jobs->updateProgress($job['job_id'], BOOKWORKER_RENDER_BUDGET, _t('Assembling the PDF'));

	$output_path = $directories['output'] . '/book-' . (int)$job['book_id'] . '-job-' . (int)$job['job_id'] . '.pdf';
	BookWorkerRenderBridge::assemble($book_data, $section_pdfs, $output_path);

	if (!is_file($output_path)) {
		throw new RuntimeException("The PDF chain reported success but {$output_path} was not written.");
	}

	return $output_path;
}

# -------------------------------------------------------
# Signals
# -------------------------------------------------------

/**
 * Installs SIGTERM/SIGINT handlers when pcntl is available.
 *
 * Without pcntl (a PHP build with the extension disabled) the worker still
 * runs: a job killed mid-render then stays in running until reapStale()
 * requeues it, which is exactly the safety net that function exists for.
 */
function bookworker_install_signal_handlers(): bool {
	if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) { return false; }

	pcntl_async_signals(true);
	$handler = static function (int $signal): void {
		global $g_bookworker;
		// Flag only. Touching the database from a signal handler would run
		// arbitrary code in the middle of another query.
		$g_bookworker['stop_requested'] = true;
	};
	pcntl_signal(SIGTERM, $handler);
	pcntl_signal(SIGINT, $handler);

	return true;
}

/**
 * Last resort requeue.
 *
 * Covers a fatal error and any exit path that left a job claimed: without it
 * the row would sit in running until the reaper, and the book would look busy
 * to every editor in the meantime. A job already closed by finish()/fail() has
 * cleared current_job_id, and release() only matches a running row anyway.
 */
function bookworker_release_current_job(): void {
	global $g_bookworker;
	if (!$g_bookworker['current_job_id'] || !($g_bookworker['jobs'] instanceof BookJobModel)) { return; }

	$job_id = (int)$g_bookworker['current_job_id'];
	$g_bookworker['current_job_id'] = 0;
	$g_bookworker['jobs']->release($job_id, 'Requeued: worker stopped before finishing');
	bookworker_log("job {$job_id}: requeued");
}

/** Sleeps in one second slices so a stop request is honoured without waiting out the interval. */
function bookworker_idle(int $seconds, int $deadline): void {
	global $g_bookworker;
	for ($i = 0; $i < $seconds; $i++) {
		if ($g_bookworker['stop_requested']) { return; }
		if ($deadline > 0 && time() >= $deadline) { return; }
		sleep(1);
	}
}

# =======================================================
# Main
# =======================================================

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "bookworker.php must be run from the command line.\n");
	exit(BOOKWORKER_EXIT_USAGE);
}

$parsed = bookworker_parse_args($argv);
$opts = $parsed['options'];

if ($parsed['error'] !== null) {
	bookworker_error($parsed['error']);
	bookworker_usage();
	exit(BOOKWORKER_EXIT_USAGE);
}
if ($opts['help']) {
	bookworker_usage();
	exit(BOOKWORKER_EXIT_OK);
}

$g_bookworker['verbose'] = (bool)$opts['verbose'];

# --- Bootstrap ------------------------------------------------------------
# A plugin CLI script has to load the application context itself. setup.php
# expects __CA_APP_TYPE__ to be defined and expects to be read from the
# Providence root, hence the chdir and the two $_SERVER keys a web request
# would normally provide (a multi-database install reads HTTP_HOST to pick its
# configuration).

$plugin_dir = dirname(__DIR__);

$resolved = bookworker_resolve_providence_root($opts['providence']);
if ($resolved['root'] === null) {
	bookworker_error((string)$resolved['error']);
	exit(BOOKWORKER_EXIT_BOOTSTRAP);
}
$providence_root = $resolved['root'];

if (!@chdir($providence_root)) {
	bookworker_error("could not change directory to {$providence_root}");
	exit(BOOKWORKER_EXIT_BOOTSTRAP);
}
if (!isset($_SERVER['DOCUMENT_ROOT']) || !$_SERVER['DOCUMENT_ROOT']) { $_SERVER['DOCUMENT_ROOT'] = $providence_root; }
if (!isset($_SERVER['HTTP_HOST']) || !$_SERVER['HTTP_HOST']) { $_SERVER['HTTP_HOST'] = (string)getenv('CA_HOSTNAME') ?: 'localhost'; }
$_SERVER['SCRIPT_FILENAME'] = __FILE__;

if (!defined('__CA_APP_TYPE__')) { define('__CA_APP_TYPE__', 'PROVIDENCE'); }
require_once($providence_root . '/setup.php');

# Messages stored on the job are read by the editor in the web interface, so
# the worker runs under the same default locale as the application. A CLI
# process has no request to infer it from.
try {
	if (function_exists('initializeLocale')) {
		$locale = (string)Configuration::load()->get('locale_default');
		if ($locale !== '') { initializeLocale($locale); }
	}
} catch (Throwable $e) {
	bookworker_error('could not initialize the locale, messages will be untranslated: ' . $e->getMessage());
}

foreach ([
	$plugin_dir . '/lib/BookJobModel.php',
	$plugin_dir . '/models/plugin_books.php',
	$plugin_dir . '/lib/PdfRendererFactory.php',
	$plugin_dir . '/lib/BookHtmlBuilder.php',
] as $plugin_file) {
	if (!is_file($plugin_file)) {
		bookworker_error("missing plugin file {$plugin_file}");
		exit(BOOKWORKER_EXIT_BOOTSTRAP);
	}
	require_once($plugin_file);
}

try {
	$jobs = new BookJobModel();
} catch (Throwable $e) {
	bookworker_error('could not open the job queue: ' . $e->getMessage());
	exit(BOOKWORKER_EXIT_BOOTSTRAP);
}
$g_bookworker['jobs'] = $jobs;

register_shutdown_function('bookworker_release_current_job');
$has_signals = bookworker_install_signal_handlers();

# One render at a time also means one job at a time: no PHP memory ceiling is
# raised here, on purpose. The 4 GB memory_limit of the v1 belonged to the HTTP
# generation; the worker holds one section of HTML at a time and the PDF engine
# runs in its own process.

$worker_id = gethostname() . ':' . getmypid();
$deadline = ($opts['max_runtime'] > 0) ? time() + $opts['max_runtime'] : 0;

bookworker_log("worker {$worker_id} started (providence: {$providence_root}, signals: " . ($has_signals ? 'on' : 'off') . ')');

$exit_code = BOOKWORKER_EXIT_OK;
$last_reap = 0;

while (true) {
	if ($g_bookworker['stop_requested']) {
		bookworker_log('stop requested, leaving the loop');
		break;
	}
	if ($deadline > 0 && time() >= $deadline) {
		bookworker_log('max runtime reached, leaving the loop');
		break;
	}

	// Requeue jobs abandoned by a dead worker, at most once a minute so a busy
	// loop does not hammer the table.
	if ($opts['reap_after'] > 0 && (time() - $last_reap) > 60) {
		$last_reap = time();
		if ($reaped = $jobs->reapStale($opts['reap_after'])) {
			bookworker_log("requeued {$reaped} stale job(s)");
		}
	}

	$job = ($opts['job'] > 0)
		? $jobs->claim($opts['job'], $worker_id)
		: $jobs->claimNext($worker_id);

	if ($job === null) {
		if ($opts['job'] > 0) {
			$state = $jobs->get($opts['job']);
			bookworker_error($state === null
				? "job {$opts['job']} does not exist"
				: "job {$opts['job']} is {$state['status']}, only a pending job can be claimed");
			$exit_code = BOOKWORKER_EXIT_RUNTIME;
			break;
		}
		if ($opts['once']) {
			bookworker_log('queue empty, nothing to do');
			break;
		}
		bookworker_idle($opts['sleep'], $deadline);
		continue;
	}

	$g_bookworker['current_job_id'] = (int)$job['job_id'];
	bookworker_log("job {$job['job_id']}: claimed (book {$job['book_id']}, type {$job['job_type']})");

	try {
		$pdf_path = bookworker_process_job($job, $jobs, $plugin_dir);
		$closed = $jobs->finish((int)$job['job_id'], $pdf_path);
		$g_bookworker['current_job_id'] = 0;
		if ($closed) {
			bookworker_log("job {$job['job_id']}: done -> {$pdf_path}");
		} else {
			// The row was no longer ours: reaped as stale and re-claimed
			// elsewhere while this render was running. The PDF is on disk and
			// valid, but the queue now belongs to the other worker, and the
			// reap threshold is too low for the books being generated here.
			bookworker_error("job {$job['job_id']}: rendered to {$pdf_path} but the job was no longer running, result discarded (raise --reap-after)");
			$exit_code = BOOKWORKER_EXIT_RUNTIME;
		}
	} catch (BookWorkerInterrupted $e) {
		// Asked to stop mid-book: back to the queue, not an error.
		$jobs->release((int)$job['job_id'], 'Requeued: ' . $e->getMessage());
		$g_bookworker['current_job_id'] = 0;
		bookworker_log("job {$job['job_id']}: requeued ({$e->getMessage()})");
		break;
	} catch (Throwable $e) {
		// The message goes to the row (the editor reads it), the detail to
		// stderr (the operator reads that).
		$jobs->fail((int)$job['job_id'], $e->getMessage());
		$g_bookworker['current_job_id'] = 0;
		bookworker_error("job {$job['job_id']} failed: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		$exit_code = BOOKWORKER_EXIT_RUNTIME;
	}

	if ($opts['job'] > 0 || $opts['once']) { break; }
}

bookworker_release_current_job();
bookworker_log("worker {$worker_id} stopped (exit {$exit_code})");
exit($exit_code);
