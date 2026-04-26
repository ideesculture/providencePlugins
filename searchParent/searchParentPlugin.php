<?php

require_once(__CA_MODELS_DIR__ . "/ca_objects.php");

class searchParentPlugin extends BaseApplicationPlugin
{
	# -------------------------------------------------------
	private $opo_config;
	private $ops_plugin_path;
	# -------------------------------------------------------
	public function __construct($ps_plugin_path)
	{
		$this->ops_plugin_path = $ps_plugin_path;
		$this->description = _t("Adds a 'Search parents of objects' link in the inspector of a set, performing a federated search on the parent_ids of all objects in the set.");
		parent::__construct();

		$conf_file = $ps_plugin_path.'/conf/local/searchParent.conf';
		if (!file_exists($conf_file)) {
			$conf_file = $ps_plugin_path.'/conf/searchParent.conf';
		}
		$this->opo_config = Configuration::load($conf_file);
	}
	# -------------------------------------------------------
	public function checkStatus()
	{
		return array(
			'description' => $this->getDescription(),
			'errors' => array(),
			'warnings' => array(),
			'available' => ((bool) $this->opo_config->get('enabled'))
		);
	}
	# -------------------------------------------------------
	public function hookAppendToEditorInspector(array $va_params = array())
	{
		$t_item = $va_params["t_item"];

		if (!isset($t_item)) return false;

		if (isset($va_params["vs_buf_append"])) {
			$vs_buf = $va_params["vs_buf_append"];
		} else {
			$vs_buf = "";
		}

		if ($va_params["caEditorInspectorAppend"]) {
			$vs_buf = $va_params["caEditorInspectorAppend"];
		}

		$vs_table_name = $t_item->tableName();
		$vn_item_id = $t_item->getPrimaryKey();

		if ($vs_table_name == "ca_sets" && $t_item->get("table_num")) {
			$o_data = new Db();

			$query = "SELECT CONCAT(\"ca_objects.object_id:\", parent_id) as parents
			FROM ca_set_items csi
			LEFT JOIN ca_objects co on csi.row_id = co.object_id
			WHERE set_id = ?
			and csi.deleted = 0
			and co.deleted = 0
			and parent_id is not null
			group by parents;";

			$qr_res = $o_data->query($query, [$vn_item_id]);

			$parents = [];
			while ($qr_res->nextRow()) {
				$parents[] = $qr_res->get("parents");
			}

			$max_parents = (int) $this->opo_config->get('max_parents');
			if (!$max_parents) { $max_parents = 100; }

			if (sizeof($parents) > 0 && sizeof($parents) <= $max_parents) {
				$request = implode(" OR ", $parents);
				$action_url = __CA_URL_ROOT__ . "/index.php/find/SearchObjects/Index";
				$vs_buf .= "<form style='display:none' id='BasicSearchForm' method='post' action='".htmlspecialchars($action_url)."' enctype=\"multipart/form-data\">
				<input type='hidden' name='search' value='".htmlspecialchars($request)."'>
				<input name=\"form_timestamp\" value=\"".time()."\" type=\"hidden\">
				</form>";

				$vs_buf .= "<a href='#' onclick='$(\"#BasicSearchForm\").submit();'>"._t("Search parents of objects")."</a>";
			}
		}

		$va_params["caEditorInspectorAppend"] = $vs_buf;
		return $va_params;
	}

	static public function getRoleActionList()
	{
		return array();
	}
}
