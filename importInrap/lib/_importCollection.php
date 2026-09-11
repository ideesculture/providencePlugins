<?php
require_once(__CA_APP_DIR__."/plugins/importInrap/lib/inrap_idno.inc.php");
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ERROR);

require_once (__CA_APP_DIR__."/plugins/importInrap/lib/migration_functionlib.php");

function _importCollection($data_to_map, $mapping, $keys, $type_id){
    // 07/09/2026 GM (ticket 7987) : les identifiants arrivaient du tableur avec des espaces
    // parasites, jamais retirés. Relevé en base : 1423 contenants et 208 opérations vivantes
    // dont l'idno porte une espace en tête ou en queue. Deux effets : la fiche est introuvable
    // par une recherche sur l'identifiant propre, et surtout le `load` ci-dessous ne retrouve
    // pas la fiche existante — l'import en crée alors une seconde, d'où les doublons.
    if (isset($data_to_map["idno"])) { $data_to_map["idno"] = inrap_normaliser_idno($data_to_map["idno"]); }
    $vt_col = new ca_collections();
    $opo_app_plugin_manager = new ApplicationPluginManager();

    $vt_col->load(["idno" => $data_to_map["idno"], "deleted" => 0]);
    $vt_col->setMode(ACCESS_WRITE);
    if (!$vt_col->getPrimaryKey()){
        $vt_col->set(array('idno' => $data_to_map["idno"],'type_id' => $type_id,'locale_id'=>2));//Define some intrinsic data.
        $vt_col->insert();//Insert the object
        if ($vt_col->numErrors()){
            var_dump($vt_col->getErrors());die();
        }
    }
    $keys[$data_to_map["idno"]] = $vt_col->getPrimaryKey();
    foreach($data_to_map as $mk=>$data){
        $map = $mapping[$mk];

        if ($mk == "idno" || $mk == "" ){continue;}
      
        if ($mk == "title"){ 
            $vt_col->removeAllLabels();
            $vt_col->addLabel(array("name" => $data), 2, null, true);
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
                case "ca_places":
                    /* $vt_place = new ca_places($data);
                    if (!$vt_place->getPrimaryKey()){
                        continue;
                    }else{
                        $vt_object->addRelationship("ca_places", $key,$map["relation_type"]);
                        $vt_object->update();
                    }*/
                    if (!$data) continue;

                    $place_id = getPlaceIDByName($data, $map["item_type"]);
                    // 10/09/2026 GM (ticket 7988) : ne rattacher que si le lieu a ete resolu franchement.
                    // C'est l'ecriture qui a produit les 402 relations fantomes du lieu 62620 « Saint magne ».
                    // Un null n'etait pas une protection : BaseModel::addRelationship() relit un identifiant
                    // non numerique comme un IDNO et fait load([idno => null, deleted => 0]), soit idno = '',
                    // donc la premiere fiche a identifiant vide — l'appariement au hasard, en silence.
                    if (!$place_id) {
                        global $VERBOSE;
                        if ($VERBOSE) { print "\tLieu non resolu, relation ignoree : \"{$data}\"\n"; }
                        break;
                    }
                    $vt_col->addRelationship("ca_places", $place_id, $map["relation_type"]);
                    break;
                case "ca_objects":
                    break;
                case "ca_entities":
                    if (!$data) continue;

                    global $VERBOSE;
                    $entity_id = getEntityID($data);
                    // 07/09/2026 GM (ticket 7988) : ne rattacher que si le nom a ete resolu
                    // franchement. Auparavant getEntityID pouvait rendre null, ou pire une
                    // entite sans rapport, et le resultat etait attache sans aucun controle :
                    // 1403 operations d'Occitanie et du Grand Est se sont ainsi retrouvees
                    // rattachees au « Musee Louvre-Lens ». Mieux vaut un rattachement
                    // manquant, que l'agent verra, qu'un rattachement faux qu'il ne verra pas.
                    if (!$entity_id) {
                        if ($VERBOSE) { print "\tNom non resolu, relation ignoree : \"{$data}\"\n"; }
                        break;
                    }
                    $vt_col->addRelationship("ca_entities", $entity_id, $map["relation_type"]);
                    break;
                case "ca_storage_locations":
                    if (!$data) continue;

                    // 10/09/2026 GM (ticket 7988) : le test etait sans effet. (int) rend toujours un nombre,
                    // donc is_numeric() etait vrai a tous les coups ; une cellule non numerique valait 0, et
                    // la relation partait avec l'identifiant 0. On exige un identifiant strictement positif,
                    // et une fiche qui existe et n'est pas supprimee.
                    $vn_loc_id = (int)$data;
                    if ($vn_loc_id > 0) {
                        $vt_loc_ref = new ca_storage_locations($vn_loc_id);
                        if ($vt_loc_ref->getPrimaryKey() && ((int)$vt_loc_ref->get('deleted') !== 1)) {
                            $vt_col->addRelationship("ca_storage_locations", $vn_loc_id, $map["relation_type"]);
                            $vt_col->update();
                        }
                    }
                    break;

                case "ca_movements":
                    if (!$data) continue;

                    $vt_mouv = new ca_movements();
                    $vt_mouv->load(["idno" => inrap_normaliser_idno($data), "deleted" => 0]);
                    if ($vt_mouv->getPrimaryKey()){
                        $vt_col->addRelationship("ca_movements", $vt_mouv->getPrimaryKey(), $map["relation_type"]);
                    }
                    break;
                default:
                    break;
            endswitch;
            continue;
        }
        $metadata = explode(".",$map["metadata"])[1];
        $vt_col->removeAttributes($metadata);
        $vt_col->addAttribute(array($metadata => $data), $metadata);
    }
    $vt_col->update();
    if ($vt_col->numErrors()){
        var_dump($vt_col->getErrors());die();
    }

    //On traite les containers ici
    foreach ($containers as $metadata => $container){
        if (!$metadata) continue;
        $vt_col->removeAttributes($metadata);
        $vt_col->update();
    }
    foreach ($containers as $metadata => $container){
        if (!$metadata) continue;
        $vt_col->addAttribute($container, $metadata);
        if($vt_col->numErrors()) {
            var_dump($vt_col->getErrors());
            die();
        }
        
        $vt_col->update();
    }
    $opo_app_plugin_manager->hookEditItem(
        array(
            'id' => $vt_col->getPrimaryKey(),
            'table_num' => $vt_col->tableNum(),
            'table_name' => $vt_col->tableName(),
            'instance' => $vt_col
        )
    );
    $vt_col->update();
    return $keys;
}