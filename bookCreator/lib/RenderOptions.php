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

/**
 * Everything a renderer needs to know about one render, and nothing else.
 *
 * This is a value object: it is built by the caller (worker, controller, test),
 * passed to a PdfRendererInterface implementation, and never mutated. It holds
 * no CollectiveAccess dependency and reads no configuration file, so the whole
 * render chain can be exercised from a plain PHP script.
 *
 * Two families of settings live here for two different reasons:
 *
 *  - page geometry (size, margins, bleed, crop marks, first page number) is
 *    normally expressed in the CSS the theme generates, and WeasyPrint reads it
 *    from there. It is carried here because Gotenberg drives Chromium, whose
 *    page setup comes from form fields rather than from @page, and because the
 *    HTML generator needs the same numbers to build that CSS. Keeping one
 *    source for both sides is what keeps the two drivers comparable.
 *
 *  - process settings (timeout, image cache folder, image optimisation) belong
 *    to the run, not to the document.
 *
 * Lengths are CSS lengths, given as strings with their unit ("210mm", "0.5in").
 * toInches() converts them for drivers that only speak numbers.
 */
final class RenderOptions {

	/** Conversion factors to inches, per CSS absolute unit. */
	private const UNITS_TO_INCH = [
		'in' => 1.0,
		'mm' => 1.0 / 25.4,
		'cm' => 1.0 / 2.54,
		'pt' => 1.0 / 72.0,
		'pc' => 1.0 / 6.0,
		'px' => 1.0 / 96.0,
		'q'  => 1.0 / 101.6,
	];

	/**
	 * @param string      $pageWidth       Trim width as a CSS length.
	 * @param string      $pageHeight      Trim height as a CSS length.
	 * @param string      $margin          Page margin as a CSS length, applied to all four sides.
	 * @param bool        $bleed           Printer-ready output: extends the page by $bleedSize.
	 * @param string      $bleedSize       Bleed width as a CSS length, ignored unless $bleed.
	 * @param bool        $cropMarks       Adds crop and cross marks, ignored unless $bleed.
	 * @param int|null    $firstPageNumber Page number of the first page of this fragment. Sections
	 *                                     are rendered one by one and numbered from the running
	 *                                     total of the ones before, so the caller injects
	 *                                     counterResetCss() into the document it renders. null
	 *                                     leaves the numbering alone (page 1).
	 * @param int         $timeoutSeconds  Wall clock budget for one render. Past it the driver
	 *                                     kills the process, or gives up on the HTTP call, and
	 *                                     returns a failed RenderResult.
	 * @param string|null $imageCacheDir   Directory WeasyPrint uses to keep its image cache on
	 *                                     disk instead of in memory (--cache-folder). Worth
	 *                                     setting for image-heavy catalogues; created by the
	 *                                     renderer if missing. null keeps the cache in memory.
	 * @param string|null $baseUrl         Base for relative URLs in the HTML. null lets the
	 *                                     driver default to the directory of the HTML file,
	 *                                     which is the right answer in almost every case.
	 * @param bool        $optimizeImages  Lossless recompression of embedded images. Cheap on
	 *                                     time, worth it on the final PDF weight.
	 * @param int|null    $imageDpi        Caps the resolution of embedded images. null embeds
	 *                                     them as they are; 300 is the usual print ceiling.
	 * @param int|null    $jpegQuality     JPEG quality between 0 and 95. null leaves originals
	 *                                     untouched.
	 * @param array|null  $assets          Files to send alongside the HTML, as
	 *                                     "relative/path/in/document" => "/absolute/path". Only
	 *                                     used by drivers that render out of process, Gotenberg
	 *                                     today; a local driver reads them straight from disk.
	 *                                     null asks the driver to collect them itself, [] sends
	 *                                     none.
	 * @param string|null $mediaType       CSS media type, "print" by default in WeasyPrint.
	 */
	public function __construct(
		public readonly string $pageWidth = '210mm',
		public readonly string $pageHeight = '297mm',
		public readonly string $margin = '12mm',
		public readonly bool $bleed = false,
		public readonly string $bleedSize = '3mm',
		public readonly bool $cropMarks = true,
		public readonly ?int $firstPageNumber = null,
		public readonly int $timeoutSeconds = 300,
		public readonly ?string $imageCacheDir = null,
		public readonly ?string $baseUrl = null,
		public readonly bool $optimizeImages = true,
		public readonly ?int $imageDpi = null,
		public readonly ?int $jpegQuality = null,
		public readonly ?array $assets = null,
		public readonly ?string $mediaType = 'print',
	) {}

	# -------------------------------------------------------
	# Derived values
	# -------------------------------------------------------

