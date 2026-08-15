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
 * Self-contained schema installer for the bookCreator plugin.
 *
 * The plugin owns its two tables and never touches the CollectiveAccess core
 * schema. On every controller call the current state is compared against the
 * expected one; when something is missing the user is sent to the install
 * controller, which applies what is needed and comes back.
 *
 * Design rules, deliberate:
 *  - additive only. Columns are created, never modified, never dropped. Legacy
 *    columns left over from v1 (sectiontype, parent_id, object_id, date) stay
 *    untouched and unused; removing them is a manual DBA decision.
 *  - no version table. The state is read from INFORMATION_SCHEMA, so a database
 *    edited by hand is detected and repaired on the next page load.
 *  - column presence only, never column types. MariaDB reports a JSON column as
 *    "longtext", so comparing types would loop forever on a schema that is in
 *    fact correct.
 */
class BookSchemaManager {

	/** Tables owned by the plugin, with every column the v2 code expects. */
	const EXPECTED = [
		'plugin_books' => [
			'book_id'       => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
			'idno'          => 'VARCHAR(255) DEFAULT NULL',
			'title'         => 'TEXT DEFAULT NULL',
			'subtitle'      => 'VARCHAR(1024) DEFAULT NULL',
			'description'   => 'LONGTEXT DEFAULT NULL',
			'theme'         => "VARCHAR(255) NOT NULL DEFAULT 'default'",
			'font_pair'     => "VARCHAR(255) NOT NULL DEFAULT 'default'",
			'page_format'   => "VARCHAR(64) NOT NULL DEFAULT 'a4-landscape'",
			'cover_pdf'     => 'VARCHAR(1024) DEFAULT NULL',
			'backcover_pdf' => 'VARCHAR(1024) DEFAULT NULL',
			'locale_id'     => 'SMALLINT UNSIGNED DEFAULT NULL',
			'created_on'    => 'INT UNSIGNED DEFAULT NULL',
			'modified_on'   => 'INT UNSIGNED DEFAULT NULL',
		],
		'plugin_booksections' => [
			'booksection_id'    => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
			'book_id'           => 'INT DEFAULT NULL',
			'sort'              => 'INT UNSIGNED DEFAULT 0',
			'title'             => 'TEXT DEFAULT NULL',
			'style'             => "VARCHAR(50) DEFAULT ''",
			'content'           => 'LONGTEXT DEFAULT NULL',
			'intro'             => 'LONGTEXT DEFAULT NULL',
			'set_id'            => 'INT DEFAULT NULL',
			'representation_id' => 'INT DEFAULT NULL',
			'pages'             => 'INT DEFAULT NULL',
			'first_page'        => 'INT DEFAULT NULL',
			'is_in_summary'     => 'TINYINT(1) DEFAULT 0',
			// v2 additions
			'options'      => 'LONGTEXT DEFAULT NULL',
			'content_hash' => 'CHAR(40) DEFAULT NULL',
			'rendered_on'  => 'INT UNSIGNED DEFAULT NULL',
		],
	];

	/** Primary key of each table, used when creating it from scratch. */
	const PRIMARY_KEYS = [
		'plugin_books'        => 'book_id',
		'plugin_booksections' => 'booksection_id',
	];

	/** Indexes the plugin needs. Name => [table, columns]. */
	const EXPECTED_INDEXES = [
		'i_book' => ['plugin_booksections', '(book_id, sort)'],
	];

	/**
	 * Columns whose DEFAULT must be NULL, the only exception to the
	 * additive-only rule.
	 *
	 * v1 shipped `pages DEFAULT 0` and `first_page DEFAULT 1`, which makes a
	 * never-rendered section indistinguishable from one rendered to zero page —
	 * a real case for an empty section, or one whose objects were all deleted.
	 * The statement only changes the default: existing values are untouched,
	 * and they get recomputed at the next generation anyway.
	 */
	const EXPECTED_NULL_DEFAULTS = [
		'plugin_booksections' => ['pages' => 'INT DEFAULT NULL', 'first_page' => 'INT DEFAULT NULL'],
	];

	const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

	/** @var Db */
	private $db;

	/**
	 * State cache, for the current request only.
	 *
	 * The check runs on every controller call, and each run costs a handful of
	 * INFORMATION_SCHEMA queries. Caching within the request avoids repeating
	 * them; deliberately not persisted, so a database altered by hand between
	 * two page loads is still detected and repaired.
	 */
	private $state_cache = null;

	public function __construct($db = null) {
		$this->db = $db ? $db : new Db();
	}

	/**
	 * Reads the current state of the two tables.
	 *
	 * Returns an array with 'missing_tables', 'missing_columns' and
	 * 'missing_indexes'. All three empty means the schema is usable.
	 */
	public function getState() {
		if ($this->state_cache !== null) { return $this->state_cache; }

		$state = ['missing_tables' => [], 'missing_columns' => [], 'missing_indexes' => [], 'wrong_defaults' => []];

		$existing_tables = $this->getExistingTables();
		foreach (self::EXPECTED as $table => $columns) {
			if (!in_array($table, $existing_tables)) {
				$state['missing_tables'][] = $table;
				continue;   // no point listing columns of a table that does not exist
			}
			$existing_columns = $this->getExistingColumns($table);
			foreach (array_keys($columns) as $column) {
				if (!in_array($column, $existing_columns)) {
					$state['missing_columns'][] = ['table' => $table, 'column' => $column];
				}
			}
		}

		foreach (self::EXPECTED_INDEXES as $index => $spec) {
			list($table, $columns) = $spec;
			if (in_array($table, $existing_tables) && !$this->indexExists($table, $index)) {
				$state['missing_indexes'][] = ['table' => $table, 'index' => $index, 'columns' => $columns];
			}
		}

		foreach (self::EXPECTED_NULL_DEFAULTS as $table => $columns) {
			if (!in_array($table, $existing_tables)) { continue; }
			foreach ($columns as $column => $definition) {
				if ($this->hasNonNullDefault($table, $column)) {
					$state['wrong_defaults'][] = ['table' => $table, 'column' => $column, 'definition' => $definition];
				}
			}
		}

		return $this->state_cache = $state;
	}

