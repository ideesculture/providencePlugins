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

		// The permissions of whoever asked for the book, not those of the system
		// account the worker runs under. Without this the generation would print
		// records the preview refuses to show, which is the same leak by another
		// door. A job with no user — one queued before this column existed —
		// renders unchecked, as it did before.
		$builder->setAccessUser(bookworker_job_user($book));

		// first_page makes the section carry the folio it holds in the finished
		// book, even though it is rendered on its own.
		$html = $builder->buildDocument((int)$book['book_id'], $section_id, ['first_page' => $page_offset]);

		$html_path = $work_dir . '/section-' . $section_id . '.html';
		if (@file_put_contents($html_path, $html) === false) {
			throw new RuntimeException("could not write {$html_path}");
		}

		$pdf_path = $work_dir . '/section-' . $section_id . '.pdf';
		$factory  = self::factory();

		// The theme and format are passed, not merely the base URL. WeasyPrint
		// reads the @page rule and would manage without them, but Gotenberg
		// drives Chromium, whose page setup comes from the form fields of the
		// request: left unsaid, every book leaves in A4 portrait, landscape
		// catalogues included. Both come from ThemeRegistry, the source the
		// @page rule is built from, so CSS and form fields cannot drift apart.
		$options = $factory->makeRenderOptions(
			$book['theme'] ?? 'default',
			$book['page_format'] ?? 'a4-landscape'
		)->withFirstPageNumber($page_offset);

		// The trace identifier makes a request findable in the service log,
		// which is the only place a Gotenberg rendering leaves a mark.
		$renderer = $factory->makeRenderer('book-'.(int)$book['book_id'].'-section-'.$section_id);
		$result = $renderer->render($html_path, $pdf_path, $options);

		if (!$result->success) {
			throw new RuntimeException($result->errorMessage ?? 'rendering failed');
		}

		// RenderResult::$warnings is a string, not a list: iterating it did
		// nothing at all. And a successful render still has to be inspected —
		// WeasyPrint exits 0 with a hole in the page when an image is missing,
		// so a lost plate would otherwise only surface on the printed copy.
		if (strlen(trim($result->warnings))) {
			bookworker_error("section {$section_id}: ".trim($result->warnings));
		}

		// Everything worth telling the editor is collected rather than only
		// logged. stderr is read by whoever reads the cron mail, which is nobody
		// on the day a catalogue comes back from the printer with ten holes in
		// it; the job row is what the interface shows.
		$warnings = [];
		$label = $section['title'] !== null && strlen(trim((string)$section['title']))
			? trim((string)$section['title'])
			: _t('section %1', $section_id);

		if ($renderer instanceof WeasyPrintRenderer) {
			foreach (WeasyPrintRenderer::extractResourceErrors($result->warnings) as $missing) {
				bookworker_error("section {$section_id}: missing resource, {$missing}");
				$warnings[] = _t('“%1”: a plate could not be loaded (%2).', $label, $missing);
			}
		}
		foreach ($builder->getSkippedMessages() as $skipped) {
			bookworker_error("section {$section_id}: {$skipped}");
			$warnings[] = _t('“%1”: %2', $label, $skipped);
		}

		// A set section whose works have all gone — set deleted, set emptied, no
		// primary representation on any of them — builds an empty body, which
		// still renders as one page. That blank page is bound into the book,
		// counted in the folios and, when the section is flagged, listed in the
		// table of contents. Saying nothing is what makes it dangerous: the
		// pagination around it stays perfectly consistent.
		if ($builder->lastDocumentWasEmpty()) {
			$message = _t('“%1” produced no content and prints as a blank page.', $label);
			bookworker_error("section {$section_id}: empty document");
			$warnings[] = $message;
		}

		// null is not zero. countPages() documents the distinction: a section
		// that renders to no page is a legitimate result, an unreadable file is
		// not. Casting the failure to 0 left every following section folioed too
		// low — measured at 4 instead of 18, then 24 instead of 38 — and the job
		// still finished "done". A section whose length cannot be established
		// fails the job instead: a book that stops is recoverable, a book with
		// wrong folios reaches the printer.
		$pages = $result->pageCount;
		if ($pages === null) {
			$assembler = $factory->makeAssembler();
			$pages = $assembler->countPages($pdf_path);
			if ($pages === null) {
				throw new RuntimeException(
					"could not count the pages of section {$section_id} ({$pdf_path}): "
					.($assembler->getLastError() ?? 'unknown error')
					.'. Every following section would be folioed from a wrong count.'
				);
			}
		}

		// Each layout block is built to be one page. More pages than blocks means
		// the renderer had to break a block in two — measured on the shipped
		// six-per-page grid, whose row height fills the usable height exactly:
		// a notice of about forty words pushes the second row onto its own page,
		// and a 200-work catalogue prints on ~68 pages instead of ~34, one page
		// in two half empty. The folios stay consistent and the table of
		// contents agrees with them, so nothing else can reveal it before the
		// proof comes back.
		$expected = (int)$builder->lastDocumentPageBlocks();
		if ($expected > 0 && (int)$pages > $expected) {
			$warnings[] = _t(
				'“%1” printed on %2 pages instead of %3: its content does not fit the layout and the pages after it are shifted. Shorten the notices, or use a layout with fewer works per page.',
				$label, (int)$pages, $expected
			);
			bookworker_error("section {$section_id}: {$pages} pages for {$expected} layout blocks");
		}

		return ['path' => $pdf_path, 'pages' => (int)$pages, 'warnings' => $warnings];
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
		if ($cover = bookworker_cover_path($book['cover_pdf'] ?? '')) {
			$parts[] = $cover;
		}
		$parts = array_merge($parts, $section_pdfs);
		if ($backcover = bookworker_cover_path($book['backcover_pdf'] ?? '')) {
			$parts[] = $backcover;
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
 * Resolves a cover to a readable file, inside the covers directory only.
 *
 * The stored value is a file NAME, never a path. It used to be a free path
 * concatenated straight into the qpdf command line, so any PDF the worker could
 * read ended up bound into a book the user could then download — a complete
 * file exfiltration route for any account allowed to use the plugin.
 *
 * basename() strips any directory the value might carry, and the resolved path
 * is checked against the real covers directory, so neither ../ nor a symlink
 * planted inside it can escape.
 *
 * @return string|null the absolute path, or null when there is nothing usable
 */
function bookworker_cover_path(string $name): ?string {
	$name = trim($name);
	if ($name === '') { return null; }

	$directory = realpath(bookworker_covers_dir());
	if (!$directory) {
		bookworker_error("the covers directory does not exist; cover {$name} was ignored");
		return null;
	}

	$path = realpath($directory . '/' . basename($name));
	if (!$path || !is_file($path) || !is_readable($path)) {
		// Said out loud, because it is the shape a v1 value takes: the old
		// version stored full paths, basename() reduces them to a file name
		// that is not in this directory, and the book would otherwise be
		// assembled without its cover and no one the wiser.
		bookworker_error(
			"cover {$name} was not found in {$directory} and was ignored"
			.(strpos($name, '/') !== false ? ' (it looks like a path from the previous version: put the file in the covers directory and save the book again)' : '')
		);
		return null;
	}

	// Confinement check, after realpath() has resolved every symlink.
	if (strpos($path, $directory . DIRECTORY_SEPARATOR) !== 0) {
		bookworker_error("cover {$name} resolves outside the covers directory and was ignored");
		return null;
	}
	return $path;
}

/** Directory holding the cover PDFs, from the configuration. */
function bookworker_covers_dir(): string {
	static $directory = null;
	if ($directory !== null) { return $directory; }

	$plugin_dir = dirname(__DIR__);
	$configured = trim((string)Configuration::load($plugin_dir . '/conf/bookCreator.conf')->get('covers_dir'));

	return $directory = strlen($configured) ? $configured : $plugin_dir . '/assets/covers';
}

/**
 * True when the layout of this section generates a table of contents.
 *
 * Read from the theme manifest rather than from the layout name: a theme is
 * free to call it whatever it likes, and the type is exactly what the manifest
 * declares it for.
 */
function bookworker_is_summary_section(array $book, array $section): bool {
	static $registries = [];

	$theme = $book['theme'] ?? 'default';
	if (!isset($registries[$theme])) { $registries[$theme] = new TemplateRegistry($theme); }

	$manifest = $registries[$theme]->getTemplate($section['style']);
	return is_array($manifest) && ($manifest['section_type'] ?? '') === 'summary';
}

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
function bookworker_directories(string $plugin_dir, string $claim = ''): array {
	$config  = Configuration::load($plugin_dir . '/conf/bookCreator.conf');
	$work    = trim((string)$config->get('job_work_dir'));
	$output  = trim((string)$config->get('job_output_dir'));

	if ($work === '')   { $work = $plugin_dir . '/tmp'; }
	if ($output === '') { $output = $plugin_dir . '/tmp'; }

	// One working directory per job. The fragments used to be named after the
	// section alone, so two workers rendering the same book wrote to the very
	// same files. reapStale() makes that a real case rather than a theoretical
	// one: it requeues a job running for more than an hour without checking
	// that its worker is still alive, and a catalogue can take longer than
	// that. The requeued render then overwrote the fragments of the live one,
	// and its clean-up deleted the files the other was assembling — producing
	// either a failed assembly or, worse, a book mixing pages from two renders
	// with folios consistent throughout. The queue row was never the whole
	// race; the disk was.
	if ($claim !== '') { $work .= '/job-' . $claim; }

	foreach ([$work, $output] as $directory) {
		if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
			throw new RuntimeException("Directory {$directory} does not exist and could not be created.");
		}
		if (!is_writable($directory)) {
			throw new RuntimeException("Directory {$directory} is not writable by the worker user.");
		}
		bookworker_protect_directory($directory);
	}

	return ['work' => rtrim($work, '/'), 'output' => rtrim($output, '/')];
}

