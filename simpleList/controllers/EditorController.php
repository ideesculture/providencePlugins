<?php
    /* ----------------------------------------------------------------------
     * simpleListEditor
     * ----------------------------------------------------------------------
     * List & list values editor plugin for Providence - CollectiveAccess
     * Open-source collections management software
     * ----------------------------------------------------------------------
     *
     * Plugin by idéesculture (www.ideesculture.com)
     * This plugin is published under GPL v.3. Please do not remove this header
     * and add your credits thereafter.
     *
     * File modified by :
     * ----------------------------------------------------------------------
     */

    require_once(__CA_MODELS_DIR__.'/ca_lists.php');
    require_once(__CA_MODELS_DIR__.'/ca_list_items.php');
    require_once(__CA_MODELS_DIR__.'/ca_objects.php');
    require_once(__CA_MODELS_DIR__.'/ca_object_labels.php');
    //require_once(__CA_LIB_DIR__."/Plugins/PDFRenderer/PhantomJS.php");
	error_reporting(E_ERROR);

 	class EditorController extends ActionController {
 		# -------------------------------------------------------
  		protected $opo_config;		// plugin configuration file
        protected $opa_list_of_lists; // list of lists
        protected $opa_listIdsFromIdno; // list of lists
        protected $opa_locale; // locale id
		private $opo_list;
 		# -------------------------------------------------------
 		# Constructor
 		# -------------------------------------------------------

 		public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
            parent::__construct($po_request, $po_response, $pa_view_paths);

 			// NO RIGHTS CHECKED FOR NOW
 			/*if (!$this->request->user->canDoAction('can_use_simplelisteditor_plugin')) {
 				$this->response->setRedirect($this->request->config->get('error_display_url').'/n/3000?r='.urlencode($this->request->getFullUrlPath()));
 				return;
 			}*/

 			$this->opo_config = Configuration::load(__CA_APP_DIR__.'/plugins/simpleList/conf/simpleList.conf');
 			
			$this->opo_list = new ca_lists("object_types");
        }

		private function custom_sort($a, $b) {
			return strcoll ($a['last_name'], $b['last_name']);
		}


 		# -------------------------------------------------------
 		# Functions to render views
 		# -------------------------------------------------------
		public function Thesaurus()  {
			$id = $this->request->getParameter("id", pInteger);
			$page = $this->request->getParameter("page", pString);
			$page_config = $this->opo_config->get($page);
			$lists = $page_config["lists"];
			
		   $query = "SELECT ca_lists.list_id, ca_lists.list_code, name FROM ca_lists LEFT JOIN ca_list_labels ON ca_list_labels.list_id=ca_lists.list_id WHERE name != '' and deleted=0 and list_code in (\"".implode("\",\"", $lists)."\") ORDER BY name";
		   //var_dump($query);die();
		   if(!$id) {
			   $this->view->setVar("object_id", false);
		   } else {
			   // TODO
		   }
		   $o_data = new Db();
		   $qr_result=$o_data->query($query);
		   $lists = [];
		   $items = [];
		   while($qr_result->nextRow()) {
			   $vt_list = new ca_lists($qr_result->get("list_id"));
			   $lists[caRemoveAccents($qr_result->get("name"))] = 
			   	[
					"list_code"=>$qr_result->get("list_code"), 
					"list_id"=>$qr_result->get("list_id"), 
					"name"=>$qr_result->get("name"),
					"facet"=>$vt_list->get("ca_lists.facet")
				];
			   $query2 = "SELECT item_id FROM ca_list_items WHERE parent_id is null  AND deleted=0 and list_id = ".$qr_result->get("list_id")."";
			   $qr_result2=$o_data->query($query2);
			   $qr_result2->nextRow();
			   $root = $qr_result2->get("item_id");

			   $items[$qr_result->get("list_id")] = [];

			   $query3 = "SELECT ca_list_items.item_id, name_singular FROM ca_list_items LEFT JOIN ca_list_item_labels on ca_list_item_labels.item_id = ca_list_items.item_id WHERE locale_id=2  AND deleted=0 AND parent_id=".$root." and list_id = ".$qr_result->get("list_id")." ORDER BY name_singular";
			   //var_dump($query3);die();
			   $qr_result3=$o_data->query($query3);
			   while($qr_result3->nextRow()) {
				   //var_dump(["item_id"=>$qr_result3->get("item_id"), "name_singular"=>$qr_result3->get("name_singular")]);die();
				   $items[$qr_result->get("list_id")][] = ["item_id"=>$qr_result3->get("item_id"), "name_singular"=>$qr_result3->get("name_singular")];
			   }
		   }
		   //$lists["Emplacements"] = ["name"=>"<a href='https://gadagne.ideesculture.fr/gestion/index.php/find/SearchStorageLocations/ViewHierarchy'>Emplacements</a>"];
		   

		   //ksort($lists);
		   //die();
		   
		   $this->view->setVar("lists", $lists);
		   $this->view->setVar("items", $items);
		   $this->view->setVar("label", $page_config["label"]);
		   
			//$this->view->setVar("template", $this->opo_config->get("template"));
		   $this->render('thesaurus_html.php');
		}

 		public function Fetch($type="") {
			$list_code= $this->request->getParameter("list_code", pString);
			$parent= $this->request->getParameter("parent", pInteger);
			$facet= $this->request->getParameter("facet", pString);
			$items = [];
			$vt_list = new ca_lists();
			$vt_list->load(["list_code"=>$list_code, "deleted"=>0]);
			$sort = $vt_list->get("default_sort");
			/*
			["Par libellé"]=>
			int(0)
			["Par rang"]=>
			int(1)
			["Par valeur"]=>
			int(2)
			["Par identifiant"]=>
			int(3)
			*/
			if($sort == 1) {
				$order = " ORDER BY cali1.rank ASC";
			} else {
				$order = "";
			}

			$o_data = new Db();
			if(!$parent) {
				$this->view->setVar("sort", $sort);
				$query_root = "SELECT cali1.item_id FROM ca_list_items cali1 LEFT JOIN ca_lists on ca_lists.list_id=cali1.list_id WHERE cali1.parent_id is null  AND cali1.deleted=0 AND ca_lists.deleted=0 and ca_lists.list_code = '".$list_code."' ".$order;
				//var_dump($query_root);die();
				$qr_result_root=$o_data->query($query_root);
				$qr_result_root->nextRow();
				$root_id = $qr_result_root->get("item_id");
				$query = "SELECT cali1.item_id, cali1.rank, COUNT(cali2.item_id) as children FROM ca_list_items cali1 LEFT JOIN ca_list_items cali2 ON cali2.parent_id=cali1.item_id WHERE cali1.parent_id = ".$root_id."  AND cali1.deleted=0  GROUP BY cali1.item_id ".$order;
				//die();
				//var_dump($query);die();
			   	$qr_result=$o_data->query($query);
				//var_dump($qr_result->getAllRows());
				//die();
			   	while($qr_result->nextRow()) {
					   	$vt_item = new ca_list_items($qr_result->get("item_id"));
						if($sort == 1) {
							if(!isset($key)) $key=0;
							$key++;
						} else {
							$key = strtolower(caRemoveAccents($vt_item->get("ca_list_item_labels.name_singular")));
						}
						
						$items[$key] = [
							"item_id"=>$qr_result->get("item_id"),
							"name_singular"=>$vt_item->get("ca_list_item_labels.name_singular"),
							"children"=>$qr_result->get("children")
						];
				}
				ksort($items);
			} else {
				$query = "SELECT cali1.item_id, COUNT(cali2.item_id) as children FROM ca_list_items cali1 LEFT JOIN ca_list_items cali2 ON cali2.parent_id=cali1.item_id WHERE cali1.parent_id = ".$parent."  AND cali1.deleted=0  GROUP BY cali1.item_id ".$order;
				//$query = "SELECT item_id FROM ca_list_items WHERE parent_id = ".$parent."  AND ca_list_items.deleted=0";
			   	$qr_result=$o_data->query($query);
			   	while($qr_result->nextRow()) {
					   	$vt_item = new ca_list_items($qr_result->get("item_id"));
						if($sort == 1) {
							if(!isset($key)) $key=0;
							$key++;
						} else {
							$key = strtolower(caRemoveAccents($vt_item->get("ca_list_item_labels.name_singular")));
						}
						$items[$key] = [
							"item_id"=>$qr_result->get("item_id"),
							"name_singular"=>$vt_item->get("ca_list_item_labels.name_singular"),
							"children"=>$qr_result->get("children")
						];
				}
				ksort($items);
			}
			$this->view->setVar("list_code", $list_code);
			$this->view->setVar("facet", $facet);
			$this->view->setVar("items", $items);
			$this->view->setVar("parent", $parent);
			print $this->render('fetch_html.php');
			exit();
		}

		public function AddItems() {
			$page = $this->request->getParameter("page", pString);
			$list_code = $this->request->getParameter("list_code", pString);
			$list_id = ca_lists::getListID($list_code);
			$items = $this->request->getParameter("items", pString);
			$items = trim($items);
			$result = [];
			$i = 0;
			$parents = [];
			$parents[0] = null;
			foreach(explode("\n", $items) as $item) {
				$item = str_replace(["\n","\r"], "", $item);
				preg_match("/[\t]+/", $item, $matches);
				
				if($matches) {
					$nb_tabs = strlen($matches[0]);
				} else {
					$nb_tabs=0;
					// = $item;
				}
				$item = str_replace("\t", "", $item);
				$item = trim($item);
				print $item."\r<br/>";
				$parents[$nb_tabs] = $item;
				$result[$i] = [
					"value" => $item
				];
				$vt_item = new ca_list_items();
				$vt_item->load(["idno"=>$item, "deleted"=>0, "list_id"=>$list_id]);
				if($vt_item->getPrimaryKey()) {
					$parent_name = $parents[$nb_tabs-1];

					$vt_parent = new ca_list_items();
					$vt_parent->load(["idno"=>$parent_name, "deleted"=>0, "list_id"=>$list_id]);
					$parent_id = $vt_parent->getPrimaryKey();
					if($parent_name && $parent_id) {
						$vt_item->setMode(ACCESS_WRITE);
						$vt_item->set(["parent_id"=>$parent_id, "is_enabled"=>true]);
						$vt_item->update();
						
						$result[$i] = [
							"result" => "ok",
							"reason" => "updated, and with parent"
						];
					} else {
						$vt_item->setMode(ACCESS_WRITE);
						$vt_item->set(["is_enabled"=>true]);
						$vt_item->update();
						$result[$i] = [
							"result" => "ok",
							"reason" => "ignored"
						];
					}
					continue;
				} else {
					//die("là");
					$vt_item->setMode(ACCESS_WRITE);
					if($nb_tabs>0) {
						$parent_name = $parents[$nb_tabs-1];
						$vt_parent = new ca_list_items();
						$vt_parent->load(["idno"=>$parent_name, "deleted"=>0, "list_id"=>$list_id]);
						$parent_id = $vt_parent->getPrimaryKey();
						$vt_item->set(["parent_id"=>$parent_id, "idno"=>$item, "list_id"=>$list_id, "access"=>1, "status"=>2, "is_enabled"=>true, "item_value"=>$item, "type_id"=>$this->opo_config->get("item_type_id")]);
					} else {
						$vt_item->set(["idno"=>$item, "list_id"=>$list_id, "access"=>1, "status"=>2, "is_enabled"=>true, "item_value"=>$item, "type_id"=>$this->opo_config->get("item_type_id")]);
					}

					$vt_item->insert();
				}
				
				if($vt_item->numErrors()) {
					$result[$i] = [
						"result" => "ko",
						"reason" => implode("\n", $vt_item->getErrors())
					];
					continue;
				}
				$vt_item->addLabel(["name_singular"=>$item, "name_plural"=>$item], $this->opo_config->get("locale_id"), null, true);
				$vt_item->update();
				if($vt_item->numErrors()) {
					$result[$i] = [
						"result" => "ko",
						"reason" => implode("\n", $vt_item->getErrors())
					];
					continue;
				}
			}

			//var_dump($result);die();
			$this->redirect("/gestion/index.php/simpleList/Editor/Thesaurus/page/".$page);
		}
 		public function Export() {
	 		//error_reporting(E_ALL);
			$id= $this->request->getParameter("id", pInteger);
			$this->view->setVar("object_id", $id);
			$item = new ca_objects($id);
			$this->view->setVar("item", $item);
			$this->view->setVar("template", $this->ConvertValuesToIdsInsideTemplate($this->opo_config->get("printTemplate")));
			$result = $this->render('export_html.php');
			print $result;
			exit();
		}

        public function Pdf() {
            //error_reporting(E_ALL);
            $id= $this->request->getParameter("id", pInteger);
            $this->view->setVar("object_id", $id);

            $item = new ca_objects($id);
            $this->view->setVar("item", $item);
            $this->view->setVar("template", $this->ConvertValuesToIdsInsideTemplate($this->opo_config->get("printTemplate")));
            $result = $this->render('pdf_html.php');

            $export_file = "archives_export_pdf_".$id.".html";
            $path = __DIR__."/../temp/".$export_file;
            unlink($path);
            file_put_contents($path, $result);
			// export QT_QPA_PLATFORM=offscreen &&  => no more needed
            $command = "cd ".__DIR__."/../temp && phantomjs rasterize.js archives_export_pdf_".$id.".html archives_export_pdf_".$id.".pdf A4";
            exec($command, $output);


            $files = [];
            //var_dump(__DIR__."/../temp/".$id);die();
            foreach(scandir(__DIR__."/../temp/".$id) as $file) {
                if(strpos($file,"_")>0 && (substr($file, -4)==".pdf")) {
                    $num = reset(explode("_", $file));
                    if(!$num*1) {
                        continue;
                    }
                    $files[] = $id."/".$file;
                }

            }
            $file = __DIR__."/../temp/".$id.".pdf";
            unlink($file);
            
            $command = "sleep 3 && cd ".__DIR__."/../temp && pdftk ".implode(" ", $files)." archives_export_pdf_".$id.".pdf cat output ".$id.".pdf";
            exec($command, $output);

			print json_encode(
				[
					"message"=>"Le fichier PDF est en cours de création",
					"URL"=>"/gestion/app/plugins/archives/temp/".$id.".pdf"
				]	
			);

			die();
        }

        private function ConvertValuesToIdsInsideTemplate($template) {
            foreach($template as $key=>$template_per_id) {
            	if($key == "_default") continue;
                //print $key."\n";
                $type_id  = $this->opo_list->getItemIDFromListByItemValue("object_types", $key);
                $template[$type_id] = $template[$key];
                unset($template[$key]);
            }
            return $template;

        }
 	}
 ?>
