<?php
// 14/09/2026 GM : une cellule dont la formule n'a pas pu etre calculee est reprise en texte
// brut plutot que de faire echouer tout l'import. C'est un fait a signaler, pas a masquer.
// 23/09/2026 GM (ticket 8047) : ce signalement ne s'affichait JAMAIS — $formules n'existe pas
// dans une vue CollectiveAccess, qui n'extrait pas ses variables. On le lit par getVar().
$formules = $this->getVar("formules");
if (!empty($formules) && is_array($formules)) { ?>
<div class="alert alert-warning">
    <strong><?= sizeof($formules) ?> cellule(s)</strong> commencent par « = » et ont été prises pour des formules par Excel.
    Leur texte a été repris tel quel. Vérifiez ces valeurs :
    <ul class="mb-0">
        <?php foreach (array_slice($formules, 0, 10) as $va_f) { ?>
        <li><code><?= htmlspecialchars($va_f['cellule'], ENT_QUOTES, 'UTF-8') ?></code> : <?= htmlspecialchars(mb_substr($va_f['valeur'], 0, 120), ENT_QUOTES, 'UTF-8') ?></li>
        <?php } ?>
    </ul>
    <?php if (sizeof($formules) > 10) { ?><span class="small">… et <?= sizeof($formules) - 10 ?> autre(s).</span><?php } ?>
</div>
<?php } ?>
<?php
    $type = $this->getVar("type");
    $file = $this->getVar("file");
    $idnos = $this->getVar("idnos");
    $name = $this->getVar("name");
    $sheet = $this->getVar("sheet");
    $jsonPath = $this->getVar("data");
    $jeton = $this->getVar("jeton");
    $duplicates = $this->getVar("duplicates");
    if (!is_array($duplicates)) { $duplicates = []; }
    $situation = $this->getVar("situation");
    if (!is_array($situation)) { $situation = []; }
    $interrompus = $this->getVar("interrompus");
    if (!is_array($interrompus)) { $interrompus = []; }
    $h = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

    // 23/09/2026 GM (ticket 8047) : décompte des situations, ligne par ligne.
    $compte = ['nouveau' => 0, 'doublon' => 0, 'deja_rattache' => 0, 'a_rattacher' => 0, 'mouvement_introuvable' => 0];
    $mvts_introuvables = [];
    foreach ($situation as $va_s) {
        $compte[$va_s['statut']] = ($compte[$va_s['statut']] ?? 0) + 1;
        if ($va_s['statut'] === 'mouvement_introuvable') { $mvts_introuvables[$va_s['mouvement']] = true; }
    }
    $nb_existantes = $compte['doublon'] + $compte['deja_rattache'] + $compte['a_rattacher']
        + sizeof(array_filter($situation, function($s) { return $s['statut'] === 'mouvement_introuvable' && !empty($s['pk']); }));
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">

<h1>Import du fichier : <?= $h($name) ?></h1>

<?php foreach ($interrompus as $va_i) { ?>
<div class="alert alert-danger" id="importInterrompu" style="margin: 15px 0;">
    <h5>&#9888; Un import de ce même fichier s'est interrompu</h5>
    <p class="mb-2">Lancé le <?= date('d/m/Y à H:i', (int)$va_i['debut']) ?>, il s'est arrêté le <?= date('d/m/Y à H:i', (int)$va_i['maj']) ?>
    après avoir traité <strong><?= sizeof((array)($va_i['traitees'] ?? [])) ?></strong> ligne(s) sur les <?= sizeof((array)($va_i['selection'] ?? [])) ?> cochées.</p>
    <p class="mb-2"><strong>Il est préférable de le reprendre plutôt que de le relancer</strong> : la reprise repart exactement de la ligne où il s'est arrêté, avec la même sélection.</p>
    <form method="post" action="/index.php/importInrap/Import/Import" style="display:inline">
        <input type="hidden" name="json" value="<?= $h(inrap_import_chemin_json($va_i['id'])) ?>"/>
        <input type="hidden" name="jeton" value="<?= $h($va_i['jeton']) ?>"/>
        <button type="submit" class="btn btn-danger btn-sm">Reprendre l'import interrompu</button>
    </form>
</div>
<?php } ?>