/**
 * The ca_users instance recorded on a job, or null.
 *
 * Loaded once per job and kept, since every section of a book asks for the
 * same one. Returns null when the job carries no user or the account has since
 * been deleted, which leaves the loading unchecked — the behaviour of every
 * job queued before the column existed.
 */
function bookworker_job_user(array $book) {
	static $cache = [];

	$user_id = (int)($book['bookworker_user_id'] ?? 0);
	if ($user_id <= 0) { return null; }
	if (array_key_exists($user_id, $cache)) { return $cache[$user_id]; }

	// Loaded explicitly: the worker boots setup.php, not the web front
	// controller, so the model is not guaranteed to be in scope yet.
	if (!class_exists('ca_users') && defined('__CA_MODELS_DIR__')) {
		require_once(__CA_MODELS_DIR__.'/ca_users.php');
	}
	if (!class_exists('ca_users')) {
		bookworker_error('ca_users is unavailable: the book is rendered without permission checks');
		return $cache[$user_id] = null;
	}

	$user = new ca_users($user_id);
	return $cache[$user_id] = $user->getPrimaryKey() ? $user : null;
}

/**
 * A file-name-safe form of the claim token held by this job.
 *
 * The token is unique to one claim — worker id plus random bytes, stamped on
 * the row by claimNext() — which is what makes it the right key for anything
 * written to disk: two workers on the same job get different names, so neither
 * can overwrite or delete the other's work.
 */
