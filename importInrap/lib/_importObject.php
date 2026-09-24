<?php
require_once(__DIR__.'/inrap_echec.inc.php');
require_once(__CA_APP_DIR__."/plugins/importInrap/lib/inrap_idno.inc.php");
function _importObject($data_to_map, $mapping, $keys, $type_id){
    // 07/09/2026 GM (ticket 7987) : les identifiants arrivaient du tableur avec des espaces
    // parasites, jamais retirés. Relevé en base : 1423 contenants et 208 opérations vivantes
    // dont l'idno porte une espace en tête ou en queue. Deux effets : la fiche est introuvable
    // par une recherche sur l'identifiant propre, et surtout le `load` ci-dessous ne retrouve
    // pas la fiche existante — l'import en crée alors une seconde, d'où les doublons.
    if (isset($data_to_map["idno"])) { $data_to_map["idno"] = inrap_normaliser_idno($data_to_map["idno"]); }
    $vt_object = new ca_objects();
    $opo_app_plugin_manager = new ApplicationPluginManager();

    $vt_object->load(["idno" => $data_to_map["idno"], "deleted" => 0]);
    $vt_object->setMode(ACCESS_WRITE);
    if (!$vt_object->getPrimaryKey()){
        $vt_object->set(array('idno' => $data_to_map["idno"],'type_id' => $type_id,'locale_id'=>2));//Define some intrinsic data.
        $vt_object->insert();//Insert the object
        if ($vt_object->numErrors()){
            // 14/09/2026 GM : exception au lieu de var_dump()+die(), pour que la ligne soit
            // mise de cote par ImportController et que l'import se poursuive.
            inrap_echec_ligne("creation de l'objet « ".$data_to_map["idno"]." »", $vt_object);
        }
    }
    $containers = [];
    $keys[$data_to_map["idno"]] = $vt_object->getPrimaryKey();
    foreach($data_to_map as $mk=>$data){
        $map = $mapping[$mk];
        if ($mk == "idno" || $mk == "" || $mk == "unite_poids"){continue;}
        if ($mk == "poids"){
            $data = strval($data);
            if (!empty($data)){
                $data .= ($data_to_map["unite_poids"]) ? $data_to_map["unite_poids"] : "g";
                $data = str_replace(",", ".", $data);
            }
        }
        // Corrigé le 23/03/2026 (#8176 bug 3) : le tableur est en mm, pas en cm
        if ($mk == "hauteur" || $mk == "profondeur" || $mk == "longueur" || $mk == "epaisseur" || $mk == "diametre" || $mk == "largeur"){
            $data = strval($data);
            if (!empty($data)){
                $data .= " mm";
                $data = str_replace(",", ".", $data);
            }
        }
        if ($mk == "title"){ 
            $vt_object->removeAllLabels();
            $vt_object->addLabel(array("name" => $data), 2, null, true);
            continue;
        }
        if ($mk == "titre_doc"){ 
            $vt_object->removeAllLabels();
            $vt_object->addLabel(array("name" => $data), 2, null, false);
            continue;
        }
        if ($map["container"]){
            $element_code = end(explode(".", $map["metadata"]));
            $element_mere_code = explode(".", $map["metadata"]);
            $element_mere_code = $element_mere_code[1];
            $containers[$element_mere_code][$element_code] = $data; 
            // The import of the containers should be at the end, when all the fetches will be grouped
            continue;
        }
        if ($map["relation"]){
            switch ($map["relation"]):
                case "ca_collections":
                    if (!$data) continue;

                    $vt_occ = new ca_collections();
                    $vt_occ->load(["idno" => inrap_normaliser_idno($data), "deleted" =>0]);
                    $primKey = $vt_occ->getPrimaryKey();
                    if ($primKey){
                        // 23/09/2026 GM (ticket 8047) : la pile d'erreurs du modèle garde celles des
                        // attributs validés plus haut (removeAttributes() déclenche un update()) ;
                        // sans ce nettoyage, le contrôle ci-dessous les imputait au rattachement.
                        $vt_object->clearErrors();
                        $vt_object->addRelationship("ca_collections", $primKey, $map["relation_type"]);
                        if ($vt_object->numErrors()){
                            inrap_echec_ligne("rattachement a l'operation « ".$data." »", $vt_object);
                        }

                    } else {
                        // 23/09/2026 GM (ticket 8047) : abandon silencieux jusqu'ici.
                        inrap_avertir_ligne("opération « ".$data." » introuvable : la fiche n'y a pas été rattachée");
                    }
                    break;
                case "ca_entities":
                    if (!$data) continue;

                    $entity_id = getEntityIDByIdno($data);
                    if ($entity_id){
                        $vt_object->removeRelationships("ca_entities", $map["relation_type"]);
                        $vt_object->addRelationship("ca_entities", $entity_id, $map["relation_type"]);
                    } else {
                        inrap_avertir_ligne("personne ou organisme « ".$data." » introuvable : relation non créée");
                    }
                    break;
                case "ca_places":
                   /* $vt_place = new ca_places($data);
                    if (!$vt_place->getPrimaryKey()){
                        continue;
                    }else{
                        $vt_object->addRelationship("ca_places", $key,$map["relation_type"]);
                        $vt_object->update();
                    }*/
                    if (!$data) continue;
                    $vt_rel_place = getPlaceIDByName($data, $map["item_type"]);
                    if ($vt_rel_place){
                        $vt_object->removeRelationships("ca_places", $map["relation_type"]);
                        $vt_object->addRelationship("ca_places", $vt_rel_place,$map["relation_type"]);
                    } else {
                        inrap_avertir_ligne("lieu « ".$data." » introuvable : relation non créée");
                    }
                    break;
                case "ca_objects":
                    if (!$data) continue;
                    $vt_rel_obj = getObjectID($data, $data, $map["item_type"]);
                    $vs_rel_op = getCollectionID($data_to_map["code_inrap"], $data_to_map["code_inrap"], 125);
                    $vt_rel_op = new ca_collections($vs_rel_op);

                    $contenantLiesOperation = $vt_rel_op->getWithTemplate("<unit relativeTo='ca_objects' restrictToTypes='contenants' delimiter=';'>^ca_objects.object_id</unit>");
                    $contenantLiesOperation = explode(";", $contenantLiesOperation);
                
                    if(!$vt_rel_op->getPrimaryKey()) {
                        inrap_echec_ligne("aucune operation ne porte l'identifiant « ".$data_to_map["code_inrap"]." »");
                    }

                    if(!$vt_rel_obj) {
                        $vt_contenant = new ca_objects();
                        $vt_contenant->setMode(ACCESS_WRITE);

                        $vt_contenant->load(["idno" => inrap_normaliser_idno($data), "deleted" => 0]);

                        if (!in_array($vt_contenant->getPrimaryKey(), $contenantLiesOperation)){
                            $vt_contenant->set(array('idno' => inrap_normaliser_idno($data), 'type_id' => $map["item_type"], 'locale_id'=>2));
                            $vt_contenant->insert();

                            $vt_contenant->addLabel(array("name" => $data), 2, null, true);
                            $vt_contenant->update();

                            if ($vt_contenant->numErrors()){
                                inrap_echec_ligne("creation du contenant « ".$data." »", $vt_contenant);
                            }

                            $vt_contenant->addAttribute($data_to_map["contenant_ref"], 'referentiel');
                            $vt_contenant->update();

                            if ($vt_contenant->numErrors()){
                                inrap_echec_ligne("referentiel du contenant « ".$data." »", $vt_contenant);
                            }

                            $vt_contenant->addRelationship("ca_collections", $vs_rel_op, 152);
                            $vt_contenant->update();

                            if ($vt_contenant->numErrors()){
                                inrap_echec_ligne("rattachement du contenant « ".$data." » a l'operation", $vt_contenant);
                            }
                        }
                        
                        $vt_rel_obj = $vt_contenant->getPrimaryKey();
                    }

                    // 10/09/2026 GM (ticket 7988) : deux relations partaient sans que $vt_rel_obj soit teste.
                    // Le cas est atteignable : quand le contenant n'existe pas, $vt_contenant->load() echoue et
                    // getPrimaryKey() rend null ; or l'operation sans contenant donne $contenantLiesOperation
                    // = [""], et in_array(null, [""]) est VRAI en comparaison souple — la creation est donc
                    // sautee et $vt_rel_obj reste null. addRelationship() ne refuse pas ce null : il le relit
                    // comme un IDNO et rattache la premiere fiche a identifiant vide venue.
                    if (!$vt_rel_obj) {
                        global $VERBOSE;
                        if ($VERBOSE) { print "\tContenant non resolu, relations ignorees : \"{$data}\"\n"; }
                        inrap_avertir_ligne("contenant « ".$data." » non résolu : la fiche n'y a pas été rattachée");
                        break;
                    }
                    $vt_object->addRelationship("ca_objects", $vt_rel_obj, 177);
                    $vt_rel_op->addRelationship("ca_objects", $vt_rel_obj, 152);
                    $vt_rel_op->update();
                    $vt_object->update();
                    _inrapSignalerValeursRefusees($vt_object);   // l'update() valide aussi les attributs en attente
                    break;
                case "ca_storage_locations":
                    if (!$data) continue;

                    // 10/09/2026 GM (ticket 7988) : un identifiant numerique venu du tableur etait rattache
                    // sans verifier que l'emplacement existe. Et le removeRelationships() qui precede detruit
                    // le rattachement legitime AVANT de savoir si le nouveau tiendra.
                    if (is_numeric($data)) {
                        $vn_loc_id = (int)$data;
                        $vt_loc_ref = ($vn_loc_id > 0) ? new ca_storage_locations($vn_loc_id) : null;
                        if ($vt_loc_ref && $vt_loc_ref->getPrimaryKey() && ((int)$vt_loc_ref->get('deleted') !== 1)) {
                            $vt_object->removeRelationships("ca_storage_locations", $map["relation_type"]);
                            $vt_object->addRelationship("ca_storage_locations", $vn_loc_id, $map["relation_type"]);
                        } else {
                            global $VERBOSE;
                            if ($VERBOSE) { print "\tEmplacement {$data} inconnu, relation ignoree\n"; }
                            inrap_avertir_ligne("emplacement n° ".$data." inconnu : la fiche n'y a pas été rangée");
                        }
                        continue;
                    }
                    $vt_rel_storage = getStorageLocationID($data, $map["item_type"]);
                    
                    if ($vt_rel_storage){
                        $vt_object->removeRelationships("ca_storage_locations", $map["relation_type"]);
                        $vt_object->addRelationship("ca_storage_locations", $vt_rel_storage, $map["relation_type"]);
                    } else {
                        inrap_avertir_ligne("emplacement « ".$data." » introuvable : la fiche n'y a pas été rangée");
                    }
                    break;
                case "ca_movements":
                    if (!$data) continue;

                    $vt_mouv = new ca_movements();
                    $vt_mouv->load(["idno" => inrap_normaliser_idno($data), "deleted" => 0]);
                    if ($vt_mouv->getPrimaryKey()){
                        $vt_object->clearErrors();   // voir le rattachement à l'opération, plus haut
                        $vt_object->addRelationship("ca_movements", $vt_mouv->getPrimaryKey(), $map["relation_type"]);
                        if ($vt_object->numErrors()){
                            inrap_echec_ligne("rattachement au mouvement « ".$data." »", $vt_object);
                        }
                    } else {
                        // 23/09/2026 GM (ticket 8047) : le rattachement au mouvement — souvent la raison
                        // d'être du fichier — était abandonné sans un mot quand l'identifiant ne
                        // correspondait à aucun mouvement.
                        inrap_avertir_ligne("mouvement « ".$data." » introuvable : la fiche n'y a pas été rattachée");
                    }
                    break;
                default:
                    break;
            endswitch;
            continue;
        }
        $metadata = explode(".",$map["metadata"])[1];
        $vt_object->removeAttributes($metadata);
        // 23/09/2026 GM (ticket 8047) : removeAttributes() enregistre les attributs en attente
        // (update()) ; une valeur refusée par Comodo (format invalide, valeur obligatoire, etc.) y
        // laisse une erreur que l'enregistrement suivant effaçait sans trace. On la signale. NB : un
        // élément de liste inconnu est, le plus souvent, ignoré sans erreur par le cœur de
        // CollectiveAccess (ListAttributeValue, requireValue = 0) : il n'est alors pas signalé.
        _inrapSignalerValeursRefusees($vt_object);

        // Corrigé le 23/03/2026 (#8176 bugs 1+2) : si la valeur est au format "Label (idno)",
        // extraire l'idno pour le matching sur liste d'autorité
        if (is_string($data) && preg_match('/\(([^)]+)\)\s*$/', $data, $matches)) {
            $data = $matches[1];
        }

        if ($map["canBeMultiple"] == 1){
            $data = explode(";", $data);
            foreach ($data as $singleData){
                $singleData = trim($singleData);
                if (preg_match('/\(([^)]+)\)\s*$/', $singleData, $matches)) {
                    $singleData = $matches[1];
                }
                $vt_object->addAttribute(array($metadata => $singleData), $metadata);
            }
            continue;
        }
        $vt_object->addAttribute(array($metadata => $data), $metadata);
    }
    $vt_object->update();
	
    if ($vt_object->numErrors()){
        inrap_echec_ligne("enregistrement de l'objet « ".$data_to_map["idno"]." »", $vt_object);
    }

    //On traite les containers ici

    //var_dump($containers);die();
    foreach ($containers as $metadata => $container){
        if (!$metadata) continue;
        $vt_object->removeAttributes($metadata);
        _inrapSignalerValeursRefusees($vt_object);
        $vt_object->update();
    }
	
    foreach ($containers as $metadata => $container){
        if (!$metadata) continue;
        $vt_object->addAttribute($container, $metadata);
        if($vt_object->numErrors()) {
            inrap_echec_ligne("ajout du conteneur « ".$metadata." » sur « ".$data_to_map["idno"]." »", $vt_object);
        }
        
        $vt_object->update();
    }

    $opo_app_plugin_manager->hookSaveItem(
        array(
            'id' => $vt_object->get('object_id'),
            'table_num' => $vt_object->tableNum(),
            'table_name' => $vt_object->tableName(),
            'instance' => $vt_object
        )
    );

    return $keys;
}
/**
 * 23/09/2026 GM (ticket 8047) — RATTACHEMENT SEUL d'une fiche existante au(x) mouvement(s) de la ligne.
 *
 * Utilisé quand la gestionnaire a choisi de NE PAS MODIFIER les fiches existantes (« Exclure les
 * doublons ») : jusqu'ici, ces lignes étaient purement et simplement écartées, rattachement au
 * mouvement compris. Or c'est souvent la raison d'être du fichier — un bordereau de mouvement.
 * C'est ainsi que 29 objets préexistants n'ont jamais rejoint le mouvement 22095775.
 *
 * Désormais la fiche existante n'est pas touchée — aucun champ, aucun libellé, aucune autre
 * relation — mais elle est rattachée au mouvement demandé par la ligne, si elle ne l'est pas déjà.
 *
 * On N'APPELLE PAS les crochets d'enregistrement (hookSaveItem) : celui de prepopulateInrap met la
 * fiche en file de recalcul complet, qui recompose son titre d'après le gabarit — vérifié sur la
 * preprod, « Alliage cuivreux » y devient « alliage cuivreux ». addRelationship() n'insère que la
 * ligne de relation, sans réenregistrer la fiche. Pour que le moteur de recherche en tienne compte
 * (facette « mouvement »), on met seulement la fiche en file de RÉINDEXATION, qui ne modifie rien.
 *
 * @param string $ps_table ca_objects ou ca_collections
 * @param bool $pb_rattache mis à true si au moins un rattachement a réellement été posé
 * @return array $keys complété de [idno => pk]
 * @throws Exception (via inrap_echec_ligne) si la fiche est introuvable ou si le rattachement échoue
 */
