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

require_once(__CA_LIB_DIR__.'/TaskQueue.php');
require_once(__CA_LIB_DIR__.'/Configuration.php');
require_once(__CA_MODELS_DIR__.'/ca_lists.php');
require_once(__CA_MODELS_DIR__.'/ca_objects.php');
require_once(__CA_MODELS_DIR__.'/ca_object_representations.php');
require_once(__CA_MODELS_DIR__.'/ca_locales.php');

require_once(__CA_BASE_DIR__."/vendor/pear/file_marc/File/MARC.php");

class SimpleZ3950Controller extends ActionController {
	# -------------------------------------------------------
	protected $opo_config;		// plugin configuration file
	protected $pa_parameters;

	# -------------------------------------------------------
	# Constructor
	# -------------------------------------------------------

	public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
		parent::__construct($po_request, $po_response, $pa_view_paths);

//		if (!$this->request->user->canDoAction('can_use_simple_z3950_plugin')) {
//			$this->response->setRedirect($this->request->config->get('error_display_url').'/n/3000?r='.urlencode($this->request->getFullUrlPath()));
//			return;
//		}

		$conf_file       = __CA_APP_DIR__.'/plugins/SimpleZ3950/conf/SimpleZ3950.conf';
		$conf_file_local = __CA_APP_DIR__.'/plugins/SimpleZ3950/conf/local/SimpleZ3950.conf';
		if (file_exists($conf_file_local)) { $conf_file = $conf_file_local; }
		$this->opo_config = Configuration::load($conf_file);

	}

	# -------------------------------------------------------
	# Functions to render views
	# -------------------------------------------------------
	public function Index($type="") {
		// GET : $opa_stat=$this->request->getParameter('stat', pString);
		// SET : $this->view->setVar('queryparameters', $opa_queryparameters);
		if(!function_exists("yaz_connect")) {
			//var_dump("test");die();
			$this->view->setVar("message","L'extension PHP Yaz n'est pas disponible sur ce serveur.");
			$this->render('error_html.php');
		} else {
			$servers = $this->opo_config->get("servers");
			$this->view->setVar("servers",$servers);
			$this->render('index_html.php');
		}
	}

	# -------------------------------------------------------
	public function Lot($type="") {
		// GET : $opa_stat=$this->request->getParameter('stat', pString);
		// SET : $this->view->setVar('queryparameters', $opa_queryparameters);
		$this->render('lot_html.php');
	}

	public function Search() {
		$servers = $this->opo_config->get("servers");

		$ps_serveur=$this->request->getParameter('serveur', pString);
		$ps_action=$this->request->getParameter('action', pString);
		$ps_search=$this->request->getParameter('search', pString);
		
		$va_server = $servers[$ps_serveur];

		if(!function_exists("yaz_connect")) {
			$this->view->setVar("message","L'extension PHP Yaz n'est pas disponible sur ce serveur.");
			$this->render('error_html.php');
		} else {
			if($va_server["user"]) {
				$va_yaz_options=["user"=>$va_server["user"],"password"=>$va_server["password"]];	
			} else {
				$va_yaz_options= null;
			}
			
			$vs_zurl=$va_server["url"];
			if (!$va_yaz_options) {
				$vo_yaz_ressource = yaz_connect($vs_zurl);
			} else {
				$vo_yaz_ressource = yaz_connect($vs_zurl, $va_yaz_options);
			}
			yaz_syntax($vo_yaz_ressource, "unimarc");
			yaz_range($vo_yaz_ressource, 1, 10);

			$request = "@attr 1=".$va_server["attribute"]." "." \"". $ps_search . "\"";

			yaz_search($vo_yaz_ressource, "rpn", $request);
			yaz_wait();
	
			$error = yaz_error($vo_yaz_ressource);
	
			if (!empty($error)) {
				echo "Erreur : $error";
			} else {
				$nb_hits = yaz_hits($vo_yaz_ressource);
				//var_dump($nb_hits);
				$files=[];
				$previews = [];
				$raws = [];
				$titles = [];
				$i=1;

				while($i<=$nb_hits) {
					$rec = yaz_record($vo_yaz_ressource, $i, "raw");
					$rec_display = yaz_record($vo_yaz_ressource, $i, "string");
					$rec_array = yaz_record($vo_yaz_ressource, $i, "array");
					if (empty($rec)) continue;
					$target_file = __CA_APP_DIR__."/tmp/z3950_".str_replace([" ",",","!","/","\\"],"",$ps_search)."_".$i.".pan";
					file_put_contents($target_file, $rec);
					$files[] = $target_file;
					$marc = new File_MARC($rec, File_MARC::SOURCE_STRING);
					$first_record_inside_marc = $marc->next();
					
					$preview = $va_server["preview"];
					preg_match_all("/\^([0-9][0-9][0-9])\/([a-z])/", $va_server["preview"], $matches);

					$fieldcodes = $matches[1];
					$subfieldcodes = $matches[2];
					$matches=$matches[0];

					foreach($matches as $key=>$match) {
						$fields = $first_record_inside_marc->getFields($fieldcodes[$key]);
						foreach($fields as $f) {
							$subf = $f->getSubfield($subfieldcodes[$key]);
							if(is_bool($subf)) {
								$preview = str_replace($match, "", $preview);
							} else {
								$preview = str_replace($match, $subf->getData(), $preview);	
							}
						}
					}
					$previews[]= $rec_display;
					
					// Cleanup preview before sending
					$preview = trim($preview, ",");
					$preview = str_replace(" ,",",",$preview);
					$preview = str_replace(",,",",",$preview);
					$titles[] = $preview;
					
					$raws[]= $rec;
					$i++;
				}
			}
			$this->view->setVar("nb_results", $nb_hits);
			$this->view->setVar("files", $files);
			$this->view->setVar("previews", $previews);
			$this->view->setVar("raws", $raws);
			$this->view->setVar("titles", $titles);
			$this->view->setVar("import_disabled", (bool)$this->opo_config->get('import_disabled'));
			$this->render('search_results_html.php');
		}

	}

	public function Import() {
		if ((bool)$this->opo_config->get('import_disabled')) {
			$this->view->setVar('message', _t("L'import Z39.50 est désactivé sur cette instance (mapping z3950_import_marc non chargé). La recherche reste disponible."));
			$this->render('error_html.php');
			return;
		}
		$vn_results = $this->request->getParameter('nb_results', pInteger);
		//var_dump($vn_results);
		$files=[];
		$commands=[];
		$outputs=[];
		for($i=0;$i<$vn_results;$i++) {
			$filepath = $this->request->getParameter('file_'.$i, pString);
			if($filepath != "") {
				$files[] = $filepath;
				$command= "cd ".__CA_BASE_DIR__."/support && ./bin/caUtils import-data -s ".$filepath." -m z3950_import_marc -f marc -l . -d DEBUG";
				//print $command;
				//print "Import de l'enregistrement n°".$i."\n<br/>";
				exec($command, $output, $return_var);
				$commands[]=$command;
				$outputs[]=$output;
				//var_dump($output);
				//var_dump($return_var);
				//print $result."<br>\n";
			}
		}
		// ./bin/caUtils import-data -s /www/www.z3950.local/gestion/app/tmp/z3950_2-02-013706-2_1.pan -m z3950_import_marc -f marc -l . -d DEBUG
		$this->view->setVar("outputs",$outputs);
		$this->view->setVar("commands",$commands);
		$this->render('import_html.php');
	}
	# -------------------------------------------------------
}
?>