<?php if ($compte['a_rattacher'] || $compte['mouvement_introuvable']) { ?>
<div class="alert alert-danger" style="margin: 15px 0;">
    <?php if ($compte['a_rattacher']) { ?>
    <p class="mb-2"><strong><?= $compte['a_rattacher'] ?> fiche(s) existent déjà dans Comodo mais ne sont PAS rattachées au mouvement indiqué dans le fichier.</strong>
    Elles sont signalées en rouge ci-dessous. Cochées, elles seront rattachées au mouvement :
    avec « Ne pas modifier les fiches existantes », sans aucun autre changement ; avec « Mettre à jour », après réécriture de leurs données par celles du fichier ;
    avec le préfixe, c'est une nouvelle fiche préfixée qui est créée et rattachée, la fiche existante restant à l'écart.</p>
    <?php } ?>
    <?php if ($compte['mouvement_introuvable']) { ?>
    <p class="mb-0"><strong><?= $compte['mouvement_introuvable'] ?> ligne(s) indiquent un mouvement qui n'existe pas dans Comodo</strong>
    (<?= $h(join(', ', array_keys($mvts_introuvables))) ?>) : elles ne pourront pas y être rattachées. Vérifiez l'identifiant du mouvement dans le fichier.</p>
    <?php } ?>
</div>
<?php } ?>

