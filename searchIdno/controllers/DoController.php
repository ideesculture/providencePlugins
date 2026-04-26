<?php

require_once(__CA_MODELS_DIR__.'/ca_objects.php');
require_once(__CA_MODELS_DIR__.'/ca_metadata_elements.php');
require_once(__CA_APP_DIR__.'/helpers/navigationHelpers.php');

class DoController extends ActionController
{
	# -------------------------------------------------------
	protected $config;
	# -------------------------------------------------------
	public function __construct(&$po_request, &$po_response, $pa_view_paths = null)
	{
		$conf_file = __CA_APP_DIR__ . '/plugins/searchIdno/conf/local/searchIdno.conf';
		if (!file_exists($conf_file)) {
			$conf_file = __CA_APP_DIR__ . '/plugins/searchIdno/conf/searchIdno.conf';
		}
		$this->config = Configuration::load($conf_file);

		parent::__construct($po_request, $po_response, $pa_view_paths);
	}
	# -------------------------------------------------------
	public function Search() {
		if (!(bool) $this->config->get('enabled')) {
			$this->view->setVar('message', _t("searchIdno plugin is disabled."));
			$this->render('error_html.php');
			return;
		}

		$idno_element_code = $this->config->get('idno_element_code');
		$element_id = ca_metadata_elements::getElementID($idno_element_code);
		if (!$element_id) {
			$this->view->setVar('message', _t("Element code '%1' not found in ca_metadata_elements.", $idno_element_code));
			$this->render('error_html.php');
			return;
		}

		$vs_search = $this->request->getParameter("search2", pString);
		$vs_search = trim((string) $vs_search);
		if ($vs_search === "") {
			$this->view->setVar("results", []);
			$this->view->setVar("search2", "");
			$this->render("results_html.php");
			return;
		}

		$this->view->setVar("search2", $vs_search);
		$vs_search_sql = str_replace("*", "%", $vs_search);

		$o_data = new Db();
		$qr_result = $o_data->query("
			SELECT co.object_id
			FROM ca_attribute_values cav
			LEFT JOIN ca_attributes ca ON ca.attribute_id = cav.attribute_id
			LEFT JOIN ca_objects co ON ca.row_id = co.object_id
			WHERE cav.element_id = ?
			AND co.deleted = 0
			AND ca.table_num = ?
			AND cav.value_longtext1 LIKE ?
		", [$element_id, (int) Datamodel::getTableNum('ca_objects'), $vs_search_sql]);

		$results = $qr_result->getAllRows();

		if (sizeof($results) === 0) {
			$this->view->setVar("results", []);
			$this->render("results_html.php");
			return;
		}

		if (sizeof($results) === 1) {
			$this->redirect(caEditorUrl($this->request, 'ca_objects', $results[0]["object_id"]));
			return;
		}

		$this->view->setVar("results", $results);
		$this->render("results_html.php");
	}
	# -------------------------------------------------------
}