function bookworker_claim_slug(array $job): string {
	$token = (string)($job['worker_id'] ?? '');
	$slug  = preg_replace('~[^A-Za-z0-9_-]+~', '-', $token);
	$slug  = trim((string)$slug, '-');

	// Never empty: a job whose token could not be read still needs a directory
	// of its own rather than the root of the work area.
	return strlen($slug) ? substr($slug, 0, 96) : 'job-' . (int)($job['job_id'] ?? 0);
}

/**
 * Drops a deny-all .htaccess into a directory the worker writes to.
 *
 * The plugin ships one in tmp/, but job_work_dir and job_output_dir can point
 * anywhere, and a directory under the document root without it serves every
 * generated catalogue by its name — book-<book_id>-job-<job_id>.pdf, two small
 * integers, no authentication. Writing the file is cheap and idempotent; an
 * existing one is never overwritten, so a host that manages its own rules keeps
 * them.
 *
 * Not a substitute for putting these directories outside the document root,
 * which remains the right answer and is what bin/README.md recommends.
 */
function bookworker_protect_directory(string $directory): void {
	$htaccess = $directory . '/.htaccess';
	if (file_exists($htaccess)) { return; }

	@file_put_contents($htaccess,
		"# Written by bookCreator: generated books are served by the download\n"
		."# controller, which checks the user, never by their file name.\n"
		."<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
		."<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
	);
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

	// Keyed by the claim token, not by the job id: reapStale() requeues the
	// same job, so two workers on the same job would otherwise share a
	// directory and each delete the fragments the other is assembling.
	$directories = bookworker_directories($plugin_dir, bookworker_claim_slug($job));

	// plugin_books exposes the whole row only through its magic getter called
	// with no property name; every named access throws on a book that could
	// not be loaded, which a deleted book would trigger on the first field.
	$book = new plugin_books($job['book_id']);
	$book_row = $book->__get(null);
	if (!isset($book_row['data']) || !is_array($book_row['data']) || !$book_row['data']) {
		throw new RuntimeException("Book {$job['book_id']} does not exist any more.");
	}
	$book_data = $book_row['data'];

	// book_id is a declared property of plugin_books, so load() assigns it
	// directly and it never goes through __set() into $data. Reading it from
	// the row would yield 0, the builder would load no sections at all, and the
	// job would finish "done" on a book of blank pages without a single error.
	$book_data['book_id'] = (int)$job['book_id'];

	// Carried on the book row because that is what the render bridge receives.
	$book_data['bookworker_user_id'] = (int)($job['user_id'] ?? 0);

	$sections = $book->getSections();
	if (!$sections) {
		throw new RuntimeException("Book {$job['book_id']} has no section to render.");
	}

	$total = sizeof($sections);
	$section_pdfs = [];
	$job_warnings = [];   // surfaced on the job at the very end, see below
	$page_offset = 1;
	$done = 0;

	$jobs->updateProgress($job['job_id'], 0, _t('Rendering %1 sections', $total), $job['worker_id']);

	foreach ($sections as $section) {
		// Stop checkpoint. Between two sections nothing is half written, so the
		// job can go back to the queue and be restarted cleanly by the next
		// worker; see the signal handlers.
		if ($g_bookworker['stop_requested']) {
			throw new BookWorkerInterrupted('Stopped after ' . $done . '/' . $total . ' sections');
		}

		$rendered = BookWorkerRenderBridge::renderSection($book_data, $section, $page_offset, $directories['work']);
		$section_pdfs[] = $rendered['path'];
		foreach ($rendered['warnings'] as $warning) { $job_warnings[] = $warning; }

		// Record what this section weighs and where it starts, before moving the
		// offset on. Nothing else writes these two columns: without this the
		// generated table of contents has no folios, the cumulated page count of
		// the interface stays at zero, and a section previewed on its own cannot
		// carry the number it holds in the book.
		$book->setSection(
			(int)$section['booksection_id'],
			['pages' => (int)$rendered['pages'], 'first_page' => $page_offset, 'rendered_on' => time()],
			false   // worker columns, not a form's
		);

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

	// Second pass for generated tables of contents.
	//
	// A table of contents lists the first page of the sections that follow it,
	// and it sits at the front of the book — so on the first pass it reads
	// folios that have not been computed yet, and prints none. Re-rendering it
	// once the whole book has been laid out is the only way it can carry real
	// numbers. Only summary sections are rendered again, and their PDF replaces
	// the one produced by the first pass.
	{
		foreach ($sections as $index => $section) {
			if (!bookworker_is_summary_section($book_data, $section)) { continue; }

			// Length recorded by the FIRST pass, read back from the database —
			// not the value carried by $section, which predates this generation
			// and is null on a book that has never been rendered. Comparing
			// against that would silence the warning on the very first run,
			// which is precisely when the table of contents gains its folios
			// and is most likely to change length.
			$stored = $book->getSection((int)$section['booksection_id']);
			$before = (int)$stored['pages'];

			$rendered = BookWorkerRenderBridge::renderSection(
				$book_data,
				$section,
				(int)$stored['first_page'],
				$directories['work']
			);
			$section_pdfs[$index] = $rendered['path'];
			foreach ($rendered['warnings'] as $warning) { $job_warnings[] = $warning; }

			// Keep the recorded length in step with what was actually produced,
			// otherwise the page counts of the interface stay on the first pass.
			$book->setSection((int)$section['booksection_id'], [
				'pages'       => (int)$rendered['pages'],
				'rendered_on' => time(),
			], false);

			// A table of contents that grew or shrank between the two passes
			// shifts everything after it, so the folios it now prints are off
			// by that difference. Rare, but it has to be said rather than
			// silently produce a book whose numbering is wrong.
			if ($before && $before !== (int)$rendered['pages']) {
				$drift = (int)$rendered['pages'] - $before;
				$warning = _t(
					'The table of contents changed length (%1 to %2 pages): the page numbers after it are off by %3. Generate again to settle them.',
					$before, (int)$rendered['pages'], $drift
				);

				bookworker_error("job {$job['job_id']}: {$warning}");

				// Held until the very end rather than written now: the very next
				// updateProgress() would overwrite it, which is precisely what
				// happened to the first version of this warning.
				$job_warnings[] = $warning;
			}
		}
	}

	$jobs->updateProgress($job['job_id'], BOOKWORKER_RENDER_BUDGET, _t('Assembling the PDF'), $job['worker_id']);

	$output_path = $directories['output'] . '/book-' . (int)$job['book_id']
		. '-job-' . (int)$job['job_id'] . '-' . bookworker_claim_slug($job) . '.pdf';
	BookWorkerRenderBridge::assemble($book_data, $section_pdfs, $output_path);

	if (!is_file($output_path)) {
		throw new RuntimeException("The PDF chain reported success but {$output_path} was not written.");
	}

	// The per-section HTML and PDF have served their purpose. Left behind, they
	// accumulate one set per section per generation: a 200-page catalogue
	// regenerated weekly fills a disk with files nobody will ever open. They
	// are only removed once the book exists, so a failed job keeps everything
	// needed to understand why.
	bookworker_clean_work_files($section_pdfs, $directories['work']);

	// Older deliverables of the same book are dropped too: only the latest is
	// ever offered for download, the job carrying its path.
	bookworker_clean_previous_outputs($directories['output'], (int)$job['book_id'], $output_path, $jobs);

	// Warnings are written last, and only here. finish() sets the status, the
	// path and the progress but never touches the message, so what is written
	// now is what the editor reads next to a finished book. Written any earlier,
	// the following progress update would have wiped it.
	if ($job_warnings) {
		$jobs->updateProgress($job['job_id'], BOOKWORKER_RENDER_BUDGET, join(' ', $job_warnings), $job['worker_id']);
	}

	return $output_path;
}

/** Removes the intermediate files of a finished job. */
function bookworker_clean_work_files(array $section_pdfs, string $work_dir): void {
	foreach ($section_pdfs as $pdf) {
		if (is_file($pdf)) { @unlink($pdf); }

		$html = preg_replace('/\.pdf$/', '.html', $pdf);
		if ($html !== $pdf && is_file($html)) { @unlink($html); }
	}

	// The per-job directory goes too, but only if this job left nothing else in
	// it: rmdir() on a non-empty directory fails and is meant to. A directory
	// belonging to another job is never touched, since each one has its own.
	if (preg_match('~/job-\d+$~', $work_dir) && is_dir($work_dir)) { @rmdir($work_dir); }
}

/** Removes the previous PDFs of a book, keeping the one just produced. */
function bookworker_clean_previous_outputs(string $output_dir, int $book_id, string $keep, ?BookJobModel $jobs = null): void {
	$removed = [];
	foreach (glob($output_dir . '/book-' . $book_id . '-job-*.pdf') ?: [] as $previous) {
		if ($previous !== $keep && is_file($previous) && @unlink($previous)) { $removed[] = $previous; }
	}

	// The rows of those jobs keep their status and their pdf_path, so anything
	// reaching an earlier job — a replay, a history screen, --job=N — found a
	// job marked done pointing at a file that no longer exists, and answered
	// "The generated file is no longer available." with no way to tell why.
	// Forgetting the path is what makes the row honest: the job did run, its
	// file has been superseded.
	if ($jobs && $removed) { $jobs->forgetOutputs($removed); }
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
	$plugin_dir . '/lib/TemplateRegistry.php',
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
	bookworker_log("job {$job['job_id']}: claimed (book {$job['book_id']})");

	try {
		$pdf_path = bookworker_process_job($job, $jobs, $plugin_dir);
		$closed = $jobs->finish((int)$job['job_id'], $pdf_path, $job['worker_id']);
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
		$jobs->fail((int)$job['job_id'], $e->getMessage(), $job['worker_id']);
		$g_bookworker['current_job_id'] = 0;
		bookworker_error("job {$job['job_id']} failed: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
		$exit_code = BOOKWORKER_EXIT_RUNTIME;
	}

	if ($opts['job'] > 0 || $opts['once']) { break; }
}

bookworker_release_current_job();
bookworker_log("worker {$worker_id} stopped (exit {$exit_code})");
exit($exit_code);
