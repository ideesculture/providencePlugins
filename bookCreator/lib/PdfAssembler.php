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

/**
 * Assembly of the book, on top of qpdf.
 *
 * qpdf replaces three tools at once: pdftk for concatenation, pdfinfo for
 * counting pages, and cpdf for page numbers — that last one only because
 * numbering is now done in CSS by the renderer, so no post-processing of the
 * content survives here. What is left is two operations on files:
 *
 *   qpdf --empty --pages cover.pdf s01.pdf ... -- book.pdf
 *   qpdf --show-npages section.pdf
 *
 * Page counting is what makes section by section rendering work: the worker
 * renders a section, asks how many pages it produced, and numbers the next one
 * from the running total.
 *
 * The class holds no CollectiveAccess dependency: it takes its binary path and
 * timeout from its constructor and returns values, so it can be tested against
 * any pair of PDF files.
 */
final class PdfAssembler {

	/**
	 * qpdf exit codes. 3 is the one that matters: the operation succeeded and
	 * the output file is written, but qpdf found something to complain about in
	 * an input — a malformed cross-reference table it repaired, usually. Reading
	 * it as failure would reject perfectly usable books.
	 */
	private const EXIT_OK = 0;
	private const EXIT_WARNINGS = 3;

	/** @var string|null reason the last operation failed, null when it succeeded */
	private ?string $lastError = null;

	/** @var string anything qpdf complained about during the last operation */
	private string $lastWarnings = '';

	/**
	 * @param string $binary  qpdf executable: a bare name looked up in PATH, or an absolute
	 *                        path when the installation puts it somewhere unusual.
	 * @param int    $timeout Wall clock budget per qpdf call, in seconds. Assembly of a 200
	 *                        page catalogue is a fraction of a second, so this only ever
	 *                        catches a pathological case.
	 */
	public function __construct(
		private readonly string $binary = 'qpdf',
		private readonly int $timeout = 120,
	) {}

	# -------------------------------------------------------
	# Availability
	# -------------------------------------------------------

	/** Whether qpdf is installed and executable. */
	public function isAvailable(): bool {
		return $this->getUnavailableReason() === null;
	}

	/**
	 * Why qpdf cannot be used, naming the package to install. null when fine.
	 */
	public function getUnavailableReason(): ?string {
		if (ProcessRunner::locate($this->binary) !== null) { return null; }

		return 'qpdf not found (looked for "'.$this->binary.'"). Install it with the system '
			.'package manager — apt install qpdf, dnf install qpdf, brew install qpdf — or set '
			.'its absolute path in the plugin configuration.';
	}

	/** Version string reported by qpdf, null when it cannot be run. */
	public function getVersion(): ?string {
		$binary = ProcessRunner::locate($this->binary);
		if ($binary === null) { return null; }

		$outcome = ProcessRunner::run(escapeshellarg($binary).' --version', 15);
		if (!$outcome->ran()) { return null; }

		$firstLine = strtok(trim($outcome->stdout), "\n");
		return $firstLine === false ? null : $firstLine;
	}

	# -------------------------------------------------------
	# Operations
	# -------------------------------------------------------