function _rattacherAuMouvementSeulement($ps_table, $data_to_map, $mapping, $keys, &$pb_rattache = null) {
    $pb_rattache = false;
    $vs_idno = inrap_normaliser_idno($data_to_map["idno"] ?? '');
    $vt_fiche = ($ps_table === 'ca_collections') ? new ca_collections() : new ca_objects();
    if ($vs_idno === '' || !$vt_fiche->load(["idno" => $vs_idno, "deleted" => 0])) {
        inrap_echec_ligne("rattachement seul : aucune fiche « ".$vs_idno." » dans Comodo");
    }
    $vn_pk = (int)$vt_fiche->getPrimaryKey();
    $keys[$vs_idno] = $vn_pk;
    $vt_fiche->setMode(ACCESS_WRITE);

    $vs_rel_table = ($ps_table === 'ca_collections') ? 'ca_movements_x_collections' : 'ca_movements_x_objects';
    $vs_pk = ($ps_table === 'ca_collections') ? 'collection_id' : 'object_id';
    foreach ($data_to_map as $mk => $data) {
        $map = $mapping[$mk] ?? null;
        if (!is_array($map) || (($map["relation"] ?? '') !== 'ca_movements')) { continue; }
        $vs_mvt = inrap_normaliser_idno($data);
        if ($vs_mvt === '') { continue; }
        $vt_mouv = new ca_movements();
        if (!$vt_mouv->load(["idno" => $vs_mvt, "deleted" => 0])) {
            inrap_avertir_ligne("mouvement « ".$vs_mvt." » introuvable : la fiche n'y a pas été rattachée");
            continue;
        }
        $vn_mid = (int)$vt_mouv->getPrimaryKey();
        // Déjà rattachée, quel que soit le type de relation : rien à faire.
        $o_db = new Db();
        $qr = $o_db->query("SELECT 1 FROM {$vs_rel_table} WHERE movement_id = ? AND {$vs_pk} = ? LIMIT 1", [$vn_mid, $vn_pk]);
        if ($qr->nextRow()) { continue; }
        $vt_fiche->clearErrors();
        $vt_fiche->addRelationship("ca_movements", $vn_mid, $map["relation_type"]);
        if ($vt_fiche->numErrors()) {
            inrap_echec_ligne("rattachement de « ".$vs_idno." » au mouvement « ".$vs_mvt." »", $vt_fiche);
        }
        $pb_rattache = true;
    }
    if ($pb_rattache && function_exists('inrap_import_reindexer_plus_tard')) { inrap_import_reindexer_plus_tard($vt_fiche->tableNum(), $vn_pk); }
    return $keys;
}
