<?php
    $keys = $this->getVar("keys");
    if (!is_array($keys)) { $keys = []; }
    $type = $this->getVar("type");
    $etat = $this->getVar("etat");
    if (!is_array($etat)) { $etat = []; }
    $bilan = $this->getVar("bilan");
    // 14/09/2026 GM : les lignes que l'import n'a pas pu traiter. Auparavant la premiere
    // d'entre elles arretait tout sur un var_dump() ; elles sont maintenant listees ici.
    $errors = $this->getVar("errors");
    if (!is_array($errors)) { $errors = []; }
    $errors_total = (int)$this->getVar("errors_total");
    if ($errors_total < sizeof($errors)) { $errors_total = sizeof($errors); }
    $avertissements = (array)($etat['avertissements'] ?? []);
    $avertissements_total = max((int)($etat['avertissements_total'] ?? 0), sizeof($avertissements));
    $h = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

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
    $c = $correspondance[$type] ?? ["fiches", "objects/ObjectEditor/Summary/object_id/", "Fiche"];
    $nb_rattachements_seuls = sizeof((array)($etat['rattachements_seuls'] ?? []));
?>

<h2>Import terminé — « <?= $h($etat['nom'] ?? '') ?> »</h2>
<p class="text-muted">Lancé le <?= !empty($etat['debut']) ? date('d/m/Y à H:i', (int)$etat['debut']) : '—' ?>,
terminé le <?= !empty($etat['fin']) ? date('d/m/Y à H:i', (int)$etat['fin']) : '—' ?>. Bilan établi le <?= date('d/m/Y à H:i') ?>, d'après l'état actuel de la base.</p>

<?php
/*
 * 23/09/2026 GM (ticket 8047) — CONTRÔLE FINAL.
 * La page de fin annonçait « N mobiliers importés avec succès » sans rien dire des lignes du
 * fichier restées sans effet. Le contrôle ci-dessous relit la base pour chaque ligne du fichier.
 */
if (is_array($bilan)) {
    $vb_mvt = ($bilan['avec_mouvement'] > 0);
    $vb_ok = (sizeof($bilan['ecarts']) === 0) && (sizeof($bilan['absentes']) === 0) && ($errors_total === 0);
?>
<div id="controleFinal" style="border:1px solid <?= $vb_ok ? '#badbcc' : '#f5c2c7' ?>;background:<?= $vb_ok ? '#d1e7dd' : '#f8d7da' ?>;color:<?= $vb_ok ? '#0f5132' : '#842029' ?>;padding:1rem;margin:1rem 0;">
    <h4 style="margin-top:0;">Contrôle final <?= $vb_ok ? ($avertissements_total ? '— conforme, mais '.$avertissements_total.' avertissement(s) à lire ci-dessous' : '— conforme') : '— À VÉRIFIER' ?></h4>
    <p style="margin:.25rem 0;">Le fichier compte <strong><?= (int)$bilan['lignes'] ?></strong> ligne(s) portant un numéro d'inventaire :
    <?= (int)$bilan['selectionnees'] ?> cochée(s), <?= (int)$bilan['traitees'] ?> traitée(s)<?php if ($nb_rattachements_seuls) { ?> (dont <?= $nb_rattachements_seuls ?> fiche(s) existante(s) seulement rattachée(s) au mouvement)<?php } ?>,
    <?= (int)$bilan['en_echec'] ?> en échec.</p>
    <?php if (!$vb_mvt && !empty($bilan['mouvement_prevu'])) { ?>
    <p style="margin:.25rem 0;"><strong>Aucune ligne ne demande de rattachement à un mouvement</strong>
    <?= empty($bilan['colonne_mouvement_associee']) ? "(la colonne « Identifiant mouvement » n'a pas été associée, ou le fichier n'en comporte pas)" : "(la colonne « Identifiant mouvement » est vide)" ?> :
    ce contrôle ne porte donc que sur l'existence des fiches.</p>
    <?php } ?>
    <?php if ($vb_mvt) { foreach ($bilan['mouvements'] as $vs_m => $va_m) { $vb_m_ok = ($va_m['demandees'] === $va_m['rattachees']); ?>
    <p style="margin:.25rem 0;font-size:1.1em;">
        Mouvement <strong><?= $h($vs_m) ?></strong><?= $va_m['id'] ? '' : ' (introuvable dans Comodo)' ?> :
        <strong><?= (int)$va_m['rattachees'] ?></strong> ligne(s) rattachée(s) sur <strong><?= (int)$va_m['demandees'] ?></strong> demandée(s) par le fichier.
        <?= $vb_m_ok ? '&#10004;' : '<strong>&#10008; '.((int)$va_m['demandees'] - (int)$va_m['rattachees']).' ligne(s) ne sont PAS rattachées.</strong>' ?>
    </p>
    <?php } } ?>

    <?php if (sizeof($bilan['ecarts'])) { ?>
    <table class="table table-sm table-bordered" style="background:#fff;color:#212529;margin-top:.75rem;">
        <thead><tr><th>Ligne du tableur</th><th>N° d'inventaire</th><th>Mouvement demandé</th><th>Pourquoi elle n'est pas rattachée</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($bilan['ecarts'], 0, 500) as $va_e) { ?>
            <tr><td><?= (int)$va_e['ligne'] ?></td><td><?= $h(inrap_import_court($va_e['idno'])) ?></td><td><?= $h(inrap_import_court($va_e['mouvement'])) ?></td><td><?= $h(inrap_import_message_court($va_e['cause'], $va_e['idno'])) ?></td></tr>
        <?php } ?>
        </tbody>
    </table>
    <?php if (sizeof($bilan['ecarts']) > 500) { ?><p>… et <?= sizeof($bilan['ecarts']) - 500 ?> autre(s) : le journal de l'application les porte toutes.</p><?php } ?>
    <p style="margin:.25rem 0;">Pour les rattacher : relancez l'import de ce fichier en cochant ces lignes. Les fiches qui existent déjà peuvent être seulement
    rattachées, sans être modifiées, avec le choix « Ne pas modifier les fiches existantes ».</p>
    <?php } ?>

    <?php if (sizeof($bilan['absentes'])) { ?>
    <p style="margin:.75rem 0 .25rem 0;"><strong><?= sizeof($bilan['absentes']) ?> ligne(s) du fichier n'ont pas de fiche dans Comodo :</strong></p>
    <table class="table table-sm table-bordered" style="background:#fff;color:#212529;">
        <thead><tr><th>Ligne du tableur</th><th>N° d'inventaire</th><th>Cause</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($bilan['absentes'], 0, 500) as $va_e) { ?>
            <tr><td><?= (int)$va_e['ligne'] ?></td><td><?= $h(inrap_import_court($va_e['idno'])) ?></td><td><?= $h(inrap_import_message_court($va_e['cause'], $va_e['idno'])) ?></td></tr>
        <?php } ?>
        </tbody>
    </table>
    <?php } ?>
