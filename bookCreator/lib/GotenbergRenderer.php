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
 * The exchange is one multipart POST to /forms/chromium/convert/html. Three
 * properties of that route drive everything this class does, and the first two
 * are the reason a naive implementation produces an unstyled PDF:
 *
 *  - the main document must be named index.html, whatever it is called on disk;
 *  - every other file of the body lands in ONE FLAT directory next to it, under
 *    its base name only. Gotenberg sanitises the part filename by keeping what
 *    follows the last slash (pkg/modules/api, sanitizeFilename), so a part named
 *    "css/base.css" is written as "base.css" and the document's own
 *    href="css/base.css" resolves to nothing. Chromium then renders a correctly
 *    sized, entirely unstyled book and the service answers 200;
 *  - relative references are all Chromium has. Absolute local paths — the plate
 *    files of the media library, as the HTML builder writes them for the local
 *    driver — do not exist inside the Gotenberg container at all.
 *
 * So the driver ships the theme (stylesheets and fonts, collected from
 * RenderOptions::$baseUrl, which is where they live) plus the local files the
 * document points at, gives each of them a flat name, and posts a REWRITTEN COPY
 * of the document in which every reference points at that flat name. The copy is
 * temporary and the caller's HTML is never touched; the stylesheets that carry
 * url() references are copied and rewritten the same way.
 *
 * The service is internal and never exposed: there is no authentication to
 * carry. The configured URL is validated all the same — a typo there would
 * otherwise turn into a POST to whatever host does answer.
 *
 * What Chromium does NOT do, and no form field fixes:
 *
 *  - counter-reset on @page. A section rendered on its own therefore always
 *    folios from 1, whatever RenderOptions::$firstPageNumber says. The driver
 *    refuses such a render rather than returning a book whose every section
 *    restarts at page 1; see $allowRestartedPageNumbering.
 *  - string-set / string(), so the running heads of the themes stay empty
 *    (Chrome 131 shipped @page margin boxes and counter(page), not the string
 *    machinery). Folios print, running heads do not.
 *  - bleed and crop marks. The sheet is enlarged through paperWidth/paperHeight
 *    when a bleed is asked for, but no mark is drawn.
 *
 * The book rendered through this driver is the same book, not the same
 * pagination — which is why acceptance is run per theme on both drivers.
 */
final class GotenbergRenderer implements PdfRendererInterface {

	/** Route of the Chromium HTML conversion. */
	private const ROUTE_HTML = '/forms/chromium/convert/html';

	/** Route answering the service health, used by isAvailable(). */
	private const ROUTE_HEALTH = '/health';

	/** Name Gotenberg expects for the main document. */
	private const INDEX_NAME = 'index.html';

	/**
	 * Files that may travel as assets, and the order in which they are offered
	 * to the budget below. Stylesheets first: when a theme is too heavy to be
	 * sent whole, losing a font is a substitution, losing the CSS is a ruined
	 * book. Scripts come last — they do nothing in the PDF path, where Paged.js
	 * is not part of the document.
	 */
	private const ASSET_PRIORITIES = [
		'css'   => 0,
		'woff2' => 1, 'woff' => 1, 'otf' => 1, 'ttf' => 1, 'eot' => 1,
		'svg'   => 2, 'png' => 2, 'jpg' => 2, 'jpeg' => 2, 'gif' => 2, 'webp' => 2, 'avif' => 2,
		'js'    => 3,
	];

	/**
	 * Ceilings on asset collection. Everything here goes over the wire on every
	 * single section of a book, so these are much tighter than what a local
	 * driver reading the same directory would tolerate: a theme is a megabyte or
	 * two, and a section carries a handful of plates. Anything beyond that is a
	 * stray directory, and what is dropped is reported in the render warnings
	 * rather than silently left out.
	 */
	private const MAX_ASSET_FILES      = 500;
	private const MAX_ASSET_BYTES      = 67108864;   // 64 MB for the whole request
	private const MAX_ASSET_FILE_BYTES = 16777216;   // 16 MB for a single file

