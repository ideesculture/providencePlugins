<?php
require_once(__CA_APP_DIR__."/plugins/importInrap/lib/inrap_idno.inc.php");
require_once(__CA_APP_DIR__."/plugins/importInrap/lib/inrap_lecture_tableur.inc.php");

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

    /**
     * Affiche une erreur d'import dans le gabarit de l'application, au lieu du die() brut
     * qui renvoyait une page blanche et un message anglais. 14/09/2026 GM.
     */
    private function erreurImport($ps_message, $ps_detail = '') {
        $this->view->setVar("message", $ps_message);
        $this->view->setVar("detail", $ps_detail);
        $this->render("erreur_html.php");
    }

    public function SelectSheet(){
        $type = $this->getRequest()->getParameter("type", pString);
        $date = time();
        $tempDir = __CA_APP_DIR__."/plugins/importInrap/temp/";

        // 14/09/2026 GM — CONTRÔLE DU TÉLÉVERSEMENT. Il n'y en avait aucun : ni code d'erreur,
        // ni extension, ni taille. Le fichier était renommé en « .xlsx » quoi qu'il arrive, puis
        // confié à PhpSpreadsheet. C'est ainsi qu'une PHOTOGRAPHIE JPEG de 1,8 Mo, prise au
        // téléphone et téléversée par erreur le 29/09/2025, a fini en « 1759151226.xlsx » et fait
        // mourir l'écran sur « Unable to identify a reader for this file ».
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            return $this->erreurImport("Aucun fichier n'a été reçu. Sélectionnez un fichier avant de valider.");
        }
        $vn_err = (int)$_FILES['file']['error'];
        if ($vn_err !== UPLOAD_ERR_OK) {
            $va_motifs = [
                UPLOAD_ERR_INI_SIZE   => "Le fichier dépasse la taille maximale autorisée par le serveur (".ini_get('upload_max_filesize').").",
                UPLOAD_ERR_FORM_SIZE  => "Le fichier dépasse la taille maximale autorisée par le formulaire.",
                UPLOAD_ERR_PARTIAL    => "Le fichier n'a été transféré que partiellement. Réessayez.",
                UPLOAD_ERR_NO_FILE    => "Aucun fichier n'a été sélectionné.",
                UPLOAD_ERR_NO_TMP_DIR => "Le serveur n'a pas de répertoire temporaire disponible.",
                UPLOAD_ERR_CANT_WRITE => "Le serveur n'a pas pu écrire le fichier sur le disque.",
                UPLOAD_ERR_EXTENSION  => "Le transfert a été interrompu par une extension PHP.",
            ];
            return $this->erreurImport($va_motifs[$vn_err] ?? "Le transfert du fichier a échoué.", "code PHP : ".$vn_err);
        }
        if ((int)$_FILES['file']['size'] === 0) {
            return $this->erreurImport("Le fichier reçu est vide.");
        }

        // L'extension d'ORIGINE fait foi : c'est elle que la gestionnaire voit, et c'est elle
        // qui trahit le fichier choisi par erreur. Le type MIME du navigateur, lui, n'est pas
        // fiable — il varie d'un poste à l'autre pour un même classeur.
        $vs_nom_origine = (string)$_FILES['file']['name'];
        $vs_ext = mb_strtolower(pathinfo($vs_nom_origine, PATHINFO_EXTENSION));
        $va_ext_admises = ['xlsx', 'xls', 'xlsm', 'csv', 'ods'];
        if (!in_array($vs_ext, $va_ext_admises, true)) {
            return $this->erreurImport(
                "« ".$vs_nom_origine." » n'est pas un tableur. L'import attend un fichier ".join(', ', $va_ext_admises).".",
                $vs_ext === '' ? "Le fichier n'a pas d'extension." : "Extension reçue : .".$vs_ext
            );
        }

        // On conserve l'extension réelle plutôt que d'imposer « .xlsx » à tout fichier : un .csv
        // renommé en .xlsx n'est pas un .xlsx, et le nom du fichier temporaire sert de trace.
        $uploadedFile = $tempDir . $date . "." . $vs_ext;

        if (move_uploaded_file($_FILES['file']['tmp_name'], $uploadedFile)) {
            try {
                $inputFileType = IOFactory::identify($uploadedFile);
                $objReader = IOFactory::createReader($inputFileType);
                
                $objPHPExcel = $objReader->load($uploadedFile);
                $sheets = $objPHPExcel->getSheetNames();
                
            } catch(\Throwable $e) {
                @unlink($uploadedFile);
                return $this->erreurImport(
                    "Le fichier « ".$vs_nom_origine." » n'a pas pu être ouvert comme un tableur. "
                   ."Vérifiez qu'il s'agit bien du bon fichier, et qu'il s'ouvre correctement dans Excel.",
                    $e->getMessage()
                );
            }
            
            $this->view->setVar("sheets", $sheets);
            $this->view->setVar("type", $type);
            $this->view->setVar("date", $date);
            $this->view->setVar("name", $vs_nom_origine);
            $this->view->setVar("file", $uploadedFile);
            $this->render("select_sheet_html.php");
        }else{
            return $this->erreurImport("Le fichier n'a pas pu être enregistré sur le serveur.",
                "destination : ".$uploadedFile);
        }

    }

    public function SelectBeforeImport(){
        $uploadedFile = $this->getRequest()->getParameter("file", pString);
        $type = $this->getRequest()->getParameter("type", pString);
        $sheetIndex = $this->getRequest()->getParameter("sheet", pInteger);
        $name = $this->getRequest()->getParameter("name", pString);

      
        // 14/09/2026 GM : chargement protege, comme dans SelectSheet(). Sans cela une erreur
        // de lecture a cette etape rend une page blanche, sans message ni retour possible.
        try {
            $inputFileType = IOFactory::identify($uploadedFile);
            $objReader = IOFactory::createReader($inputFileType);
            $objPHPExcel = $objReader->load($uploadedFile);
        } catch (\Throwable $e) {
            return $this->erreurImport("Le fichier « ".$name." » n'a pas pu être relu.", $e->getMessage());
        }

        //  Get worksheet dimensions
        $sheet = $objPHPExcel->getSheet($sheetIndex); 
        $highestColumn = $sheet->getHighestColumn();
        $mapping = $this->opo_config->get('mapping');
        // 14/09/2026 GM : lecture resiliente. Une cellule d'en-tete commencant par « = » est
        // typee formule par Excel ; son calcul echouait et emportait tout l'ecran.
        $formules = [];
        $header = inrap_plage_en_tableau($sheet, 'A1:' . $highestColumn . "1", $formules);
        inrap_journaliser_incidents($formules, $uploadedFile);
        $this->view->setVar("formules", $formules);
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
        // 14/09/2026 GM : chargement protege, meme motif que ci-dessus.
        try {
            $inputFileType = IOFactory::identify($uploadedFile);
            $objReader = IOFactory::createReader($inputFileType);
            $objPHPExcel = $objReader->load($uploadedFile);
        } catch (\Throwable $e) {
            return $this->erreurImport("Le fichier « ".$name." » n'a pas pu être relu.", $e->getMessage());
        }
        $sheet = $objPHPExcel->getSheet($sheetIndex); 
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestColumn();
        $row=0;
        // 14/09/2026 GM : meme lecture resiliente sur le corps du tableau. Mesure sur le
        // classeur 1777965966.xlsx (05/05/2026) : l'ancienne lecture mourait sur la cellule
        // U102 (« =chateau II? = si oui, prendre le code afan... », saisie prise pour une
        // formule) et perdait les 3 076 lignes du fichier ; la nouvelle les rend toutes.
        $formules = [];
        $datas = inrap_plage_en_tableau($sheet, 'A1:' . $highestColumn.($highestRow+1), $formules);
        inrap_journaliser_incidents($formules, $uploadedFile);
        $this->view->setVar("formules", $formules);

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
            if (isset($data_to_map[$row]["idno"])) { $data_to_map[$row]["idno"] = inrap_normaliser_idno($data_to_map[$row]["idno"]); }
            $idnos[] = $data_to_map[$row]["idno"]; 
            $row++;
        }
        // 14/09/2026 GM : mb_substr(..., 0, -5) retirait « .xlsx » en comptant les caracteres.
        // Depuis que l'extension d'origine est conservee (.xls, .csv), ce compte est faux.
        $dateTime = pathinfo($uploadedFile, PATHINFO_FILENAME);
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

		// 14/09/2026 GM : l'import avance une ligne par requete HTTP. La liste des lignes en
		// echec doit donc voyager de requete en requete, comme $keys, sans quoi le bilan final
		// ne montrerait que la derniere.
		//
		// Transport en base64 et non en JSON nu : getParameter(pString) fait un rawurldecode(),
		// qui mangerait un « % » present dans un message d'erreur, et le filtrage des parametres
		// peut retoucher les chevrons. Un message d'erreur contient n'importe quoi ; on le met
		// donc a l'abri du transport.
		$errors = json_decode((string)base64_decode((string)$this->getRequest()->getParameter("errors", pString), true), true);
		if(!is_array($errors)) $errors = [];
		$errors_total = (int)$this->getRequest()->getParameter("errors_total", pInteger);
		// Un import de plusieurs milliers de lignes entierement en echec ferait enfler le champ
		// cache a chaque requete. On detaille les 200 premieres, on compte toutes les autres.
		$errors_max = 200;

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
        // 10/09/2026 GM : troncature retiree. Elle gardait les n+1 PREMIERES lignes du
        // fichier, n etant le NOMBRE de lignes cochees, alors que $authorizeLine porte
        // leurs positions absolues. Toute selection ne commencant pas a la ligne 1
        // n'importait donc rien, en affichant « import termine ». Le filtre reel est
        // plus bas : if (in_array($row, $authorizeLine)).

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

                // 14/09/2026 GM — UNE LIGNE FAUTIVE NE FAIT PLUS TOMBER L'IMPORT.
                // L'ancien traitement affichait cinq var_dump() — dont la pile d'appels
                // complete — puis die(). Pour la gestionnaire : un mur de texte anglais au
                // milieu d'un import a moitie fait, sans savoir ce qui etait passe ni ou
                // reprendre. C'est l'un des sens du mot « fatale » dans les tickets 7042,
                // 7536 et 7947. Et l'import des OPERATIONS n'etait meme pas protege : une
                // exception y produisait une erreur PHP nue.
                //
                // La ligne en echec est desormais mise de cote et l'import continue. Elle est
                // journalisee, puis listee dans le bilan final avec son numero de ligne dans
                // le tableur, pour etre reprise a la main.
                //
                // RESERVE ASSUMEE : une exception survenue APRES la creation de la fiche peut
                // laisser un enregistrement incomplet en base. C'etait deja le cas avec die(),
                // qui abandonnait en outre toutes les lignes suivantes. Le bilan nomme la ligne
                // concernee precisement pour qu'elle soit verifiee.
                try {
                    if ($type == "operation"){
                        $keys = _importCollection($data, $mapping, $keys, $type_id);
                    }else{
                        $keys = _importObject($data, $mapping, $keys, $type_id);
                    }
                } catch (\Throwable $e) {
                    // Ligne du TABLEUR telle que la voit la gestionnaire : $index compte les
                    // lignes de donnees a partir de 1, l'en-tete occupe la ligne 1 du fichier.
                    $vn_ligne_tableur = ((int)$index) + 1;
                    $vs_idno = isset($data["idno"]) ? (string)$data["idno"] : '';
                    $errors_total++;
                    if (sizeof($errors) < $errors_max) {
                        $errors[] = [
                            'ligne'   => $vn_ligne_tableur,
                            'idno'    => $vs_idno,
                            'message' => $e->getMessage(),
                        ];
                    }
                    error_log(sprintf('importInrap : ligne %d du tableur (%s) non importee — %s [%s:%d]',
                        $vn_ligne_tableur, $vs_idno !== '' ? $vs_idno : 'sans identifiant',
                        $e->getMessage(), $e->getFile(), $e->getLine()));
                }
            }
			// if the number of rows processed has reached the page size, set the start for the next page
			// 10/09/2026 GM : le test etait evalue AVANT $row++, si bien que la ligne
			// frontiere etait rejouee a la requete suivante — deux traitements par ligne.
			if ($row >= $start + $page_size - 1) {
				$this->view->setVar("start", $row + 1);
				break;
			}
			
            $row++;
        }
		//var_dump($keys);
		//die();
        
        $this->view->setVar("keys", $keys);
        $this->view->setVar("errors", $errors);
        $this->view->setVar("errors_total", $errors_total);
        
		if($end) {
			$this->render("imported_html.php");
		} else {
			$this->render("progress_html.php");
		}
    }
}