<?php
require_once(__CA_APP_DIR__ . '/plugins/exportInrap/lib/h2p/autoloader.php');

use H2P\Converter\PhantomJS;
use H2P\TempFile;

class ExportController extends ActionController
{
    protected $opo_config,        // plugin configuration file
        $ops_plugin_name, $ops_plugin_path,
        $ops_user_groups, $opo_result_context;


    public function __construct(&$po_request, &$po_response, $pa_view_paths = null)
    {
        global $allowed_universes;

        parent::__construct($po_request, $po_response, $pa_view_paths);

        $this->ops_plugin_name = "importInrap";
        $this->ops_plugin_path = __CA_APP_DIR__ . "/plugins/" . $this->ops_plugin_name;

        $vs_conf_file = $this->ops_plugin_path . "/conf/" . $this->ops_plugin_name . ".conf";
        if (is_file($vs_conf_file)) {
            $this->opo_config = Configuration::load($vs_conf_file);
        }

        $this->path = "https://" . __CA_SITE_HOSTNAME__ . __CA_URL_ROOT__ . "/app/plugins/exportInrap/";
        $this->dir = __CA_BASE_DIR__ . "/app/plugins/exportInrap/";

        $va_groups = $this->getRequest()->getUser()->getUserGroups();
        $this->ops_user_groups = [];
        foreach ($va_groups as $group) {
            if (in_array($group["code"], ["gestion", "admin"])) continue;
            $this->ops_user_groups[] = $group["code"];
        }
    }

    public function Index()
    {
        $set_id = $this->request->getParameter("set_id", pInteger);
        $occ_id = $this->request->getParameter("occurrence_id", pInteger);
        $this->view->setVar("occ_id", $occ_id);
        $this->view->setVar("set_id", $set_id);
        $this->render("index_html.php");
    }

