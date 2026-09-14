<?php
    $keys = $this->getVar("keys");
    $type = $this->getVar("type");
    // 14/09/2026 GM : les lignes que l'import n'a pas pu traiter. Auparavant la premiere
    // d'entre elles arretait tout sur un var_dump() ; elles sont maintenant listees ici.
    $errors = $this->getVar("errors");
    if (!is_array($errors)) { $errors = []; }
    $errors_total = (int)$this->getVar("errors_total");
    if ($errors_total < sizeof($errors)) { $errors_total = sizeof($errors); }

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

<?php if ($errors_total) { ?>
<div class="alert alert-danger" style="border:1px solid #f5c2c7;background:#f8d7da;color:#842029;padding:1rem;margin:1rem 0;">
    <strong><?= $errors_total ?> ligne(s) du tableur n'ont pas pu être importées.</strong>
    Les autres l'ont été normalement. Reprenez ces lignes à la main, ou corrigez-les dans le
    tableur et relancez un import portant uniquement sur elles.
    <ul style="margin-top:.5rem;">
        <?php foreach ($errors as $va_e) { if (!is_array($va_e)) { continue; } ?>
        <li>
            Ligne <strong><?= (int)($va_e['ligne'] ?? 0) ?></strong><?php
            if (!empty($va_e['idno'])) { print " (".htmlspecialchars((string)$va_e['idno'], ENT_QUOTES, 'UTF-8').")"; }
            ?> : <?= htmlspecialchars(mb_substr((string)($va_e['message'] ?? ''), 0, 300), ENT_QUOTES, 'UTF-8') ?>
        </li>
        <?php } ?>
    </ul>
    <?php if ($errors_total > sizeof($errors)) { ?>
    <p style="margin:.25rem 0;">… et <?= $errors_total - sizeof($errors) ?> autre(s), non détaillée(s) ici. Le journal de l'application les porte toutes.</p>
    <?php } ?>
    <span style="font-size:.9em;">Vérifiez ces fiches : une erreur survenue en cours d'écriture peut avoir laissé un enregistrement incomplet.</span>
</div>
<?php } ?>

Voici la liste des <?= $correspondance[$type][0]?> : 

<ul>
    <?php 
    foreach ($keys as $idno=>$id){
        print "<li>".$correspondance[$type][2]." : <a href='/index.php/editor/".$correspondance[$type][1].$id."'>".$idno."</a></li>";
    }?>
</ul>