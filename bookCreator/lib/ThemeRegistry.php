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

/**
 * Discovers themes and turns a (theme, format, font pair) choice into CSS.
 *
 * A theme is a self-describing directory under themes/, holding a theme.conf,
 * its own fonts and its own stylesheets. Nothing here knows the name of a
 * single design token: the stylesheets use custom properties, theme.conf
 * declares their values, and this class emits the :root block and the @page
 * rule that bind them at render time. Adding a token, a format or a font pair
 * is therefore a configuration change, never a code change.
 *
 * The same output feeds WeasyPrint and the Paged.js preview, which is what
 * keeps the two consistent.
 */
class ThemeRegistry {

	/** Directory holding the themes, relative to the plugin root. */
	const THEMES_SUBDIR = 'themes';

	/** @var string theme code, ie. its directory name */
	private $code;

	/** @var Configuration parsed theme.conf */
	private $config;

	/** @var string absolute path of the theme directory */
	private $path;

	/**
	 * @param string $code Theme directory name. Falls back to 'default' when the
	 *                     requested theme no longer exists, so a book whose theme
	 *                     was deleted still renders instead of failing.
	 */
	public function __construct($code = 'default') {
		if (!self::exists($code)) { $code = 'default'; }
		$this->code = $code;
		$this->path = self::themesPath().'/'.$code;
		$this->config = Configuration::load($this->path.'/theme.conf');
	}

	# -------------------------------------------------------
	# Discovery
	# -------------------------------------------------------

	/** Absolute path of the themes directory. */
	public static function themesPath() {
		return __CA_APP_DIR__.'/plugins/bookCreator/'.self::THEMES_SUBDIR;
	}

	/** True when the theme directory exists and carries a theme.conf. */
	public static function exists($code) {
		if (!$code || strpos($code, '/') !== false || strpos($code, '.') === 0) { return false; }
		return is_file(self::themesPath().'/'.$code.'/theme.conf');
	}

	/**
	 * All installed themes, as code => display name.
	 *
	 * Read from the filesystem on each call rather than cached: dropping a theme
	 * directory in place is expected to be enough to install it.
	 */
	public static function getThemes() {
		$themes = [];
		$dir = self::themesPath();
		if (!is_dir($dir)) { return $themes; }

		foreach (scandir($dir) as $entry) {
			if ($entry === '.' || $entry === '..' || !self::exists($entry)) { continue; }
			$conf = Configuration::load($dir.'/'.$entry.'/theme.conf');
			$name = $conf->get('name');
			$themes[$entry] = $name ? $name : $entry;
		}
		return $themes;
	}

	# -------------------------------------------------------
	# Theme contents
	# -------------------------------------------------------

	public function getCode() { return $this->code; }

	public function getName() {
		$name = $this->config->get('name');
		return $name ? $name : $this->code;
	}

	public function getPath() { return $this->path; }

	/** Page formats declared by the theme, as code => properties. */
	public function getFormats() {
		$formats = $this->config->getAssoc('formats');
		return is_array($formats) ? $formats : [];
	}

	/**
	 * One page format. Falls back to the first declared format when the
	 * requested one is unknown, so a book keeps rendering after a theme change.
	 */
	public function getFormat($code) {
		$formats = $this->getFormats();
		if (isset($formats[$code])) { return $formats[$code]; }
		return $formats ? reset($formats) : null;
	}

	/** Typographic pairs declared by the theme, as code => properties. */
	public function getFontPairs() {
		$pairs = $this->config->getAssoc('font_pairs');
		return is_array($pairs) ? $pairs : [];
	}

	/** One typographic pair, with the same fallback rule as getFormat(). */
	public function getFontPair($code) {
		$pairs = $this->getFontPairs();
		if (isset($pairs[$code])) { return $pairs[$code]; }
		return $pairs ? reset($pairs) : null;
	}

	/** Design tokens declared by the theme, as name => value. */
	public function getTokens() {
		$tokens = $this->config->getAssoc('tokens');
		return is_array($tokens) ? $tokens : [];
	}

	/** Stylesheets of the theme, relative to its directory. */
	public function getStylesheets() {
		$sheets = $this->config->getList('stylesheets');
		return is_array($sheets) && sizeof($sheets) ? $sheets : ['css/base.css'];
	}

	# -------------------------------------------------------
	# CSS generation
	# -------------------------------------------------------