    public function Export()
    {
        $allObjects = explode("_&_", $this->request->getParameter("allExportedObject", pString));
        $allMetada = explode("_&_", $this->request->getParameter("allExportedObjectMetadata", pString));
        $allOpMetada = explode("_&_", $this->request->getParameter("allOperationMetadata", pString));
        $regroupement = $this->request->getParameter("regroupVal", pString);
        $code_inrap = $this->request->getParameter("codeInrap", pString);
        $stylePage = $this->request->getParameter("stylePage", pString);
        $titre = $this->request->getParameter("titre", pString);

        if ($set_id = $this->request->getParameter("set_id", pInteger)) {
            $set = new ca_sets($set_id);
            $allSetItems = $set->getWithTemplate("^ca_set_items.item_id");
            $allSetItems = explode(";", $allSetItems);
            foreach ($allSetItems as  $itemId) {
                $vt_set_items = new ca_set_items($itemId);
                $allObjects[] = $vt_set_items->getWithTemplate("^ca_set_items.row_id");
            }
        }
        if ($occ_id = $this->request->getParameter("occ_id", pInteger)) {
            $occ = new ca_occurrences($occ_id);
            $allObjects = explode(";", $occ->getWithTemplate("^ca_objects.object_id"));
            if (!$titre) {
                $titre = $occ->getWithTemplate("^ca_occurrences.preferred_labels");
            }
        }

        if ($stylePage == "texte-deux-images-gauche" || $stylePage == "texte-deux-images-droite" || $stylePage == "ensemble-2-par-page-texte-droite") {
            $slice = 2;
        } else {
            $slice = 1;
        }
        if ($code_inrap) {
            $op = new ca_collections();
            $op->load(["idno" => $code_inrap, "deleted" => 0]);

            foreach ($allOpMetada as $metadata) {
                if ($metadata == "ca_storage_locations_entree") {
                    $opData[$metadata] = strip_tags($op->getWithTemplate("<unit relativeTo='ca_storage_locations' restrictToRelationshipTypes='lieu_entree'>^ca_storage_locations.preferred_labels</unit>"));
                    continue;
                }
                if ($metadata == "ca_places") {
                    $opData[$metadata] = strip_tags($op->getWithTemplate("<unit relativeTo='ca_places'>^ca_places.preferred_labels</unit>"));
                    continue;
                }
                if ($metadata == "ca_entities_RO") {
                    $opData[$metadata] = strip_tags($op->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.displayname</unit>"));
                    continue;
                }
                if ($metadata == "ca_entities_SRA") {
                    $opData[$metadata] = strip_tags($op->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='attribue' restrictToTypes='sra'>^ca_entities.preferred_labels.displayname</unit>"));
                    continue;
                }
                if ($metadata == "ca_entities_DIR") {
                    $opData[$metadata] = strip_tags($op->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='DIR'>^ca_entities.preferred_labels.displayname</unit>"));
                    continue;
                }
                $opData[$metadata] = strip_tags($op->getWithTemplate("^" . $metadata));
            }
        } else {
            $opData = [];
        }
        foreach ($allObjects as $object_id) {
            if (!$object_id) continue;
            $object = new ca_objects($object_id);

            foreach ($allMetada as $metadata) {
                if ($regroupement == "mat") {
                    $regroup = explode(";", $object->getWithTemplate("^ca_objects.inrap_materiaux"))[0];
                } else if ($regroupement == "dom") {
                    $regroup = explode(";", $object->getWithTemplate("^ca_objects.inrap_domaine"))[0];
                } else if ($regroupement == "inv") {
                    $regroup = explode(";", $object->getWithTemplate("^ca_objects.idno"))[0];
                } else if ($regroupement == "iso") {
                    $regroup = explode(";", $object->getWithTemplate("^ca_objects.inrap_numero_isolation"))[0];
                } else if ($regroupement == "pcm") {
                    $regroup = explode(";", $object->getWithTemplate("^ca_objects.inrap_musee_chrono"))[0];
                } else  if ($regroupement == "matm") {
                    $regroup = explode(";", $object->getWithTemplate("^ca_objects.inrap_musee_materiaux"))[0];
                } else if ($regroupement == "pc") {
                    $regroup = explode(";", $object->getWithTemplate("^ca_objects.inrap_periode_chrono_site"))[0];
                } else {
                    $regroup = $object->getWithTemplate("^ca_objects.type_mobilier");
                }

                if ($metadata == "ca_object_representations") {
                    //$reps = explode(";", $object->getWithTemplate("^ca_object_representations.representation_id"));
					$reps = array_keys($object->getRepresentationIDs());
                    $reps = array_slice($reps, 0, $slice);
                    $data[$regroup][$object_id][$metadata] = $reps;
                    continue;
                }
                if ($metadata == "ca_objects") {
                    $data[$regroup][$object_id][$metadata] = strip_tags($object->getWithTemplate("<unit relativeTo='ca_objects.related' restrictToTypes='contenant'>^ca_objects.preferred_labels</unit>"));
                    continue;
                }
                if ($metadata == "ca_occurrences.etat_sanitaires") {

                    $data[$regroup][$object_id][$metadata] = strip_tags($object->getWithTemplate("<unit relativeTo='ca_occurrences' restrictToTypes='suivi'>^ca_occurrences.inrap_etat_sanitaire</unit>"));
                    continue;
                }
                if ($metadata == "ca_storage_locations") {
                    $data[$regroup][$object_id][$metadata] = strip_tags($object->getWithTemplate("<unit relativeTo='ca_storage_locations' restrictToRelationshipTypes='related'>^ca_storage_locations.preferred_labels</unit>"));
                    continue;
                }
                if ($metadata == "ca_collections") {
                    $data[$regroup][$object_id][$metadata] = strip_tags($object->getWithTemplate("<unit relativeTo='ca_collections'>^ca_collections.preferred_labels</unit>"));
                    continue;
                }
                if ($metadata == "ca_movements") {
                    $data[$regroup][$object_id][$metadata] = strip_tags($object->getWithTemplate("<unit relativeTo='ca_movements' resctrictToTypes='movement'>^ca_movements.preferred_labels</unit>"));
                    continue;
                }
                if ($metadata == "ca_occurrences.traitements") {

                    $data[$regroup][$object_id][$metadata] = strip_tags($object->getWithTemplate("<unit relativeTo='ca_occurrences' restrictToTypes='suivi'>^ca_occurrences.inrap_traitement_txt</unit>"));
                    continue;
                }
                if ($metadata == "ca_objects.dimensions") {
                    $data[$regroup][$object_id][$metadata] = strip_tags($object->getWithTemplate("<ifdef code='ca_objects.dimensions.dimensions_height'>H. ^ca_objects.dimensions.dimensions_height x </ifdef><ifdef code='ca_objects.dimensions.dimensions_width'>L. ^ca_objects.dimensions.dimensions_width</ifdef><ifdef code='ca_objects.dimensions.dimensions_depth'> L. ^ca_objects.dimensions.dimensions_depth</ifdef><ifdef code='ca_objects.dimensions.dimension_epaisseur'> x E. ^ca_objects.dimensions.dimension_epaisseur</ifdef><ifdef code='ca_objects.dimensions.dimensions_poids'>. Poids ^ca_objects.dimensions.dimensions_poids</ifdef>"));
                    continue;
                }
                if ($metadata == "ca_objects.dimensions_cm") {
                    $data[$regroup][$object_id][$metadata] = strip_tags($object->getWithTemplate("<ifdef code='ca_objects.dimensions_cm.dimensions_height_cm'>H. ^ca_objects.dimensions_cm.dimensions_height_cm x </ifdef><ifdef code='ca_objects.dimensions_cm.dimensions_width_cm'>L. ^ca_objects.dimensions_cm.dimensions_width_cm </ifdef><ifdef code='ca_objects.dimensions_cm.dimensions_depth_cm'> L. ^ca_objects.dimensions_cm.dimensions_depth_cm</ifdef><ifdef code='ca_objects.dimensions_cm.dimension_epaisseur_cm'> x E. ^ca_objects.dimensions_cm.dimension_epaisseur_cm</ifdef><ifdef code='ca_objects.dimensions.dimensions_poids'>. Poids ^ca_objects.dimensions.dimensions_poids</ifdef>"));
                    continue;
                }
                $data[$regroup][$object_id][$metadata] = strip_tags($object->getWithTemplate("^" . $metadata));
            }
        }
        if ($regroupement == "inv" || $regroupement == "iso" || $regroupement == "pcm") {
            ksort($data);
        }
        $newData = [];
        foreach ($data as $dat) {
            foreach ($dat as $obj => $row) {
                $newData[$obj] = $row;
            }
        }


        $result =  $this->generateHTML($newData, $stylePage, $code_inrap, $opData, $titre);
        $timeStamp = time();

        file_put_contents($this->dir . "tmp/pdf-content_" . $timeStamp . ".html", $result);
        // var_dump($result);die();
        // Rendering with PhantomJS (alternative)
        $input = new TempFile($result, 'html'); // Make sure the 2nd parameter is 'html'
        $converter = new PhantomJS(array(
            // You should use 'search_paths' when you want to point the phantomjs binary to somewhere else
            // 'search_paths' => shell_exec('which phantomjs'),
            'orientation' => PhantomJS::ORIENTATION_LANDSCAPE,
            'format' => PhantomJS::FORMAT_A4,
            'zoomFactor' => 0.4,
            'border' => '1cm',
            //'header' => array(
            //    'height' => '0.3cm',
            //    'content' => "<span style='font-size:6pt;'>Catalogue raisonné Louis Floutier</span>",
            //),
            'footer' => array(
                'height' => '0.3cm',
                'content' => "<div style='font-size:6pt; font-style:italic; text-align:right'>$titre - Inrap " . date("d/m/Y") . " - " . $code_inrap . " - {{pageNum}} / {{totalPages}}</div>",
            )
        ));
        $converter->convert($input, $this->dir . "tmp/temp_" . $timeStamp . ".pdf");
        //if($output === array()) {
        if (is_file($this->dir . "tmp/temp_" . $timeStamp . ".pdf")) {
            // No problem, so showing pdf
            $cmd = "pdftk " . $this->dir . "tmp/temp_" . $timeStamp . ".pdf output " . $this->dir . "tmp/rapport_" . $timeStamp . ".pdf";
            // var_dump($cmd);die();
            $result = exec($cmd, $output);
            if ($output === array() or is_null($output)) {
                $this->view->setVar("filename", "export_operation_" . $code_inrap . ".pdf");
                $this->view->setVar("file", $this->dir . "tmp/rapport_" . $timeStamp . '.pdf');
				print "Redirection dans 10s vers <a href='".$this->path . "tmp/rapport_" . $timeStamp . ".pdf'>".$this->path . "tmp/rapport_" . $timeStamp . ".pdf</a>";
				print "<script>setTimeout(function() {window.location.href='" . $this->path . "tmp/rapport_" . $timeStamp . ".pdf'}, 10000);</script>";die();
                header("location: " . $this->path . "tmp/rapport_" . $timeStamp . ".pdf");
            } else {
                var_dump($output);
                die("hein ?");
            }
            // die();
        }
    }
    private function generateHTML($datas, $stylePage, $code_inrap, $op_data, $titre)
    {
        $metadataName = [
            "ca_objects.oa_number" => "Numéro OA",
            "ca_objects.inrap_unite_enregistrement" => "Unité d'enregistrement",
            "ca_objects.idno" => "Numéro d'inventaire",
            "ca_objects.inrap_numero_isolation" => "Numéro d'isolation",
            "ca_objects.inrap_periode_chrono_site" => "Période chronologique",
            "ca_objects.dateMillesime" => "Datation",
            "ca_objects.inrap_domaine" => "Domaine",
            "ca_objects.type_mobilier" => "Identification",
            "ca_objects.description" => "Description",
            "ca_objects.inrap_materiaux" => "Matériaux",
            "ca_objects.inrap_precisions_matiere" => "Précision matière",
            "ca_objects.quantification_mobilier" => "Quantification mobilier",
            "ca_objects.etat_fragmentaire_inrap" => "État fragmentaire",
            "ca_objects.fragments_mobilier" => "Nombres de restes",
            "ca_objects.dimensions" => "Dimensions (mm)",
            "ca_storage_locations" => "Emplacement de stockage",
            "ca_occurrences.etat_sanitaires" => "Etat sanitaire",
            "ca_occurrences.traitements" => "Traitements",
            "ca_objects.num_temp_lab.num_temp_num" => "Numéro d'entrée au laboratoire",
            "ca_movements" => "Mouvements",
            "ca_entities_DIR" => "Direction Inrap",
            "ca_entities_SRA" => "SRA",
            "ca_collections.statut_collection" => "Statut de la collection",
            "ca_collections.inrap_type_op.inrap_type_ope" => "Type de l'opération",
            "ca_collections.inrap_annee_inter" => "Année d'intervention",
            "ca_collections.inrap_volume_total" => "Volume totale de la collection (m3)",
            "ca_entities_RO" => "Responsable d'opération",
            "ca_collections.lieudit" => "Lieu-dit",
            "ca_places" => "Commune",
            "ca_collections.idno" => "Code Inrap",
            "ca_collections.oa_number" => "Numéro OA",
            "ca_storage_locations_entree" => "Lieu d'entrée de la collection",
            "ca_objects" => "Contenant lié",
            "ca_objects.INRAP_note.INRAP_note_note_txt" => "Note",
            "ca_objects.inrap_valeur_assurance" => "Valeur d'assurance",
            "ca_objects.inrap_type_struct_archeo" => "Nature du contexte enfouissement",
            "ca_object.type_contexte_archeologique" => "Type de contexte archéologique",
            "ca_objects.num_temp_lab.num_temp_lab_lab" => "Laboratoire",
            "ca_objects.dimensions_cm" => "Dimensions (cm)",
            "ca_objects.inrap_musee_materiaux" => "Matériaux",
            "ca_objects.date_periode_chrono_site" => "Date chronologique",
            "ca_objects.inrap_musee_chrono" => "Période chronologique",
            "ca_collections" => "Opération"
        ];
        // Loading styles
        $result = "<head><meta charset=\"UTF-8\" />";
        $result .= "<link rel=\"stylesheet\" href=\"" . $this->dir . "assets/css/pdf.css\" type=\"text/css\" media=\"screen,print\">";
        $result .= "<title> Export.pdf</title>";
        $result .= "</head><body>";
        if (!$titre) {
            $titre = "Export de l'opération " . $code_inrap;
        }


        $result .= "<div class='page-blanche'><div class='texte-deux-images-gauche'>";
        $result .= "<div class='content'>";
        $result .= "<h1 style='width:19cm'><img src='/var/www/comodo/collectiveaccess/providence/app/plugins/exportInrap/assets/img/inrap_0.jpg' style='width:3cm;float:left;' />";
        $result .= "<br/><br/>" . $titre . "</h1>";
        if ($code_inrap) {
            $result .= "<h2> Résumé de l'opération </h2>";

            foreach ($op_data as $key => $value) {
                $result .= "<span>" . $metadataName[$key] . " : </span>" . $value . "<br/>";
            }
        }

        $result .= "</div></div>";

        $result .= "</div>";

        $i = 0;
        $result .= "<div class='page-blanche'>";
        if ($stylePage == "texte-deux-images-gauche" || $stylePage == "texte-deux-images-droite") {
            $nbPages = sizeof($datas);
        } else if ($stylePage == "ensemble-2-par-page-texte-bas" || $stylePage == "ensemble-2-par-page-texte-droite") {
            $nbPages = ceil(sizeof($datas) / 2);
        } else {
            $nbPages = ceil(sizeof($datas) / 4);
        }
        $nbPages += 1;
        foreach ($datas as $data) {
            if ($stylePage == "texte-deux-images-gauche") {
                if ($i != 0) {
                    $result .= "<div class='page-blanche'>";
                }
                $result .= "<div class='texte-deux-images-gauche'>";
                $result .= "<div class='media-bar'>";
                foreach ($data["ca_object_representations"] as $rep) {
                    $rep = new ca_object_representations($rep);
                    $vs_media_url = $rep->getMediaPath("media", "page");
                    if (!$vs_media_url) continue;
                    $result .= "<div class='media'><img src=\"" . $vs_media_url . "\" /></div> ";
                }

                $result .= "</div>";



                $result .= "<div class='content'>";
                if ($data["ca_objects.idno"]) {
                    $result .= "<span>" . $metadataName["ca_objects.idno"] . " : </span>" . $data["ca_objects.idno"] . "<br/>";
                }
                foreach ($data as $key => $value) {
                    if ($key == "ca_object_representations") continue;
                    if ($key == "ca_objects.idno") continue;
					if ($key == "ca_objects.inrap_valeur_assurance") $value.=" €";
                    if (!$value) continue;

                    $result .= "<span>" . $metadataName[$key] . " : </span>" . $value . "<br/>";
                }
                $result .= "</div></div></div>";
            } else if ($stylePage == "texte-deux-images-droite") {

                if ($i != 0) {
                    $result .= "<div class='page-blanche'>";
                }
                $result .= "<div class='texte-deux-images-droite'>";
                $result .= "<div class='content'>";
                if ($data["ca_objects.idno"]) {
                    $result .= "<span>" . $metadataName["ca_objects.idno"] . " : </span>" . $data["ca_objects.idno"] . "<br/>";
                }
                foreach ($data as $key => $value) {
                    if ($key == "ca_object_representations") continue;
                    if ($key == "ca_objects.idno") continue;
                    if (!$value) continue;
					if ($key == "ca_objects.inrap_valeur_assurance") $value.=" €";
                    $result .= "<span>" . $metadataName[$key] . " : </span>" . $value . "<br/>";
                }
                $result .= "</div>";
                $result .= "<div class='media-bar'>";
                foreach ($data["ca_object_representations"] as $rep) {
                    $rep = new ca_object_representations($rep);
                    $vs_media_url = $rep->getMediaPath("media", "page");
                    if (!$vs_media_url) {
                        continue;
                    }
                    $result .= "<div class='media'><img src=\"" . $vs_media_url . "\" /></div> ";
                }

                $result .= "</div></div></div>";
            } else if ($stylePage == "ensemble-2-par-page-texte-bas") {
                if (($i % 2) == 0 && $i != 0) {
                    $result .= "</div></div><div class='page-blanche'><div class='ensemble-2-par-page-texte-bas'>";
                }
                if ($i == 0) {
                    $result .= "<div class='ensemble-2-par-page-texte-bas'>";
                }

                $result .= "<div class='media'>";
                $result .= "<div class='image'> ";
                foreach ($data["ca_object_representations"] as $rep) {
                    $rep = new ca_object_representations($rep);
                    $vs_media_url = $rep->getMediaPath("media", "page");
                    if (!$vs_media_url) {
                        continue;
                    }
                    $result .= "<img src=\"" . $vs_media_url . "\" />";
                }

                $result .= "</div><p>";
                if ($data["ca_objects.idno"]) {
                    $result .= "<span>" . $metadataName["ca_objects.idno"] . " : </span>" . $data["ca_objects.idno"] . "<br/>";
                }
                foreach ($data as $key => $value) {
                    if ($key == "ca_object_representations") continue;
                    if ($key == "ca_objects.idno") continue;
                    if (!$value) continue;
					if ($key == "ca_objects.inrap_valeur_assurance") $value.=" €";
                    $result .= "<span>" . $metadataName[$key] . " : </span>" . $value . "<br/>";
                }
                $result .= "</p></div>";
            } else if ($stylePage == "ensemble-2-par-page-texte-droite") {
                if (($i % 2) == 0 && $i != 0) {
                    $result .= "</div></div><div class='page-blanche'><div class='ensemble-2-par-page-texte-droite'>";
                }
                if ($i == 0) {
                    $result .= "<div class='ensemble-2-par-page-texte-droite'>";
                }

                $result .= "<div class='media'>";
                $result .= "<div class='image'>";
                foreach ($data["ca_object_representations"] as $rep) {
                    $rep = new ca_object_representations($rep);
                    $vs_media_url = $rep->getMediaPath("media", "page");
                    if (!$vs_media_url) {
                        continue;
                    }
                    $result .= "<img src=\"" . $vs_media_url . "\" />";
                }
                $result .=  "</div><p>";
                if ($data["ca_objects.idno"]) {
                    $result .= "<span>" . $metadataName["ca_objects.idno"] . " : </span>" . $data["ca_objects.idno"] . "<br/>";
                }
                foreach ($data as $key => $value) {
                    if ($key == "ca_object_representations") continue;
                    if ($key == "ca_objects.idno") continue;
                    if (!$value) continue;
					if ($key == "ca_objects.inrap_valeur_assurance") $value.=" €";
                    $result .= "<span>" . $metadataName[$key] . " : </span>" . $value . "<br/>";
                }
                $result .= "</p></div>";
            } else if ($stylePage == "ensemble-4-par-page-texte-droite") {
                if (($i % 4) == 0 && $i != 0) {
                    $result .= "</div></div><div class='page-blanche'><div class='ensemble-4-par-page-texte-droite'>";
                }
                if ($i == 0) {
                    $result .= "<div class='ensemble-4-par-page-texte-droite'>";
                }
                $result .= "<div class='media'>";
                $result .= "<div class='image'>";
                foreach ($data["ca_object_representations"] as $rep) {
                    $rep = new ca_object_representations($rep);
                    $vs_media_url = $rep->getMediaPath("media", "page");
                    if (!$vs_media_url) {
                        continue;
                    }
                    $result .= "<img src=\"" . $vs_media_url . "\" />";
                }
                $result .=  "</div><p>";
                if ($data["ca_objects.idno"]) {
                    $result .= "<span>" . $metadataName["ca_objects.idno"] . " : </span>" . $data["ca_objects.idno"] . "<br/>";
                }
                foreach ($data as $key => $value) {
                    if ($key == "ca_object_representations") continue;
                    if ($key == "ca_objects.idno") continue;
                    if (!$value) continue;
					if ($key == "ca_objects.inrap_valeur_assurance") $value.=" €";
                    $result .= "<span>" . $metadataName[$key] . " : </span>" . $value . "<br/>";
                }
                $result .= "</p></div>";
            } else if ($stylePage == "ensemble-4-par-page-texte-bas") {
                if (($i % 4) == 0 && $i != 0) {
                    $result .= "</div></div><div class='page-blanche'><div class='ensemble-4-par-page-texte-bas'>";
                }
                if ($i == 0) {
                    $result .= "<div class='ensemble-4-par-page-texte-bas'>";
                }
                $result .= "<div class='media'>";

                $result .= "<div class='image'>";
                foreach ($data["ca_object_representations"] as $rep) {
                    $rep = new ca_object_representations($rep);
                    $vs_media_url = $rep->getMediaPath("media", "page");
                    if (!$vs_media_url) {
                        continue;
                    }
                    $result .= "<img src=\"" . $vs_media_url . "\" />";
                }
                $result .=  "</div><p>";
                if ($data["ca_objects.idno"]) {
                    $result .= "<span>" . $metadataName["ca_objects.idno"] . " : </span>" . $data["ca_objects.idno"] . "<br/>";
                }
                foreach ($data as $key => $value) {
                    if ($key == "ca_object_representations") continue;
                    if ($key == "ca_objects.idno") continue;
                    if (!$value) continue;
					if ($key == "ca_objects.inrap_valeur_assurance") $value.=" €";
                    $result .= "<span>" . $metadataName[$key] . " : </span>" . $value . "<br/>";
                }
                $result .= "</p></div>";
            }
            $i++;
        }

        if (($stylePage == "ensemble-4-par-page-texte-droite" || $stylePage == "ensemble-4-par-page-texte-bas") && $i % 4 != 0) {
            $result .= "</div></div>";
        }

        if (($stylePage == "ensemble-2-par-page-texte-droite" || $stylePage == "ensemble-2-par-page-texte-bas") && $i % 2 != 0) {
            $result .= "</div></div>";
        }

        $result .= "</body>";
        return $result;
    }
}
