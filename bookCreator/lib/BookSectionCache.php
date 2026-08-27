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
 * Reuse of the PDF of a section that has not changed.
 *
 * A book is rendered one section at a time and assembled with qpdf, so the
 * expensive half of a generation — the PDF engine — is already per section.
 * What was missing is the memory: every generation re-rendered all fifty
 * sections of a catalogue to change one of them, which is what an editor does
 * all day. The assembly itself is not the cost, and never was: it is qpdf
 * copying page objects, a fraction of a second on a 200 page book.
 *
 * The rule this class exists to enforce is the conservative one. A cache that
 * serves a stale section is worse than no cache at all — the book leaves for
 * the printer with an old photograph in it and nothing says so — so anything
 * that cannot be established with certainty makes the fingerprint refuse to
 * answer, and a section with no fingerprint is rendered.
 *
 * WHAT THE FINGERPRINT COVERS
 *
 * Almost everything, because it is taken over the HTML document itself rather
 * than over a list of fields someone has to remember to extend. The document
 * built by BookHtmlBuilder already holds, expanded:
 *
 *   - the Markdown of the section, its intro and its title;
 *   - the layout, through the markup its manifest dictates;
 *   - the identity AND the order of the works of the set, since a lost or
 *     newly forbidden record simply stops being emitted;
 *   - every field of every work and of every caption, since the merge
 *     templates are resolved into the document;
 *   - the theme tokens, the page format and the font pair, inlined as the
 *     :root block by ThemeRegistry;
 *   - the folio the section starts on, as the @page :first counter-reset —
 *     which is what makes a section whose predecessor grew by one page
 *     re-render rather than print a wrong page number.
 *
 * What the document only *references* is added to the fingerprint separately:
 *
 *   - the plates: every local file the document points at contributes its size
 *     and modification time (or its content, see media_digest);
 *   - the theme directory: stylesheets, layout manifests and fonts;
 *   - the plugin's own code and configuration;
 *   - the identity and the version of the PDF engine.
 *
 * WHAT IT DOES NOT COVER — the blind spots, said out loud
 *
 *   - a plate replaced in place with a file of exactly the same size and the
 *     same modification time. Set media_digest = content to close this at the
 *     cost of reading every plate on every generation;
 *   - a theme file restored from a backup that carries its old timestamp and
 *     its old size but different content. Same remedy at theme scale: bump
 *     cache_epoch, or generate once with "regenerate everything";
 *   - anything outside the document that a stylesheet reaches over the network.
 *     Nothing in the shipped theme does, and the PDF chain is deliberately
 *     offline, but a theme that added a remote font would not be seen;
 *   - the Gotenberg driver reports no version, so an upgrade of the service is
 *     invisible to the fingerprint. cache_epoch is the answer there too.
 *
 * A section whose document references a plate the fingerprint cannot resolve —
 * a media derivative missing from disk, which BookHtmlBuilder replaces with an
 * HTTP URL — is never cached: it is rendered, every time, until the derivative
 * comes back.
 *
 * ON DISK
 *
 * One directory per book, one file per section AND per fingerprint:
 *
 *     <work>/cache/book-12/section-345-<fingerprint>.pdf
 *
 * Content addressing rather than a per-job name is what makes this safe next
 * to the incident bookworker_directories() documents: two workers on the same
 * book cannot overwrite each other's work, because a given name can only ever
 * hold one set of bytes. Publishing goes through a temporary file and rename(),
 * which is atomic on a POSIX filesystem, so a reader never sees a half written
 * PDF.
 */
final class BookSectionCache {

	/**
	 * Fingerprint format version.
	 *
	 * Bumped when the composition below changes, so an installation upgrading
	 * the plugin cannot match an entry computed by the previous rules.
	 */
	private const FORMAT = 'bookCreator/section-cache/1';

	/** Subdirectory of the work area holding the cache. */
	private const SUBDIR = 'cache';