	/**
	 * The counter-reset rule that numbers this fragment from its real first page.
	 *
	 * The rule is scoped to :first. Written against plain @page it would apply to
	 * every page of the fragment, which prints the same folio throughout — a
	 * mistake with no visible symptom until someone reads the PDF. Verified
	 * against WeasyPrint 69: with counter-reset: page 5 on :first, a five page
	 * section folios 5 to 9. The value is the page number itself, not one less,
	 * because the reset happens after the counter has been incremented for that
	 * page.
	 *
	 * Caveat for the Gotenberg driver: Chromium ignores this, so a fragment
	 * rendered there always numbers from 1.
	 *
	 * The caller inlines the returned rule with the theme CSS; renderers never
	 * touch the document.
	 *
	 * @return string A @page rule, or an empty string when numbering starts at 1.
	 */
	public function counterResetCss(): string {
		if ($this->firstPageNumber === null || $this->firstPageNumber <= 1) { return ''; }
		return "@page :first { counter-reset: page ".$this->firstPageNumber."; }\n";
	}

	/**
	 * Same options, renumbered. Used by the worker as it walks the sections in
	 * sort order and accumulates page counts.
	 */
	public function withFirstPageNumber(?int $page): self {
		return $this->with(firstPageNumber: $page);
	}

	/** Same options, with another base URL. */
	public function withBaseUrl(?string $url): self {
		return $this->with(baseUrl: $url);
	}

	/**
	 * Copy constructor with named overrides. Anything left out is carried over.
	 * PHP has no "clone with" before 8.5, so the list is written out once here
	 * rather than at every call site.
	 */
	public function with(
		?string $pageWidth = null,
		?string $pageHeight = null,
		?string $margin = null,
		?bool $bleed = null,
		?string $bleedSize = null,
		?bool $cropMarks = null,
		int|false|null $firstPageNumber = false,
		?int $timeoutSeconds = null,
		string|false|null $imageCacheDir = false,
		string|false|null $baseUrl = false,
		?bool $optimizeImages = null,
		int|false|null $imageDpi = false,
		int|false|null $jpegQuality = false,
		array|false|null $assets = false,
		string|false|null $mediaType = false,
	): self {
		// false means "not given" for the nullable fields, so that passing null
		// stays a way of clearing them.
		return new self(
			pageWidth: $pageWidth ?? $this->pageWidth,
			pageHeight: $pageHeight ?? $this->pageHeight,
			margin: $margin ?? $this->margin,
			bleed: $bleed ?? $this->bleed,
			bleedSize: $bleedSize ?? $this->bleedSize,
			cropMarks: $cropMarks ?? $this->cropMarks,
			firstPageNumber: $firstPageNumber === false ? $this->firstPageNumber : $firstPageNumber,
			timeoutSeconds: $timeoutSeconds ?? $this->timeoutSeconds,
			imageCacheDir: $imageCacheDir === false ? $this->imageCacheDir : $imageCacheDir,
			baseUrl: $baseUrl === false ? $this->baseUrl : $baseUrl,
			optimizeImages: $optimizeImages ?? $this->optimizeImages,
			imageDpi: $imageDpi === false ? $this->imageDpi : $imageDpi,
			jpegQuality: $jpegQuality === false ? $this->jpegQuality : $jpegQuality,
			assets: $assets === false ? $this->assets : $assets,
			mediaType: $mediaType === false ? $this->mediaType : $mediaType,
		);
	}

	/**
	 * Page width including bleed on both edges, as a number of inches.
	 * Drivers that size the page themselves ask for this rather than for the
	 * trim size, since bleed is part of the sheet they must produce.
	 */
	public function paperWidthInches(): ?float {
		return $this->sheetInches($this->pageWidth);
	}

	/** Page height including bleed, in inches. */
	public function paperHeightInches(): ?float {
		return $this->sheetInches($this->pageHeight);
	}

	/** Margin in inches, null when it cannot be read as an absolute length. */
	public function marginInches(): ?float {
		return self::toInches($this->margin);
	}

	private function sheetInches(string $length): ?float {
		$size = self::toInches($length);
		if ($size === null) { return null; }
		if (!$this->bleed) { return $size; }

		$bleed = self::toInches($this->bleedSize);
		return $bleed === null ? $size : $size + (2 * $bleed);
	}

	/**
	 * Converts a CSS absolute length to inches.
	 *
	 * Relative units (em, %, vw) have no meaning outside a rendered document and
	 * return null; the caller decides what to do about it, usually falling back
	 * on the driver's own default rather than guessing.
	 */
	public static function toInches(string $length): ?float {
		$length = strtolower(trim($length));
		if ($length === '') { return null; }

		if (!preg_match('/^([+-]?[0-9]*\.?[0-9]+)\s*([a-z]*)$/', $length, $m)) { return null; }

		$value = (float)$m[1];
		$unit  = $m[2] === '' ? 'px' : $m[2];   // a bare number is CSS pixels

		if (!isset(self::UNITS_TO_INCH[$unit])) { return null; }
		return $value * self::UNITS_TO_INCH[$unit];
	}
}
