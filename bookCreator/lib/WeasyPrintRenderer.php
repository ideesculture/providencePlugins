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

require_once(__DIR__.'/PdfRendererInterface.php');
require_once(__DIR__.'/PdfAssembler.php');

/**
 * Default driver: the WeasyPrint command line, run locally.
 *
 * Chosen because it implements CSS Paged Media natively — page counters,
 * running heads through string-set, bleed and crop marks — which is what allows
 * the chain to drop its post-processing steps, and because it installs with a
 * single pip install on an ordinary server, with no container to operate.
 *
 * Three things this class is careful about:
 *
 *  - stderr is not failure. WeasyPrint reports substituted fonts and ignored
 *    properties there while exiting 0 with a good PDF. Warnings are captured
 *    and carried in the result; only the exit code and the produced file decide
 *    success.
 *  - every argument goes through escapeshellarg(), the binary included. Paths
 *    come from the database and from the media catalogue.
 *  - one render at a time. Nothing here forks or batches: keeping a single
 *    WeasyPrint process per worker is what bounds the memory peak, measured at
 *    roughly 65 MB in section by section mode against 161 MB for a 220 page
 *    catalogue rendered in one go.
 *
 * Page geometry is not passed on the command line — WeasyPrint has no such
 * options and reads it from the @page rule the theme emits. The geometry in
 * RenderOptions is there for the other driver and for the HTML generator.
 */
final class WeasyPrintRenderer implements PdfRendererInterface {

	/**
	 * @param string        $binary        weasyprint executable: a bare name looked up in PATH,
	 *                                     or an absolute path — a virtualenv install is common,
	 *                                     and its binary is not on the web server's PATH.
	 * @param PdfAssembler|null $assembler Optional, used only to read back the page count of
	 *                                     the produced file. Injected rather than built here so
	 *                                     the renderer stays testable without qpdf; when it is
	 *                                     missing, results simply carry a null page count.
	 * @param array|null    $env           Environment for the child process, null to inherit.
	 *                                     A virtualenv install may need PATH or FONTCONFIG_PATH
	 *                                     set explicitly under php-fpm.
	 * @param array         $extraArgs     Already-escaped extra arguments, appended last. An
	 *                                     escape hatch for a per-installation need — a
	 *                                     --pdf-variant for a printer, say — without touching
	 *                                     this class.
	 */
	public function __construct(
		private readonly string $binary = 'weasyprint',
		private readonly ?PdfAssembler $assembler = null,
		private readonly ?array $env = null,
		private readonly array $extraArgs = [],
	) {}

	public function getName(): string { return 'weasyprint'; }

	# -------------------------------------------------------
	# Availability
	# -------------------------------------------------------

	public function isAvailable(): bool {
		return $this->getUnavailableReason() === null;
	}

	public function getUnavailableReason(): ?string {
		if (ProcessRunner::locate($this->binary) !== null) { return null; }

		return 'WeasyPrint not found (looked for "'.$this->binary.'"). Install it with '
			.'"pip install weasyprint" — or the distribution package, apt install weasyprint — '
			.'and set the absolute path of the binary in the plugin configuration when it lives '
			.'in a virtualenv the web server does not have on its PATH.';
	}


	# -------------------------------------------------------
	# Rendering
	# -------------------------------------------------------

	public function render(string $htmlPath, string $pdfPath, RenderOptions $opts): RenderResult {
		$started = microtime(true);

		if (($reason = $this->getUnavailableReason()) !== null) {
			return RenderResult::failure($reason, microtime(true) - $started, renderer: $this->getName());
		}
		if (!is_file($htmlPath) || !is_readable($htmlPath)) {
			return RenderResult::failure(
				'cannot read the HTML to render: '.$htmlPath,
				microtime(true) - $started, renderer: $this->getName()
			);
		}
		if (($reason = $this->checkWritable($pdfPath)) !== null) {
			return RenderResult::failure($reason, microtime(true) - $started, renderer: $this->getName());
		}
		if (($reason = $this->prepareCacheFolder($opts)) !== null) {
			return RenderResult::failure($reason, microtime(true) - $started, renderer: $this->getName());
		}

		$outcome = ProcessRunner::run(
			$this->buildCommand($htmlPath, $pdfPath, $opts),
			max(1, $opts->timeoutSeconds),
			dirname($htmlPath),      // relative assets resolve the same way as with --base-url
			$this->env
		);

		$warnings = trim($outcome->stderr);
		$duration = microtime(true) - $started;

		if ($outcome->timedOut) {
			// The file, if any, is a truncated render: do not leave it around for
			// the assembler to pick up.
			@unlink($pdfPath);
			return RenderResult::failure(
				'WeasyPrint timed out after '.$opts->timeoutSeconds.'s on '.basename($htmlPath)
				.'. Raise the timeout, or render the book section by section.',
				$duration, warnings: $warnings, renderer: $this->getName()
			);
		}
		if ($outcome->startError !== null) {
			return RenderResult::failure($outcome->startError, $duration, warnings: $warnings, renderer: $this->getName());
		}
		if ($outcome->exitCode !== 0) {
			$detail = $outcome->lastStderrLine();
			@unlink($pdfPath);
			return RenderResult::failure(
				'WeasyPrint failed (exit '.$outcome->exitCode.') on '.basename($htmlPath)
				.($detail === '' ? '.' : ': '.$detail),
				$duration, warnings: $warnings, renderer: $this->getName()
			);
		}
		if (!is_file($pdfPath) || filesize($pdfPath) === 0) {
			return RenderResult::failure(
				'WeasyPrint exited cleanly but wrote no PDF at '.$pdfPath.'.',
				$duration, warnings: $warnings, renderer: $this->getName()
			);
		}

		// Exit code 0 with a file on disk is success, however much was said on
		// stderr. The warnings travel with the result for the job log.
		return RenderResult::success(
			$pdfPath,
			$duration,
			$this->assembler?->countPages($pdfPath),
			$warnings,
			$this->getName()
		);
	}