	/** Guard on the document rewriting, which happens in memory. */
	private const MAX_DOCUMENT_BYTES = 33554432;    // 32 MB of HTML

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
	 *                                        the themes carry their geometry in CSS. Ignored
	 *                                        when a bleed is requested, since the CSS page size
	 *                                        is the trim size and the sheet to produce is larger.
	 * @param bool         $printBackground   Prints background colours and images. Off by
	 *                                        default in Chromium, which loses every coloured
	 *                                        block of a layout.
	 * @param string|null  $traceIdPrefix     Prefix of the Gotenberg-Trace header, which ties a
	 *                                        request to its lines in the service log. Worth
	 *                                        setting to the book or job identifier.
	 * @param array        $extraFormFields   Additional Gotenberg form fields, as name => value.
	 *                                        Escape hatch for a service option this class does
	 *                                        not expose.
	 * @param bool         $allowRestartedPageNumbering Accepts rendering a fragment whose folios
	 *                                        cannot start where they should. Off by default:
	 *                                        Chromium ignores counter-reset on @page, so every
	 *                                        section of a book would restart at page 1 — a defect
	 *                                        with no visible symptom before the proof comes back
	 *                                        from the printer. Turned on, the render goes through
	 *                                        and the result carries a warning instead.
	 * @param bool         $failOnResourceLoadingFailed Asks the service to answer 400 when
	 *                                        Chromium fails to load a stylesheet, a font or an
	 *                                        image, rather than producing a book with a hole in
	 *                                        it. This is the Gotenberg equivalent of the resource
	 *                                        errors the WeasyPrint driver reads back from stderr.
	 */
	public function __construct(
		string $baseUrl,
		private readonly ?PdfAssembler $assembler = null,
		private readonly bool $preferCssPageSize = true,
		private readonly bool $printBackground = true,
		private readonly ?string $traceIdPrefix = null,
		private readonly array $extraFormFields = [],
		private readonly bool $allowRestartedPageNumbering = false,
		private readonly bool $failOnResourceLoadingFailed = true,
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
		if (isset($parts['user']) || isset($parts['pass'])) {
			return 'the Gotenberg URL must not carry credentials: the service is internal and '
				.'unauthenticated. Remove the user:password part.';
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
		$started   = microtime(true);
		$warnings  = [];
		$temporary = [];

		try {
			if (($reason = $this->configurationError()) !== null) {
				return $this->fail($reason, $started, $warnings);
			}
			if (!is_file($htmlPath) || !is_readable($htmlPath)) {
				return $this->fail('cannot read the HTML to render: '.$htmlPath, $started, $warnings);
			}
			if (($reason = $this->checkWritable($pdfPath)) !== null) {
				return $this->fail($reason, $started, $warnings);
			}
			if (($reason = $this->pageNumberingProblem($opts, $warnings)) !== null) {
				return $this->fail($reason, $started, $warnings);
			}

			// What is actually sent: a rewritten copy of the document whenever a
			// reference had to be flattened, the original file otherwise, plus the
			// files it points at.
			$prepared = $this->prepareRequest($htmlPath, $pdfPath, $opts, $warnings, $temporary);
			if (is_string($prepared)) {   // preparation returned a reason instead of a request
				return $this->fail($prepared, $started, $warnings);
			}
			[$documentPath, $files] = $prepared;

			return $this->post($documentPath, $files, $pdfPath, $opts, $started, $warnings, basename($htmlPath));
		} finally {
			foreach ($temporary as $path) { @unlink($path); }
		}
	}

	/**
	 * The HTTP exchange itself, split out so render() reads as the sequence of
	 * checks it is.
	 *
	 * @param array $files flat filename => absolute path
	 */
	private function post(
		string $documentPath,
		array $files,
		string $pdfPath,
		RenderOptions $opts,
		float $started,
		array $warnings,
		string $label
	): RenderResult {
		// The response is streamed to a temporary file: a 200 page catalogue has
		// no business going through memory, and an error body must not be able to
		// overwrite a previous good PDF.
		$tmpPath = $pdfPath.'.part';
		$handle  = @fopen($tmpPath, 'wb');
		if ($handle === false) {
			return $this->fail('cannot open the temporary output file: '.$tmpPath, $started, $warnings);
		}

		$curl = curl_init($this->baseUrl.self::ROUTE_HTML);
		if ($curl === false) {
			fclose($handle);
			@unlink($tmpPath);
			return $this->fail('could not initialise the HTTP client.', $started, $warnings);
		}

		$headers = ['Expect:'];   // no 100-continue: it only adds a round trip here
		if ($this->traceIdPrefix !== null && $this->traceIdPrefix !== '') {
			$headers[] = 'Gotenberg-Trace: '.$this->sanitiseTrace($this->traceIdPrefix);
		}

		curl_setopt_array($curl, [
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $this->buildFormFields($documentPath, $files, $opts),
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_FILE           => $handle,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => max(1, $opts->timeoutSeconds),
			CURLOPT_FOLLOWLOCATION => false,
		]);

		$ok      = curl_exec($curl);
		$status  = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		$curlErr = curl_error($curl);
		$curlNo  = curl_errno($curl);
		unset($curl);   // curl_close() is a no-op since PHP 8.0 and deprecated in 8.5
		fclose($handle);

		if ($ok === false) {
			$body = $this->readErrorBody($tmpPath);
			@unlink($tmpPath);
			$hint = ($curlNo === CURLE_OPERATION_TIMEDOUT)
				? 'Gotenberg timed out after '.$opts->timeoutSeconds.'s on '.$label
					.'. Raise render_timeout, check the service own --api-timeout, or render the '
					.'book section by section.'
				: 'Gotenberg request failed: '.$curlErr;
			return $this->fail($hint.($body === '' ? '' : ' — '.$body), $started, $warnings);
		}
		if ($status !== 200) {
			// Gotenberg answers errors in plain text; it is by far the most useful
			// thing to put in the job message.
			$body = $this->readErrorBody($tmpPath);
			@unlink($tmpPath);
			$hint = '';
			if ($status === 400 && $this->failOnResourceLoadingFailed) {
				$hint = ' A 400 here is usually a resource Chromium could not load: check that the '
					.'theme directory given as base URL holds the stylesheets and the fonts, and '
					.'that the plates of the section exist on disk.';
			}
			return $this->fail(
				'Gotenberg answered HTTP '.$status.' on '.$label.($body === '' ? '.' : ': '.$body).$hint,
				$started, $warnings
			);
		}

		$size = is_file($tmpPath) ? (int)filesize($tmpPath) : 0;
		if ($size === 0) {
			@unlink($tmpPath);
			return $this->fail('Gotenberg answered HTTP 200 with an empty body.', $started, $warnings);
		}
		// A 200 carrying something other than a PDF — a proxy error page, a service
		// answering on that port that is not Gotenberg — must not be renamed into
		// place and handed to the assembler.
		if (!$this->looksLikePdf($tmpPath)) {
			@unlink($tmpPath);
			return $this->fail(
				'Gotenberg answered HTTP 200 but the body is not a PDF ('.$size.' bytes). '
				.'Check that '.$this->baseUrl.' is the Gotenberg service itself and not a proxy.',
				$started, $warnings
			);
		}
		if (!@rename($tmpPath, $pdfPath)) {
			@unlink($tmpPath);
			return $this->fail('could not move the produced PDF into place: '.$pdfPath, $started, $warnings);
		}

		return RenderResult::success(
			$pdfPath,
			microtime(true) - $started,
			$this->assembler?->countPages($pdfPath),
			join("\n", $warnings),
			$this->getName()
		);
	}

	# -------------------------------------------------------
	# Page numbering
	# -------------------------------------------------------

	/**
	 * Whether this render can honour the folio numbering it was asked for.
	 *
	 * RenderOptions::counterResetCss() emits "@page :first { counter-reset: page N }",
	 * which WeasyPrint honours and Chromium ignores — Chrome 131 shipped @page
	 * margin boxes and the page counter, not the ability to set it. A section
	 * rendered here therefore numbers from 1 whatever N is, and a book assembled
	 * from such sections carries the same wrong folios on every chapter.
	 *
	 * That is a rendering limit, not something a driver may paper over, so the
	 * default is to refuse the render and say why. The alternative — accept and
	 * warn — is available through the constructor for an installation that has
	 * decided its own way out (whole book in one request, renumbering at assembly,
	 * or folios simply dropped from the theme).
	 *
	 * @param array $warnings appended to when the render is allowed through
	 */
	private function pageNumberingProblem(RenderOptions $opts, array &$warnings): ?string {
		if ($opts->firstPageNumber === null || $opts->firstPageNumber <= 1) { return null; }

		$message = 'this fragment should start at page '.$opts->firstPageNumber
			.', and Chromium cannot do it: it ignores counter-reset on @page, so the section '
			.'would be folioed from 1. Render the whole book in one request instead of section '
			.'by section, renumber at assembly, or use the WeasyPrint driver, which honours the '
			.'rule.';

		if ($this->allowRestartedPageNumbering) {
			$warnings[] = 'page numbering: '.$message.' Rendered anyway, as configured.';
			return null;
		}
		return $message;
	}

	# -------------------------------------------------------
	# Request body
	# -------------------------------------------------------

	/**
	 * The multipart body: the document as index.html, its assets under their flat
	 * names, then the Chromium options.
	 *
	 * cURL keys a multipart body by array key, so each part needs a distinct
	 * name; Gotenberg identifies files by their filename rather than by the field
	 * name, which is what makes that acceptable. The filename is what matters and
	 * it is set explicitly on every part.
	 *
	 * @param array $files flat filename => absolute path
	 */
	private function buildFormFields(string $documentPath, array $files, RenderOptions $opts): array {
		$fields = [];
		$fields[self::INDEX_NAME] = new CURLFile($documentPath, 'text/html', self::INDEX_NAME);

		foreach ($files as $name => $absolute) {
			$name = (string)$name;
			if ($name === '' || $name === self::INDEX_NAME) { continue; }
			if (!is_string($absolute) || !is_file($absolute) || !is_readable($absolute)) { continue; }

			$fields[$name] = new CURLFile($absolute, $this->mimeType($absolute), $name);
		}

		// Page setup. Chromium takes inches, so CSS lengths are converted; a length
		// it cannot read is left out rather than guessed, and Gotenberg falls back
		// on its own default — which is US Letter, not A4.
		//
		// preferCssPageSize is dropped when a bleed is asked for: the @page size in
		// the CSS is the trim size, while the sheet to produce is that plus twice
		// the bleed, and only paperWidth/paperHeight carry the larger value.
		$preferCss = $this->preferCssPageSize && !$opts->bleed;
		$fields['preferCssPageSize'] = $preferCss ? 'true' : 'false';
		$fields['printBackground']   = $this->printBackground ? 'true' : 'false';

		if (($width = $opts->paperWidthInches()) !== null)   { $fields['paperWidth']  = $this->inches($width); }
		if (($height = $opts->paperHeightInches()) !== null) { $fields['paperHeight'] = $this->inches($height); }

		// Margins are sent even under preferCssPageSize: Chromium gives the @page
		// margin precedence when the stylesheet declares one, and both values come
		// from the same page format, so the two cannot disagree. Left out, Chromium
		// would apply its own 0.39in default to a theme that declares none.
		if (($margin = $opts->marginInches()) !== null) {
			$value = $this->inches($margin);
			foreach (['marginTop', 'marginBottom', 'marginLeft', 'marginRight'] as $side) {
				$fields[$side] = $value;
			}
		}
		if ($opts->mediaType !== null && $opts->mediaType !== '') {
			$fields['emulatedMediaType'] = $opts->mediaType;
		}
		if ($this->failOnResourceLoadingFailed) {
			$fields['failOnResourceLoadingFailed'] = 'true';
		}

		foreach ($this->extraFormFields as $name => $value) {
			if (is_string($name) && $name !== '' && (is_string($value) || is_numeric($value))) {
				$fields[$name] = (string)$value;
			}
		}

		return $fields;
	}

	# -------------------------------------------------------
	# Assets and document rewriting
	# -------------------------------------------------------

	/**
	 * Builds the file set of the request and the document that goes with it.
	 *
	 * Everything the document references and that exists on this machine is
	 * collected, given a flat name, and the reference rewritten to that name in a
	 * temporary copy — see the class comment for why flat names are not a choice.
	 * The caller's HTML is left untouched.
	 *
	 * Only what is referenced travels. The theme is offered whole to the bundle,
	 * but a file is admitted the first time something points at it, so a request
	 * carries the two fonts of the pair in use rather than every family the theme
	 * ships, and the work directory of the job does not turn into an attachment.
	 *
	 * @param array  $warnings  appended to
	 * @param array  $temporary temporary files to delete once the request is done
	 * @return array{0: string, 1: array}|string [document to post, flat name => absolute path],
	 *                                           or the reason preparation failed
	 */
	private function prepareRequest(
		string $htmlPath,
		string $pdfPath,
		RenderOptions $opts,
		array &$warnings,
		array &$temporary
	): array|string {
		// [] means "send the document alone", the escape hatch of a caller that
		// has already made its HTML self-contained.
		if (is_array($opts->assets) && $opts->assets === []) { return [$htmlPath, []]; }

		$size = (int)filesize($htmlPath);
		if ($size > self::MAX_DOCUMENT_BYTES) {
			return 'the HTML to render is '.$this->megabytes($size).', over the '
				.$this->megabytes(self::MAX_DOCUMENT_BYTES).' this driver rewrites in memory. '
				.'Render the book section by section, or use the WeasyPrint driver.';
		}
		$html = @file_get_contents($htmlPath);
		if ($html === false) {
			return 'cannot read the HTML to render: '.$htmlPath;
		}

		$bundle = new GotenbergAssetBundle(
			self::MAX_ASSET_FILES, self::MAX_ASSET_BYTES, self::MAX_ASSET_FILE_BYTES
		);

		// 1. Candidates, keyed by the path a reference would use. Explicit assets
		//    are the caller's own list and are sent whether or not this driver can
		//    see them referenced; collected ones wait to be asked for.
		foreach ($this->candidateAssets($htmlPath, $opts, $warnings) as $relative => $absolute) {
			if (is_array($opts->assets)) { $bundle->add((string)$relative, (string)$absolute); }
			else { $bundle->offer((string)$relative, (string)$absolute); }
		}

		// 2. The document, rewritten. References are resolved against the theme
		//    directory, exactly as --base-url does for the local driver.
		$rewritten = $this->rewriteDocument($html, $bundle, $warnings);

		// 3. Stylesheets carry references of their own, relative to their own
		//    directory. They are rewritten and shipped as copies. A stylesheet
		//    admitted while rewriting another one — an @import — joins the list, so
		//    the loop runs until nothing new turns up.
		$rewrittenSheets = [];
		while (($pending = array_diff_key($bundle->stylesheets(), $rewrittenSheets)) !== []) {
			foreach ($pending as $name => $absolute) {
				$rewrittenSheets[$name] = true;

				$css = @file_get_contents($absolute);
				if ($css === false) { continue; }

				$updated = $this->rewriteCss($css, $bundle->keyOf($name) ?? $name, $bundle, $warnings);
				if ($updated === $css) { continue; }

				$copy = $pdfPath.'.asset-'.count($temporary).'.css';
				if (@file_put_contents($copy, $updated) === false) {
					$warnings[] = 'assets: could not rewrite '.$name.', sending it unchanged.';
					continue;
				}
				$temporary[] = $copy;
				$bundle->replace($name, $copy);
			}
		}

		$files = $bundle->files();
		foreach ($bundle->skipped() as $skipped) { $warnings[] = 'assets: '.$skipped; }

		if ($bundle->totalBytes() > (int)(self::MAX_ASSET_BYTES / 2)) {
			$warnings[] = 'assets: '.count($files).' files, '.$this->megabytes($bundle->totalBytes())
				.' posted with this section. Every section of the book carries them again; consider '
				.'passing a precise list through RenderOptions::$assets.';
		}

		if (!count($files)) {
			$warnings[] = 'assets: nothing was sent with the document. Unless the HTML is '
				.'self-contained, Chromium will render it unstyled. Check that RenderOptions::$baseUrl '
				.'points at the theme directory.';
		}

		if ($rewritten === $html) { return [$htmlPath, $files]; }

		$copy = $pdfPath.'.index.html';
		if (@file_put_contents($copy, $rewritten) === false) {
			return 'cannot write the rewritten document: '.$copy;
		}
		$temporary[] = $copy;
		return [$copy, $files];
	}

	/**
	 * Where the assets come from.
	 *
	 * A caller that knows exactly what its document needs passes them in
	 * RenderOptions::$assets and nothing is walked. Otherwise the theme directory
	 * is walked — it holds the stylesheets and the fonts, and it is what $baseUrl
	 * points at — plus the directory of the document, which is where a caller
	 * writing its own images alongside the HTML would put them.
	 *
	 * @return array relative path => absolute path
	 */
	private function candidateAssets(string $htmlPath, RenderOptions $opts, array &$warnings): array {
		if (is_array($opts->assets)) { return $opts->assets; }

		$candidates = [];
		$themeDir   = self::localDirectory($opts->baseUrl);

		if ($themeDir !== null) {
			$candidates = self::collectAssets($themeDir);
		} elseif ($opts->baseUrl !== null && $opts->baseUrl !== '') {
			$warnings[] = 'assets: the base URL "'.$opts->baseUrl.'" is not a readable local '
				.'directory. Gotenberg renders from files posted with the request, so the theme '
				.'must be reachable on this machine; the document is being sent without it.';
		} else {
			$warnings[] = 'assets: no base URL given. The stylesheets and the fonts of the theme '
				.'live in the theme directory, not next to the HTML, so nothing of the theme can '
				.'be sent.';
		}

		foreach (self::collectAssets(dirname($htmlPath), $htmlPath) as $relative => $absolute) {
			if (!isset($candidates[$relative])) { $candidates[$relative] = $absolute; }
		}
		return $candidates;
	}

	/**
	 * Rewrites href, src and url() references of an HTML document to the flat
	 * names of the request.
	 *
	 * A reference that resolves to a file we can ship is added to the bundle on
	 * the spot — that is how the plates of a section, written as absolute local
	 * paths by the HTML builder for the local driver, travel with the request.
	 */
	private function rewriteDocument(string $html, GotenbergAssetBundle $bundle, array &$warnings): string {
		if (preg_match('~<base\b~i', $html)) {
			$warnings[] = 'the document carries a <base> element. Chromium resolves every relative '
				.'reference against it, so the files posted with the request are unreachable. That '
				.'element belongs to the browser preview only.';
		}

		$html = preg_replace_callback(
			'~\b(href|src)\s*=\s*(["\'])(.*?)\2~i',
			function (array $m) use ($bundle, &$warnings): string {
				$name = $this->flatNameFor($m[3], '', $bundle, $warnings, true);
				return $name === null ? $m[0] : $m[1].'='.$m[2].$name.$m[2];
			},
			$html
		) ?? $html;

		return $this->rewriteUrlTokens($html, '', $bundle, $warnings, true);
	}

	/**
	 * Same work inside a stylesheet, where references resolve against the
	 * directory of the stylesheet rather than against the document.
	 *
	 * @param string $relative path of the stylesheet itself, relative to the theme
	 */
	private function rewriteCss(string $css, string $relative, GotenbergAssetBundle $bundle, array &$warnings): string {
		$base = str_contains($relative, '/') ? dirname($relative) : '';

		$css = preg_replace_callback(
			'~@import\s+(["\'])(.*?)\1~i',
			function (array $m) use ($base, $bundle, &$warnings): string {
				$name = $this->flatNameFor($m[2], $base, $bundle, $warnings);
				return $name === null ? $m[0] : '@import '.$m[1].$name.$m[1];
			},
			$css
		) ?? $css;

		return $this->rewriteUrlTokens($css, $base, $bundle, $warnings);
	}

	/** url(...) references, in an HTML style attribute or in a stylesheet. */
	private function rewriteUrlTokens(
		string $text,
		string $base,
		GotenbergAssetBundle $bundle,
		array &$warnings,
		bool $inHtml = false
	): string {
		return preg_replace_callback(
			'~url\(\s*(["\']?)([^"\'()]+)\1\s*\)~i',
			function (array $m) use ($base, $bundle, &$warnings, $inHtml): string {
				$name = $this->flatNameFor($m[2], $base, $bundle, $warnings, $inHtml);
				return $name === null ? $m[0] : 'url('.$m[1].$name.$m[1].')';
			},
			$text
		) ?? $text;
	}

	/**
	 * The flat name a reference must be rewritten to, null when it must be left
	 * alone (remote URL, data: URI, fragment, or a file we have no copy of).
	 *
	 * @param string $base   directory the reference resolves against, relative to the theme
	 * @param bool   $inHtml the reference comes from markup, where the builder has escaped it
	 */
	private function flatNameFor(
		string $reference,
		string $base,
		GotenbergAssetBundle $bundle,
		array &$warnings,
		bool $inHtml = false
	): ?string {
		$raw  = $inHtml ? html_entity_decode($reference, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $reference;
		$path = self::normaliseReference($raw);
		if ($path === null) { return null; }

		if (str_starts_with($path, '/')) {
			// An absolute local path: the media library plates, which exist on this
			// machine and nowhere inside the Gotenberg container. They are admitted
			// on sight, since nothing offered them.
			$name = $bundle->resolve($path) ?? $bundle->add($path, $path);
			if ($name === null) {
				$warnings[] = 'assets: '.$path.' is referenced by the document but was not sent; '
					.'Chromium will render a hole where it is.';
			}
			return $name;
		}

		$key = self::normalisePath(($base === '' ? '' : $base.'/').$path);
		if ($key === null) { return null; }

		$name = $bundle->resolve($key);
		if ($name === null) {
			$warnings[] = 'assets: "'.$reference.'" is referenced'
				.($base === '' ? ' by the document' : ' from '.$base)
				.' but no such file was collected; Chromium will not load it.';
		}
		return $name;
	}

	/**
	 * Files of a directory that can be sent as assets.
	 *
	 * Walked recursively, keyed by path relative to the directory — which is how
	 * the document refers to them — and ordered stylesheets first, then fonts,
	 * then images, so that a directory too large to be sent whole still gets its
	 * layout across. Public and static so a caller with a precise list — the
	 * plates of one section, say — can build its own and pass it through
	 * RenderOptions instead.
	 *
	 * No budget is applied here: it is applied when the files are admitted into a
	 * request, which is the only place that knows about the other sources.
	 *
	 * @param string      $dir      Directory to walk.
	 * @param string|null $skipPath File to leave out, normally the document itself.
	 * @return array relative path => absolute path
	 */
	public static function collectAssets(string $dir, ?string $skipPath = null): array {
		$assets = [];
		if ($dir === '' || !is_dir($dir)) { return $assets; }

		$root = rtrim($dir, DIRECTORY_SEPARATOR);
		$skip = $skipPath === null ? null : realpath($skipPath);

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		$ranked = [];
		foreach ($iterator as $file) {
			if (!$file->isFile() || !$file->isReadable()) { continue; }

			$absolute  = $file->getPathname();
			$extension = strtolower($file->getExtension());
			if (!isset(self::ASSET_PRIORITIES[$extension])) { continue; }
			if ($skip !== null && realpath($absolute) === $skip) { continue; }

			$relative = ltrim(str_replace('\\', '/', substr($absolute, strlen($root))), '/');
			if ($relative === '') { continue; }

			$ranked[$relative] = [self::ASSET_PRIORITIES[$extension], $relative, $absolute];
		}

		// Stable: same theme, same order, same request, whatever the filesystem
		// hands back.
		uasort($ranked, static fn(array $a, array $b): int => ($a[0] <=> $b[0]) ?: strcmp($a[1], $b[1]));

		foreach ($ranked as $entry) { $assets[$entry[1]] = $entry[2]; }
		return $assets;
	}

	/** Whether an extension may travel as an asset. */
	public static function isAssetExtension(string $extension): bool {
		return isset(self::ASSET_PRIORITIES[strtolower($extension)]);
	}

	/**
	 * A reference reduced to a path, or null when it points at something that is
	 * not a local file: a remote URL, a data: URI, an in-page fragment.
	 */
	public static function normaliseReference(string $reference): ?string {
		$reference = trim($reference);
		if ($reference === '' || str_starts_with($reference, '#')) { return null; }
		if (str_starts_with($reference, '//')) { return null; }              // protocol-relative
		if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $reference)) { return null; }  // http:, data:, file:

		$path = preg_split('/[?#]/', $reference)[0];
		return ($path === '') ? null : $path;
	}

