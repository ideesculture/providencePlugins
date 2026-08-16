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

require_once(__DIR__.'/RenderOptions.php');

/**
 * Contracts of the HTML to PDF chain: the driver interface, the result it
 * returns, and the process runner its command line implementations share.
 *
 * One interface, two drivers. WeasyPrintRenderer runs a local binary and is the
 * default, installable on a plain Providence server. GotenbergRenderer posts to
 * an internal Chromium service and is the option for container deployments,
 * where rendering leaves the application pod. Both eat the same HTML and CSS,
 * which is the whole point of writing the themes in standard CSS Paged Media.
 *
 * Nothing in this file knows about CollectiveAccess.
 */
interface PdfRendererInterface {

	/**
	 * Renders one HTML file, with its assets, to one PDF file.
	 *
	 * Implementations never throw for an expected failure: a missing binary, an
	 * unreachable service, a timeout, a non-zero exit or an unreadable output
	 * all come back as a failed RenderResult carrying its reason. The caller
	 * decides whether that fails the job, retries, or falls back to the other
	 * driver. Only programming errors — an argument of the wrong type — surface
	 * as exceptions, and those are the engine's own.
	 *
	 * @param string        $htmlPath Absolute path of the HTML file to render.
	 * @param string        $pdfPath  Absolute path to write. Its directory must exist and be
	 *                                writable; an existing file is overwritten.
	 * @param RenderOptions $opts
	 */
	public function render(string $htmlPath, string $pdfPath, RenderOptions $opts): RenderResult;

	/**
	 * Whether this driver can run at all right now: binary present and
	 * executable, or service answering. Meant for the install check and for the
	 * worker's startup log, not to be called before each render.
	 */
	public function isAvailable(): bool;

	/**
	 * Why the driver is unavailable, in a message an administrator can act on —
	 * it names what to install or what to configure. null when available.
	 */
	public function getUnavailableReason(): ?string;

	/** Short driver name, for logs and job messages: "weasyprint", "gotenberg". */
	public function getName(): string;
}

/**
 * Outcome of one render.
 *
 * Immutable, and deliberately dumb: it reports, it does not decide. Page count
 * is optional because no renderer knows it — it is read back from the produced
 * file by PdfAssembler, which is why success results can be completed after the
 * fact with withPageCount().
 *
 * Warnings are kept apart from errors on purpose. WeasyPrint writes plenty on
 * stderr — a font it substituted, a property it ignored — while exiting 0 and
 * producing a perfectly good PDF. Treating that output as failure is the classic
 * mistake with this tool, so a successful result carries its warnings and the
 * caller logs them.
 */
final class RenderResult {

	private function __construct(
		public readonly bool $success,
		public readonly string $pdfPath,
		public readonly ?int $pageCount,
		public readonly ?string $errorMessage,
		public readonly float $durationSeconds,
		public readonly string $warnings,
		public readonly string $renderer,
	) {}

	/**
	 * @param string $warnings Anything the engine said while succeeding, verbatim.
	 */
	public static function success(
		string $pdfPath,
		float $durationSeconds,
		?int $pageCount = null,
		string $warnings = '',
		string $renderer = ''
	): self {
		return new self(true, $pdfPath, $pageCount, null, $durationSeconds, $warnings, $renderer);
	}

	/**
	 * @param string $errorMessage Why it failed, in terms of what to do about it.
	 */
	public static function failure(
		string $errorMessage,
		float $durationSeconds = 0.0,
		string $pdfPath = '',
		string $warnings = '',
		string $renderer = ''
	): self {
		return new self(false, $pdfPath, null, $errorMessage, $durationSeconds, $warnings, $renderer);
	}

	/** Same result, with the page count filled in once it has been read. */
	public function withPageCount(?int $pageCount): self {
		return new self(
			$this->success, $this->pdfPath, $pageCount, $this->errorMessage,
			$this->durationSeconds, $this->warnings, $this->renderer
		);
	}

	/** True when the engine had something to say although it succeeded. */
	public function hasWarnings(): bool {
		return $this->success && trim($this->warnings) !== '';
	}

	/** One line for a log or a job message. */
	public function __toString(): string {
		$prefix = ($this->renderer !== '' ? $this->renderer.': ' : '');
		if (!$this->success) {
			return $prefix.'failed after '.round($this->durationSeconds, 2).'s: '.(string)$this->errorMessage;
		}
		return $prefix.'rendered '
			.($this->pageCount === null ? '' : $this->pageCount.' page(s) ')
			.'in '.round($this->durationSeconds, 2).'s to '.$this->pdfPath;
	}
}

/**
 * What a finished child process left behind.
 */
final class ProcessOutcome {

	public function __construct(
		public readonly int $exitCode,
		public readonly string $stdout,
		public readonly string $stderr,
		public readonly float $durationSeconds,
		public readonly bool $timedOut,
		public readonly ?string $startError = null,
	) {}

	/** True when the process ran to completion, whatever its exit code. */
	public function ran(): bool {
		return $this->startError === null && !$this->timedOut;
	}

	/**
	 * The most useful line of stderr for an error message. Engines print a stack
	 * of warnings then the real reason last, so the tail is what matters.
	 */
	public function lastStderrLine(): string {
		$lines = preg_split('/\R/', trim($this->stderr)) ?: [];
		for ($i = count($lines) - 1; $i >= 0; $i--) {
			if (trim($lines[$i]) !== '') { return trim($lines[$i]); }
		}
		return '';
	}
}

