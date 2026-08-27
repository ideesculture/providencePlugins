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
require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/PdfRendererInterface.php');
require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/PdfAssembler.php');
require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/WeasyPrintRenderer.php');
require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/GotenbergRenderer.php');
require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/ThemeRegistry.php');
require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/BookSectionCache.php');

/**
 * Builds the rendering chain from bookCreator.conf.
 *
 * The renderers and the assembler deliberately read no configuration
 * themselves, so they stay testable in isolation and can be driven from a CLI
 * worker as easily as from a controller. This class is the single place that
 * turns settings into objects — which also makes it the single place to look at
 * when a deployment renders nothing.
 *
 * It is also where the page geometry of a book becomes render options. The
 * numbers come from the theme, through ThemeRegistry, because that is where the
 * @page rule is built from: a driver that sizes its own pages — Gotenberg does,
 * Chromium reading form fields rather than CSS — must be given the very format
 * the stylesheet was generated for, or every book leaves in A4 portrait.
 */
class PdfRendererFactory {

	/** @var Configuration */
	private $config;

	public function __construct($config = null) {
		$this->config = $config ? $config : Configuration::load(
			__CA_APP_DIR__.'/plugins/bookCreator/conf/bookCreator.conf'
		);
	}

	/**
	 * The configured renderer, ready to use.
	 *
	 * @param string|null $trace_id Identifier of the job or the book, carried in the
	 *                              Gotenberg-Trace header so a request can be found in
	 *                              the service log. Ignored by the local driver.
	 */
	public function makeRenderer($trace_id = null) {
		$assembler = $this->makeAssembler();

		if ($this->getRendererName() === 'gotenberg') {
			$url = (string)$this->config->get('gotenberg_url');
			return new GotenbergRenderer(
				$url,
				$assembler,
				preferCssPageSize: $this->boolOr('gotenberg_prefer_css_page_size', true),
				printBackground: $this->boolOr('gotenberg_print_background', true),
				traceIdPrefix: (strlen((string)$trace_id) ? (string)$trace_id : null),
				allowRestartedPageNumbering: $this->boolOr('gotenberg_allow_restarted_page_numbering', false),
				failOnResourceLoadingFailed: $this->boolOr('gotenberg_fail_on_resource_error', true)
			);
		}

		$binary = $this->pathOr('weasyprint_path', 'weasyprint');
		return new WeasyPrintRenderer($binary, $assembler);
	}

	/** The qpdf wrapper, shared by the renderer and the assembly step. */
	public function makeAssembler() {
		return new PdfAssembler($this->pathOr('qpdf_path', 'qpdf'), $this->getTimeout());
	}

	/**
	 * Work area of the worker, from the configuration.
	 *
	 * Also the root of the section cache, and the single place the fallback on
	 * the tmp/ directory of the plugin is decided: the worker and the web side
	 * have to agree on it, or a book deleted from the interface would leave its
	 * cache entries behind.
	 */
	public function getWorkDir() {
		$work = trim((string)$this->config->get('job_work_dir'));
		return strlen($work)
			? rtrim($work, '/')
			: __CA_APP_DIR__.'/plugins/bookCreator/tmp';
	}

	/**
	 * The section cache, pointed at the work area of the worker.
	 *
	 * Built here like everything else that turns settings into objects, and
	 * given the identity of the engine rather than being left to find it: a
	 * cached section is only reusable by the very renderer that produced it.
	 *
	 * @param string|null $root work directory root, the one holding the per-job
	 *                          directories. Null reads it from the configuration.
	 */
	public function makeSectionCache($root = null) {
		return new BookSectionCache(
			$root === null ? $this->getWorkDir() : (string)$root,
			__CA_APP_DIR__.'/plugins/bookCreator',
			$this->getEngineIdentity(),
			$this->boolOr('section_cache', true),
			strtolower(trim((string)$this->config->get('section_cache_media_digest'))),
			trim((string)$this->config->get('section_cache_epoch'))
		);
	}

	/**
	 * Name and version of the PDF engine, as the cache fingerprint sees it.
	 *
	 * A version upgrade changes how a page is laid out — 62.3 and 69.0 do not
	 * agree on five declarations of the shipped theme, measured — so an entry
	 * produced by one must never be served for a book rendered by the other.
	 *
	 * Read once per process: it costs a process launch, against a rendering
	 * budget counted in seconds. WeasyPrint is asked; Gotenberg has no version
	 * endpoint the driver relies on, so its URL stands as its identity and an
	 * upgrade of the service has to be declared with section_cache_epoch.
	 */
	public function getEngineIdentity() {
		static $identity = null;
		if ($identity !== null) { return $identity; }

		if ($this->getRendererName() === 'gotenberg') {
			return $identity = 'gotenberg/'.trim((string)$this->config->get('gotenberg_url'));
		}

		$binary = ProcessRunner::locate($this->pathOr('weasyprint_path', 'weasyprint'));
		if ($binary === null) { return $identity = 'weasyprint/unavailable'; }

		$outcome = ProcessRunner::run(escapeshellarg($binary).' --version', 30);
		$version = trim($outcome->stdout.' '.$outcome->stderr);

		// The version string only counts when the command actually ran: a
		// failure must not become an identity of its own that a later, working
		// run would then disagree with.
		return $identity = 'weasyprint/'.($outcome->ran() && $version !== '' ? $version : 'unknown');
	}