	/** Drops the cached state, so the next read hits the database again. */
	public function refresh() {
		$this->state_cache = null;
	}

	/** True when nothing is missing and the plugin can run. */
	public function isUsable() {
		$state = $this->getState();
		return !$state['missing_tables'] && !$state['missing_columns']
			&& !$state['missing_indexes'] && !$state['wrong_defaults'];
	}

	/**
	 * Applies whatever getState() reported as missing.
	 *
	 * Additive only: CREATE TABLE, ADD COLUMN, ADD INDEX. Returns the list of
	 * statements applied, so the install view can show exactly what was done.
	 */
	public function install() {
		$applied = [];
		$state = $this->getState();

		foreach ($state['missing_tables'] as $table) {
			$sql = $this->buildCreateTable($table);
			$this->db->query($sql);
			$applied[] = $sql;
		}

		// A table just created already has every column, so re-read the state
		// from the database rather than from the now stale cache.
		if ($state['missing_tables']) {
			$this->refresh();
			$state = $this->getState();
		}

		foreach ($state['missing_columns'] as $missing) {
			$definition = self::EXPECTED[$missing['table']][$missing['column']];
			$sql = "ALTER TABLE `{$missing['table']}` ADD COLUMN `{$missing['column']}` {$definition}";
			$this->db->query($sql);
			$applied[] = $sql;
		}

		foreach ($state['missing_indexes'] as $missing) {
			$sql = "ALTER TABLE `{$missing['table']}` ADD INDEX `{$missing['index']}` {$missing['columns']}";
			$this->db->query($sql);
			$applied[] = $sql;
		}

		foreach ($state['wrong_defaults'] as $wrong) {
			$sql = "ALTER TABLE `{$wrong['table']}` MODIFY `{$wrong['column']}` {$wrong['definition']}";
			$this->db->query($sql);
			$applied[] = $sql;
		}

		// Whatever happened, the cached state no longer describes the database.
		$this->refresh();

		return $applied;
	}

	/**
	 * Human readable summary of what is missing, for the install view.
	 */
	public function describeState($state = null) {
		if ($state === null) { $state = $this->getState(); }
		$lines = [];
		foreach ($state['missing_tables'] as $table) {
			$lines[] = _t('Table %1 is missing and will be created.', $table);
		}
		foreach ($state['missing_columns'] as $missing) {
			$lines[] = _t('Column %1 is missing from table %2 and will be added.', $missing['column'], $missing['table']);
		}
		foreach ($state['missing_indexes'] as $missing) {
			$lines[] = _t('Index %1 is missing from table %2 and will be added.', $missing['index'], $missing['table']);
		}
		foreach ($state['wrong_defaults'] as $wrong) {
			$lines[] = _t('Column %1 of table %2 must default to NULL and will be altered.', $wrong['column'], $wrong['table']);
		}
		return $lines;
	}

	# -------------------------------------------------------
	# Introspection
	# -------------------------------------------------------

	/** Names of the plugin tables that already exist in the current database. */
	private function getExistingTables() {
		$tables = [];
		$expected = array_keys(self::EXPECTED);
		$placeholders = join(', ', array_fill(0, sizeof($expected), '?'));
		$qr = $this->db->query(
			"SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
			$expected
		);
		if (!$qr) { return $tables; }
		while ($qr->nextRow()) { $tables[] = $qr->get('TABLE_NAME'); }
		return $tables;
	}

	/** Column names of a given table. */
	private function getExistingColumns($table) {
		$columns = [];
		$qr = $this->db->query(
			"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
			[$table]
		);
		if (!$qr) { return $columns; }
		while ($qr->nextRow()) { $columns[] = $qr->get('COLUMN_NAME'); }
		return $columns;
	}

	/**
	 * True when the column carries a default other than NULL.
	 *
	 * MariaDB reports an explicit NULL default as the four-character string
	 * "NULL" while MySQL reports a real NULL, so both spellings count as "no
	 * default" here. Getting this wrong would make the installer re-issue the
	 * same ALTER on every page load.
	 */
	private function hasNonNullDefault($table, $column) {
		$qr = $this->db->query(
			"SELECT COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
			[$table, $column]
		);
		if (!$qr || !$qr->nextRow()) { return false; }   // unknown column: handled as a missing one

		$default = $qr->get('COLUMN_DEFAULT');
		return !(is_null($default) || strtoupper((string)$default) === 'NULL');
	}

	/** True when the named index exists on the table. */
	private function indexExists($table, $index) {
		$qr = $this->db->query(
			"SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
			[$table, $index]
		);
		return ($qr && $qr->nextRow());
	}

	/** Full CREATE TABLE for a table the database does not have yet. */
	private function buildCreateTable($table) {
		$definitions = [];
		foreach (self::EXPECTED[$table] as $column => $definition) {
			$definitions[] = "`{$column}` {$definition}";
		}
		$definitions[] = "PRIMARY KEY (`" . self::PRIMARY_KEYS[$table] . "`)";

		foreach (self::EXPECTED_INDEXES as $index => $spec) {
			if ($spec[0] === $table) { $definitions[] = "INDEX `{$index}` {$spec[1]}"; }
		}

		return "CREATE TABLE `{$table}` (\n  " . join(",\n  ", $definitions) . "\n) " . self::TABLE_OPTIONS;
	}
}
