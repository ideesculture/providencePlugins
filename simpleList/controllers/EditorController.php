<?php
/* ----------------------------------------------------------------------
 * simpleList — EditorController
 * ----------------------------------------------------------------------
 * Browse/edit ca_lists items in a hierarchical tree, with bulk add.
 *
 * Plugin by idéesculture (www.ideesculture.com), GPL v.3.
 * ----------------------------------------------------------------------
 */

require_once(__CA_MODELS_DIR__.'/ca_lists.php');
require_once(__CA_MODELS_DIR__.'/ca_list_items.php');

class EditorController extends ActionController {
	# -------------------------------------------------------
	protected $opo_config;
	# -------------------------------------------------------
	public function __construct(&$po_request, &$po_response, $pa_view_paths = null) {
		parent::__construct($po_request, $po_response, $pa_view_paths);

		if (!$this->request->user->canDoAction('can_use_simplelist_plugin')) {
			$this->response->setRedirect(
				$this->request->config->get('error_display_url')
				.'/n/3000?r='.urlencode($this->request->getFullUrlPath())
			);
			return;
		}

		$this->opo_config = Configuration::load(__CA_APP_DIR__.'/plugins/simpleList/conf/simpleList.conf');
	}
	# -------------------------------------------------------
	# Render the configured list of lists as a tree.
	# -------------------------------------------------------
	public function Thesaurus() {
		$page = $this->request->getParameter('page', pString);
		$page_config = $this->opo_config->get($page);
		if (!is_array($page_config) || !isset($page_config['lists'])) {
			$this->view->setVar('lists', []);
			$this->view->setVar('label', '');
			$this->render('thesaurus_html.php');
			return;
		}
		$list_codes = $page_config['lists'];
		$locale_id = (int)$this->request->user->getPreferredUILocaleID();

		$o_data = new Db();
		$placeholders = array_fill(0, count($list_codes), '?');
		$sql_lists = "SELECT ca_lists.list_id, ca_lists.list_code, ca_list_labels.name
			FROM ca_lists
			LEFT JOIN ca_list_labels ON ca_list_labels.list_id = ca_lists.list_id
			WHERE ca_list_labels.name != ''
			  AND ca_lists.deleted = 0
			  AND ca_lists.list_code IN (".implode(',', $placeholders).")
			ORDER BY ca_list_labels.name";
		$qr_lists = $o_data->query($sql_lists, $list_codes);

		$lists = [];
		while ($qr_lists->nextRow()) {
			$list_id = (int)$qr_lists->get('list_id');
			$lists[caRemoveAccents($qr_lists->get('name'))] = [
				'list_code' => $qr_lists->get('list_code'),
				'list_id' => $list_id,
				'name' => $qr_lists->get('name'),
			];
		}
		ksort($lists);

		$this->view->setVar('lists', $lists);
		$this->view->setVar('label', $page_config['label']);
		$this->render('thesaurus_html.php');
	}
	# -------------------------------------------------------
	# AJAX: fetch direct children of $parent within $list_code.
	# If $parent is null/0, fetch top-level items (children of the list root).
	# -------------------------------------------------------
	public function Fetch() {
		$list_code = $this->request->getParameter('list_code', pString);
		$parent = (int)$this->request->getParameter('parent', pInteger);

		$list_id = ca_lists::getListID($list_code);
		if (!$list_id) { exit(); }

		$o_data = new Db();
		$items = [];

		if (!$parent) {
			$qr_root = $o_data->query(
				"SELECT item_id FROM ca_list_items
				 WHERE list_id = ? AND parent_id IS NULL AND deleted = 0
				 LIMIT 1",
				[(int)$list_id]
			);
			if (!$qr_root->nextRow()) { exit(); }
			$parent = (int)$qr_root->get('item_id');
		}

		$qr = $o_data->query(
			"SELECT cali.item_id, cali.idno, COUNT(child.item_id) AS children
			 FROM ca_list_items cali
			 LEFT JOIN ca_list_items child ON child.parent_id = cali.item_id AND child.deleted = 0
			 WHERE cali.parent_id = ? AND cali.deleted = 0
			 GROUP BY cali.item_id
			 ORDER BY cali.idno",
			[(int)$parent]
		);
		while ($qr->nextRow()) {
			$vt_item = new ca_list_items($qr->get('item_id'));
			$name = $vt_item->get('ca_list_item_labels.name_singular');
			$key = strtolower(caRemoveAccents($name)).'_'.$qr->get('item_id');
			$items[$key] = [
				'item_id' => (int)$qr->get('item_id'),
				'name_singular' => $name,
				'children' => (int)$qr->get('children'),
			];
		}
		ksort($items);

		$this->view->setVar('list_code', $list_code);
		$this->view->setVar('items', $items);
		$this->view->setVar('parent', $parent);
		print $this->render('fetch_html.php');
		exit();
	}
	# -------------------------------------------------------
	# Bulk-add list items from a newline-separated textarea.
	# -------------------------------------------------------
	public function AddItems() {
		$page = $this->request->getParameter('page', pString);
		$list_code = $this->request->getParameter('list_code', pString);
		$list_id = ca_lists::getListID($list_code);
		if (!$list_id) {
			$this->redirect(caNavUrl($this->request, 'simpleList', 'Editor', 'Thesaurus', ['page' => $page]));
			return;
		}

		$locale_id = (int)$this->request->user->getPreferredUILocaleID();
		$items = trim((string)$this->request->getParameter('items', pString));

		foreach (explode("\n", $items) as $item) {
			$item = trim(str_replace(["\n", "\t", "\r"], '', $item));
			if ($item === '') { continue; }

			$vt_item = new ca_list_items();
			$vt_item->load(['idno' => $item, 'deleted' => 0, 'list_id' => $list_id]);
			if ($vt_item->getPrimaryKey()) { continue; }

			$vt_item->setMode(ACCESS_WRITE);
			$vt_item->set([
				'idno' => $item,
				'list_id' => $list_id,
				'access' => 1,
				'status' => 0,
				'is_enabled' => 1,
				'item_value' => $item,
			]);
			$vt_item->insert();
			if ($vt_item->numErrors()) { continue; }

			$vt_item->addLabel(
				['name_singular' => $item, 'name_plural' => $item],
				$locale_id, null, true
			);
			$vt_item->update();
		}

		$this->redirect(caNavUrl($this->request, 'simpleList', 'Editor', 'Thesaurus', ['page' => $page]));
	}
	# -------------------------------------------------------
}
