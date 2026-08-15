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
 * Turns the Markdown of a section into HTML, through either parser.
 *
 * Two implementations are kept side by side on purpose, selected by the
 * markdown_parser setting:
 *
 *  - parsedown  : ParsedownExtra 0.7 / Parsedown 1.6, vendored by hand and
 *                 unmaintained. This is what produced every book printed so
 *                 far, so it is the reference during the acceptance run.
 *  - commonmark : league/commonmark 2.x, maintained and spec-compliant.
 *
 * Keeping both allows the three outputs the acceptance run compares: the
 * original chain, the new chain with the old parser, and the new chain with
 * the new one. Comparing the first two isolates the PDF chain; comparing the
 * last two isolates the parser. Merging the change into a single output would
 * make any difference impossible to attribute.
 *
 * Measured on the 80 sections of the Floutier catalogue (15/08/2026): 67 render
 * identically once whitespace that HTML collapses anyway is neutralised. The 13
 * that differ come from Markdown that Parsedown tolerated and CommonMark reads
 * to the letter — unclosed emphasis, an ATX heading without its space. They are
 * content to be fixed, not a parser to be configured around.
 */
class MarkdownRenderer {

	const PARSER_PARSEDOWN  = 'parsedown';
	const PARSER_COMMONMARK = 'commonmark';

	/** @var string selected parser */
	private $parser;

	/** @var object lazily built parser instance */
	private $engine = null;

	/**
	 * @param string|null $parser Forces a parser, bypassing the configuration.
	 *                            Used by the acceptance run to produce both
	 *                            versions from the same content.
	 */
	public function __construct($parser = null) {
		if ($parser === null) {
			$config = Configuration::load(__CA_APP_DIR__.'/plugins/bookCreator/conf/bookCreator.conf');
			$parser = $config->get('markdown_parser');
		}
		$this->parser = ($parser === self::PARSER_COMMONMARK)
			? self::PARSER_COMMONMARK
			: self::PARSER_PARSEDOWN;   // anything unknown falls back to the reference parser
	}

	/** Which parser this instance uses. */
	public function getParser() { return $this->parser; }

	/** Renders Markdown to HTML. */
	public function render($markdown) {
		if (!strlen((string)$markdown)) { return ''; }

		if ($this->parser === self::PARSER_COMMONMARK) {
			return (string)$this->commonMark()->convert($markdown);
		}
		return $this->parsedown()->text($markdown);
	}

	# -------------------------------------------------------

	/** ParsedownExtra, vendored under lib/. */
	private function parsedown() {
		if ($this->engine === null) {
			require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/parsedown/Parsedown.php');
			require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/parsedown-extra/ParsedownExtra.php');
			$this->engine = new ParsedownExtra();
		}
		return $this->engine;
	}

	/**
	 * league/commonmark, configured to stay as close as possible to the
	 * Parsedown output.
	 *
	 * Only the Autolink extension actually changes the result on the current
	 * corpus; the other three are enabled for the features the plan calls for.
	 * The defaults are deliberately left alone — in particular soft_break, whose
	 * default "\n" matches Parsedown's breaksEnabled = false. Forcing "<br />"
	 * there, which looks like the obvious move, degrades the match from 67 to 54
	 * sections out of 80.
	 */
	private function commonMark() {
		if ($this->engine === null) {
			require_once(__CA_APP_DIR__.'/plugins/bookCreator/vendor/autoload.php');

			$environment = new \League\CommonMark\Environment\Environment([
				'html_input'         => 'allow',   // Parsedown runs with safeMode off
				'allow_unsafe_links' => true,
			]);
			$environment->addExtension(new \League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension());
			$environment->addExtension(new \League\CommonMark\Extension\Autolink\AutolinkExtension());
			$environment->addExtension(new \League\CommonMark\Extension\Table\TableExtension());
			$environment->addExtension(new \League\CommonMark\Extension\Attributes\AttributesExtension());
			$environment->addExtension(new \League\CommonMark\Extension\Footnote\FootnoteExtension());

			$this->engine = new \League\CommonMark\MarkdownConverter($environment);
		}
		return $this->engine;
	}
}
