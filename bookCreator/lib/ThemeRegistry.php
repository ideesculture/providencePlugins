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

		// Page geometry comes last, because it is authoritative: a theme may
		// declare a page-margin token as documentation of its A4 value, but the
		// margin actually applied is the one of the format in use, and the
		// stylesheets derive the composition area from it. Emitting it earlier
		// would let the theme token win and skew every grid on any other format.
		$declarations[] = '--page-width: '.$format['width'];
		$declarations[] = '--page-height: '.$format['height'];
		if (isset($format['margin'])) {
			$declarations[] = '--page-margin: '.$format['margin'];
		}

		$css  = ":root {\n\t".join(";\n\t", $declarations).";\n}\n";
		$css .= $this->buildPageRule($format, $options, $pair);
		$css .= $this->buildFontFaces($pair);

		return $css;
	}

	/**
	 * The @page rules: size, margins, optional bleed, and the margin boxes that
	 * carry the folio and the running heads.
	 *
	 * Folios and running heads belong here rather than in the stylesheets:
	 * margin boxes only exist inside @page, and the stylesheets have no way to
	 * reach them. base.css does its half of the work by setting string-set on
	 * the headings and assigning named pages to the front matter.
	 *
	 * Values are inlined rather than read through var(): custom properties
	 * declared on :root are not reliably visible from margin boxes across
	 * engines, and a folio silently failing to print is exactly the kind of
	 * defect that only surfaces on the printed copy.
	 */
	private function buildPageRule($format, $options = [], $pair = null) {
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

		$css = "@page {\n\t".join(";\n\t", $rules).";\n}\n";

		// A section rendered on its own is a document of its own, and CSS Paged
		// Media makes the first page of a document a :right page whatever the
		// page counter says (Paged Media 3, §4.1: the parity follows the page
		// progression, not counter(page); the counter-reset emitted by the HTML
		// builder does not move it). So a section that starts on page 12 — a
		// left-hand page in the assembled book — is laid out by the renderer as
		// a right-hand one, and its folio lands in the outer corner of the wrong
		// side. Half the sections of a book come out mirrored.
		//
		// Passing the parity of first_page and swapping the two rules puts the
		// furniture back where the binding expects it.
		$first_page = (int)caGetOption('first_page', $options, 0);
		$mirrored   = ($first_page > 0 && $first_page % 2 === 0);
		$css .= $this->buildMarginBoxes($mirrored, $pair);

		return $css;
	}

	/**
	 * Folio and running head, mirrored for left and right pages.
	 *
	 * @param bool $mirrored swap the two rules, for a section whose first page
	 *                       is even; see buildPageRule().
	 */
	private function buildMarginBoxes($mirrored = false, $pair = null) {
		$tokens = $this->getTokens();
		$size  = isset($tokens['font-size-folio']) ? $tokens['font-size-folio'] : '9pt';
		$color = isset($tokens['color-folio']) ? $tokens['color-folio'] : '#1a1a1a';

		// The family, spelled out. A margin box is not a descendant of body, so
		// it inherits nothing from it: without this declaration WeasyPrint falls
		// back to its own default and every folio and every running head of the
		// book prints in Times New Roman, inside a catalogue set in Garamond.
		// Measured on WeasyPrint 69, and invisible until the proof comes back
		// from the printer. Inlined for the same reason as the size and the
		// colour above: custom properties declared on :root are not reliably
		// visible from inside a margin box.
		$family = 'serif';
		if (is_array($pair) && isset($pair['body']['family'])) {
			$family = '"'.$pair['body']['family'].'", serif';
		}

		// line-height and font-weight for the same reason as the family: a margin
		// box inherits nothing from body, so it falls back to the initial value
		// — `normal` and 400. Invisible with the theme as shipped, whose body
		// weight is 400, and a trap that reopens for any theme setting a
		// different text weight or leading.
		$line   = isset($tokens['line-height']) ? $tokens['line-height'] : '1.2';
		$weight = isset($tokens['font-weight-body']) ? $tokens['font-weight-body'] : '400';

		$style = "font-family: {$family}; font-size: {$size}; line-height: {$line}; "
			."font-weight: {$weight}; font-style: normal; color: {$color};";

		// Which selector carries the left-hand page furniture.
		$verso = $mirrored ? ':right' : ':left';
		$recto = $mirrored ? ':left' : ':right';

		$css  = "@page {$verso} {\n";
		$css .= "\t@bottom-left { content: counter(page); {$style} }\n";
		$css .= "\t@top-left { content: string(chapter); {$style} }\n";
		$css .= "}\n";

		$css .= "@page {$recto} {\n";
		$css .= "\t@bottom-right { content: counter(page); {$style} }\n";
		$css .= "\t@top-right { content: string(chapter); {$style} }\n";
		$css .= "}\n";

		// Front matter, blank pages and full-bleed plates carry no furniture.
		// base.css assigns these named pages to the layouts concerned.
		foreach (['liminaire', 'blanche', 'pleine-page'] as $named) {
			$css .= "@page {$named} {\n";
			$css .= "\t@bottom-left { content: none }\n";
			$css .= "\t@bottom-right { content: none }\n";
			$css .= "\t@bottom-center { content: none }\n";
			$css .= "\t@top-left { content: none }\n";
			$css .= "\t@top-right { content: none }\n";
			$css .= "}\n";
		}

		return $css;
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