	/**
	 * Files under this size are digested by content, larger ones by size and
	 * modification time.
	 *
	 * The split is what keeps the theme honest without paying for it:
	 * stylesheets and layout manifests are a few kilobytes and are read in
	 * full, fonts are megabytes and are only stat()ed. A font whose bytes
	 * change keeps neither its size nor its timestamp in any real deployment.
	 */
	private const CONTENT_DIGEST_MAX_BYTES = 262144;

	/** @var string absolute path of the cache root, without a trailing slash */
	private string $root;

	/** @var string absolute path of the plugin directory */
	private string $pluginDir;

	/** @var string identity of the PDF engine, version included when it has one */
	private string $engineId;

	/** @var string 'stat' or 'content' */
	private string $mediaDigest;

	/** @var string free text from the configuration; changing it invalidates everything */
	private string $epoch;

	/** @var bool */
	private bool $enabled;

	/** @var array<string,string> digests computed during this run, keyed by what they describe */
	private array $memo = [];

	/**
	 * @param string $root        Cache root. Created on demand by store().
	 * @param string $pluginDir   Plugin directory, the one holding lib/ and conf/.
	 * @param string $engineId    Renderer name and version, from PdfRendererFactory.
	 * @param bool   $enabled     False turns every lookup into a miss and stores nothing.
	 * @param string $mediaDigest 'stat' (default) or 'content'.
	 * @param string $epoch       Operator controlled salt, from the configuration.
	 */
	public function __construct(
		string $root,
		string $pluginDir,
		string $engineId = 'unknown',
		bool $enabled = true,
		string $mediaDigest = 'stat',
		string $epoch = ''
	) {
		$this->root        = rtrim($root, '/');
		$this->pluginDir   = rtrim($pluginDir, '/');
		$this->engineId    = $engineId;
		$this->enabled     = $enabled;
		$this->mediaDigest = ($mediaDigest === 'content') ? 'content' : 'stat';
		$this->epoch       = $epoch;
	}

	public function isEnabled(): bool { return $this->enabled; }

	/** Absolute path of the cache root. */
	public function getRoot(): string { return $this->root; }

	# -------------------------------------------------------
	# Fingerprint
	# -------------------------------------------------------

	/**
	 * Fingerprint of everything that goes into the PDF of this section.
	 *
	 * @param string $html      the document BookHtmlBuilder produced for the section
	 * @param string $themeCode theme of the book, whose directory is digested whole
	 * @return string|null null when the section must not be cached, which the
	 *                     caller has to read as "render it".
	 */
	public function fingerprint(string $html, string $themeCode): ?string {
		if (!$this->enabled) { return null; }

		$resources = $this->digestReferencedFiles($html);
		if ($resources === null) { return null; }   // a plate could not be resolved

		return sha1(join("\n", [
			self::FORMAT,
			'epoch='     . $this->epoch,
			'engine='    . $this->engineId,
			'code='      . $this->digestPluginCode(),
			'theme='     . $this->digestTheme($themeCode),
			'document='  . sha1($html),
			'resources=' . $resources,
		]));
	}

	/**
	 * Digest of every local file the document points at, or null when one of
	 * them cannot be read.
	 *
	 * The paths are read back out of the document rather than recomputed from
	 * the records, so this cannot drift from what the builder actually decided
	 * to print: whatever ends up in an src or in a url() is what is digested.
	 *
	 * A reference that is not an absolute local path is a media derivative
	 * missing from disk, which mediaSource() replaces with an HTTP URL. Its
	 * content is unknowable from here, so the whole section becomes uncacheable
	 * rather than being fingerprinted on incomplete information.
	 */
	private function digestReferencedFiles(string $html): ?string {
		$references = [];

		// src="..." — the plates of the layouts that pin a single representation.
		if (preg_match_all('~\bsrc="([^"]+)"~i', $html, $matches)) {
			foreach ($matches[1] as $value) { $references[] = html_entity_decode($value, ENT_QUOTES, 'UTF-8'); }
		}
		// url('...') — the grid layouts place their plates as background images,
		// percent-encoded by BookHtmlBuilder::cssUrl().
		if (preg_match_all("~url\\(\\s*['\"]?([^'\")]+)['\"]?\\s*\\)~i", $html, $matches)) {
			foreach ($matches[1] as $value) { $references[] = rawurldecode(html_entity_decode($value, ENT_QUOTES, 'UTF-8')); }
		}

		$digests = [];
		foreach (array_unique($references) as $reference) {
			$reference = trim($reference);
			if ($reference === '') { continue; }

			// Relative references live inside the theme directory, which is
			// digested as a whole by digestTheme(): the stylesheets and the
			// Paged.js polyfill are declared relative to it.
			if ($reference[0] !== '/') {
				if (preg_match('~^(https?:)?//~i', $reference)) { return null; }
				continue;
			}
			if (!is_file($reference) || !is_readable($reference)) { return null; }

			$digests[] = $reference . '|' . $this->digestFile($reference, $this->mediaDigest === 'content');
		}

		sort($digests);
		return sha1(join("\n", $digests));
	}

