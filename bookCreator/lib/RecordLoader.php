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
 * Loads CollectiveAccess records for the book renderer, and keeps track of what
 * it had to skip.
 *
 * A catalogue references sets, objects and representations by id, without any
 * foreign key: users delete a set or an object while a book still points at it.
 * The renderer must survive that — a book missing one work still prints, a book
 * that throws prints nothing.
 *
 * Two conditions are checked together, because either alone is insufficient:
 *  - the id must exist. BundlableLabelable::load() leaves an empty instance
 *    otherwise, and getPrimaryKey() is the only reliable tell;
 *  - the record must not be soft-deleted. load() does NOT filter on `deleted`,
 *    so a deleted record loads perfectly well, just with deleted = 1.
 *
 * Skipped items are counted rather than silently dropped. On a 200-page
 * catalogue a work that vanishes because someone emptied a set would otherwise
 * only be noticed once printed, in 150 copies. The count is surfaced next to
 * the preview and at the end of the generation job — never inside the paginated
 * document itself, which must contain nothing that is not headed for the PDF.
 */
class RecordLoader {

	/** @var array skipped items, each as ['table' => …, 'id' => …, 'reason' => …] */
	private $skipped = [];

	/**
	 * Loads a record, or returns null when it cannot be used.
	 *
	 * @param string $table CollectiveAccess table name, eg. 'ca_objects'
	 * @param mixed  $id    primary key; 0, null and '' are treated as "not set"
	 * @param string $context free text telling where the reference comes from,
	 *                        so the report can name the guilty section
	 * @return BaseModel|null
	 */
	public function load($table, $id, $context = '') {
		// v1 stores 0 rather than NULL for "no set", so both mean "not set here".
		if (!$id) { return null; }

		// Datamodel::getInstance() types its id parameter as ?int; ids read back
		// from the database come as numeric strings.
		$instance = Datamodel::getInstance($table, false, (int)$id);

		if (!$instance || !$instance->getPrimaryKey()) {
			$this->skip($table, $id, 'missing', $context);
			return null;
		}
		if ($instance->hasField('deleted') && (int)$instance->get('deleted')) {
			$this->skip($table, $id, 'deleted', $context);
			return null;
		}
		return $instance;
	}

	/**
	 * Loads the primary representation of an object, or null.
	 *
	 * Split out because an object may perfectly well exist with no primary
	 * representation at all, which is a third failure mode on top of the two
	 * above and would otherwise produce an <img src="">.
	 */
	public function loadPrimaryRepresentation($object, $context = '') {
		if (!$object) { return null; }

		$representation_id = $object->getPrimaryRepresentationID();
		if (!$representation_id) {
			$this->skip('ca_object_representations', $object->getPrimaryKey(), 'no_primary_representation', $context);
			return null;
		}
		return $this->load('ca_object_representations', $representation_id, $context);
	}

	/**
	 * Row ids of a set, already filtered by CollectiveAccess.
	 *
	 * ca_sets::getItems() joins with "AND rel.deleted = 0", so deleted members
	 * are excluded upstream and need no filtering here. The set itself is the
	 * part that has to be checked.
	 */
	public function loadSetItemIDs($set_id, $context = '') {
		$set = $this->load('ca_sets', $set_id, $context);
		if (!$set) { return []; }

		$row_ids = $set->getItemRowIDs();
		return is_array($row_ids) ? array_keys($row_ids) : [];
	}

	# -------------------------------------------------------
	# Reporting
	# -------------------------------------------------------

	private function skip($table, $id, $reason, $context) {
		$this->skipped[] = ['table' => $table, 'id' => $id, 'reason' => $reason, 'context' => $context];
	}

	/** How many items were left out. */
	public function countSkipped() { return sizeof($this->skipped); }

	/**
	 * One human-readable line per skipped item, ready to display outside the
	 * paginated document.
	 */
	public function getSkippedMessages() {
		$messages = [];
		foreach ($this->skipped as $item) {
			switch ($item['reason']) {
				case 'deleted':
					$text = _t('%1 %2 has been deleted', $item['table'], $item['id']);
					break;
				case 'no_primary_representation':
					$text = _t('Object %1 has no primary representation', $item['id']);
					break;
				default:
					$text = _t('%1 %2 no longer exists', $item['table'], $item['id']);
			}
			if ($item['context']) { $text .= ' ('.$item['context'].')'; }
			$messages[] = $text;
		}
		return $messages;
	}

}
