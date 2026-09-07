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
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        $row=0;
        $datas=$sheet->rangeToArray('A1:' . $highestColumn.$highestRow,NULL,TRUE,FALSE);
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
            $idnos[] = $data_to_map[$row]["idno"]; 
            $row++;
        }
        $dateTime = mb_substr(end(explode("/", $uploadedFile)), 0, -5);
        $jsonPath = __CA_APP_DIR__."/plugins/importInrap/temp/".$dateTime.".json";
        file_put_contents($jsonPath, json_encode($data_to_map));
        $this->view->setVar("data", $jsonPath);
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
		$this->view->setVar("length", $length);
		$start = $this->getRequest()->getParameter("start", pInteger);
		if(!$start) $start = 0;
		$this->view->setVar("start", $start);
        $uploadedFile = $this->getRequest()->getParameter("file", pString);
		$this->view->setVar("uploadedFile", $uploadedFile);
        $type = $this->getRequest()->getParameter("type", pString);
		$this->view->setVar("type", $type);
        $allRows = $this->getRequest()->getParameter("allRows", pString);
		$this->view->setVar("allRows", $allRows);
        $authorizeLine = explode(";", $allRows);
		
        $removed = array_shift($authorizeLine);
		$this->view->setVar("removed", $removed);
        //TODO remove First Element
        $jsonPath = $this->getRequest()->getParameter("json", pString);
		$this->view->setVar("jsonPath", $jsonPath);
        $json = file_get_contents($jsonPath);
		//print $json;
        $json = json_decode($json, true);
		$end = false;
		//die();

		$page_size = 10;

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
			if ($row >= sizeof($json)-1) {
				$end = true;
				break;
			}
			// if the line is not in the list of authorized lines, skip it
            if (in_array($row, $authorizeLine)){
                if ($type == "operation"){
					//print "import de ".$index." (collection) : ".$data["idno"]."<br>";
                    $keys = _importCollection($data, $mapping, $keys, $type_id);
                }else{
					//print "import de ".$index." (objet) : ".$data["idno"]."<br>";
					$keys = _importObject($data, $mapping, $keys, $type_id);
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