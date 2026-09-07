<?php 

function _importObject($data_to_map, $mapping, $keys, $type_id){
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
        if ($mk == "hauteur" || $mk == "profondeur" || $mk == "longueur" || $mk == "epaisseur" || $mk == "diametre" || $mk == "largeur"){
            $data = strval($data);
            if (!empty($data)){
                $data .= " cm";
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
                    
                    $vt_object->addRelationship("ca_objects", $vt_rel_obj, $map["relation_type"]);
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
                    $vt_mouv->load(["idno" => $data, "deleted" => 0]);
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

        if ($map["canBeMultiple"] == 1){
            $data = explode(";", $data);
            foreach ($data as $singleData){
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