/**
 * Runs one external command under a wall clock budget, capturing stdout and
 * stderr separately.
 *
 * Shared by every command line piece of the chain — the WeasyPrint driver and
 * the qpdf assembler — because they need exactly the same three things, and
 * getting any of them wrong is how these integrations rot:
 *
 *  - separate streams: stderr is not failure here, it is the warning channel;
 *  - a timeout that actually kills, so a stuck render cannot pin a worker;
 *  - no shell interpretation of arguments, since paths come from the database
 *    and from a catalogue. Callers build their command line with
 *    escapeshellarg() on every single argument, including the binary.
 *
 * The command is still handed to a shell, so it is prefixed with exec: the
 * shell replaces itself with the binary and the pid we hold is the process we
 * must kill on timeout, not a parent that would leave an orphan behind.
 */
final class ProcessRunner {

	/** Seconds given to a timed out process to die on SIGTERM before SIGKILL. */
	private const GRACE_SECONDS = 3;

	/** Read chunk. Large enough that a chatty engine does not cost us a syscall per line. */
	private const CHUNK_BYTES = 65536;

	/**
	 * @param string      $command      Fully escaped command line.
	 * @param int         $timeout      Wall clock budget in seconds. 0 or less means no limit,
	 *                                  which no caller in this plugin should want.
	 * @param string|null $cwd          Working directory of the child, null to inherit.
	 * @param array|null  $env          Environment of the child, null to inherit.
	 * @param int         $maxOutputBytes Cap on each captured stream, so a runaway engine
	 *                                  cannot fill memory with warnings.
	 */
	public static function run(
		string $command,
		int $timeout = 300,
		?string $cwd = null,
		?array $env = null,
		int $maxOutputBytes = 1048576
	): ProcessOutcome {
		$started = microtime(true);

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		// exec so that the pid below is the engine itself (see class comment).
		$process = @proc_open('exec '.$command, $descriptors, $pipes, $cwd, $env);
		if (!is_resource($process)) {
			return new ProcessOutcome(
				exitCode: -1,
				stdout: '',
				stderr: '',
				durationSeconds: microtime(true) - $started,
				timedOut: false,
				startError: 'could not start process: '.$command,
			);
		}

		fclose($pipes[0]);   // nothing to feed in: input is a file path, not stdin
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout = '';
		$stderr = '';
		$timedOut = false;
		$deadline = ($timeout > 0) ? $started + $timeout : null;

		$open = [1 => $pipes[1], 2 => $pipes[2]];
		while ($open) {
			$read   = array_values($open);
			$write  = null;
			$except = null;

			// Short select so the deadline is checked even on a silent process.
			if (@stream_select($read, $write, $except, 0, 200000) === false) { break; }

			foreach ($read as $stream) {
				$chunk = fread($stream, self::CHUNK_BYTES);
				if ($chunk === false || ($chunk === '' && feof($stream))) {
					$key = array_search($stream, $open, true);
					if ($key !== false) {
						fclose($stream);
						unset($open[$key]);
					}
					continue;
				}
				if ($chunk === '') { continue; }

				if ($stream === $pipes[1]) {
					if (strlen($stdout) < $maxOutputBytes) { $stdout .= $chunk; }
				} else {
					if (strlen($stderr) < $maxOutputBytes) { $stderr .= $chunk; }
				}
			}

			if ($deadline !== null && microtime(true) > $deadline) {
				$timedOut = true;
				self::kill($process);
				break;
			}
		}

		foreach ($open as $stream) { fclose($stream); }

		$exitCode = proc_close($process);

		return new ProcessOutcome(
			exitCode: $timedOut ? -1 : $exitCode,
			stdout: $stdout,
			stderr: $stderr,
			durationSeconds: microtime(true) - $started,
			timedOut: $timedOut,
		);
	}

	/**
	 * Whether a binary can be run: an explicit path must be an executable file,
	 * a bare name is looked up in PATH ourselves rather than through which(1),
	 * which would be one more process and one more shell.
	 *
	 * @return string|null Absolute path when found, null otherwise.
	 */
	public static function locate(string $binary): ?string {
		if ($binary === '') { return null; }

		if (str_contains($binary, DIRECTORY_SEPARATOR)) {
			return (is_file($binary) && is_executable($binary)) ? $binary : null;
		}

		$path = (string)getenv('PATH');
		if ($path === '') { $path = '/usr/local/bin:/usr/bin:/bin'; }

		foreach (explode(PATH_SEPARATOR, $path) as $dir) {
			if ($dir === '') { continue; }
			$candidate = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$binary;
			if (is_file($candidate) && is_executable($candidate)) { return $candidate; }
		}
		return null;
	}

	/** SIGTERM, a short grace period, then SIGKILL. */
	private static function kill($process): void {
		@proc_terminate($process, 15);

		$until = microtime(true) + self::GRACE_SECONDS;
		while (microtime(true) < $until) {
			$status = @proc_get_status($process);
			if (!is_array($status) || !$status['running']) { return; }
			usleep(100000);
		}
		@proc_terminate($process, 9);
	}
}