	/**
	 * Resolves . and .. inside a relative path, null when it escapes its root —
	 * which a document has no business doing and a request cannot represent.
	 */
	public static function normalisePath(string $path): ?string {
		$absolute = str_starts_with($path, '/');
		$segments = [];

		foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
			if ($segment === '' || $segment === '.') { continue; }
			if ($segment === '..') {
				if (!count($segments)) { return null; }   // escapes its root
				array_pop($segments);
				continue;
			}
			$segments[] = $segment;
		}
		if (!count($segments)) { return null; }

		return ($absolute ? '/' : '').join('/', $segments);
	}

	/**
	 * The local directory a base URL designates, null when it designates none.
	 * Both a plain path and a file:// URL are accepted, since the local driver is
	 * given either.
	 */
	public static function localDirectory(?string $baseUrl): ?string {
		if ($baseUrl === null) { return null; }

		$path = trim($baseUrl);
		if ($path === '') { return null; }
		if (str_starts_with(strtolower($path), 'file://')) {
			$path = (string)parse_url($path, PHP_URL_PATH);
			$path = rawurldecode($path);
		} elseif (preg_match('~^[a-z][a-z0-9+.-]*://~i', $path)) {
			return null;   // http(s): nothing to read from disk
		}

		$path = rtrim($path, '/');
		return is_dir($path) ? $path : null;
	}

	# -------------------------------------------------------

	/** Failed result, with whatever was noticed on the way. */
	private function fail(string $message, float $started, array $warnings): RenderResult {
		return RenderResult::failure(
			$message,
			microtime(true) - $started,
			warnings: join("\n", $warnings),
			renderer: $this->getName()
		);
	}

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
			'eot'   => 'application/vnd.ms-fontobject',
			'jpg', 'jpeg' => 'image/jpeg',
			'png'   => 'image/png',
			'gif'   => 'image/gif',
			'svg'   => 'image/svg+xml',
			'webp'  => 'image/webp',
			'avif'  => 'image/avif',
			default => 'application/octet-stream',
		};
	}

	/** True when a file starts with a PDF header. */
	private function looksLikePdf(string $path): bool {
		$handle = @fopen($path, 'rb');
		if ($handle === false) { return false; }

		$head = (string)fread($handle, 5);
		fclose($handle);
		return $head === '%PDF-';
	}

	/** A byte count as an administrator would read it. */
	private function megabytes(int $bytes): string {
		return round($bytes / 1048576, 1).' MB';
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

/**
 * The files of one Gotenberg request, and the flat names they travel under.
 *
 * Gotenberg drops every posted file into a single directory under its base name
 * (see GotenbergRenderer), so "css/base.css" and "templates/css/base.css" would
 * both arrive as "base.css" and one would overwrite the other. Every file
 * therefore gets a name of its own here, derived from its path so that a request
 * stays readable in the service log, and the renderer rewrites the document to
 * match.
 *
 * This is also where the request budget is enforced, because it is the only
 * place that sees every source at once.
 */
final class GotenbergAssetBundle {

	/** @var array<string,string> flat name => absolute path */
	private array $files = [];

	/** @var array<string,string> reference key => flat name */
	private array $names = [];

	/** @var array<string,string> flat name => reference key */
	private array $keys = [];

	/** @var array<string,string> reference key => absolute path, waiting to be referenced */
	private array $offered = [];

	/** @var array<string,string> real path => flat name, so one file travels once */
	private array $byRealPath = [];

	/** @var string[] what was left out, and why */
	private array $skipped = [];

	private int $bytes = 0;

	public function __construct(
		private readonly int $maxFiles,
		private readonly int $maxBytes,
		private readonly int $maxFileBytes,
	) {}

	/**
	 * Records a file the document may reference. Nothing is sent until something
	 * asks for it: a theme ships several font families and a book uses two.
	 */
	public function offer(string $key, string $absolute): void {
		if (!isset($this->names[$key]) && !isset($this->offered[$key])) {
			$this->offered[$key] = $absolute;
		}
	}

	/**
	 * The flat name of a file, admitting it into the request the first time it is
	 * asked for. null when nothing was offered under that key, or when the file
	 * cannot travel.
	 */
	public function resolve(string $key): ?string {
		if (isset($this->names[$key])) { return $this->names[$key]; }
		if (!isset($this->offered[$key])) { return null; }

		return $this->add($key, $this->offered[$key]);
	}

	/**
	 * Admits one file into the request.
	 *
	 * @param string $key      How the document refers to it: a path relative to the
	 *                         theme, or an absolute local path.
	 * @param string $absolute Where it is on this machine.
	 * @return string|null The flat name it travels under, null when it was left out.
	 */
	public function add(string $key, string $absolute): ?string {
		if (isset($this->names[$key])) { return $this->names[$key]; }
		if ($absolute === '') { return null; }
		unset($this->offered[$key]);

		if (!is_file($absolute) || !is_readable($absolute)) {
			$this->skipped[] = $key.' is not a readable file';
			return null;
		}

		// The same file reached through two paths — the plate of a section, seen
		// both in the work directory and through the absolute path the document
		// carries — travels once, under one name.
		$real = realpath($absolute);
		if ($real !== false && isset($this->byRealPath[$real])) {
			$name = $this->byRealPath[$real];
			$this->names[$key] = $name;
			return $name;
		}
		$extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
		if (!GotenbergRenderer::isAssetExtension($extension)) {
			$this->skipped[] = $key.' has no extension a browser would load';
			return null;
		}

		$size = (int)filesize($absolute);
		if ($size > $this->maxFileBytes) {
			$this->skipped[] = $key.' is '.self::readableSize($size).', over the '
				.self::readableSize($this->maxFileBytes).' a single file may weigh';
			return null;
		}
		if (count($this->files) >= $this->maxFiles) {
			$this->skipped[] = $key.' dropped: already '.$this->maxFiles.' files in the request';
			return null;
		}
		if ($this->bytes + $size > $this->maxBytes) {
			$this->skipped[] = $key.' dropped: the request is already '
				.self::readableSize($this->bytes).', of '.self::readableSize($this->maxBytes).' allowed';
			return null;
		}

		$name = $this->uniqueName($key, $extension);
		$this->files[$name] = $absolute;
		$this->names[$key]  = $name;
		$this->keys[$name]  = $key;
		$this->bytes       += $size;
		if ($real !== false) { $this->byRealPath[$real] = $name; }

		return $name;
	}

	/** How the document refers to a file, from its flat name. */
	public function keyOf(string $name): ?string {
		return $this->keys[$name] ?? null;
	}

	/** Sends a rewritten copy in place of the original file. */
	public function replace(string $name, string $absolute): void {
		if (isset($this->files[$name])) { $this->files[$name] = $absolute; }
	}

	/** @return array<string,string> flat name => absolute path */
	public function files(): array { return $this->files; }

	/** @return array<string,string> the stylesheets of the request, which carry references of their own */
	public function stylesheets(): array {
		return array_filter(
			$this->files,
			static fn(string $path): bool => strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'css'
		);
	}

	/** @return string[] */
	public function skipped(): array { return $this->skipped; }

	public function totalBytes(): int { return $this->bytes; }

	/** A byte count as it reads in a job message. */
	private static function readableSize(int $bytes): string {
		if ($bytes >= 1048576) { return round($bytes / 1048576, 1).' MB'; }
		if ($bytes >= 1024)    { return round($bytes / 1024).' kB'; }
		return $bytes.' bytes';
	}

	/**
	 * A flat name that keeps enough of the original path to be recognisable, and
	 * that no other file of this request already holds.
	 */
	private function uniqueName(string $key, string $extension): string {
		$candidate = preg_replace('~[^A-Za-z0-9._-]+~', '_', trim($key, '/')) ?? '';
		$candidate = trim($candidate, '_');
		if ($candidate === '' || $candidate === '.'.$extension) { $candidate = 'asset.'.$extension; }

		// An absolute path mangles into something long and useless; its base name
		// plus a digest of the path is both shorter and unambiguous.
		if (strlen($candidate) > 80) {
			$base = pathinfo($key, PATHINFO_BASENAME);
			$base = preg_replace('~[^A-Za-z0-9._-]+~', '_', $base) ?? 'asset';
			$candidate = substr(sha1($key), 0, 8).'-'.substr($base, -60);
		}

		if (!isset($this->files[$candidate])) { return $candidate; }

		$dot  = strrpos($candidate, '.');
		$stem = ($dot === false) ? $candidate : substr($candidate, 0, $dot);
		$tail = ($dot === false) ? '' : substr($candidate, $dot);

		for ($i = 2; $i < 1000; $i++) {
			$next = $stem.'-'.$i.$tail;
			if (!isset($this->files[$next])) { return $next; }
		}
		return substr(sha1($key), 0, 12).$tail;
	}
}