	/**
	 * The command line, fully escaped.
	 *
	 * Public so an installation check, or a test, can show exactly what will be
	 * run without running it.
	 */
	public function buildCommand(string $htmlPath, string $pdfPath, RenderOptions $opts): string {
		$binary = ProcessRunner::locate($this->binary) ?? $this->binary;

		$args = [escapeshellarg($binary)];

		// Base for relative URLs. Without it, images and fonts referenced
		// relatively resolve against the current directory rather than against
		// the document, which is the classic silent way of losing every image.
		$baseUrl = $opts->baseUrl ?? (dirname($htmlPath).DIRECTORY_SEPARATOR);
		$args[] = '--base-url';
		$args[] = escapeshellarg($baseUrl);

		if ($opts->mediaType !== null && $opts->mediaType !== '') {
			$args[] = '--media-type';
			$args[] = escapeshellarg($opts->mediaType);
		}

		if ($opts->optimizeImages) {
			// Lossless recompression of embedded images: smaller catalogue, no
			// visible cost on a print job.
			$args[] = '--optimize-images';
		}
		if ($opts->jpegQuality !== null) {
			$args[] = '--jpeg-quality';
			$args[] = escapeshellarg((string)max(0, min(95, $opts->jpegQuality)));
		}
		if ($opts->imageDpi !== null && $opts->imageDpi > 0) {
			$args[] = '--dpi';
			$args[] = escapeshellarg((string)$opts->imageDpi);
		}

		// Keeps the image cache on disk instead of in memory. This is the option
		// that keeps a heavily illustrated catalogue inside a bounded pod.
		if ($opts->imageCacheDir !== null && $opts->imageCacheDir !== '') {
			$args[] = '--cache-folder';
			$args[] = escapeshellarg($opts->imageCacheDir);
		}

		// WeasyPrint's own --timeout only bounds HTTP fetches; the wall clock
		// budget of the whole render is enforced by ProcessRunner. Both are set:
		// this one stops a slow remote asset from eating the entire budget.
		$args[] = '--timeout';
		$args[] = escapeshellarg((string)max(1, (int)ceil($opts->timeoutSeconds / 4)));

		foreach ($this->extraArgs as $extra) {
			if (is_string($extra) && $extra !== '') { $args[] = $extra; }
		}

		$args[] = escapeshellarg($htmlPath);
		$args[] = escapeshellarg($pdfPath);

		return join(' ', $args);
	}

	/**
	 * Resources WeasyPrint could not load, read back from the warnings of a
	 * successful render.
	 *
	 * This exists because of a trap worth naming: a missing image makes
	 * WeasyPrint print a line starting with ERROR on stderr and carry on, exit
	 * code 0, PDF produced — with a hole where the plate was. On a 200 page
	 * catalogue nobody notices before the proof comes back from the printer. The
	 * worker is expected to run this over the warnings of every successful render
	 * and put whatever it finds in the job message.
	 *
	 * @return string[] URLs, in the order they were reported.
	 */
	public static function extractResourceErrors(string $warnings): array {
		if (trim($warnings) === '') { return []; }

		$urls = [];
		if (preg_match_all("/Failed to load [a-z ]*at '([^']+)'/i", $warnings, $matches)) {
			foreach ($matches[1] as $url) { $urls[] = $url; }
		}

		// A declaration WeasyPrint refuses to parse is a hole in the page just
		// the same, and it is reported as a WARNING rather than an ERROR: an
		// unreadable background-image leaves the plate blank while the renderer
		// exits 0. Only the image properties are matched — an ignored margin is
		// a cosmetic loss, an ignored plate is a missing work.
		if (preg_match_all('/Ignored `(background(?:-image)?|src|list-style-image)\s*:\s*([^`]*)`/i', $warnings, $matches)) {
			foreach ($matches[2] as $declaration) {
				$urls[] = trim($declaration);
			}
		}

		return array_values(array_unique($urls));
	}

	# -------------------------------------------------------

	/** Creates the image cache folder when asked for one. */
	private function prepareCacheFolder(RenderOptions $opts): ?string {
		$dir = $opts->imageCacheDir;
		if ($dir === null || $dir === '') { return null; }

		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			return 'cannot create the image cache folder: '.$dir;
		}
		if (!is_writable($dir)) {
			return 'image cache folder is not writable: '.$dir;
		}
		return null;
	}

	/** Checks the output can be written before spending time on the render. */
	private function checkWritable(string $pdfPath): ?string {
		if ($pdfPath === '') { return 'no output path given for the PDF.'; }

		$dir = dirname($pdfPath);
		if (!is_dir($dir)) { return 'output directory does not exist: '.$dir; }
		if (!is_writable($dir)) { return 'output directory is not writable: '.$dir; }
		if (is_file($pdfPath) && !is_writable($pdfPath)) {
			return 'output file exists and is not writable: '.$pdfPath;
		}
		return null;
	}
}
