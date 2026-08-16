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
 * Optional driver: a Gotenberg 8 service, rendering with Chromium.
 *
 * The point of this driver is where the work happens, not what it produces:
 * rendering leaves the Providence pod entirely for a service sized for it. It
 * is also the answer when an installation needs the PDF to come from the same
 * engine as the browser preview, Chromium against Chromium.
 *
 * The exchange is one multipart POST to /forms/chromium/convert/html. Gotenberg
 * requires the document itself to be named index.html whatever it is called on
 * disk; every other file of the multipart body becomes a sibling of it in the
 * service's working directory, so relative URLs in the HTML keep resolving.
 * That is why assets travel with the request here while the local driver simply
 * reads them from disk.
 *
 * The service is internal and never exposed: there is no authentication to
 * carry. The configured URL is validated all the same — a typo there would
 * otherwise turn into a POST to whatever host does answer.
 *
 * Chromium reads far less of CSS Paged Media than WeasyPrint does. Page
 * geometry is therefore sent as form fields as well as left in the CSS, and
 * page numbering restarted with counter-reset, which WeasyPrint honours, is not
 * reliable here. The book rendered through this driver is the same book, not
 * the same pagination — which is why acceptance is run per theme on both.
 */
final class GotenbergRenderer implements PdfRendererInterface {

	/** Route of the Chromium HTML conversion. */
	private const ROUTE_HTML = '/forms/chromium/convert/html';

	/** Route answering the service health, used by isAvailable(). */
	private const ROUTE_HEALTH = '/health';

	/** Name Gotenberg expects for the main document. */
	private const INDEX_NAME = 'index.html';

	/** Files that may travel as assets, by extension. */
	private const ASSET_EXTENSIONS = [
		'css', 'js',
		'woff2', 'woff', 'otf', 'ttf', 'eot',
		'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'avif',
	];

	/** Ceilings on automatic asset collection, to keep a stray directory out of the request. */
	private const MAX_ASSET_FILES = 2000;
	private const MAX_ASSET_BYTES = 536870912;   // 512 MB

	/** @var string service base URL, without trailing slash */
	private readonly string $baseUrl;

	/** @var string|null why the configured URL is unusable, null when it is fine */
	private readonly ?string $urlError;

	/**
	 * @param string       $baseUrl           Base URL of the service, "http://gotenberg:3000".
	 * @param PdfAssembler|null $assembler    Optional, only to read back the page count of the
	 *                                        produced file.
	 * @param bool         $preferCssPageSize Lets the @page rule of the theme drive the page
	 *                                        size, form fields serving as fallback. Left on:
	 *                                        the themes carry their geometry in CSS.
	 * @param bool         $printBackground   Prints background colours and images. Off by
	 *                                        default in Chromium, which loses every coloured
	 *                                        block of a layout.
	 * @param string|null  $traceIdPrefix     Prefix of the Gotenberg-Trace header, which ties a
	 *                                        request to its lines in the service log. Worth
	 *                                        setting to the book or job identifier.
	 * @param array        $extraFormFields   Additional Gotenberg form fields, as name => value.
	 *                                        Escape hatch for a service option this class does
	 *                                        not expose.
	 */
	public function __construct(
		string $baseUrl,
		private readonly ?PdfAssembler $assembler = null,
		private readonly bool $preferCssPageSize = true,
		private readonly bool $printBackground = true,
		private readonly ?string $traceIdPrefix = null,
		private readonly array $extraFormFields = [],
	) {
		$this->urlError = self::validateUrl($baseUrl);
		$this->baseUrl  = rtrim(trim($baseUrl), '/');
	}

	public function getName(): string { return 'gotenberg'; }

	/** Configured base URL, normalised. */
	public function getBaseUrl(): string { return $this->baseUrl; }

	# -------------------------------------------------------
	# Configuration and availability
	# -------------------------------------------------------

