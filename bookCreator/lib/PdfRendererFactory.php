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

/**
 * Builds the rendering chain from bookCreator.conf.
 *
 * The renderers and the assembler deliberately read no configuration
 * themselves, so they stay testable in isolation and can be driven from a CLI
 * worker as easily as from a controller. This class is the single place that
 * turns settings into objects — which also makes it the single place to look at
 * when a deployment renders nothing.
 */
class PdfRendererFactory {

	/** @var Configuration */
	private $config;

	public function __construct($config = null) {
		$this->config = $config ? $config : Configuration::load(
			__CA_APP_DIR__.'/plugins/bookCreator/conf/bookCreator.conf'
		);
	}

	/** The configured renderer, ready to use. */
	public function makeRenderer() {
		$assembler = $this->makeAssembler();

		if ($this->getRendererName() === 'gotenberg') {
			$url = (string)$this->config->get('gotenberg_url');
			return new GotenbergRenderer($url, $assembler);
		}

		$binary = $this->pathOr('weasyprint_path', 'weasyprint');
		return new WeasyPrintRenderer($binary, $assembler);
	}

	/** The qpdf wrapper, shared by the renderer and the assembly step. */
	public function makeAssembler() {
		return new PdfAssembler($this->pathOr('qpdf_path', 'qpdf'), $this->getTimeout());
	}

	/**
	 * Render options carrying what the configuration knows.
	 *
	 * Page geometry and first page number belong to the book being rendered,
	 * not to the installation, so they are left to the caller.
	 */
	public function makeRenderOptions() {
		$options = new RenderOptions();

		$cache = trim((string)$this->config->get('image_cache_dir'));
		if (strlen($cache)) { $options = $options->with(imageCacheDir: $cache); }

		return $options->with(timeoutSeconds: $this->getTimeout());
	}

	/** Which driver is configured, normalised. */
	public function getRendererName() {
		$name = strtolower(trim((string)$this->config->get('renderer')));
		return ($name === 'gotenberg') ? 'gotenberg' : 'weasyprint';
	}

	/**
	 * Whether the whole chain can run, with the reason when it cannot.
	 *
	 * Used by the install screen: a plugin whose renderer is missing should say
	 * so on a configuration page, not when a user has waited for a 200-page
	 * catalogue that was never going to be produced.
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

	private function getTimeout() {
		$timeout = (int)$this->config->get('render_timeout');
		return ($timeout > 0) ? $timeout : 120;
	}
}
