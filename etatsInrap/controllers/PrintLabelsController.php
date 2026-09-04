<?php
/* ----------------------------------------------------------------------
 * plugins/statisticsViewer/controllers/StatisticsController.php :
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2010 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * ----------------------------------------------------------------------
 */
 
	define("__INRAP_TYPE_ID_OP__", 125);

 	require_once(__CA_LIB_DIR__.'/TaskQueue.php');
 	require_once(__CA_LIB_DIR__.'/Configuration.php');
 	require_once(__CA_MODELS_DIR__.'/ca_lists.php');
 	require_once(__CA_MODELS_DIR__.'/ca_objects.php');
	require_once(__CA_MODELS_DIR__.'/ca_collections.php');
 	require_once(__CA_MODELS_DIR__.'/ca_object_representations.php');
 	require_once(__CA_MODELS_DIR__.'/ca_locales.php');
 	require_once(__CA_MODELS_DIR__.'/ca_bundle_displays.php');
 	error_reporting(E_ERROR);

 	class PrintLabelsController extends ActionController {
 		# -------------------------------------------------------
  		protected $opo_config,		// plugin configuration file
        $ops_plugin_name, $ops_plugin_path,
		$ops_user_groups;
		protected $opo_datamodel;


 		# -------------------------------------------------------
 		# Constructor
 		# -------------------------------------------------------

 		public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
 			global $allowed_universes;
 			
 			parent::__construct($po_request, $po_response, $pa_view_paths);

 			$this->ops_plugin_name = "etatsInrap";
 			$this->ops_plugin_path = __CA_APP_DIR__."/plugins/".$this->ops_plugin_name;

 			$vs_conf_file = $this->ops_plugin_path."/conf/".$this->ops_plugin_name.".conf";
 			if(is_file($vs_conf_file)) {
                $this->opo_config = Configuration::load($vs_conf_file);
            }

			$this->opo_datamodel = Datamodel::load();

			$va_groups = $this->getRequest()->getUser()->getUserGroups();
			$this->ops_user_groups = [];
 			foreach($va_groups as $group) {
 				if(in_array($group["code"], ["gestion","admin"])) continue;
				$this->ops_user_groups[] =$group["code"];
			}

 		}

 		# -------------------------------------------------------
 		# Functions to render views
 		# -------------------------------------------------------
 		public function Index($type="") {
            $this->render('index_html.php');
 		}

		public function Generate($pa_options=null) {
 			$vs_label_layout = $this->getRequest()->getParameter("format_etiquette", pInteger);
			$vn_number = $this->getRequest()->getParameter("nombre", pInteger);
			$this->view->setVar("vn_number", $vn_number);
			$vn_display = $this->getRequest()->getParameter("display", pInteger);
			$vn_collection_id = $this->getRequest()->getParameter("collection", pInteger);
			var_dump($vs_label_layout);
			//
			// PDF output
			//
			$va_template_info= [
				"name"=>"Generic summary",
				"type"=>"page",
				"pageSize"=>"letter",
				"pageOrientation"=>"portrait",
				"tables"=>["*"],
				"marginLeft"=>"0.25in",
				"marginRight"=>"0.25in",
				"marginTop"=>"0.75in",
				"marginBottom"=>"0.5in",
				"horizontalGutter"=>null,
				"verticalGutter"=>null,
				"labelWidth"=>null, "labelHeight"=>NULL, "elementCode"=>NULL, "showOnlyIn"=>NULL, "filename"=>NULL,
				"path"=>__CA_BASE_DIR__."/app/plugins/etatsInrap/printTemplates/summary/summary.php"
			];
/*
 * code			libellé			Désignation							caractéristique souhaitée				finition				[données usine]
																											125 μ  et 175 μ			long.			hauteur
	inrap_10	inrap 10 vues	Feuille A4 étiquette pré-découpée	10 vues, fond blanc, avec/sans trou		imprutrecible			10,5 cm			5,94 cm
	inrap_16	inrap 16 vues	Feuille A4 étiquette pré-découpée	16 vues, fond blanc, avec/sans trou		imprutrecible			10,5 cm			3,71 cm
	inrap_24	inrap 24 vues	Feuille A4 étiquette pré-découpée	24 vues, fond blanc, avec/sans trou		imprutrecible			7,0 cm			3,71 cm
								Feuille A4							blanc									imprutrecible
 */

			switch($vs_label_layout) {
				case 1:
					$this->view->setVar("code", "inrap_10");
					$this->view->setVar("label", "inrap 10 vues");
					$this->view->setVar("vn_labels_per_page", 10);
					$this->view->setVar("vn_labels_per_line", 2);
					$this->view->setVar("long", "105");
					$this->view->setVar("larg", "59.4");
					$this->view->setVar('pageWidth', "210mm");
					$this->view->setVar('pageHeight', "297mm");
					$this->view->setVar('marginTop', '0mm');
					$this->view->setVar('marginRight', '0mm');
					$this->view->setVar('marginBottom', '0mm');
					$this->view->setVar('marginLeft', '0mm');

					break;
				case 2:
					$this->view->setVar("code", "inrap_16");
					$this->view->setVar("label", "inrap 16 vues");
					$this->view->setVar("long", "105");
					$this->view->setVar("vn_labels_per_page", 16);
					$this->view->setVar("vn_labels_per_line", 2);

					$this->view->setVar("larg", "37.1");
					$this->view->setVar('pageWidth', "210mm");
					$this->view->setVar('pageHeight', "297mm");
					$this->view->setVar('marginTop', '0mm');
					$this->view->setVar('marginRight', '0mm');
					$this->view->setVar('marginBottom', '0mm');
					$this->view->setVar('marginLeft', '0mm');
					break;
				case 3:
				default:
					$this->view->setVar("code", "inrap_24");
					$this->view->setVar("label", "inrap 24 vues");
					$this->view->setVar("vn_labels_per_page", 24);
				$this->view->setVar("vn_labels_per_line", 3);

					$this->view->setVar("long", "70");
					$this->view->setVar("larg", "37.1");
					$this->view->setVar('pageWidth', "210mm");
					$this->view->setVar('pageHeight', "297mm");
				$this->view->setVar('marginTop', '0mm');
				$this->view->setVar('marginRight', '0mm');
				$this->view->setVar('marginBottom', '0mm');
				$this->view->setVar('marginLeft', '0mm');
					break;
			}

			$t_subject = $this->opo_datamodel->getInstanceByTableName("ca_collections");
			$t_subject->load($vn_collection_id);

			error_reporting(E_ERROR);

			AssetLoadManager::register('tableList');
			$t_display = new ca_bundle_displays();
			$va_displays = caExtractValuesByUserLocale($t_display->getBundleDisplays(
				array(
					'table' => $t_subject->tableNum(),
					'user_id' => $this->request->getUserID(),
					'access' => __CA_BUNDLE_DISPLAY_READ_ACCESS__,
					'restrictToTypes' => array(
						$t_subject->getTypeID()
					)
				)
			));

			if ((!($vn_display_id = $this->request->getParameter('display_id', pInteger))) || !isset($va_displays[$vn_display_id])) {
				$vn_display_id = $this->request->user->getVar($t_subject->tableName().'_summary_display_id');
			}

			if (!isset($va_displays[$vn_display_id]) || (is_array($va_displays[$vn_display_id]['settings']['show_only_in']) && sizeof($va_displays[$vn_display_id]['settings']['show_only_in']) && !in_array('editor_summary', $va_displays[$vn_display_id]['settings']['show_only_in']))) {
				$va_tmp = array_filter($va_displays, function($v) { return isset($v['settings']['show_only_in']) && is_array($v['settings']['show_only_in']) && in_array('editor_summary', $v['settings']['show_only_in']); });
				$vn_display_id = sizeof($va_tmp) > 0 ? array_shift(array_keys($va_tmp)) : 0;
			}

			$this->view->setVar('t_display', $t_display);
			$this->view->setVar('bundle_displays', $va_displays);

			// Check validity and access of specified display
			if ($t_display->load($vn_display_id) && ($t_display->haveAccessToDisplay($this->request->getUserID(), __CA_BUNDLE_DISPLAY_READ_ACCESS__))) {
				$this->view->setVar('display_id', $vn_display_id);

				$va_placements = $t_display->getPlacements(array('returnAllAvailableIfEmpty' => true, 'table' => $t_subject->tableNum(), 'user_id' => $this->request->getUserID(), 'access' => __CA_BUNDLE_DISPLAY_READ_ACCESS__, 'no_tooltips' => true, 'format' => 'simple', 'settingsOnly' => true, 'omitEditingInfo' => true));
				$va_display_list = array();
				foreach($va_placements as $vn_placement_id => $va_display_item) {
					$va_settings = caUnserializeForDatabase($va_display_item['settings']);

					// get column header text
					$vs_header = $va_display_item['display'];
					if (isset($va_settings['label']) && is_array($va_settings['label'])) {
						if ($vs_tmp = array_shift(caExtractValuesByUserLocale(array($va_settings['label'])))) { $vs_header = $vs_tmp; }
					}

					$va_display_list[$vn_placement_id] = array(
						'placement_id' => $vn_placement_id,
						'bundle_name' => $va_display_item['bundle_name'],
						'display' => $vs_header,
						'settings' => $va_settings
					);
				}

				$this->view->setVar('placements', $va_display_list);

				$this->request->user->setVar($t_subject->tableName().'_summary_display_id', $vn_display_id);
			} else {
				$vn_display_id = null;
				$this->view->setVar('display_id', null);
				$this->view->setVar('placements', array());
			}

			$this->view->setVar('t_subject', $t_subject);



			$va_barcode_files_to_delete = array();
			try {
				$this->view->setVar('base_path', $vs_base_path = pathinfo($va_template_info['path'], PATHINFO_DIRNAME));
				$this->view->addViewPath(array($vs_base_path, "{$vs_base_path}/local"));


				$o_pdf = new PDFRenderer();

				$this->view->setVar('PDFRenderer', $o_pdf->getCurrentRendererCode());

				$va_page_size =	PDFRenderer::getPageSize(caGetOption('pageSize', $va_template_info, 'A4'), 'mm', caGetOption('pageOrientation', $va_template_info, 'portrait'));
				$vn_page_width = $va_page_size['width']; $vn_page_height = $va_page_size['height'];

				$vs_content = $this->render($va_template_info['path']);
				//print $vs_content;die();

				$o_pdf->setPage(caGetOption('pageSize', $va_template_info, 'A4'), caGetOption('pageOrientation', $va_template_info, 'portrait'), caGetOption('marginTop', $va_template_info, '0mm'), caGetOption('marginRight', $va_template_info, '0mm'), caGetOption('marginBottom', $va_template_info, '0mm'), caGetOption('marginLeft', $va_template_info, '0mm'));

				$o_pdf->render($vs_content, array('stream'=> true, 'filename' => ($vs_filename = $this->view->getVar('filename')) ? $vs_filename : caGetOption('filename', $va_template_info, 'print_summary.pdf')));

				$vb_printed_properly = true;

				foreach($va_barcode_files_to_delete as $vs_tmp) { @unlink($vs_tmp);}
				exit;
			} catch (Exception $e) {
				foreach($va_barcode_files_to_delete as $vs_tmp) { @unlink($vs_tmp);}
				$vb_printed_properly = false;
				$this->postError(3100, _t("Could not generate PDF"),"BaseEditorController->PrintSummary()");
			}
			return;
		}


 	}
 ?>