	/**
	 * Checks a configured Gotenberg URL without contacting anything.
	 *
	 * @return string|null Why it is unusable, null when it is well formed.
	 */
	public static function validateUrl(string $url): ?string {
		$url = trim($url);
		if ($url === '') {
			return 'no Gotenberg URL configured. Set gotenberg_url to the internal address of '
				.'the service, for instance http://gotenberg:3000.';
		}

		$parts = parse_url($url);
		if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
			return 'malformed Gotenberg URL: "'.$url.'". Expected a full URL with scheme and '
				.'host, for instance http://gotenberg:3000.';
		}
		$scheme = strtolower($parts['scheme']);
		if ($scheme !== 'http' && $scheme !== 'https') {
			return 'unsupported scheme "'.$scheme.'" in the Gotenberg URL. Use http or https.';
		}
		if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
			return 'invalid port in the Gotenberg URL: '.$parts['port'].'.';
		}
		if (isset($parts['query']) || isset($parts['fragment'])) {
			return 'the Gotenberg URL must be a base address, without query string or fragment.';
		}
		return null;
	}

	/**
	 * Whether the service answers. Unlike the local driver, this one costs a
	 * network round trip, so it belongs to the install check and the worker
	 * startup, not to each render.
	 */
	public function isAvailable(): bool {
		return $this->getUnavailableReason() === null;
	}

	public function getUnavailableReason(): ?string {
		if (($reason = $this->configurationError()) !== null) { return $reason; }

		$handle = curl_init($this->baseUrl.self::ROUTE_HEALTH);
		if ($handle === false) { return 'could not initialise the HTTP client.'; }

		curl_setopt_array($handle, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 10,
			CURLOPT_FOLLOWLOCATION => false,
		]);
		$body   = curl_exec($handle);
		$status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		$error  = curl_error($handle);
		unset($handle);   // curl_close() is a no-op since PHP 8.0 and deprecated in 8.5

		if ($body === false) {
			return 'Gotenberg is unreachable at '.$this->baseUrl.': '.$error
				.'. Check that the service is running and that the URL is the internal one.';
		}
		if ($status !== 200) {
			return 'Gotenberg answered HTTP '.$status.' on '.self::ROUTE_HEALTH
				.'; the service is up but not healthy.';
		}
		return null;
	}

	/** Configuration problems, checkable without any network access. */
	private function configurationError(): ?string {
		if ($this->urlError !== null) { return $this->urlError; }
		if (!function_exists('curl_init')) {
			return 'the PHP cURL extension is required by the Gotenberg driver but is not '
				.'loaded. Install php-curl, or use the default WeasyPrint driver.';
		}
		return null;
	}

	# -------------------------------------------------------
	# Rendering
	# -------------------------------------------------------

	public function render(string $htmlPath, string $pdfPath, RenderOptions $opts): RenderResult {
		$started = microtime(true);

		if (($reason = $this->configurationError()) !== null) {
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

		$assets = $opts->assets ?? self::collectAssets(dirname($htmlPath), $htmlPath);

		// The response is streamed to a temporary file: a 200 page catalogue has
		// no business going through memory, and an error body must not be able to
		// overwrite a previous good PDF.
		$tmpPath = $pdfPath.'.part';
		$handle  = @fopen($tmpPath, 'wb');
		if ($handle === false) {
			return RenderResult::failure(
				'cannot open the temporary output file: '.$tmpPath,
				microtime(true) - $started, renderer: $this->getName()
			);
		}

		$curl = curl_init($this->baseUrl.self::ROUTE_HTML);
		if ($curl === false) {
			fclose($handle);
			@unlink($tmpPath);
			return RenderResult::failure(
				'could not initialise the HTTP client.',
				microtime(true) - $started, renderer: $this->getName()
			);
		}

		$headers = ['Expect:'];   // no 100-continue: it only adds a round trip here
		if ($this->traceIdPrefix !== null && $this->traceIdPrefix !== '') {
			$headers[] = 'Gotenberg-Trace: '.$this->sanitiseTrace($this->traceIdPrefix);
		}

		curl_setopt_array($curl, [
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $this->buildFormFields($htmlPath, $assets, $opts),
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_FILE           => $handle,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => max(1, $opts->timeoutSeconds),
			CURLOPT_FOLLOWLOCATION => false,
		]);

		$ok       = curl_exec($curl);
		$status   = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		$curlErr  = curl_error($curl);
		$curlNo   = curl_errno($curl);
		unset($curl);   // curl_close() is a no-op since PHP 8.0 and deprecated in 8.5
		fclose($handle);

		$duration = microtime(true) - $started;

		if ($ok === false) {
			$body = $this->readErrorBody($tmpPath);
			@unlink($tmpPath);
			$hint = ($curlNo === CURLE_OPERATION_TIMEDOUT)
				? 'Gotenberg timed out after '.$opts->timeoutSeconds.'s on '.basename($htmlPath)
					.'. Raise the timeout, or render the book section by section.'
				: 'Gotenberg request failed: '.$curlErr;
			return RenderResult::failure(
				$hint.($body === '' ? '' : ' — '.$body),
				$duration, renderer: $this->getName()
			);
		}
		if ($status !== 200) {
			// Gotenberg answers errors in plain text; it is by far the most useful
			// thing to put in the job message.
			$body = $this->readErrorBody($tmpPath);
			@unlink($tmpPath);
			return RenderResult::failure(
				'Gotenberg answered HTTP '.$status.' on '.basename($htmlPath)
				.($body === '' ? '.' : ': '.$body),
				$duration, renderer: $this->getName()
			);
		}
		if (!is_file($tmpPath) || filesize($tmpPath) === 0) {
			@unlink($tmpPath);
			return RenderResult::failure(
				'Gotenberg answered HTTP 200 with an empty body.',
				$duration, renderer: $this->getName()
			);
		}
		if (!@rename($tmpPath, $pdfPath)) {
			@unlink($tmpPath);
			return RenderResult::failure(
				'could not move the produced PDF into place: '.$pdfPath,
				$duration, renderer: $this->getName()
			);
		}

		return RenderResult::success(
			$pdfPath,
			$duration,
			$this->assembler?->countPages($pdfPath),
			'',
			$this->getName()
		);
	}

	# -------------------------------------------------------
	# Request body
	# -------------------------------------------------------

	/**
	 * The multipart body: the document as index.html, its assets under their
	 * relative paths, then the Chromium options.
	 *
	 * cURL keys a multipart body by array key, so each part needs a distinct
	 * name; Gotenberg identifies files by their filename rather than by the
	 * field name, which is what makes that acceptable. The filename is what
	 * matters and it is set explicitly on every part.
	 *
	 * @param array $assets relative path => absolute path
	 */
	private function buildFormFields(string $htmlPath, array $assets, RenderOptions $opts): array {
		$fields = [];
		$fields[self::INDEX_NAME] = new CURLFile($htmlPath, 'text/html', self::INDEX_NAME);

		foreach ($assets as $relative => $absolute) {
			$relative = ltrim(str_replace('\\', '/', (string)$relative), '/');
			if ($relative === '' || $relative === self::INDEX_NAME) { continue; }
			if (str_contains($relative, '../')) { continue; }        // never send a path escaping the document
			if (!is_string($absolute) || !is_file($absolute) || !is_readable($absolute)) { continue; }

			$fields[$relative] = new CURLFile($absolute, $this->mimeType($absolute), $relative);
		}

		// Page setup. Chromium takes inches, so CSS lengths are converted; a
		// length it cannot read is left out rather than guessed, and Gotenberg
		// falls back on its own default.
		$fields['preferCssPageSize'] = $this->preferCssPageSize ? 'true' : 'false';
		$fields['printBackground']   = $this->printBackground ? 'true' : 'false';

		if (($width = $opts->paperWidthInches()) !== null)   { $fields['paperWidth']  = $this->inches($width); }
		if (($height = $opts->paperHeightInches()) !== null) { $fields['paperHeight'] = $this->inches($height); }

		if (($margin = $opts->marginInches()) !== null) {
			$value = $this->inches($margin);
			foreach (['marginTop', 'marginBottom', 'marginLeft', 'marginRight'] as $side) {
				$fields[$side] = $value;
			}
		}
		if ($opts->mediaType !== null && $opts->mediaType !== '') {
			$fields['emulatedMediaType'] = $opts->mediaType;
		}

		foreach ($this->extraFormFields as $name => $value) {
			if (is_string($name) && $name !== '' && (is_string($value) || is_numeric($value))) {
				$fields[$name] = (string)$value;
			}
		}

		return $fields;
	}

	/**
	 * Files of the document directory that can be sent as assets.
	 *
	 * Walked recursively, keyed by path relative to the directory so that
	 * relative URLs in the HTML keep working on the other side. Public and
	 * static so a caller with a precise list — the images of one section, say —
	 * can build its own and pass it through RenderOptions instead.
	 *
	 * @param string      $dir      Directory to walk.
	 * @param string|null $skipPath File to leave out, normally the document itself.
	 * @return array relative path => absolute path
	 */
	public static function collectAssets(string $dir, ?string $skipPath = null): array {
		$assets = [];
		if (!is_dir($dir)) { return $assets; }

		$root  = rtrim($dir, DIRECTORY_SEPARATOR);
		$skip  = $skipPath === null ? null : realpath($skipPath);
		$bytes = 0;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ($iterator as $file) {
			if (!$file->isFile() || !$file->isReadable()) { continue; }
			if (count($assets) >= self::MAX_ASSET_FILES || $bytes >= self::MAX_ASSET_BYTES) { break; }

			$absolute = $file->getPathname();
			if ($skip !== null && realpath($absolute) === $skip) { continue; }

			$extension = strtolower($file->getExtension());
			if (!in_array($extension, self::ASSET_EXTENSIONS, true)) { continue; }

			$relative = ltrim(str_replace('\\', '/', substr($absolute, strlen($root))), '/');
			if ($relative === '') { continue; }

			$assets[$relative] = $absolute;
			$bytes += (int)$file->getSize();
		}

		return $assets;
	}

	# -------------------------------------------------------

	/** Inches with enough precision for a trim size, and no exponent notation. */
	private function inches(float $value): string {
		return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
	}

	/** Content type of an asset, guessed from its extension. */
	private function mimeType(string $path): string {
		return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
			'css'   => 'text/css',
			'js'    => 'application/javascript',
			'woff2' => 'font/woff2',
			'woff'  => 'font/woff',
			'otf'   => 'font/otf',
			'ttf'   => 'font/ttf',
			'jpg', 'jpeg' => 'image/jpeg',
			'png'   => 'image/png',
			'gif'   => 'image/gif',
			'svg'   => 'image/svg+xml',
			'webp'  => 'image/webp',
			'avif'  => 'image/avif',
			default => 'application/octet-stream',
		};
	}

	/** First line of an error body, truncated: enough to diagnose, short enough to log. */
	private function readErrorBody(string $path): string {
		if (!is_file($path)) { return ''; }

		$body = (string)@file_get_contents($path, false, null, 0, 2048);
		$body = trim(preg_replace('/\s+/', ' ', $body) ?? '');
		return strlen($body) > 500 ? substr($body, 0, 500).'…' : $body;
	}

	/** Keeps a trace identifier to characters a header may carry. */
	private function sanitiseTrace(string $trace): string {
		$trace = preg_replace('/[^A-Za-z0-9._-]/', '-', $trace) ?? '';
		return substr($trace, 0, 64);
	}

	/** Checks the output can be written before spending time on the request. */
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
