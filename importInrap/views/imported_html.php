<?php
    $keys = $this->getVar("keys");
    $type = $this->getVar("type");

    $correspondance = array(
        "operation" => ["opérations", "collections/CollectionEditor/Summary/collection_id/", "Opération"],
        "mobilier" => ["mobiliers", "objects/ObjectEditor/Summary/object_id/", "Objet"],
        "documentation_numerique" => ["documentations numériques", "objects/ObjectEditor/Summary/object_id/", "Document"],
        "documentation_ecrite" => ["documentations écrite", "objects/ObjectEditor/Summary/object_id/", "Document"],
        "musee" => ["musées", "objects/ObjectEditor/Summary/object_id/", "Musées"],
        "contenant_mobilier" => ["contenants mobilier", "objects/ObjectEditor/Summary/object_id/", "Contenant mobilier"],
        "contenant_num" => ["contenants numérique", "objects/ObjectEditor/Summary/object_id/", "Contenant Numérique"],
        "contenant_doc" => ["contenants documentaire", "objects/ObjectEditor/Summary/object_id/", "Contenant Documentaire"]

    );
?>

<h2><?= sizeOf($keys) ." ". $correspondance[$type][0]?> ont été importés avec succès</h2>

Voici la liste des <?= $correspondance[$type][0]?> : 

<ul>
    <?php 
    foreach ($keys as $idno=>$id){
        print "<li>".$correspondance[$type][2]." : <a href='/index.php/editor/".$correspondance[$type][1].$id."'>".$idno."</a></li>";
    }?>
</ul>