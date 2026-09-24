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
require_once (__CA_APP_DIR__."/plugins/importInrap/lib/inrap_import_suivi.inc.php");


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

    /**
     * 23/09/2026 GM (ticket 8047) : le type d'import vient de la page ; il n'était jamais contrôlé.
     * Un type inconnu donnait un mappage vide, et removeAttributes(null) efface alors TOUS les
     * attributs de la fiche. Seuls les types de la configuration sont admis.
     */
    private function _typeValide($ps_type) {
        $va_mapping = $this->opo_config ? $this->opo_config->get('mapping') : [];
        return (is_string($ps_type) && is_array($va_mapping) && isset($va_mapping[$ps_type])
            && in_array($ps_type, ["mobilier", "documentation_ecrite", "documentation_numerique", "operation", "musee", "contenant_mobilier", "contenant_num", "contenant_doc"], true))
            ? $ps_type : null;
    }

    public function Index(){
        // 23/09/2026 GM (ticket 8047) : l'accueil liste les imports de la gestionnaire, et signale
        // en tête ceux qui se sont interrompus, avec de quoi les reprendre là où ils se sont arrêtés.
        $this->view->setVar("imports", inrap_import_etats_utilisateur($this->getRequest()->getUserID(), 30));
        $this->render("index_html.php");
    }

    /**
     * Affiche une erreur d'import dans le gabarit de l'application, au lieu du die() brut
     * qui renvoyait une page blanche et un message anglais. 14/09/2026 GM.
     */
    private function erreurImport($ps_message, $ps_detail = '', $ps_titre = null) {
        $this->view->setVar("message", $ps_message);
        $this->view->setVar("detail", $ps_detail);
        $this->view->setVar("titre", $ps_titre ?: "L'import n'a pas pu démarrer");
        $this->render("erreur_html.php");
    }

    public function SelectSheet(){
        $type = $this->getRequest()->getParameter("type", pString);
        if (!$this->_typeValide($type)) {
            return $this->erreurImport("Type d'import inconnu. Recommencez depuis l'accueil de l'import.");
        }
        $tempDir = __CA_APP_DIR__."/plugins/importInrap/temp/";
        // 23/09/2026 GM (ticket 8047) : l'horodatage sert d'identifiant d'import ; deux téléversements
        // dans la même seconde partageaient le même, donc le même fichier de travail et le même état.
        $date = time();
        while (glob($tempDir.$date.'.*')) { $date++; }

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

        // 23/09/2026 GM (ticket 8047) : le chemin du classeur vient de la page ; on n'accepte qu'un
        // fichier téléversé du répertoire temporaire du greffon.
        if (!($uploadedFile = inrap_import_classeur_valide($uploadedFile))) {
            return $this->erreurImport("Le fichier à importer est introuvable. Recommencez depuis l'accueil de l'import.");
        }
        if (!$this->_typeValide($type)) {
            return $this->erreurImport("Type d'import inconnu. Recommencez depuis l'accueil de l'import.");
        }

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

        if (!($uploadedFile = inrap_import_classeur_valide($uploadedFile))) {
            return $this->erreurImport("Le fichier à importer est introuvable. Recommencez depuis l'accueil de l'import.");
        }
        if (!$this->_typeValide($type)) {
            return $this->erreurImport("Type d'import inconnu. Recommencez depuis l'accueil de l'import.");
        }

        //Setting data
        // 23/09/2026 GM (ticket 8047) : l'écran d'association numérote ses colonnes d'après leur
        // position dans le tableur, en sautant les en-têtes vides ; la boucle de 0 à length-1 perdait
        // alors, sans rien dire, la dernière colonne associée dès qu'un en-tête vide était intercalé.
        // L'écran transmet désormais la liste exacte de ses indices.
        $va_indices = [];
        foreach (explode(',', (string)$this->getRequest()->getParameter("colonnes", pString)) as $v) {
            if (preg_match('/^[0-9]+$/', trim($v))) { $va_indices[] = (int)trim($v); }
        }
        if (!sizeof($va_indices)) { $va_indices = ($length > 0) ? range(0, $length - 1) : []; }
        $mapping_xlsx = [];
        foreach ($va_indices as $i){
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
        $data_to_map = [];
        $idnos = [];
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
        $jsonPath = inrap_import_chemin_json($dateTime);
        $vs_json = json_encode($data_to_map);
        file_put_contents($jsonPath, $vs_json);
        $this->view->setVar("data", $jsonPath);

        // 23/09/2026 GM (ticket 8047) — SITUATION DE CHAQUE LIGNE.
        // L'écran marquait « Doublon » toute ligne dont la fiche existait déjà, sans distinguer une
        // fiche déjà rattachée au mouvement du fichier d'une fiche qui ne l'est PAS. Après un import
        // interrompu puis relancé, la gestionnaire excluait donc de bonne foi des lignes jamais
        // traitées. On dit maintenant, ligne par ligne, ce qu'il en est vraiment.
        $mapping_type = $this->opo_config->get('mapping')[$type] ?? [];
        $va_tables = inrap_import_tables($type);
        $situation = inrap_import_situation_lignes($data_to_map, $type, $mapping_type);

        // Aperçu des fiches existantes (trois premières), comme auparavant.
        $duplicates = [];
        $t_check = ($va_tables['table'] === 'ca_collections') ? new ca_collections() : new ca_objects();
        foreach ($idnos as $key => $idno) {
            $vn_ligne = $key + 1;
            if (empty($idno) || empty($situation[$vn_ligne]['pk'])) continue;
            $entree = ['idno' => $idno, 'object_id' => $situation[$vn_ligne]['pk'], 'label' => '', 'location' => '',
                'import_label' => '', 'import_location' => '', 'statut' => $situation[$vn_ligne]['statut'],
                'mouvement' => $situation[$vn_ligne]['mouvement']];
            if (sizeof($duplicates) < 3 && $t_check->load($situation[$vn_ligne]['pk'])) {
                $entree['label'] = $t_check->getLabelForDisplay();
                if ($va_tables['table'] === 'ca_objects') {
                    $va_locs = $t_check->getRelatedItems('ca_storage_locations', ['returnAs' => 'array', 'limit' => 1]);
                    if (is_array($va_locs) && sizeof($va_locs) > 0) {
                        $va_loc = array_shift($va_locs);
                        $entree['location'] = $va_loc['label'] ?? '';
                    }
                }
                $excel_row = $data_to_map[$vn_ligne] ?? [];
                $entree['import_label'] = $excel_row['title'] ?? '';
                $import_location_raw = $excel_row['musee'] ?? ($excel_row['emplacement'] ?? '');
                if ($import_location_raw && is_numeric($import_location_raw)) {
                    $t_loc = new ca_storage_locations($import_location_raw);
                    $entree['import_location'] = $t_loc->getPrimaryKey() ? $t_loc->getLabelForDisplay() : $import_location_raw;
                } else {
                    $entree['import_location'] = (string)$import_location_raw;
                }
                $t_check->clear();
            }
            $duplicates[$key] = $entree;
        }
        $this->view->setVar("duplicates", $duplicates);
        $this->view->setVar("situation", $situation);

        // Un import du MÊME contenu, par la même personne, s'est-il interrompu ? Mieux vaut le
        // reprendre que le relancer : c'est la relance qui a produit l'écart du 21/09.
        $vs_empreinte = md5($vs_json);
        $interrompus = [];
        foreach (inrap_import_etats_utilisateur($this->getRequest()->getUserID(), 30) as $va_e) {
            if (!empty($va_e['interrompu']) && (($va_e['empreinte'] ?? '') === $vs_empreinte) && (($va_e['type'] ?? '') === $type) && (($va_e['id'] ?? '') !== $dateTime)) {
                $interrompus[] = $va_e;
            }
        }
        $this->view->setVar("interrompus", $interrompus);
        $vs_jeton = inrap_import_delivrer_jeton($dateTime, $this->getRequest()->getUserID(), $vs_empreinte, $type);
        if (!$vs_jeton) {
            return $this->erreurImport("Le serveur n'a pas pu enregistrer la sélection (disque plein ou droits d'écriture). Signalez-le à l'assistance.");
        }
        $this->view->setVar("jeton", $vs_jeton);

        $this->view->setVar("length", $length);
        $this->view->setVar("idnos", $idnos);
        $this->view->setVar("type", $type);
        $this->view->setVar("name", $name);
        $this->view->setVar("sheet", $sheetIndex);
        $this->view->setVar("file", $uploadedFile);

        $this->render("select_line_html.php");
    }

    /**
     * 23/09/2026 GM (ticket 8047) — MOTEUR D'IMPORT.
     *
     * L'import avance toujours d'une ligne RÉELLE par requête HTTP, pour que chaque requête reste
     * courte. Mais son état est désormais tenu côté serveur (inrap_import_suivi.inc.php) :
     *  - le pointeur de progression est celui du serveur : une requête rejouée ne retraite rien ;
     *  - un import interrompu reste « en cours » et se reprend depuis l'accueil de l'import ;
     *  - les lignes vides ou non cochées sont passées dans la même requête, au lieu de coûter
     *    chacune un aller-retour (le classeur du 23/09 comptait 746 lignes vides : 746 requêtes) ;
     *  - la fin d'import affiche un bilan de contrôle relu en base.
     */
    public function Import(){
        $o_req = $this->getRequest();
        $vs_titre = "L'import ne peut pas se poursuivre";
        if (strtoupper((string)$o_req->getRequestMethod()) !== 'POST') {
            return $this->erreurImport("Un import ne peut être lancé ou poursuivi que depuis ses propres pages. Reprenez depuis l'accueil de l'import.", '', $vs_titre);
        }
        $vs_id = inrap_import_id_depuis_json($o_req->getParameter("json", pString));
        if (!$vs_id || !is_file(inrap_import_chemin_json($vs_id))) {
            return $this->erreurImport("Cet import est introuvable : son fichier de travail n'existe plus. Recommencez depuis l'accueil de l'import.", '', $vs_titre);
        }
        $vs_jeton = preg_replace('/[^a-f0-9]/', '', (string)$o_req->getParameter("jeton", pString));
        $vb_nouvelle_selection = ((int)$o_req->getParameter("nouvelle_selection", pInteger) === 1);

        $vr_verrou = inrap_import_verrouiller($vs_id, 60);
        if (!$vr_verrou) {
            return $this->erreurImport("Cet import est déjà en cours de traitement, sans doute dans un autre onglet ou une autre fenêtre : laissez-le se poursuivre là-bas. "
                ."S'il ne progresse plus, il apparaîtra à l'accueil de l'import, avec un bouton pour le reprendre.", '', $vs_titre);
        }
        try {
            return $this->_importerSousVerrou($vs_id, $vs_jeton, $vb_nouvelle_selection);
        } finally {
            inrap_import_deverrouiller($vr_verrou);
        }
    }

    private function _importerSousVerrou($vs_id, $vs_jeton, $vb_nouvelle_selection) {
        $o_req = $this->getRequest();
        $vs_titre = "L'import ne peut pas se poursuivre";
        $vn_user = (int)$o_req->getUserID();
        $vs_json = (string)file_get_contents(inrap_import_chemin_json($vs_id));
        $json = json_decode($vs_json, true);
        if (!is_array($json)) {
            return $this->erreurImport("Le fichier de travail de cet import est illisible. Recommencez depuis l'accueil de l'import.", '', "L'import ne peut pas se poursuivre");
        }
        $etat = inrap_import_etat_lire($vs_id);
        if ($etat && ((int)($etat['user_id'] ?? 0) !== $vn_user)) {
            return $this->erreurImport("Cet import a été lancé par un autre utilisateur.", '', $vs_titre);
        }

        if ($vb_nouvelle_selection) {
            // Le jeton doit avoir été délivré par le serveur, pour ce fichier, à cet utilisateur.
            $va_jeton = inrap_import_jeton_delivre($vs_id, $vn_user, $vs_jeton);
            if (!$va_jeton || (($va_jeton['empreinte'] ?? '') !== md5($vs_json)) || (($va_jeton['type'] ?? '') !== (string)$o_req->getParameter("type", pString))) {
                return $this->erreurImport("Cette sélection n'est plus valable : la page est trop ancienne, ou le fichier a été relu depuis. Rechargez l'écran de sélection des lignes.", '', "L'import n'a pas pu démarrer");
            }
            // Même jeton, mais autre sélection (écran restauré par l'historique puis modifié) : on ne
            // l'ignore pas en silence.
            if ($etat && (($etat['jeton'] ?? '') === $vs_jeton) && (($etat['empreinte_selection'] ?? '') !== $this->_empreinteSelection())) {
                return $this->erreurImport("Cette sélection a déjà été lancée depuis cette page, avec d'autres lignes ou un autre choix. Pour lancer une nouvelle sélection, rechargez l'écran de sélection des lignes.", '', "L'import n'a pas pu démarrer");
            }
            if ($etat && (($etat['jeton'] ?? '') !== $vs_jeton)) {
                // La gestionnaire est revenue à l'écran de sélection et a validé une nouvelle
                // sélection : l'état précédent est mis de côté, pas détruit.
                @rename(inrap_import_chemin_etat($vs_id), inrap_import_dossier().$vs_id.'.etat-remplace-'.date('Ymd-His').'.json');
                $etat = null;
            }
            if (!$etat) {
                $etat = $this->_nouvelEtat($vs_id, $vs_jeton, $vs_json, $json);
            }
        } elseif (!$etat) {
            // Page de progression antérieure à cette version (import lancé avant la mise à jour) :
            // on reconstitue l'état depuis les champs qu'elle transporte, pour ne pas le perdre.
            // Seulement pour un fichier de travail produit par l'ancienne version (aucun jeton n'a
            // jamais été délivré pour lui) et récent.
            if (is_file(inrap_import_chemin_selection($vs_id)) || (time() - (int)@filemtime(inrap_import_chemin_json($vs_id)) > 2 * 86400)) {
                return $this->erreurImport("Cet import ne peut pas être poursuivi depuis cette page. Recommencez depuis l'accueil de l'import.", '', $vs_titre);
            }
            $etat = $this->_etatDepuisAnciennePage($vs_id, $vs_json, $json);
            if (!$etat) {
                return $this->erreurImport("Cet import ne peut pas être poursuivi depuis cette page. Recommencez depuis l'accueil de l'import.", '', $vs_titre);
            }
        } elseif (($etat['jeton'] ?? '') !== $vs_jeton) {
            return $this->erreurImport("Cette page d'import est périmée : une autre sélection a été lancée depuis sur le même fichier. Reprenez depuis l'accueil de l'import.", '', $vs_titre);
        }

        if (($etat['empreinte'] ?? '') !== md5($vs_json)) {
            return $this->erreurImport("Le fichier a été relu depuis le début de cet import, avec un autre contenu ou un autre choix de colonnes : l'import ne peut pas être poursuivi tel quel. Relancez-le depuis l'accueil de l'import.", '', $vs_titre);
        }
        if (($etat['statut'] ?? '') === 'abandonne') {
            return $this->erreurImport("Cet import a été abandonné le ".date('d/m/Y à H:i', (int)($etat['abandonne_le'] ?? $etat['maj'] ?? time())).". Relancez-le depuis l'accueil de l'import si nécessaire.", '', $vs_titre);
        }
        if (($etat['statut'] ?? '') === 'remplace') {
            return $this->erreurImport("Cet import s'était interrompu ; le même fichier a été relancé et mené à bien depuis. Il est donc clos. Consultez le bilan de l'import le plus récent depuis l'accueil de l'import.", '', $vs_titre);
        }
        if (($etat['statut'] ?? '') === 'termine') {
            return $this->_rendreBilan($etat, $json);
        }

        $type = $this->_typeValide($etat['type'] ?? null);
        if (!$type) {
            return $this->erreurImport("Type d'import inconnu. Recommencez depuis l'accueil de l'import.", '', $vs_titre);
        }
        $mapping = $this->opo_config->get('mapping');
        $mapping = $mapping[$type] ?? [];
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
        $type_id = $typeIds[$type] ?? null;
        $va_tables = inrap_import_tables($type);

        // no memory_limit
        ini_set('memory_limit', -1);
        // no time limit
        set_time_limit(0);

        $va_index = array_keys($json);
        $vn_total = sizeof($va_index);
        $va_sel = array_flip(array_map('intval', (array)$etat['selection']));
        $keys = (array)($etat['keys'] ?? []);
        $row = (int)($etat['prochaine'] ?? 0);
        $vn_traitees_ici = 0;

        while ($row < $vn_total) {
            $index = $va_index[$row];
            $data = $json[$index];
            if (!isset($va_sel[$row]) || !is_array($data) || inrap_normaliser_idno($data['idno'] ?? '') === '') {
                $row++;
                continue;
            }
            if ($vn_traitees_ici >= 1) { break; }   // une ligne réelle par requête

            // Ligne du TABLEUR telle que la voit la gestionnaire : $index compte les lignes de
            // données à partir de 1, l'en-tête occupe la ligne 1 du fichier.
            $vn_ligne_tableur = ((int)$index) + 1;
            $data['idno'] = inrap_normaliser_idno($data['idno']);

            // Une requête précédente est morte PENDANT cette ligne (délai dépassé, erreur fatale) ?
            // On la retente une fois ; à la seconde, on la met de côté pour ne pas boucler.
            $vn_tentatives = (int)($etat['tentatives'][$row] ?? 0);
            if (isset($etat['en_traitement']) && ((int)$etat['en_traitement'] === $row) && ($vn_tentatives >= 2)) {
                inrap_import_etat_consigner($etat, 'erreurs', ['row' => $row, 'ligne' => $vn_ligne_tableur, 'idno' => $data['idno'],
                    'message' => "le traitement de cette ligne a interrompu l'import à deux reprises ; elle est mise de côté"], 200);
                $etat['lignes_en_echec'][] = $row;
                unset($etat['en_traitement']);
                $row++;
                $etat['prochaine'] = $row;
                inrap_import_etat_ecrire($vs_id, $etat);
                continue;
            }
            $vs_mode = $etat['mode_doublons'] ?? 'overwrite';
            // La décision « la fiche existe / quel identifiant écrire » est prise UNE fois, avant la
            // première tentative, et mémorisée : si la requête meurt en cours de ligne, la tentative
            // suivante ne doit pas conclure « existe » d'après la fiche que la première a créée
            // (elle en ferait une seconde, préfixée, ou se contenterait d'un rattachement seul).
            if (!isset($etat['decisions'][$row])) {
              try {
                $vb_existe = false;
                if (($vs_mode === 'skip') || ($etat['idno_prefix'] && ($etat['idno_prefix_scope'] !== 'all'))) {
                    $t_check_dup = ($va_tables['table'] === 'ca_collections') ? new ca_collections() : new ca_objects();
                    $vb_existe = (bool)$t_check_dup->load(["idno" => $data["idno"], "deleted" => 0]);
                }
                $vs_idno_final = $data["idno"];
                // Apply idno prefix if set (duplicate avoidance)
                if ($etat['idno_prefix'] && !empty($data["idno"])) {
                    if ($etat['idno_prefix_scope'] === 'all' || $vb_existe) {
                        $vs_idno_final = inrap_normaliser_idno($etat['idno_prefix'] . $data["idno"]);
                        $vb_existe = false;
                    }
                }
                $etat['decisions'][$row] = ['existe' => $vb_existe, 'idno' => $vs_idno_final];
              } catch (\Throwable $e) {
                // Même la recherche de la fiche existante a échoué (identifiant inexploitable par la
                // base, par exemple) : la ligne est mise de côté, sans bloquer l'import.
                inrap_import_etat_consigner($etat, 'erreurs', ['row' => $row, 'ligne' => $vn_ligne_tableur, 'idno' => $data['idno'], 'message' => $e->getMessage()], 200);
                $etat['lignes_en_echec'][] = $row;
                error_log(sprintf('importInrap : ligne %d du tableur (%s) non importee — %s', $vn_ligne_tableur, $data['idno'], $e->getMessage()));
                $row++;
                $etat['prochaine'] = $row;
                inrap_import_etat_ecrire($vs_id, $etat);
                // Compte comme la ligne de cette requête : une panne passagère de la base ne doit pas
                // mettre en échec, en une seule requête, toutes les lignes restantes.
                $vn_traitees_ici++;
                continue;
              }
            }
            $vb_existe = (bool)$etat['decisions'][$row]['existe'];
            $data["idno"] = (string)$etat['decisions'][$row]['idno'];

            $etat['en_traitement'] = $row;
            $etat['tentatives'][$row] = $vn_tentatives + 1;
            if (!inrap_import_etat_ecrire($vs_id, $etat)) {
                // Sans état enregistré, on ne saurait ni reprendre ni éviter de retraiter : on s'arrête.
                return $this->erreurImport("L'état de l'import n'a pas pu être enregistré sur le serveur (disque plein ou droits d'écriture). L'import est suspendu avant la ligne ".$vn_ligne_tableur." ; signalez-le à l'assistance.", '', $vs_titre);
            }

            inrap_avertissements_prendre();
            if ($vn_tentatives >= 1) {
                inrap_avertir_ligne("cette ligne a été reprise après une interruption survenue pendant son traitement : vérifiez la fiche");
            }
            try {
                if (($vs_mode === 'skip') && $vb_existe) {
                    // « Ne pas modifier les fiches existantes » : la fiche n'est pas touchée, mais
                    // elle est rattachée au mouvement que demande la ligne.
                    $vb_rattache = false;
                    $keys = _rattacherAuMouvementSeulement($va_tables['table'], $data, $mapping, $keys, $vb_rattache);
                    if ($vb_rattache) { $etat['rattachements_seuls'][] = $row; }
                } elseif ($type == "operation"){
                    $keys = _importCollection($data, $mapping, $keys, $type_id);
                } else {
                    $keys = _importObject($data, $mapping, $keys, $type_id);
                }
                $etat['traitees'][] = $row;
                if (!empty($keys[$data['idno']])) { $etat['fiches'][$row] = (int)$keys[$data['idno']]; }
            } catch (\Throwable $e) {
                // 14/09/2026 GM — une ligne fautive ne fait plus tomber l'import : elle est mise de
                // côté, journalisée et listée dans le bilan. Réserve assumée : une exception
                // survenue APRÈS la création de la fiche peut laisser un enregistrement incomplet.
                inrap_import_etat_consigner($etat, 'erreurs', ['row' => $row, 'ligne' => $vn_ligne_tableur, 'idno' => $data['idno'],
                    'message' => $e->getMessage()], 200);
                $etat['lignes_en_echec'][] = $row;
                error_log(sprintf('importInrap : ligne %d du tableur (%s) non importee — %s [%s:%d]',
                    $vn_ligne_tableur, $data['idno'] !== '' ? $data['idno'] : 'sans identifiant',
                    $e->getMessage(), $e->getFile(), $e->getLine()));
            }
            foreach (inrap_avertissements_prendre() as $vs_avert) {
                inrap_import_etat_consigner($etat, 'avertissements', ['row' => $row, 'ligne' => $vn_ligne_tableur, 'idno' => $data['idno'], 'message' => $vs_avert], 500);
                error_log(sprintf('importInrap : ligne %d du tableur (%s) — %s', $vn_ligne_tableur, $data['idno'], $vs_avert));
            }
            unset($etat['en_traitement'], $etat['tentatives'][$row]);
            $row++;
            $vn_traitees_ici++;
            $etat['prochaine'] = $row;
            $etat['keys'] = $keys;
            if (!inrap_import_etat_ecrire($vs_id, $etat)) {
                return $this->erreurImport("L'état de l'import n'a pas pu être enregistré sur le serveur (disque plein ou droits d'écriture). La ligne ".$vn_ligne_tableur." a été traitée ; l'import est suspendu. Signalez-le à l'assistance.", '', $vs_titre);
            }
        }

        $etat['prochaine'] = $row;
        $etat['keys'] = $keys;
        if ($row >= $vn_total) {
            $etat['statut'] = 'termine';
            $etat['fin'] = time();
            inrap_import_etat_ecrire($vs_id, $etat);
            return $this->_rendreBilan($etat, $json, true);
        }
        inrap_import_etat_ecrire($vs_id, $etat);

        // Ligne du tableur de la prochaine ligne cochée, pour l'affichage.
        $vn_prochaine_ligne = null;
        for ($r = $row; $r < $vn_total; $r++) {
            if (isset($va_sel[$r])) { $vn_prochaine_ligne = ((int)$va_index[$r]) + 1; break; }
        }
        $this->view->setVar("etat", $etat);
        $this->view->setVar("jsonPath", inrap_import_chemin_json($vs_id));
        $this->view->setVar("prochaine_ligne", $vn_prochaine_ligne);
        $this->render("progress_html.php");
    }

    /**
     * Empreinte de la sélection soumise (lignes cochées et choix pour les fiches existantes).
     */
    private function _empreinteSelection() {
        $o_req = $this->getRequest();
        $va_sel = [];
        foreach (explode(";", (string)$o_req->getParameter("allRows", pString)) as $v) {
            if (preg_match('/^[0-9]+$/', trim($v))) { $va_sel[(int)trim($v)] = true; }
        }
        ksort($va_sel);
        $vs_mode = (string)$o_req->getParameter("dup_action", pString);
        $vs_prefix = ($vs_mode === 'prefix') ? trim((string)$o_req->getParameter("idno_prefix", pString)) : '';
        $vs_scope = ($vs_mode === 'prefix') ? (string)$o_req->getParameter("idno_prefix_scope", pString) : '';
        return md5(join(',', array_keys($va_sel)).'|'.$vs_mode.'|'.$vs_prefix.'|'.$vs_scope);
    }

    /**
     * État initial d'un import, à partir de l'écran de sélection des lignes.
     */
    private function _nouvelEtat($vs_id, $vs_jeton, $vs_json, array $json) {
        $o_req = $this->getRequest();
        $va_sel = [];
        foreach (explode(";", (string)$o_req->getParameter("allRows", pString)) as $v) {
            if (preg_match('/^[0-9]+$/', trim($v))) { $va_sel[(int)trim($v)] = true; }
        }
        $vs_mode = (string)$o_req->getParameter("dup_action", pString);
        if (!in_array($vs_mode, ['overwrite', 'prefix', 'skip'], true)) { $vs_mode = 'overwrite'; }
        $vs_prefix = trim((string)$o_req->getParameter("idno_prefix", pString));
        $vs_scope = ((string)$o_req->getParameter("idno_prefix_scope", pString) === 'all') ? 'all' : 'duplicates_only';
        if ($vs_mode !== 'prefix') { $vs_prefix = ''; }
        $type = (string)$this->_typeValide($o_req->getParameter("type", pString));
        $o_user = $o_req->getUser();
        return [
            'version' => INRAP_IMPORT_ETAT_VERSION,
            'id' => $vs_id,
            'jeton' => $vs_jeton !== '' ? $vs_jeton : bin2hex(random_bytes(8)),
            'empreinte' => md5($vs_json),
            'nom' => (string)$o_req->getParameter("name", pString),
            'type' => $type,
            'user_id' => (int)$o_req->getUserID(),
            'user_name' => $o_user ? (string)$o_user->get('user_name') : '',
            'debut' => time(),
            'statut' => 'en_cours',
            'mode_doublons' => $vs_mode,
            'idno_prefix' => $vs_prefix,
            'idno_prefix_scope' => $vs_scope,
            'empreinte_selection' => $this->_empreinteSelection(),
            'selection' => array_keys($va_sel),
            'total_entrees' => sizeof($json),
            'prochaine' => 0,
            'traitees' => [],
            'rattachements_seuls' => [],
            'fiches' => [],
            'keys' => [],
            'erreurs' => [], 'erreurs_total' => 0,
            'avertissements' => [], 'avertissements_total' => 0,
        ];
    }

    /**
     * Reprise d'une page de progression produite AVANT cette version : elle transporte la sélection,
     * la position, les fiches traitées et les erreurs dans ses champs cachés.
     */
    private function _etatDepuisAnciennePage($vs_id, $vs_json, array $json) {
        $o_req = $this->getRequest();
        $vs_all = (string)$o_req->getParameter("allRows", pString);
        if ($vs_all === '') { return null; }
        $va_sel = [];
        foreach (explode(";", $vs_all) as $v) { if (preg_match('/^[0-9]+$/', trim($v))) { $va_sel[(int)trim($v)] = true; } }
        $keys = json_decode((string)$o_req->getParameter("keys", pString), true);
        $errors = json_decode((string)base64_decode((string)$o_req->getParameter("errors", pString), true), true);
        $vs_prefix = trim((string)$o_req->getParameter("idno_prefix", pString));
        $o_user = $o_req->getUser();
        $etat = [
            'version' => INRAP_IMPORT_ETAT_VERSION, 'id' => $vs_id, 'jeton' => bin2hex(random_bytes(8)),
            'empreinte' => md5($vs_json), 'nom' => (string)$o_req->getParameter("name", pString),
            'type' => (string)$this->_typeValide($o_req->getParameter("type", pString)),
            'user_id' => (int)$o_req->getUserID(), 'user_name' => $o_user ? (string)$o_user->get('user_name') : '',
            'debut' => time(), 'statut' => 'en_cours', 'mode_doublons' => ($vs_prefix !== '' ? 'prefix' : 'overwrite'),
            'idno_prefix' => $vs_prefix,
            'idno_prefix_scope' => ((string)$o_req->getParameter("idno_prefix_scope", pString) === 'all') ? 'all' : 'duplicates_only',
            'selection' => array_keys($va_sel), 'total_entrees' => sizeof($json),
            'prochaine' => max(0, (int)$o_req->getParameter("start", pInteger)),
            // Les lignes cochées antérieures au point de reprise ont été traitées par l'ancienne version.
            'traitees' => array_values(array_filter(array_keys($va_sel), function($r) use ($o_req) { return $r < max(0, (int)$o_req->getParameter("start", pInteger)); })),
            'rattachements_seuls' => [], 'fiches' => [], 'keys' => is_array($keys) ? $keys : [],
            'erreurs' => [], 'erreurs_total' => 0, 'avertissements' => [], 'avertissements_total' => 0,
            'repris_depuis_ancienne_page' => true,
        ];
        foreach ((is_array($errors) ? $errors : []) as $va_e) {
            if (is_array($va_e)) { inrap_import_etat_consigner($etat, 'erreurs', ['ligne' => (int)($va_e['ligne'] ?? 0), 'idno' => (string)($va_e['idno'] ?? ''), 'message' => (string)($va_e['message'] ?? '')], 200); }
        }
        return $etat;
    }

    /**
     * Page de fin : bilan de contrôle relu en base, erreurs, avertissements, fiches traitées.
     */
    private function _rendreBilan(array $etat, array $json, $pb_journaliser = false) {
        $mapping = $this->opo_config->get('mapping');
        $bilan = inrap_import_bilan($etat, $json, $mapping[$etat['type']] ?? []);
        if ($pb_journaliser) {
            inrap_import_journaliser_bilan($etat, $bilan);
            // Les imports interrompus du MÊME contenu par la même personne sont couverts par celui-ci
            // (c'est le cas d'un import relancé au lieu d'être repris) : ils cessent d'être signalés
            // à l'accueil comme interrompus, mais restent consultables.
            // … mais seulement si CET import a traité toutes les lignes que l'autre n'avait pas faites.
            $va_faites_ici = array_flip(array_map('intval', (array)($etat['traitees'] ?? [])));
            foreach (inrap_import_etats_utilisateur((int)($etat['user_id'] ?? 0), 30) as $va_autre) {
                if (empty($va_autre['interrompu']) || (($va_autre['empreinte'] ?? '') !== ($etat['empreinte'] ?? '')) || (($va_autre['type'] ?? '') !== ($etat['type'] ?? '')) || (($va_autre['id'] ?? '') === ($etat['id'] ?? ''))) { continue; }
                $va_restantes = array_diff(array_map('intval', (array)($va_autre['selection'] ?? [])), array_map('intval', (array)($va_autre['traitees'] ?? [])));
                $vb_couvert = true;
                foreach ($va_restantes as $r) { if (!isset($va_faites_ici[$r])) { $vb_couvert = false; break; } }
                if (!$vb_couvert) { continue; }
                $vr = inrap_import_verrouiller($va_autre['id'], 2);
                if (!$vr) { continue; }
                $va_relu = inrap_import_etat_lire($va_autre['id']);
                if ($va_relu && (($va_relu['statut'] ?? '') === 'en_cours')) {
                    $va_relu['statut'] = 'remplace';
                    $va_relu['remplace_par'] = $etat['id'];
                    inrap_import_etat_ecrire($va_autre['id'], $va_relu);
                }
                inrap_import_deverrouiller($vr);
            }
        }
        $this->view->setVar("etat", $etat);
        $this->view->setVar("bilan", $bilan);
        $this->view->setVar("keys", (array)($etat['keys'] ?? []));
        $this->view->setVar("type", $etat['type']);
        $this->view->setVar("errors", (array)($etat['erreurs'] ?? []));
        $this->view->setVar("errors_total", (int)($etat['erreurs_total'] ?? 0));
        $this->render("imported_html.php");
    }

    /**
     * 23/09/2026 GM (ticket 8047) : abandon explicite d'un import interrompu, pour qu'il cesse
     * d'être signalé à l'accueil. Rien n'est défait en base : ce qui a été importé le reste.
     */
    public function Abandonner() {
        $o_req = $this->getRequest();
        if (strtoupper((string)$o_req->getRequestMethod()) !== 'POST') {
            return $this->erreurImport("Un import ne peut être abandonné que depuis l'accueil de l'import.", '', "L'import n'a pas pu être abandonné");
        }
        $vs_id = inrap_import_id_depuis_json($o_req->getParameter("json", pString));
        $vs_jeton = preg_replace('/[^a-f0-9]/', '', (string)$o_req->getParameter("jeton", pString));
        $vr_verrou = $vs_id ? inrap_import_verrouiller($vs_id, 10) : null;
        if (!$vr_verrou) {
            return $this->erreurImport("Cet import est introuvable, ou en cours de traitement dans un autre onglet.", '', "L'import n'a pas pu être abandonné");
        }
        try {
            $etat = inrap_import_etat_lire($vs_id);
            if (!$etat || (($etat['jeton'] ?? '') !== $vs_jeton) || ((int)($etat['user_id'] ?? 0) !== (int)$o_req->getUserID())) {
                return $this->erreurImport("Cet import est introuvable.", '', "L'import n'a pas pu être abandonné");
            }
            if (($etat['statut'] ?? '') === 'en_cours') {
                $etat['statut'] = 'abandonne';
                $etat['abandonne_le'] = time();
                inrap_import_etat_ecrire($vs_id, $etat);
                error_log(sprintf("importInrap : import %s (« %s ») abandonné par %s après la ligne %d", $vs_id, $etat['nom'] ?? '', $etat['user_name'] ?? '', (int)($etat['prochaine'] ?? 0)));
            }
        } finally {
            inrap_import_deverrouiller($vr_verrou);
        }
        return $this->Index();
    }
}
