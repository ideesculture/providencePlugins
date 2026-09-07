<?php
function _importObject($data_to_map, $mapping, $keys, $type_id){
    // 07/09/2026 GM (ticket 7987) : les identifiants arrivaient du tableur avec des espaces
    // parasites, jamais retirés. Relevé en base : 1423 contenants et 208 opérations vivantes
    // dont l'idno porte une espace en tête ou en queue. Deux effets : la fiche est introuvable
    // par une recherche sur l'identifiant propre, et surtout le `load` ci-dessous ne retrouve
    // pas la fiche existante — l'import en crée alors une seconde, d'où les doublons.
    if (isset($data_to_map["idno"])) { $data_to_map["idno"] = trim((string)$data_to_map["idno"]); }
    $vt_object = new ca_objects();
    $opo_app_plugin_manager = new ApplicationPluginManager();

    $vt_object->load(["idno" => $data_to_map["idno"], "deleted" => 0]);
    $vt_object->setMode(ACCESS_WRITE);
    if (!$vt_object->getPrimaryKey()){
        $vt_object->set(array('idno' => $data_to_map["idno"],'type_id' => $type_id,'locale_id'=>2));//Define some intrinsic data.
        $vt_object->insert();//Insert the object
        if ($vt_object->numErrors()){
            var_dump($vt_object->getErrors());die();
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
                    $vt_occ->load(["idno" => trim($data), "deleted" =>0]);
                    $primKey = $vt_occ->getPrimaryKey();
                    if ($primKey){
                        $vt_object->addRelationship("ca_collections", $primKey, $map["relation_type"]);
                        if ($vt_object->numErrors()){
                            var_dump($vt_object->getErrors());die();
                        }

                    }
                    break;
                case "ca_entities":
                    if (!$data) continue;

                    $entity_id = getEntityIDByIdno($data);
                    if ($entity_id){
                        $vt_object->removeRelationships("ca_entities", $map["relation_type"]);
                        $vt_object->addRelationship("ca_entities", $entity_id, $map["relation_type"]);
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
                        var_dump("No collection found for ".$data_to_map["code_inrap"]);die();
                    }

                    if(!$vt_rel_obj) {
                        $vt_contenant = new ca_objects();
                        $vt_contenant->setMode(ACCESS_WRITE);

                        $vt_contenant->load(["idno" => trim((string)$data), "deleted" => 0]);

                        if (!in_array($vt_contenant->getPrimaryKey(), $contenantLiesOperation)){
                            $vt_contenant->set(array('idno' => trim((string)$data), 'type_id' => $map["item_type"], 'locale_id'=>2));
                            $vt_contenant->insert();

                            $vt_contenant->addLabel(array("name" => $data), 2, null, true);
                            $vt_contenant->update();

                            if ($vt_contenant->numErrors()){
                                var_dump($vt_contenant->getErrors());die();
                            }

                            $vt_contenant->addAttribute($data_to_map["contenant_ref"], 'referentiel');
                            $vt_contenant->update();

                            if ($vt_contenant->numErrors()){
                                var_dump($vt_contenant->getErrors());die();
                            }

                            $vt_contenant->addRelationship("ca_collections", $vs_rel_op, 152);
                            $vt_contenant->update();

                            if ($vt_contenant->numErrors()){
                                var_dump($vt_contenant->getErrors());
                                die();
                            }
                        }
                        
                        $vt_rel_obj = $vt_contenant->getPrimaryKey();
                    }

                    $vt_object->addRelationship("ca_objects", $vt_rel_obj, 177);
                    $vt_rel_op->addRelationship("ca_objects", $vt_rel_obj, 152);
                    $vt_rel_op->update();
                    $vt_object->update();
                    break;
                case "ca_storage_locations":
                    if (!$data) continue;

                    if (is_numeric($data)){
                        $vt_object->removeRelationships("ca_storage_locations", $map["relation_type"]);
                        $vt_object->addRelationship("ca_storage_locations", $data, $map["relation_type"]);
                        continue;
                    }
                    $vt_rel_storage = getStorageLocationID($data, $map["item_type"]);
                    
                    if ($vt_rel_storage){
                        $vt_object->removeRelationships("ca_storage_locations", $map["relation_type"]);
                        $vt_object->addRelationship("ca_storage_locations", $vt_rel_storage, $map["relation_type"]);
                    }
                    break;
                case "ca_movements":
                    if (!$data) continue;

                    $vt_mouv = new ca_movements();
                    $vt_mouv->load(["idno" => trim((string)$data), "deleted" => 0]);
                    if ($vt_mouv->getPrimaryKey()){
                        $vt_object->addRelationship("ca_movements", $vt_mouv->getPrimaryKey(), $map["relation_type"]);
                    }
                    break;
                default:
                    break;
            endswitch;
            continue;
        }
        $metadata = explode(".",$map["metadata"])[1];
        $vt_object->removeAttributes($metadata);

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
        var_dump($vt_object->getErrors());die();
    }

    //On traite les containers ici

    //var_dump($containers);die();
    foreach ($containers as $metadata => $container){
        if (!$metadata) continue;
        $vt_object->removeAttributes($metadata);
        $vt_object->update();
    }
	
    foreach ($containers as $metadata => $container){
        if (!$metadata) continue;
        $vt_object->addAttribute($container, $metadata);
        if($vt_object->numErrors()) {
            var_dump($vt_object->getErrors());
            die();
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