<?php if ($nb_existantes > 0): ?>
<div class="alert alert-warning" id="duplicateAlert" style="margin: 15px 0;">
    <h5>&#9888; <?= $nb_existantes ?> numéro(s) d'inventaire déjà présent(s) dans la base</h5>
    <ul class="mb-2">
        <?php if ($compte['deja_rattache']) { ?><li><?= $compte['deja_rattache'] ?> fiche(s) existent et sont <strong>déjà rattachées</strong> au mouvement du fichier.</li><?php } ?>
        <?php if ($compte['a_rattacher']) { ?><li><?= $compte['a_rattacher'] ?> fiche(s) existent mais <strong>ne sont pas encore rattachées</strong> au mouvement du fichier.</li><?php } ?>
        <?php if ($compte['doublon']) { ?><li><?= $compte['doublon'] ?> fiche(s) existent déjà.</li><?php } ?>
    </ul>

    <table class="table table-sm table-bordered" style="background: #fff; margin: 10px 0;">
        <thead><tr>
            <th>N° inventaire</th>
            <th>Fiche existante</th>
            <th>Localisation actuelle</th>
            <th>Fiche à importer</th>
            <th>Localisation à importer</th>
        </tr></thead>
        <tbody>
        <?php
            $preview_count = 0;
            foreach ($duplicates as $dup) {
                if ($preview_count >= 3) {
                    print "<tr><td colspan='5' style='text-align:center; font-style:italic;'>… et " . ($nb_existantes - 3) . " autre(s)</td></tr>";
                    break;
                }
                print "<tr>";
                print "<td><strong>" . $h($dup['idno']) . "</strong></td>";
                print "<td>" . $h($dup['label']) . " <span style='color:#999;'>(id:" . (int)$dup['object_id'] . ")</span></td>";
                print "<td>" . $h($dup['location'] ?: '—') . "</td>";
                print "<td>" . $h($dup['import_label'] ?: '—') . "</td>";
                print "<td>" . $h($dup['import_location'] ?: '—') . "</td>";
                print "</tr>";
                $preview_count++;
            }
        ?>
        </tbody>
    </table>

    <div style="margin-top: 10px;">
        <strong>Que souhaitez-vous faire des fiches existantes ?</strong>
        <div class="form-check mt-2">
            <input class="form-check-input" type="radio" name="duplicateAction" id="dupActionOverwrite" value="overwrite" checked onchange="updateDuplicateAction()">
            <label class="form-check-label" for="dupActionOverwrite">Mettre à jour les fiches existantes (écraser avec les données du fichier)</label>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="radio" name="duplicateAction" id="dupActionPrefix" value="prefix" onchange="updateDuplicateAction()">
            <label class="form-check-label" for="dupActionPrefix">Créer de nouvelles fiches en ajoutant un préfixe aux n° d'inventaire</label>
            <div id="prefixInputContainer" style="display: none; margin: 8px 0 0 22px; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                <div style="margin-bottom: 8px;">
                    <label>Préfixe : </label>
                    <input type="text" id="prefixValue" placeholder="ex: DENON-" style="width: 150px; padding: 3px;"/>
                </div>
                <div style="margin-bottom: 4px;"><strong>Appliquer le préfixe à :</strong></div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="prefixScope" id="prefixScopeAll" value="all" checked>
                    <label class="form-check-label" for="prefixScopeAll">Tous les enregistrements de cet import</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="prefixScope" id="prefixScopeDuplicates" value="duplicates_only">
                    <label class="form-check-label" for="prefixScopeDuplicates">Seulement les doublons (n° d'inventaire déjà existants)</label>
                </div>
            </div>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="radio" name="duplicateAction" id="dupActionSkip" value="skip" onchange="updateDuplicateAction()">
            <label class="form-check-label" for="dupActionSkip">Ne pas modifier les fiches existantes : seules les nouvelles fiches sont créées.
                <?php if ($compte['a_rattacher']) { ?>Les fiches existantes signalées en rouge sont seulement rattachées au mouvement du fichier, sans autre modification.<?php } ?></label>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="form-check">
    <label for="allChecked" class="form-check-label">Tout sélectionner</label>
    <input type='checkbox' onclick="checkedAll()" class='form-check-input' id='allChecked'>
</div>
<div class="form-check">
    <label for="allUnchecked" class="form-check-label">Tout désélectionner</label>
    <input type='checkbox' onclick="uncheckedAll()" class='form-check-input' id='allUnchecked'>
</div>

<div class="alert alert-warning" id="avertissementFenetre" style="margin: 15px 0;">
    <strong>&#9888; Important — gardez cet écran ouvert pendant tout l'import.</strong>
    L'import se déroule dans cette fenêtre, ligne après ligne. <strong>Si vous fermez l'écran, changez de page, ou si l'ordinateur
    se met en veille, l'import s'interrompt.</strong> Laissez la fenêtre ouverte et au premier plan jusqu'à l'écran « Import terminé ».
    En cas d'interruption, rien n'est perdu : l'import pourra être repris depuis l'accueil de l'import, là où il s'est arrêté.
</div>
<p class="mt-2 mb-1"><span id="compteCochees">0</span> ligne(s) cochée(s) sur <?= sizeof($situation) ?>.</p>
<button class="btn btn-secondary" id="Submit" onclick="submitForm()"> Valider </button>

<form action="/index.php/importInrap/Import/Import" id='form' method="POST">
    <input type="hidden" name="file" value="<?= $h($file) ?>"/>
    <input type="hidden" name="json" value="<?= $h($jsonPath) ?>"/>
    <input type="hidden" name="type" value="<?= $h($type) ?>" />
    <input type="hidden" name="name" value="<?= $h($name) ?>" />
    <input type="hidden" name="jeton" value="<?= $h($jeton) ?>" />
    <input type="hidden" name="nouvelle_selection" value="1" />
    <input type="hidden" name="dup_action" id="dupActionField" value="overwrite" />
    <input type="hidden" name="idno_prefix" id="idnoPrefixField" value="" />
    <input type="hidden" name="idno_prefix_scope" id="idnoPrefixScopeField" value="" />

    <input type="hidden" name="sheet" value="<?= $h($sheet) ?>"/>
    <table class="table table-hover table-bordered" id="tableForm">
        <thead>
            <tr>
                <th>Importer</th>
                <th>Ligne</th>
                <th>Numéro d'inventaire</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            <?php
            foreach ($idnos as $key => $idno){
                if ($idno == "") { continue; }
                $s = $situation[$key + 1] ?? ['statut' => 'nouveau', 'pk' => null, 'mouvement' => null];
                $mvt = $h($s['mouvement']);
                switch ($s['statut']) {
                    case 'a_rattacher':
                        $row_style = "style='background-color: #f8d7da;'";
                        $classes = "isARattacher";
                        $status = "<span class='badge bg-danger'>Existe déjà — PAS rattachée au mouvement {$mvt}</span>";
                        break;
                    case 'deja_rattache':
                        $row_style = "style='background-color: #fff3cd;'";
                        $classes = "isDuplicate";
                        $status = "<span class='badge bg-warning text-dark'>Existe déjà — déjà rattachée au mouvement {$mvt}</span>";
                        break;
                    case 'doublon':
                        $row_style = "style='background-color: #fff3cd;'";
                        $classes = "isDuplicate";
                        $vs_label = isset($duplicates[$key]['label']) && $duplicates[$key]['label'] !== '' ? " : ".$h($duplicates[$key]['label']) : '';
                        $status = "<span class='badge bg-warning text-dark'>Doublon{$vs_label}</span>";
                        break;
                    case 'mouvement_introuvable':
                        $row_style = "style='background-color: #f8d7da;'";
                        $classes = !empty($s['pk']) ? "isMvtIntrouvable isExistante" : "isMvtIntrouvable";
                        $status = "<span class='badge bg-danger'>Mouvement {$mvt} introuvable</span>"
                            .(!empty($s['pk']) ? " <span class='badge bg-secondary'>fiche existante</span>" : " <span class='badge bg-success'>Nouveau</span>");
                        break;
                    default:
                        $row_style = "";
                        $classes = "";
                        $status = "<span class='badge bg-success'>Nouveau</span>";
                }
                // 23/09/2026 GM (ticket 8047) : plus d'attribut name sur les cases. Chacune devenait une
                // variable POST : au-delà de max_input_vars, PHP tronquait la requête et allRows, placé
                // après le tableau, était perdu — l'import ne traitait alors rien. La sélection voyage
                // uniquement dans allRows.
                print "<tr {$row_style}><td><input type='checkbox' class='form-check-input isPresent {$classes}' data-cle='".(int)$key."' data-statut='".$h($s['statut'])."' /> </td><td>".((int)$key + 2)."</td><td>".$h(inrap_import_court($idno))."</td><td>{$status}</td></tr>";
            }
            ?>
        </tbody>
    </table>
    <input type="hidden" value="" id="allRow" name="allRows" />
</form>


<script>

    function compterCochees() {
        $("#compteCochees").text($("#tableForm .isPresent:checked").length);
    }
    $(document).on("change", "#tableForm .isPresent", compterCochees);
    $(compterCochees);

    function updateDuplicateAction() {
        var action = $("input[name='duplicateAction']:checked").val();
        if (action === 'prefix') {
            $("#prefixInputContainer").slideDown(150);
        } else {
            $("#prefixInputContainer").slideUp(150);
        }
        if (action === 'skip') {
            // Les fiches existantes DÉJÀ rattachées (ou sans mouvement) n'ont rien à recevoir :
            // on les décoche. Les fiches à rattacher (en rouge) restent cochées : elles seront
            // rattachées au mouvement sans être modifiées.
            $(".isDuplicate").prop('checked', false);
        }
        compterCochees();
    }

    function submitForm(){
        // Handle prefix option
        var action = $("input[name='duplicateAction']:checked").val() || 'overwrite';
        $("#idnoPrefixField").val('');
        $("#idnoPrefixScopeField").val('');
        if (action === 'prefix') {
            var prefix = $("#prefixValue").val();
            if (!prefix || prefix.trim() === '') {
                alert("Veuillez saisir un préfixe.");
                return false;
            }
            $("#idnoPrefixField").val(prefix.trim());
            $("#idnoPrefixScopeField").val($("input[name='prefixScope']:checked").val());
        } else if (action === 'skip') {
            // Make sure no duplicate is checked
            $(".isDuplicate").prop('checked', false);
        }
        $("#dupActionField").val(action);

        // La liste est reconstruite à chaque envoi : un double clic ne la double plus.
        var lignes = [];
        $("#tableForm").find(":checkbox").each(function(){
            if ($(this).is(":checked")){
                lignes.push($(this).attr("data-cle"));
            }
        });
        if (lignes.length === 0){
            alert("Vous devez choisir au moins une ligne à importer");
            return false;
        }
        $("#allRow").val(";" + lignes.join(";"));
        $("#Submit").prop("disabled", true).text("Import lancé…");
        $("#form").submit();
    }

    function checkedAll(){
        var action = $("input[name='duplicateAction']:checked").val();
        $(".isPresent").each(function () {
            // If skip mode, don't check duplicates
            if (action === 'skip' && $(this).hasClass('isDuplicate')) {
                $(this).prop('checked', false);
            } else {
                $(this).prop('checked', true);
            }
         });
        $("#allUnchecked").prop("checked", false);
        compterCochees();
    }
    function uncheckedAll(){
        $(".isPresent").each(function () {
            $(this).prop('checked', false);
        });
        $("#allChecked").prop("checked", false);
        compterCochees();
    }
</script>