	/**
	 * Digest of the theme directory: stylesheets, layout manifests, fonts.
	 *
	 * Deliberately the whole directory rather than the files the document
	 * happens to link. Over-covering only ever costs a rendering; missing a
	 * stylesheet costs a book printed with the wrong gutters.
	 */
	private function digestTheme(string $themeCode): string {
		$key = 'theme:' . $themeCode;
		if (isset($this->memo[$key])) { return $this->memo[$key]; }

		$path = $this->pluginDir . '/themes/' . basename($themeCode);
		return $this->memo[$key] = $this->digestTree($path);
	}

	/**
	 * Digest of the plugin's own code and configuration.
	 *
	 * The document already reflects any change to the builder — it is its
	 * output — but not a change to the arguments the renderer is driven with,
	 * nor to a setting like media_version that decides which derivative is
	 * asked for. Digesting lib/, bin/ and conf/ makes an upgrade of the plugin
	 * re-render every book exactly once, which is the right answer: what else
	 * changed in it cannot be known from here.
	 */
	private function digestPluginCode(): string {
		if (isset($this->memo['code'])) { return $this->memo['code']; }

		$digests = [];
		foreach (['lib/*.php', 'bin/*.php', 'conf/*.conf'] as $pattern) {
			foreach (glob($this->pluginDir . '/' . $pattern) ?: [] as $file) {
				$digests[] = basename($file) . '|' . $this->digestFile($file, true);
			}
		}
		sort($digests);
		return $this->memo['code'] = sha1(join("\n", $digests));
	}

	/** Digest of every file under a directory, recursively. */
	private function digestTree(string $path): string {
		if (!is_dir($path)) { return 'absent:' . $path; }

		$digests = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ($iterator as $entry) {
			if (!$entry->isFile()) { continue; }
			$file = $entry->getPathname();
			$digests[] = substr($file, strlen($path) + 1) . '|'
				. $this->digestFile($file, $entry->getSize() <= self::CONTENT_DIGEST_MAX_BYTES);
		}

		sort($digests);
		return sha1(join("\n", $digests));
	}

	/**
	 * One file, either by content or by size and modification time.
	 *
	 * The timestamp is taken in nanoseconds when the filesystem exposes them:
	 * two derivatives written in the same second by the same reprocessing run
	 * would otherwise be indistinguishable.
	 */
	private function digestFile(string $path, bool $byContent): string {
		if ($byContent) {
			$hash = @sha1_file($path);
			if ($hash !== false) { return 'sha1:' . $hash; }
		}

		$stat = @stat($path);
		if ($stat === false) { return 'unreadable'; }

		$nanoseconds = isset($stat['mtime_nsec']) ? (string)$stat['mtime_nsec'] : '';
		return 'stat:' . $stat['size'] . ':' . $stat['mtime'] . ($nanoseconds !== '' ? '.' . $nanoseconds : '');
	}

	# -------------------------------------------------------
	# Entries
	# -------------------------------------------------------

	/** Directory holding the entries of one book. */
	public function bookDir(int $bookId): string {
		return $this->root . '/' . self::SUBDIR . '/book-' . $bookId;
	}