	/**
	 * Render options for one book: what the installation knows, plus the page
	 * geometry of the theme and format the book is composed in.
	 *
	 * The geometry is not decoration. WeasyPrint reads the @page rule and would
	 * be right without it, but Gotenberg drives Chromium, whose page setup comes
	 * from the form fields of the request; left unset, RenderOptions defaults to
	 * 210x297mm and every book — a 297x210 landscape catalogue included — leaves
	 * in A4 portrait. Both come from ThemeRegistry, the same source the @page
	 * rule is built from, so the CSS and the form fields cannot drift apart.
	 *
	 * Called without arguments, the defaults of RenderOptions stand: that is only
	 * ever right for a caller that sets the geometry itself.
	 *
	 * @param string|null $theme_code  Theme of the book. Also sets the base URL, since the
	 *                                 stylesheets and the fonts the document references live
	 *                                 in the theme directory.
	 * @param string|null $format_code Page format of the book, as declared by the theme.
	 * @param array       $options     bleed => true for the printer-ready version, which
	 *                                 enlarges the sheet by the bleed of the format.
	 */
	public function makeRenderOptions($theme_code = null, $format_code = null, $options = []) {
		$render_options = new RenderOptions();

		if (strlen((string)$theme_code)) {
			$theme = new ThemeRegistry((string)$theme_code);
			$render_options = $render_options->withBaseUrl($theme->getPath().'/');

			$format = $theme->getFormat((string)$format_code);
			if (is_array($format)) {
				$geometry = [];
				if (isset($format['width']))  { $geometry['pageWidth']  = (string)$format['width']; }
				if (isset($format['height'])) { $geometry['pageHeight'] = (string)$format['height']; }
				if (isset($format['margin'])) { $geometry['margin']     = (string)$format['margin']; }
				if (isset($format['bleed']))  { $geometry['bleedSize']  = (string)$format['bleed']; }

				$render_options = $render_options->with(...$geometry);
			}
		}

		if (isset($options['bleed'])) {
			$render_options = $render_options->with(bleed: (bool)$options['bleed']);
		}
		if (isset($options['crop_marks'])) {
			$render_options = $render_options->with(cropMarks: (bool)$options['crop_marks']);
		}

		$cache = trim((string)$this->config->get('image_cache_dir'));
		if (strlen($cache)) { $render_options = $render_options->with(imageCacheDir: $cache); }

		return $render_options->with(timeoutSeconds: $this->getTimeout());
	}

	/** Which driver is configured, normalised. */
	public function getRendererName() {
		$name = strtolower(trim((string)$this->config->get('renderer')));
		return ($name === 'gotenberg') ? 'gotenberg' : 'weasyprint';
	}

	/**
	 * Whether the whole chain can run, with the reason when it cannot.
	 *
	 * Called before a generation is queued and by the worker on start-up: a
	 * plugin whose renderer is missing must say so before a user waits for a
	 * 200-page catalogue that was never going to be produced. The install
	 * screen does not use it — it answers for the database schema, not for the
	 * rendering chain.
	 *
	 * @return array ['ok' => bool, 'reasons' => string[]]
	 */
	public function checkAvailability() {
		$reasons = [];

		$renderer = $this->makeRenderer();
		if (!$renderer->isAvailable()) {
			$reasons[] = $renderer->getUnavailableReason();
		}

		$assembler = $this->makeAssembler();
		if (!$assembler->isAvailable()) {
			$reasons[] = $assembler->getUnavailableReason();
		}

		return ['ok' => !sizeof($reasons), 'reasons' => array_filter($reasons)];
	}

	# -------------------------------------------------------

	/** A configured path, or the bare command name to be found in PATH. */
	private function pathOr($setting, $default) {
		$value = trim((string)$this->config->get($setting));
		return strlen($value) ? $value : $default;
	}

	/**
	 * A boolean setting, with its default when it is absent.
	 *
	 * A missing key and an explicit 0 read the same through Configuration::get(),
	 * so the empty string has to mean "not set" — otherwise every optional switch
	 * shipped as on would silently be off on installations whose bookCreator.conf
	 * predates it.
	 */
	private function boolOr($setting, $default) {
		$value = trim((string)$this->config->get($setting));
		if (!strlen($value)) { return $default; }

		return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
	}

	private function getTimeout() {
		$timeout = (int)$this->config->get('render_timeout');
		return ($timeout > 0) ? $timeout : 120;
	}
}