	/**
	 * Builds the :root block, the @page rule and the @font-face declarations
	 * for one book, from its format and font pair.
	 *
	 * @param string $format_code
	 * @param string $font_pair_code
	 * @param array  $options bleed => true adds 3mm bleed and crop marks, for the
	 *                        printer-ready version.
	 * @return string CSS, ready to be inlined ahead of the theme stylesheets.
	 */
	public function buildRootCss($format_code, $font_pair_code, $options = []) {
		$format = $this->getFormat($format_code);
		$pair   = $this->getFontPair($font_pair_code);
		if (!$format) { return ''; }

		$declarations = [];

		// Page geometry, always exposed so stylesheets can size against it.
		$declarations[] = '--page-width: '.$format['width'];
		$declarations[] = '--page-height: '.$format['height'];

		// Typography. Font families are quoted here so theme.conf stays readable.
		if (is_array($pair)) {
			if (isset($pair['heading']['family'])) {
				$declarations[] = '--font-heading: "'.$pair['heading']['family'].'"';
			}
			if (isset($pair['body']['family'])) {
				$declarations[] = '--font-body: "'.$pair['body']['family'].'"';
			}
		}

		// Every other token, verbatim. The registry never interprets them.
		foreach ($this->getTokens() as $name => $value) {
			$declarations[] = '--'.$name.': '.$value;
		}

		// Per-format token overrides, so a 21x21 booklet can narrow its margins
		// without duplicating the whole theme.
		if (isset($format['tokens']) && is_array($format['tokens'])) {
			foreach ($format['tokens'] as $name => $value) {
				$declarations[] = '--'.$name.': '.$value;
			}
		}

		$css  = ":root {\n\t".join(";\n\t", $declarations).";\n}\n";
		$css .= $this->buildPageRule($format, $options);
		$css .= $this->buildFontFaces($pair);

		return $css;
	}

	/** The @page rule: size, margins, and optionally bleed and crop marks. */
	private function buildPageRule($format, $options = []) {
		$rules = ['size: '.$format['width'].' '.$format['height']];

		if (isset($format['margin'])) {
			$rules[] = 'margin: '.$format['margin'];
		}
		if (caGetOption('bleed', $options, false)) {
			// Printer-ready output: 3mm bleed plus crop marks, both standard
			// CSS Paged Media and supported by WeasyPrint.
			$rules[] = 'bleed: '.(isset($format['bleed']) ? $format['bleed'] : '3mm');
			$rules[] = 'marks: crop cross';
		}

		return "@page {\n\t".join(";\n\t", $rules).";\n}\n";
	}

	/**
	 * @font-face declarations for the pair, pointing at the fonts shipped with
	 * the theme. Paths stay relative to the theme directory so the same CSS
	 * works for the browser preview and for WeasyPrint.
	 */
	private function buildFontFaces($pair) {
		if (!is_array($pair)) { return ''; }

		$css = '';
		foreach (['heading', 'body'] as $role) {
			if (!isset($pair[$role]['family']) || !isset($pair[$role]['files'])) { continue; }

			$family = $pair[$role]['family'];
			$dir = rtrim($pair[$role]['files'], '/');

			foreach ($this->findFontFiles($dir) as $file) {
				$css .= "@font-face {\n";
				$css .= "\tfont-family: \"".$family."\";\n";
				$css .= "\tsrc: url(\"".$dir.'/'.$file['name']."\") format(\"".$file['format']."\");\n";
				$css .= "\tfont-weight: ".$file['weight'].";\n";
				$css .= "\tfont-style: ".$file['style'].";\n";
				$css .= "}\n";
			}
		}
		return $css;
	}

	/**
	 * Font files of a directory, with weight and style guessed from the file
	 * name. Naming follows the usual convention of libre font distributions:
	 * Family-Bold.otf, Family-Italic.woff2, Family-BoldItalic.ttf.
	 */
	private function findFontFiles($dir) {
		$absolute = $this->path.'/'.$dir;
		if (!is_dir($absolute)) { return []; }

		$formats = ['woff2' => 'woff2', 'woff' => 'woff', 'otf' => 'opentype', 'ttf' => 'truetype'];
		$files = [];

		foreach (scandir($absolute) as $entry) {
			$extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
			if (!isset($formats[$extension])) { continue; }

			$name = pathinfo($entry, PATHINFO_FILENAME);
			$files[] = [
				'name'   => $entry,
				'format' => $formats[$extension],
				'weight' => (stripos($name, 'bold') !== false) ? 'bold' : 'normal',
				'style'  => (stripos($name, 'italic') !== false) ? 'italic' : 'normal',
			];
		}
		return $files;
	}
}
