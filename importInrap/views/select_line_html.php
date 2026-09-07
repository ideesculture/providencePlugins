<?php
    $sheet = $this->getVar("sheets");
    $type = $this->getVar("type");
    $file = $this->getVar("file");
    $idnos = $this->getVar("idnos");
    $name = $this->getVar("name");
    $jsonPath = $this->getVar("data");
    $duplicates = $this->getVar("duplicates");
    $duplicate_count = is_array($duplicates) ? count($duplicates) : 0;
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">

<h1>Import du fichier : <?= $name ?></h1>

<?php if ($duplicate_count > 0): ?>
<div class="alert alert-warning" id="duplicateAlert" style="margin: 15px 0;">
    <h5>&#9888; <?= $duplicate_count ?> numéro(s) d'inventaire déjà présent(s) dans la base</h5>
    <p>Les lignes surlignées en orange correspondent à des fiches existantes. Si vous les importez, <strong>leurs données actuelles seront écrasées</strong>.</p>

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
                    print "<tr><td colspan='5' style='text-align:center; font-style:italic;'>… et " . ($duplicate_count - 3) . " autre(s)</td></tr>";
                    break;
                }
                print "<tr>";
                print "<td><strong>" . htmlspecialchars($dup['idno']) . "</strong></td>";
                print "<td>" . htmlspecialchars($dup['label']) . " <span style='color:#999;'>(id:" . $dup['object_id'] . ")</span></td>";
                print "<td>" . htmlspecialchars($dup['location'] ?: '—') . "</td>";
                print "<td>" . htmlspecialchars($dup['import_label'] ?: '—') . "</td>";
                print "<td>" . htmlspecialchars($dup['import_location'] ?: '—') . "</td>";
                print "</tr>";
                $preview_count++;
            }
        ?>
        </tbody>
    </table>

    <div style="margin-top: 10px;">
        <strong>Que souhaitez-vous faire ?</strong>
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
            <label class="form-check-label" for="dupActionSkip">Exclure les doublons de l'import (ne traiter que les nouvelles fiches)</label>
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

<button class="btn btn-secondary" id="Submit" onclick="submitForm()"> Valider </button>

<form action="/index.php/importInrap/Import/Import" id='form' method="POST">
    <input type="hidden" name="file" value="<?= $file ?>"/>
    <input type="hidden" name="json" value="<?= $jsonPath ?>"/>
    <input type="hidden" name="type" value="<?= $type ?>" />
    <input type="hidden" name="name" value="<?= $name ?>" />
    <input type="hidden" name="idno_prefix" id="idnoPrefixField" value="" />
    <input type="hidden" name="idno_prefix_scope" id="idnoPrefixScopeField" value="" />

    <input type="hidden" name="sheet" value="<?= $sheet ?>"/>
    <table class="table table-hover table-bordered" id="tableForm">
        <thead>
            <tr>
                <th>Importer</th>
                <th>Numéro d'inventaire</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $duplicate_keys = is_array($duplicates) ? array_keys($duplicates) : [];
            foreach ($idnos as $key => $idno){
                if ($idno != ""){
                    $is_dup = in_array($key, $duplicate_keys);
                    $row_class = $is_dup ? "style='background-color: #fff3cd;'" : "";
                    $dup_class = $is_dup ? "isDuplicate" : "";
                    $status = $is_dup
                        ? "<span class='badge bg-warning text-dark'>Doublon : " . htmlspecialchars($duplicates[$key]['label']) . "</span>"
                        : "<span class='badge bg-success'>Nouveau</span>";
                    print "<tr {$row_class}><td><input type='checkbox' class='form-check-input isPresent {$dup_class}' name='{$key}' data-dup='" . ($is_dup ? "1" : "0") . "' /> </td><td>{$idno}</td><td>{$status}</td></tr>";
                }
            }
            ?>
        </tbody>
    </table>
    <input type="hidden" value="" id="allRow" name="allRows" />
</form>


<script>

    function updateDuplicateAction() {
        var action = $("input[name='duplicateAction']:checked").val();
        if (action === 'prefix') {
            $("#prefixInputContainer").slideDown(150);
        } else {
            $("#prefixInputContainer").slideUp(150);
        }
        if (action === 'skip') {
            // Uncheck all duplicate rows
            $(".isDuplicate").prop('checked', false);
        }
    }

    function submitForm(){
        // Handle prefix option
        var action = $("input[name='duplicateAction']:checked").val();
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

        let isOneChecked = false;
        $("#tableForm").find(":checkbox").each(function(){
            if ($(this).is(":checked")){
                $("#allRow").val($("#allRow").val() + ";" +  $(this).attr("name"));
                isOneChecked = true;
            }
        })
        if (isOneChecked == false){
            alert("Vous devez choisir au moins une ligne à importer");
            return false;
        }
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
    }
    function uncheckedAll(){
        $(".isPresent").each(function () {
            $(this).prop('checked', false);
        });
        $("#allChecked").prop("checked", false);
    }
</script>
