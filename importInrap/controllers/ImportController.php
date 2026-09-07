<?php

use PhpOffice\PhpSpreadsheet\IOFactory;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ERROR);
require_once (__CA_APP_DIR__."/plugins/importInrap/lib/_importObject.php");
require_once (__CA_APP_DIR__."/plugins/importInrap/lib/_importCollection.php");
require_once (__CA_APP_DIR__."/plugins/importInrap/lib/migration_functionlib.php");


class ImportController extends ActionController{
    protected $opo_config,		// plugin configuration file
    $ops_plugin_name, $ops_plugin_path,
    $ops_user_groups, $opo_result_context;

    public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
        global $allowed_universes;
        
        parent::__construct($po_request, $po_response, $pa_view_paths);

        $this->ops_plugin_name = "importInrap";
        $this->ops_plugin_path = __CA_APP_DIR__."/plugins/".$this->ops_plugin_name;

        $vs_conf_file = $this->ops_plugin_path."/conf/".$this->ops_plugin_name.".conf";
        if(is_file($vs_conf_file)) {
           $this->opo_config = Configuration::load($vs_conf_file);
       }

       $va_groups = $this->getRequest()->getUser()->getUserGroups();
       $this->ops_user_groups = [];
        foreach($va_groups as $group) {
            if(in_array($group["code"], ["gestion","admin"])) continue;
           $this->ops_user_groups[] =$group["code"];
       }
    }

    public function Index(){
        $this->render("index_html.php");
    }

    public function SelectSheet(){
        $type = $this->getRequest()->getParameter("type", pString);
        $date = time();
        $tempDir = __CA_APP_DIR__."/plugins/importInrap/temp/";
        $uploadedFile = $tempDir . $date . ".xlsx";

        if (move_uploaded_file($_FILES['file']['tmp_name'], $uploadedFile)) {
            try {
                $inputFileType = IOFactory::identify($uploadedFile);
                $objReader = IOFactory::createReader($inputFileType);
                
                $objPHPExcel = $objReader->load($uploadedFile);
                $sheets = $objPHPExcel->getSheetNames();
                
            } catch(Exception $e) {
                die('Error loading file "'.pathinfo($uploadedFile,PATHINFO_BASENAME).'": '.$e->getMessage());
            }
            
            $this->view->setVar("sheets", $sheets);
            $this->view->setVar("type", $type);
            $this->view->setVar("date", $date);
            $this->view->setVar("name", $_FILES["file"]["name"]);
            $this->view->setVar("file", $uploadedFile);
            $this->render("select_sheet_html.php");
        }else{
            die("Erreur : Le fichier n'a pas pu être déplacé");
        }

    }

    public function SelectBeforeImport(){
        $uploadedFile = $this->getRequest()->getParameter("file", pString);
        $type = $this->getRequest()->getParameter("type", pString);
        $sheetIndex = $this->getRequest()->getParameter("sheet", pInteger);
        $name = $this->getRequest()->getParameter("name", pString);

      
        $inputFileType = IOFactory::identify($uploadedFile);
        $objReader = IOFactory::createReader($inputFileType);
        $objPHPExcel = $objReader->load($uploadedFile);
            
        //  Get worksheet dimensions
        $sheet = $objPHPExcel->getSheet($sheetIndex); 
        $highestColumn = $sheet->getHighestColumn();
        $mapping = $this->opo_config->get('mapping');
        $header = $sheet->rangeToArray('A1:' . $highestColumn . "1",NULL,TRUE,FALSE);
        $header = array_filter($header[0]);
        $this->view->setVar("type", $type);
        $this->view->setVar("sheet", $sheetIndex);
        $this->view->setVar("mapping", $mapping);
        $this->view->setVar("header", $header);
        $this->view->setVar("name", $name);
        $this->view->setVar("file", $uploadedFile);
        $this->render("before_import_html.php");
       
    }

    public function SelectLineBeforeImport(){
        $uploadedFile = $this->getRequest()->getParameter("file", pString);
        $name = $this->getRequest()->getParameter("name", pString);
        $type = $this->getRequest()->getParameter("type", pString);
        $sheetIndex = $this->getRequest()->getParameter("sheet", pInteger);
        $length = $this->getRequest()->getParameter("length", pInteger);

        //Setting data
        for ($i=0; $i<$length; $i++){
            $mapping_xlsx[$this->getRequest()->getParameter("column".$i, pString)] =  $this->getRequest()->getParameter("data".$i, pString);
        }

        // Excel File
        $inputFileType = IOFactory::identify($uploadedFile);
        $objReader = IOFactory::createReader($inputFileType);
        $objPHPExcel = $objReader->load($uploadedFile);
        $sheet = $objPHPExcel->getSheet($sheetIndex); 
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestColumn();
        $row=0;
        $datas=$sheet->rangeToArray('A1:' . $highestColumn.($highestRow+1),NULL,TRUE,FALSE);

        $headers = [];
        foreach ($datas as $col=>$data){
            if(!$row) {
                $headers = array_flip($data);
                $row++;
                continue;
            } 
            foreach($headers as $origin=>$target) {
                $data_to_map[$row][$mapping_xlsx[$origin]] = $data[$target];    
            }
            // 07/09/2026 GM (ticket 7987) : normalisation à la SOURCE, dès la lecture du tableur.
            // Les cellules Excel sont reprises telles quelles ligne au-dessus ; un identifiant
            // saisi avec une espace se propageait ensuite partout, JSON intermédiaire compris.
            if (isset($data_to_map[$row]["idno"])) { $data_to_map[$row]["idno"] = trim((string)$data_to_map[$row]["idno"]); }
            $idnos[] = $data_to_map[$row]["idno"]; 
            $row++;
        }
        $dateTime = mb_substr(end(explode("/", $uploadedFile)), 0, -5);
        $jsonPath = __CA_APP_DIR__."/plugins/importInrap/temp/".$dateTime.".json";
        file_put_contents($jsonPath, json_encode($data_to_map));
        $this->view->setVar("data", $jsonPath);

        // Duplicate detection: check which idnos already exist in the database
        $duplicates = [];
        $t_check = new ca_objects();
        foreach ($idnos as $key => $idno) {
            if (empty($idno)) continue;
            $t_check->load(["idno" => $idno, "deleted" => 0]);
            if ($t_check->getPrimaryKey()) {
                $vs_label = $t_check->getLabelForDisplay();
                $vs_location = '';
                $va_locs = $t_check->getRelatedItems('ca_storage_locations', ['returnAs' => 'array', 'limit' => 1]);
                if (is_array($va_locs) && sizeof($va_locs) > 0) {
                    $va_loc = array_shift($va_locs);
                    $vs_location = $va_loc['label'] ?? '';
                }

                // Data from the Excel file for this row
                // $key is 0-based from $idnos, $data_to_map is 1-based
                $excel_row = $data_to_map[$key + 1] ?? [];
                $import_label = $excel_row['title'] ?? '';
                // Try to resolve storage location name from the Excel value
                $import_location_raw = $excel_row['musee'] ?? ($excel_row['emplacement'] ?? '');
                $import_location = '';
                if ($import_location_raw) {
                    if (is_numeric($import_location_raw)) {
                        $t_loc = new ca_storage_locations($import_location_raw);
                        if ($t_loc->getPrimaryKey()) {
                            $import_location = $t_loc->getLabelForDisplay();
                        } else {
                            $import_location = $import_location_raw;
                        }
                    } else {
                        $import_location = $import_location_raw;
                    }
                }

                $duplicates[$key] = [
                    'idno' => $idno,
                    'object_id' => $t_check->getPrimaryKey(),
                    'label' => $vs_label,
                    'location' => $vs_location,
                    'import_label' => $import_label,
                    'import_location' => $import_location
                ];
                $t_check->clear();
            }
        }
        $this->view->setVar("duplicates", $duplicates);

        $this->view->setVar("length", $length);
        $this->view->setVar("idnos", $idnos);
        $this->view->setVar("type", $type);
        $this->view->setVar("name", $name);
        $this->view->setVar("sheet", $sheetIndex);
        $this->view->setVar("file", $uploadedFile);

        $this->render("select_line_html.php");
    }

    public function Import(){
        //Getting parameter
		$keys = $this->getRequest()->getParameter("keys", pString);
		$keys = json_decode($keys, true);
		if(!$keys) $keys = [];

        $length = $this->getRequest()->getParameter("length", pInteger);
		$start = $this->getRequest()->getParameter("start", pInteger);
		if(!$start) $start = 0;
		$this->view->setVar("start", $start);
        $uploadedFile = $this->getRequest()->getParameter("file", pString);
		$this->view->setVar("uploadedFile", $uploadedFile);
        $type = $this->getRequest()->getParameter("type", pString);
		$this->view->setVar("type", $type);
        $allRows = $this->getRequest()->getParameter("allRows", pString);
		$this->view->setVar("allRows", $allRows);
        $idnoPrefix = $this->getRequest()->getParameter("idno_prefix", pString);
		$this->view->setVar("idnoPrefix", $idnoPrefix);
        $idnoPrefixScope = $this->getRequest()->getParameter("idno_prefix_scope", pString);
		$this->view->setVar("idnoPrefixScope", $idnoPrefixScope);
        $authorizeLine = explode(";", $allRows);

        $removed = array_shift($authorizeLine);
		$this->view->setVar("removed", $removed);
        //TODO remove First Element
        $jsonPath = $this->getRequest()->getParameter("json", pString);
		$this->view->setVar("jsonPath", $jsonPath);
        $json = file_get_contents($jsonPath);
		//print $json;
        $json = json_decode($json, true);
        $json = array_slice($json, 0, count($authorizeLine) + 1);

        if($length === '') $length = count($authorizeLine) - 1;
		$this->view->setVar("length", $length);
    
		$end = false;
		//die();

		$page_size = 1;

		// no memory_limit
		ini_set('memory_limit', -1);
		// no time limit
		set_time_limit(0);

        //Get Mapping
        $mapping = $this->opo_config->get('mapping');
        $mapping = $mapping[$type];

        $row = 0;
        $typeIds = [
            "mobilier" => 24,
            "documentation_ecrite" => 26,
            "documentation_numerique" => 26,
            "operation" =>125,
            "musee" => 39119,
            "contenant_mobilier" => 28, 
            "contenant_num" => 1886,
            "contenant_doc" => 30
        ];
        $type_id = $typeIds[$type];
        foreach ($json as $index => $data){
            // ignore first line until the starting one has been reached
			if ($row < $start) {
                $row++;
                continue;
			}
            
			// if the end of the file has been reached, break the loop
			if ($row >= sizeof($json) - 1) {
				$end = true;
				break;
			}
			// if the line is not in the list of authorized lines, skip it
            if (in_array($row, $authorizeLine)){
                // Apply idno prefix if set (duplicate avoidance)
                if ($idnoPrefix && !empty($data["idno"])) {
                    if ($idnoPrefixScope === 'all') {
                        // Prefix all records in this import
                        $data["idno"] = $idnoPrefix . $data["idno"];
                    } else {
                        // Prefix only duplicates (idnos that already exist in the database)
                        $t_check_dup = new ca_objects();
                        $t_check_dup->load(["idno" => $data["idno"], "deleted" => 0]);
                        if ($t_check_dup->getPrimaryKey()) {
                            $data["idno"] = $idnoPrefix . $data["idno"];
                        }
                    }
                }

                if ($type == "operation"){
					//print "import de ".$index." (collection) : ".$data["idno"]."<br>";
                    $keys = _importCollection($data, $mapping, $keys, $type_id);
                }else{
					//print "import de ".$index." (objet) : ".$data["idno"]."<br>";
					try {
                        $keys = _importObject($data, $mapping, $keys, $type_id);
                    } catch (Exception $e) {
                        var_dump($e->getMessage());
                        var_dump($e->getFile(), $e->getLine());
                        var_dump($e->getTrace());
                        var_dump($e->getPrevious());
                        var_dump($data);
                        die();
                    }
                }
            }
			// if the number of rows processed has reached the page size, set the start for the next page
			if ($row >= $start + $page_size) {
				$this->view->setVar("start", $start + $page_size);
				break;
			}
			
            $row++;
        }
		//var_dump($keys);
		//die();
        
        $this->view->setVar("keys", $keys);
        
		if($end) {
			$this->render("imported_html.php");
		} else {
			$this->render("progress_html.php");
		}
    }
}