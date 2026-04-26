<?php


class exemplairesPlugin extends BaseApplicationPlugin
{
	# -------------------------------------------------------
	private $opo_config;
	private $ops_plugin_path;

	# -------------------------------------------------------
	public function __construct($ps_plugin_path)
	{
		$this->ops_plugin_path = $ps_plugin_path;
		$this->description = _t('exemplaires');
		parent::__construct();
		$ps_plugin_path = __CA_BASE_DIR__ . "/app/plugins/exemplaires";

		if (file_exists($ps_plugin_path . '/conf/local/exemplaires.conf')) {
			$this->opo_config = Configuration::load($ps_plugin_path . '/conf/local/exemplaires.conf');
		} else {
			$this->opo_config = Configuration::load($ps_plugin_path . '/conf/exemplaires.conf');
		}
	}
	# -------------------------------------------------------
	/**
	 * Override checkStatus() to return true - the ampasFrameImporterPlugin plugin always initializes ok
	 */
	public function checkStatus()
	{
		return array(
			'description' => $this->getDescription(),
			'errors' => array(),
			'warnings' => array(),
			'available' => ((bool)$this->opo_config->get('enabled'))
		);
	}

	# -------------------------------------------------------
	/**
	 * Insert into ObjectEditor info (side bar)
	 */
	public function hookAppendToEditorInspector(array $va_params = array())
	{
        MetaTagManager::addLink('stylesheet', __CA_URL_ROOT__."/app/plugins/exemplaires/assets/css/exemplaires.css",'text/css');

		$t_item = $va_params["t_item"];

		// basic zero-level error detection
		if (!isset($t_item)) return false;

		// fetching content of already filled vs_buf_append to surcharge if present (cumulative plugins)
		if (isset($va_params["vs_buf_append"])) {
			$vs_buf = $va_params["vs_buf_append"];
		} else {
			$vs_buf = "";
		}

		$vs_table_name = $t_item->tableName();
		$vn_item_id = $t_item->getPrimaryKey();
		$vn_code = $t_item->getTypeCode();

		if ($vs_table_name == "ca_objects") {

			if (in_array($vn_code, $this->opo_config->get('TypesNoticesAvecExemplaires'))) {
				// biens acquis
				$numRep = 0;
				if(is_array($t_item->getRepresentations(array('original')))) $numRep = sizeof($t_item->getRepresentations(array('original')));
				
				
				$vs_inventaire_link_text_affectes = "Ajouter un exemplaire";

				$vs_buf = "<form action=\"/gestion/index.php/editor/objects/ObjectEditor/Edit\" method=\"post\" id=\"caNewChildForm\" target=\"_top\" enctype=\"multipart/form-data\">
<input type=\"hidden\" name=\"_formName\" value=\"caNewChildForm\"><input type=hidden name=type_id value='750'><input name=\"object_id\" value=\"0\" type=\"hidden\">
<input name=\"parent_id\" value=\"" . $vn_item_id . "\" type=\"hidden\">";
				
				$vs_buf .= "</form>";
				$vs_buf .= "<div style=\"text-align:center;width:100%;margin-top:10px;\">";
				if(!$numRep) {
					$vs_buf .= "<img src='https://covers.openlibrary.org/b/isbn/".$t_item->get("ca_objects.idno")."-M.jpg' style='max-height:200px;width:auto;'/>"
					."<img src='' id='google_cover' data-isbn='".str_replace(["-"," "],"",$t_item->get("ca_objects.idno"))."' style='max-height:200px;width:auto;'/>";
				}
				$vs_buf .= "<a onClick='jQuery(\"#caNewChildForm\").submit()' style='cursor:pointer;background:#6EBDCE;color:white;padding:6px 12px;margin:10px 10px 28px 10px;display:block;'>"
					. $vs_inventaire_link_text_affectes
					. "</a></div>";
				if(!$numRep) {
					$vs_buf .= "<script>
					var isbn = $('#google_cover').data('isbn');
					
					$.ajax({
					  dataType: 'json',
					  url: 'https://www.googleapis.com/books/v1/volumes?q=isbn:' + isbn,
					  success: handleResponse
					});
					
					function handleResponse( response ) {
						//console.log(response);
						if(response.items) {
							console.log(response.items);
							if(response.items[0]) {
								console.log(response.items[0].volumeInfo);
							}
						}
						/*$.each( response.items, function( i, item ) {
						    var thumb    = item.volumeInfo.imageLinks.thumbnail;
						    $('#google_cover').attr('src', thumb);
						});*/
					}
				</script>";
				}					
			}
			
			if (in_array($vn_code, $this->opo_config->get('TypesNoticesAvecEtatDesCollections'))) {
				// biens acquis
				$vs_inventaire_link_text_affectes = "Ajouter un état des collections";

				$vs_buf = "<form action=\"/gestion/index.php/editor/objects/ObjectEditor/Edit\" method=\"post\" id=\"caNewChildForm\" target=\"_top\" enctype=\"multipart/form-data\">
<input type=\"hidden\" name=\"_formName\" value=\"caNewChildForm\"><input type=hidden name=type_id value='34764'><input name=\"object_id\" value=\"0\" type=\"hidden\">
<input name=\"parent_id\" value=\"" . $vn_item_id . "\" type=\"hidden\">";

				$vs_buf .= "</form><div style=\"text-align:center;width:100%;margin-top:10px;\">"
					. "<a onClick='jQuery(\"#caNewChildForm\").submit()' style='cursor:pointer;background:#6EBDCE;color:white;padding:6px 12px;margin:10px 10px 28px 10px;display:block;'>"
					. $vs_inventaire_link_text_affectes
					. "</a></div>";
			}

			if(($vn_code=="Etat des collections")) {
				$vs_buf .= "<div class='etatDesCollections_button'><a id='etatDesCollButton' onClick='caMediaPanel.showPanel(\"/gestion/index.php/exemplaires/EtatDesCollections/Index/id/".$vn_item_id."\");'>Etat des collections (CKEd4)</a></div>";
			}
		}
//$vs_buf .= "<p>test</p>";
		$va_params["caEditorInspectorAppend"] = $vs_buf;
		return $va_params;

	}

	# -------------------------------------------------------
	/**
	 * Insert activity menu
	 */
	public function hookRenderMenuBar($pa_menu_bar)
	{


		return $pa_menu_bar;
	}

	public function hookRenderWidgets($pa_widgets_config)
	{

		return $pa_widgets_config;
	}
	# -------------------------------------------------------
	/**
	 * Get plugin user actions
	 */

	static public function getRoleActionList() {
		return array();
	}

	# -------------------------------------------------------
	/**
	 * Add plugin user actions
	 */
	public function hookGetRoleActionList($pa_role_list) {


		return $pa_role_list;
	}
}

?>