	/** Absolute path of the entry for one section at one fingerprint. */
	public function pathFor(int $bookId, int $sectionId, string $fingerprint): string {
		return $this->bookDir($bookId) . '/section-' . $sectionId . '-' . $fingerprint . '.pdf';
	}

	/**
	 * The reusable PDF of this section, or null when there is none.
	 *
	 * The file name carries the fingerprint, so a hit is proof in itself: there
	 * is no second place the answer could be read from and disagree.
	 */
	public function lookup(int $bookId, int $sectionId, ?string $fingerprint): ?string {
		if (!$this->enabled || $fingerprint === null) { return null; }

		$path = $this->pathFor($bookId, $sectionId, $fingerprint);
		if (!is_file($path) || !is_readable($path) || filesize($path) === 0) { return null; }

		return $path;
	}

	/**
	 * Publishes a freshly rendered PDF into the cache.
	 *
	 * A hard link when the work area and the cache share a filesystem — the
	 * usual case, both being under the same tmp/ — so a 40 MB catalogue section
	 * is published without copying a byte, and the entry survives the clean-up
	 * that removes the per-job fragments. A copy otherwise. Both land through
	 * rename(), so no reader ever sees a partial file.
	 *
	 * @return string|null the path of the entry, or null when it could not be
	 *                     written; failing to cache is never a reason to fail a
	 *                     generation, so the caller only logs it.
	 */
	public function store(int $bookId, int $sectionId, ?string $fingerprint, string $pdfPath): ?string {
		if (!$this->enabled || $fingerprint === null) { return null; }
		if (!is_file($pdfPath)) { return null; }

		$dir = $this->bookDir($bookId);
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) { return null; }

		$final = $this->pathFor($bookId, $sectionId, $fingerprint);
		if (is_file($final)) { return $final; }   // same bytes by construction

		$temporary = $final . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
		if (!@link($pdfPath, $temporary) && !@copy($pdfPath, $temporary)) { return null; }

		if (!@rename($temporary, $final)) {
			@unlink($temporary);
			return null;
		}
		return $final;
	}

	# -------------------------------------------------------
	# Purge
	# -------------------------------------------------------

	/**
	 * Drops the entries of a book that the generation just finished did not use.
	 *
	 * Steady state is therefore one file per section: the previous fingerprint
	 * of an edited section goes as soon as the new one has been assembled.
	 *
	 * $graceSeconds protects an entry too young to be superseded. reapStale()
	 * can put a second worker on a book while the first is still assembling it,
	 * and that worker's entries are legitimate work in progress rather than
	 * leftovers.
	 *
	 * @param string[] $keep absolute paths the finished book was assembled from
	 * @return int number of files removed
	 */
	public function purge(int $bookId, array $keep, int $graceSeconds = 900): int {
		if (!$this->enabled) { return 0; }

		$keep = array_flip(array_filter($keep, 'strlen'));
		$now = time();
		$removed = 0;

		foreach (glob($this->bookDir($bookId) . '/section-*.pdf') ?: [] as $entry) {
			if (isset($keep[$entry])) { continue; }
			if (($now - (int)@filemtime($entry)) < $graceSeconds) { continue; }
			if (@unlink($entry)) { $removed++; }
		}
		return $removed;
	}

	/**
	 * Removes every entry of a book, and the directory itself.
	 *
	 * Used by the --purge-cache option of the worker and when a book is
	 * deleted. Nothing is lost that a generation cannot rebuild.
	 */
	public function purgeBook(int $bookId): int {
		$removed = 0;
		foreach (glob($this->bookDir($bookId) . '/*') ?: [] as $entry) {
			if (is_file($entry) && @unlink($entry)) { $removed++; }
		}
		@rmdir($this->bookDir($bookId));
		return $removed;
	}

	/** Removes the entries of every book. Returns the number of files removed. */
	public function purgeAll(): int {
		$removed = 0;
		foreach (glob($this->root . '/' . self::SUBDIR . '/book-*', GLOB_ONLYDIR) ?: [] as $dir) {
			if (preg_match('~/book-(\d+)$~', $dir, $matches)) {
				$removed += $this->purgeBook((int)$matches[1]);
			}
		}
		return $removed;
	}
}