	/**
	 * Concatenates PDF files, in the given order, into one.
	 *
	 * Order is the caller's: cover, sections in sort order, back cover. Nothing
	 * is inferred here.
	 *
	 * @param string[] $pdfPaths   Absolute paths, at least one, each readable.
	 * @param string   $outputPath Absolute path to write; overwritten if it exists, and left
	 *                             untouched when the operation fails.
	 * @return bool True on success. On false, getLastError() says why.
	 */
	public function concat(array $pdfPaths, string $outputPath): bool {
		$this->reset();

		if (!$pdfPaths) {
			return $this->fail('nothing to concatenate: the list of PDF files is empty.');
		}
		if (($reason = $this->getUnavailableReason()) !== null) {
			return $this->fail($reason);
		}
		foreach ($pdfPaths as $path) {
			if (!is_string($path) || $path === '') {
				return $this->fail('invalid PDF path in the list to concatenate.');
			}
			if (!is_file($path) || !is_readable($path)) {
				return $this->fail('cannot read PDF to concatenate: '.$path);
			}
		}
		if (($reason = $this->checkWritable($outputPath)) !== null) {
			return $this->fail($reason);
		}

		// qpdf --empty --pages a.pdf b.pdf ... -- out.pdf
		// Every argument is escaped, paths included: they come from the database
		// and from the media catalogue, and are never assumed to be tame.
		$binary = (string)ProcessRunner::locate($this->binary);
		$parts = [escapeshellarg($binary), '--empty', '--pages'];
		foreach ($pdfPaths as $path) { $parts[] = escapeshellarg($path); }
		$parts[] = '--';
		$parts[] = escapeshellarg($outputPath);

		$outcome = ProcessRunner::run(join(' ', $parts), $this->timeout);
		$this->lastWarnings = trim($outcome->stderr);

		if ($outcome->timedOut) {
			return $this->fail('qpdf timed out after '.$this->timeout.'s while concatenating '
				.count($pdfPaths).' files.');
		}
		if ($outcome->startError !== null) {
			return $this->fail($outcome->startError);
		}
		if ($outcome->exitCode !== self::EXIT_OK && $outcome->exitCode !== self::EXIT_WARNINGS) {
			$detail = $outcome->lastStderrLine();
			return $this->fail('qpdf failed (exit '.$outcome->exitCode.')'
				.($detail === '' ? '.' : ': '.$detail));
		}
		if (!is_file($outputPath) || filesize($outputPath) === 0) {
			return $this->fail('qpdf reported success but produced no file at '.$outputPath.'.');
		}

		return true;
	}

	/**
	 * Number of pages of a PDF.
	 *
	 * @return int|null null when the file cannot be read or qpdf cannot be run;
	 *                  getLastError() says which. Callers must keep null apart
	 *                  from 0: a section that renders to no page at all is a
	 *                  legitimate result, an unreadable file is not.
	 */
	public function countPages(string $pdfPath): ?int {
		$this->reset();

		if (($reason = $this->getUnavailableReason()) !== null) {
			$this->fail($reason);
			return null;
		}
		if (!is_file($pdfPath) || !is_readable($pdfPath)) {
			$this->fail('cannot read PDF to count pages: '.$pdfPath);
			return null;
		}

		$binary = (string)ProcessRunner::locate($this->binary);
		$command = escapeshellarg($binary).' --show-npages '.escapeshellarg($pdfPath);

		$outcome = ProcessRunner::run($command, $this->timeout);
		$this->lastWarnings = trim($outcome->stderr);

		if ($outcome->timedOut) {
			$this->fail('qpdf timed out after '.$this->timeout.'s while counting pages of '.$pdfPath.'.');
			return null;
		}
		if ($outcome->startError !== null) {
			$this->fail($outcome->startError);
			return null;
		}
		if ($outcome->exitCode !== self::EXIT_OK && $outcome->exitCode !== self::EXIT_WARNINGS) {
			$detail = $outcome->lastStderrLine();
			$this->fail('qpdf could not read '.$pdfPath.' (exit '.$outcome->exitCode.')'
				.($detail === '' ? '.' : ': '.$detail));
			return null;
		}

		$value = trim($outcome->stdout);
		if (!preg_match('/^[0-9]+$/', $value)) {
			$this->fail('unexpected qpdf --show-npages output for '.$pdfPath.': "'.$value.'".');
			return null;
		}

		return (int)$value;
	}

	# -------------------------------------------------------
	# Last operation
	# -------------------------------------------------------

	/** Why the last operation failed, null when it succeeded. */
	public function getLastError(): ?string {
		return $this->lastError;
	}


	# -------------------------------------------------------

	private function reset(): void {
		$this->lastError = null;
		$this->lastWarnings = '';
	}

	/** Records the reason and returns false, so callers can `return $this->fail(...)`. */
	private function fail(string $message): bool {
		$this->lastError = $message;
		return false;
	}

	/** Checks the output can be written before spending time on the render. */
	private function checkWritable(string $outputPath): ?string {
		if ($outputPath === '') { return 'no output path given.'; }

		$dir = dirname($outputPath);
		if (!is_dir($dir)) { return 'output directory does not exist: '.$dir; }
		if (!is_writable($dir)) { return 'output directory is not writable: '.$dir; }
		if (is_file($outputPath) && !is_writable($outputPath)) {
			return 'output file exists and is not writable: '.$outputPath;
		}
		return null;
	}
}
