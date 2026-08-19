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
require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/ThemeRegistry.php');

/**
 * Discovers the page layouts of a theme and resolves their merge templates.
 *
 * A layout is a directory under themes/<theme>/templates/, holding a
 * manifest.conf, an HTML skeleton and a picker thumbnail. Two consequences,
 * both deliberate:
 *
 * 1. A layout is calibrated for one or more page formats, it does not adapt to
 *    them. The production grids are tuned by hand against the usable height of
 *    A4 landscape — 3 x (7.4 + 1 + 0.9) cm for 28.2 cm available — so the
 *    number of works per page is arithmetic, not a setting. Six per page in A4
 *    landscape, at most four in a 21x21 booklet: those are different layouts.
 *    getTemplates() therefore filters on the format of the book, and never
 *    offers a layout its format cannot hold.
 *
 * 2. Field mapping lives in the manifest as CollectiveAccess display templates,
 *    not in PHP. Adapting the module to a client's profile is configuration.
 *    Each merge template can be overridden per installation from
 *    bookCreator.conf, which is what keeps the Floutier field names out of the
 *    shipped code.
 */
class TemplateRegistry {

	/** @var ThemeRegistry */
	private $theme;

	/** @var Configuration plugin configuration, holding per-install overrides */
	private $config;

	/** @var array manifest cache, keyed by layout code */
	private $manifests = [];

	public function __construct($theme_code = 'default') {
		$this->theme = new ThemeRegistry($theme_code);
		$this->config = Configuration::load(__CA_APP_DIR__.'/plugins/bookCreator/conf/bookCreator.conf');
	}

	/** Absolute path of the templates directory of the current theme. */
	public function templatesPath() {
		return $this->theme->getPath().'/templates';
	}

	/**
	 * Layouts of the theme, as code => manifest.
	 *
	 * @param string|null $format Page format code. When given, only layouts
	 *                            calibrated for it are returned. A layout that
	 *                            declares no format is considered format-neutral
	 *                            — a blank page or a colophon fits anywhere.
	 */
	public function getTemplates($format = null) {
		$templates = [];
		$dir = $this->templatesPath();
		if (!is_dir($dir)) { return $templates; }

		foreach (scandir($dir) as $entry) {
			if ($entry === '.' || $entry === '..') { continue; }
			if (!is_file($dir.'/'.$entry.'/manifest.conf')) { continue; }

			$manifest = $this->getTemplate($entry);
			if (!$manifest) { continue; }
			if ($format !== null && !$this->supportsFormat($manifest, $format)) { continue; }

			$templates[$entry] = $manifest;
		}
		return $templates;
	}

	/** One layout manifest, or null when the layout does not exist. */
	public function getTemplate($code) {
		if (isset($this->manifests[$code])) { return $this->manifests[$code]; }
		if (!$code || strpos($code, '/') !== false || strpos($code, '.') === 0) { return null; }

		$path = $this->templatesPath().'/'.$code.'/manifest.conf';
		if (!is_file($path)) { return null; }

		$conf = Configuration::load($path);
		$formats = $conf->getList('formats');
		$options = $conf->getAssoc('options');

		return $this->manifests[$code] = [
			'code'         => $code,
			'label'        => $conf->get('label') ? $conf->get('label') : $code,
			'section_type' => $conf->get('section_type') ? $conf->get('section_type') : 'text',
			'formats'      => is_array($formats) ? $formats : [],
			'css'          => $conf->get('css'),
			'show_title'   => $conf->get('show_title'),
			'outer_container' => $conf->get('outer_container'),
			'text_container'  => $conf->get('text_container'),
			'media_container' => $conf->get('media_container'),
			// Dérivée à utiliser pour la planche du gabarit, quand elle diffère de
			// celle de l'instance : la planche pleine page a besoin d'une version
			// plus grande que les vignettes des grilles.
			'media_version'   => $conf->get('media_version'),
			'options'      => is_array($options) ? $options : [],
			'path'         => $this->templatesPath().'/'.$code,
		];
	}

	/** True when the layout is calibrated for this page format. */
	public function supportsFormat($manifest, $format) {
		if (!is_array($manifest)) { return false; }
		// No declared format means format-neutral, not "no format".
		if (!sizeof($manifest['formats'])) { return true; }
		return in_array($format, $manifest['formats']);
	}

	/**
	 * The display template used to render one part of a layout.
	 *
	 * Resolution order, first match wins:
	 *   1. bookCreator.conf, block merge_templates, key "<layout>.<part>"
	 *      — per-installation override, so a client's field names never reach
	 *        the shipped code;
	 *   2. bookCreator.conf, block merge_templates, key "<part>"
	 *      — installation-wide default for that part;
	 *   3. the layout manifest.
	 *
	 * @return string|null a CollectiveAccess display template, to be passed to
	 *                     getWithTemplate()
	 */
	public function getMergeTemplate($code, $part) {
		$overrides = $this->config->getAssoc('merge_templates');
		if (is_array($overrides)) {
			if (isset($overrides[$code.'.'.$part])) { return $overrides[$code.'.'.$part]; }
			if (isset($overrides[$part])) { return $overrides[$part]; }
		}

		$path = $this->templatesPath().'/'.$code.'/manifest.conf';
		if (!is_file($path)) { return null; }

		$manifest_templates = Configuration::load($path)->getAssoc('merge_templates');
		return (is_array($manifest_templates) && isset($manifest_templates[$part]))
			? $manifest_templates[$part]
			: null;
	}

	/**
	 * Value of a per-section option, falling back to the manifest default.
	 *
	 * @param array $section_options options stored on the section, decoded from
	 *                               its JSON column
	 */
	public function getOptionValue($code, $option, $section_options = []) {
		if (isset($section_options[$option])) { return $section_options[$option]; }

		$manifest = $this->getTemplate($code);
		if ($manifest && isset($manifest['options'][$option]['default'])) {
			return $manifest['options'][$option]['default'];
		}
		return null;
	}


}