</div>
<?php } ?>

<?php if ($errors_total) { ?>
<div class="alert alert-danger" style="border:1px solid #f5c2c7;background:#f8d7da;color:#842029;padding:1rem;margin:1rem 0;">
    <strong><?= $errors_total ?> ligne(s) du tableur n'ont pas pu être importées.</strong>
    Les autres l'ont été normalement. Reprenez ces lignes à la main, ou corrigez-les dans le
    tableur et relancez un import portant uniquement sur elles.
    <ul style="margin-top:.5rem;">
        <?php foreach ($errors as $va_e) { if (!is_array($va_e)) { continue; } ?>
        <li>
            Ligne <strong><?= (int)($va_e['ligne'] ?? 0) ?></strong><?php
            if (!empty($va_e['idno'])) { print " (".$h(inrap_import_court($va_e['idno'])).")"; }
            ?> : <?= $h(inrap_import_message_court($va_e['message'] ?? '', $va_e['idno'] ?? '')) ?>
        </li>
        <?php } ?>
    </ul>
    <?php if ($errors_total > sizeof($errors)) { ?>
    <p style="margin:.25rem 0;">… et <?= $errors_total - sizeof($errors) ?> autre(s), non détaillée(s) ici. Le journal de l'application les porte toutes.</p>
    <?php } ?>
    <span style="font-size:.9em;">Vérifiez ces fiches : une erreur survenue en cours d'écriture peut avoir laissé un enregistrement incomplet.</span>
</div>
<?php } ?>

<?php if ($avertissements_total) { ?>
<div class="alert alert-warning" style="border:1px solid #ffecb5;background:#fff3cd;color:#664d03;padding:1rem;margin:1rem 0;">
    <strong><?= $avertissements_total ?> avertissement(s) :</strong> ces lignes ont été importées, mais une partie de ce qu'elles demandaient n'a pas pu être faite.
    <ul style="margin-top:.5rem;">
        <?php foreach ($avertissements as $va_a) { if (!is_array($va_a)) { continue; } ?>
        <li>Ligne <strong><?= (int)($va_a['ligne'] ?? 0) ?></strong><?php if (!empty($va_a['idno'])) { print " (".$h(inrap_import_court($va_a['idno'])).")"; } ?> : <?= $h(inrap_import_message_court($va_a['message'] ?? '', $va_a['idno'] ?? '')) ?></li>
        <?php } ?>
    </ul>
    <?php if ($avertissements_total > sizeof($avertissements)) { ?><p style="margin:.25rem 0;">… et <?= $avertissements_total - sizeof($avertissements) ?> autre(s) : le journal de l'application les porte toutes.</p><?php } ?>
</div>
<?php } ?>

<h4><?= sizeof($keys) ?> fiche(s) <?= $h($c[0]) ?> traitée(s) par cet import</h4>
<details <?= sizeof($keys) <= 30 ? 'open' : '' ?>>
<summary>Voir la liste</summary>
<ul>
    <?php
    foreach ($keys as $idno=>$id){
        print "<li>".$h($c[2])." : <a href='/index.php/editor/".$c[1].(int)$id."'>".$h($idno)."</a></li>";
    }?>
</ul>
</details>
<p><a href="/index.php/importInrap/Import/Index" class="btn btn-secondary">Retour à l'accueil de l'import</a></p>
