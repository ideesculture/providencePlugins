<?php
/**
 * index_html.php — accueil de l'import.
 *
 * 23/09/2026 GM (ticket 8047) : l'accueil signale en tête les imports interrompus de la
 * gestionnaire — un import qui s'arrête ne passe plus inaperçu — et permet de les reprendre là
 * où ils se sont arrêtés, ou de consulter le bilan des imports terminés.
 */
$imports = $this->getVar("imports");
if (!is_array($imports)) { $imports = []; }
$h = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$interrompus = array_values(array_filter($imports, function($e) { return !empty($e['interrompu']); }));
$recents = array_slice(array_values(array_filter($imports, function($e) { return empty($e['interrompu']); })), 0, 10);
$form_import = function($e, $libelle, $classe, $action = 'Import') use ($h) {
    return "<form method='post' action='/index.php/importInrap/Import/".$action."' style='display:inline'>"
        ."<input type='hidden' name='json' value='".$h(inrap_import_chemin_json($e['id']))."'/>"
        ."<input type='hidden' name='jeton' value='".$h($e['jeton'] ?? '')."'/>"
        ."<button type='submit' class='btn btn-sm ".$classe."'>".$h($libelle)."</button></form>";
};
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">


<h1>Import INRAP</h1>

<?php if (sizeof($interrompus)) { ?>
<div class="alert alert-danger" id="importsInterrompus">
    <h5>&#9888; <?= sizeof($interrompus) ?> import(s) interrompu(s)</h5>
    <p class="mb-2">Ces imports se sont arrêtés avant la fin : les lignes suivantes n'ont pas été traitées.
    La reprise repart de la ligne où chacun s'est arrêté, avec la même sélection : les lignes déjà faites ne sont pas refaites
    (seule la ligne en cours au moment de l'arrêt est reprise).</p>
    <table class="table table-sm table-bordered" style="background:#fff;">
        <thead><tr><th>Fichier</th><th>Lancé le</th><th>Arrêté le</th><th>Avancement</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($interrompus as $e) { ?>
            <tr>
                <td><?= $h($e['nom'] ?? '') ?></td>
                <td><?= date('d/m/Y H:i', (int)($e['debut'] ?? 0)) ?></td>
                <td><?= date('d/m/Y H:i', (int)($e['maj'] ?? 0)) ?></td>
                <td><?= inrap_import_nb_faites($e) ?> ligne(s) traitée(s) sur <?= sizeof((array)($e['selection'] ?? [])) ?> cochée(s)</td>
                <td><?= $form_import($e, "Reprendre l'import", 'btn-danger') ?> <?= $form_import($e, "Abandonner", 'btn-outline-secondary', 'Abandonner') ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
</div>
<?php } ?>

<form action="/index.php/importInrap/Import/SelectSheet" method="POST" enctype="multipart/form-data">
    <div class="mb-3">
        <label for="formFile" class="form-label">Sélectionner le type à importer</label>

        <select class="form-select" aria-label="Default select example" name="type">
            <option selected value="mobilier">Mobilier</option>
            <option value="musee">Musée</option>
            <option value="operation">Opération</option>
            <option value="documentation_numerique">Documentation numérique</option>
            <option value="documentation_ecrite">Documentation écrite</option>
            <option value="contenant_num">Contenant Numérique</option>
            <option value="contenant_doc">Contenant Document</option>
            <option value="contenant_mobilier">Contenant Mobilier</option>

        </select>

    </div>
    <div class="mb-3">
        <label for="formFile" class="form-label">Sélectionner le fichier excel à importer</label>
        <input class="form-control" type="file" id="formFile" name="file" accept=".xlsx,.xls,.xlsm,.csv,.ods">
    </div>
    <p class="small text-muted mb-2">L'import se déroule dans la fenêtre ouverte : <strong>si vous la fermez avant l'écran « Import terminé », il s'interrompt</strong>.
    Il peut alors être repris ici, là où il s'est arrêté.</p>
    <div class="col-auto">
        <button type="submit" class="btn btn-secondary mb-3">Valider</button>
    </div>
</form>

<?php if (sizeof($recents)) { ?>
<h4 class="mt-4">Vos derniers imports</h4>
<table class="table table-sm table-bordered" id="derniersImports">
    <thead><tr><th>Fichier</th><th>Lancé le</th><th>État</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recents as $e) {
        $statut = $e['statut'] ?? '';
        $libelle = ['termine' => 'terminé', 'abandonne' => 'abandonné', 'en_cours' => 'en cours', 'remplace' => 'interrompu, puis relancé et mené à bien par un import ultérieur du même fichier'][$statut] ?? $statut;
    ?>
        <tr>
            <td><?= $h($e['nom'] ?? '') ?></td>
            <td><?= date('d/m/Y H:i', (int)($e['debut'] ?? 0)) ?></td>
            <td><?= $h($libelle) ?> — <?= sizeof((array)($e['traitees'] ?? [])) ?> ligne(s) traitée(s) sur <?= sizeof((array)($e['selection'] ?? [])) ?> cochée(s)<?php if (!empty($e['erreurs_total'])) { ?>, <?= (int)$e['erreurs_total'] ?> en échec<?php } ?></td>
            <td><?php
                if ($statut === 'termine') { print $form_import($e, "Voir le bilan", 'btn-outline-primary'); }
                // Un import « en cours » depuis moins de deux minutes tourne peut-être encore dans un autre
                // onglet ; si l'onglet a été fermé, on peut le reprendre sans attendre (le verrou empêche
                // tout double traitement).
                elseif ($statut === 'en_cours') { print $form_import($e, "Reprendre l'import", 'btn-outline-danger'); }
            ?></td>
        </tr>
    <?php } ?>
    </tbody>
</table>
<?php } ?>
