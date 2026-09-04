<?php
$set_id = $this->getVar("set_id");
$occ_id = $this->getVar("occ_id");
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">


<h1>Export Inrap</h1>

<?php if (!$set_id && !$occ_id) {
?>

    <p> Avant de commencer, veuillez saisir le code de l'opération qui va être exporté.</p>

    <div class="mb-3 row">
        <label for="staticEmail" class="col-sm-2 col-form-label">Code Inrap</label>
        <div class="col-sm-3">
            <input type="text" class="form-control" id="code_inrap" name="code_inrap" value="">
        </div>

    </div>
    <button type="button" id="show-object" class="btn btn-info col-2" data-toggle-next="1">Suivant</button>
<?php
}
?>
<div id="parameters" style="<?= (!$set_id && !$occ_id) ? 'display:none'  : ''; ?>">
   <?php if (!$set_id && !$occ_id){?> <div class="row" id="selectObject">
        <h2>Choisir les objets à exporter :</h2>
        <div class="col-md-6" id="mobilier" data-type-id="24">
            <h3>Mobilier</h3>
            <div class="form-check">
                <input class="form-check-input" type="radio" data-check="mobilier" value="1">
                <label class="form-check-label" for="exampleRadios1">
                    Tout cocher
                </label>
            </div>
            <div class="form-check" style="margin-bottom: 10px">
                <input class="form-check-input" type="radio" data-check="mobilier" value="0">
                <label class="form-check-label" for="exampleRadios1">
                    Tout décocher
                </label>
            </div>
        </div>
        <div class="col-md-6" id="prelevement" data-type-id="25">
            <h3>Prélévement</h3>
            <div class="form-check">
                <input class="form-check-input" type="radio" data-check="prelevement" value="1">
                <label class="form-check-label" for="exampleRadios1">
                    Tout cocher
                </label>
            </div>
            <div class="form-check" style="margin-bottom: 10px">
                <input class="form-check-input" type="radio" data-check="prelevement" value="0">
                <label class="form-check-label" for="exampleRadios1">
                    Tout décocher
                </label>
            </div>
        </div>
        <div class="col-md-6" id="doc" data-type-id="26">
            <h3>Document</h3>
            <div class="form-check">
                <input class="form-check-input" type="radio" data-check="document" value="1">
                <label class="form-check-label" for="exampleRadios1">
                    Tout cocher
                </label>
            </div>
            <div class="form-check" style="margin-bottom: 10px">
                <input class="form-check-input" type="radio" data-check="document" value="0">
                <label class="form-check-label" for="exampleRadios1">
                    Tout décocher
                </label>
            </div>
        </div>
        <div class="col-md-6" id="contenant_mob" data-type-id="28">
            <h3>Contenant mobilier</h3>
            <div class="form-check">
                <input class="form-check-input" type="radio" data-check="contenant_mob" value="1">
                <label class="form-check-label" for="exampleRadios1">
                    Tout cocher
                </label>
            </div>
            <div class="form-check" style="margin-bottom: 10px">
                <input class="form-check-input" type="radio" data-check="contenant_mob" value="0">
                <label class="form-check-label" for="exampleRadios1">
                    Tout décocher
                </label>
            </div>
        </div>
        <div class="col-md-6" id="contenant_doc" data-type-id="30">
            <h3>Contenant documentation</h3>
            <div class="form-check">
                <input class="form-check-input" type="radio" data-check="contenant_doc" value="1">
                <label class="form-check-label" for="exampleRadios1">
                    Tout cocher
                </label>
            </div>
            <div class="form-check" style="margin-bottom: 10px">
                <input class="form-check-input" type="radio" data-check="contenant_doc" value="0">
                <label class="form-check-label" for="exampleRadios1">
                    Tout décocher
                </label>
            </div>
        </div>
        <div class="col-md-6" id="contenant_doc_num" data-type-id="1886">
            <h3>Contenant documentaion numérique</h3>
            <div class="form-check">
                <input class="form-check-input" type="radio" data-check="contenant_doc_num" value="1">
                <label class="form-check-label" for="exampleRadios1">
                    Tout cocher
                </label>
            </div>
            <div class="form-check" style="margin-bottom: 10px">
                <input class="form-check-input" type="radio" data-check="contenant_doc_num" value="0">
                <label class="form-check-label" for="exampleRadios1">
                    Tout décocher
                </label>
            </div>
        </div>

    </div>
   <?php }else if ($set_id){
    print "Vous allez exporter tous les objets de l'ensemble";
   }else if ($occ_id){
    print "Vous allez exporter tous les objets de l'exposition";
   } ?>
   
   <?php if (!$set_id && !$occ_id){
    ?><div class="row" id="selectOperationMetadata">
        <h2>Choisir les métadonnées de l'opération à exporter :</h2>
        <div class="col-md-12">
            <div class="form-check">
                <input class="form-check-input" type="radio" value="1">
                <label class="form-check-label" for="exampleRadios1">
                    Tout cocher
                </label>
            </div>
            <div class="form-check" style="margin-bottom: 10px">
                <input class="form-check-input" type="radio" value="0">
                <label class="form-check-label" for="exampleRadios1">
                    Tout décocher
                </label>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_storage_locations_entree" /><label class='form-check-label'>Lieu d'entrée de la collection</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_collections.oa_number" /><label class='form-check-label'>Numéro OA</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_collections.idno" /><label class='form-check-label'>Code INRAP</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_places" /><label class='form-check-label'>Commune</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_collections.lieudit" /><label class='form-check-label'>Lieu-dit</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_entities_RO" /><label class='form-check-label'>Responsable d'opération</label></div>
        </div>
        <div class="col-md-6">
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_collections.inrap_annee_inter" /><label class='form-check-label'>Année d'intervention</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_collections.inrap_type_op.inrap_type_ope" /><label class='form-check-label'>Type d'opération</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_collections.statut_collection" /><label class='form-check-label'>Statut de l'opération</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_entities_SRA" /><label class='form-check-label'>SRA</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_entities_DIR" /><label class='form-check-label'>Direction INRAP</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_collections.inrap_volume_total" /><label class='form-check-label'>Volume total de la collection</label></div>
        </div>


    </div>
    <?php } ?>
    <div class="row" id="selectObjectsMetadata">
        <h2>Choisir les métadonnées des objets à exporter :</h2>
        <div class="col-md-12">
            <div class="form-check">
                <input class="form-check-input" type="radio" value="1">
                <label class="form-check-label" for="exampleRadios1">
                    Tout cocher
                </label>
            </div>
            <div class="form-check" style="margin-bottom: 10px">
                <input class="form-check-input" type="radio" value="0">
                <label class="form-check-label" for="exampleRadios1">
                    Tout décocher
                </label>
            </div>
        </div>
        <div class="col-6">
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_object_representations" /><label class='form-check-label'>Photos (jusqu'à 2)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.type_mobilier" /><label class='form-check-label'>Identification (Mobilier)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.oa_number" /><label class='form-check-label'>N° OA (Mobilier)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.inrap_unite_enregistrement" /><label class='form-check-label'>Unit d'enregistrement(Mobilier)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.idno" /><label class='form-check-label'>N° inventaire (Mobilier, automatique en Musée)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.inrap_numero_isolation" /><label class='form-check-label'>N° isolation (Mobilier)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.inrap_periode_chrono_site" /><label class='form-check-label'>Période chronologique (Mobilier)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.dateMillesime" /><label class='form-check-label'>Datation (Mobilier)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.inrap_materiaux" /><label class='form-check-label'>Matière (Mobilier)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.inrap_precisions_matiere" /><label class='form-check-label'>Précision matière (Mobilier)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_collections" /><label class='form-check-label'>Opération liée (Mobilier)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.quantification_mobilier" /><label class='form-check-label'>Quantification du mobilier (Mobilier)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.etat_fragmentaire_inrap" /><label class='form-check-label'>Etat fragmentaire (Mobilier)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.fragments_mobilier" /><label class='form-check-label'>Nb de restes (Mobilier)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_occurrences.etat_sanitaires" /><label class='form-check-label'>Etat sanitaire (Mobilier)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.INRAP_note.INRAP_note_note_txt" /><label class='form-check-label'>Note (Mobilier)</label></div>

        </div>
        <div class="col-6">
            
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_occurrences.traitements" /><label class='form-check-label'>Traitements (Mobilier)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.num_temp_lab.num_temp_lab_lab" /><label class='form-check-label'>Nom laboratoire (Mobilier)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.num_temp_lab.num_temp_num" /><label class='form-check-label'>N° laboratoire (Mobilier)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects" /><label class='form-check-label'>Contenant lié (Mobilier)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_object.type_contexte_archeologique" /><label class='form-check-label'>Type de contexte archéologique (Mobilier)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.inrap_type_struct_archeo" /><label class='form-check-label'>Nature du contexte enfouissement (Mobilier)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.inrap_valeur_assurance" /><label class='form-check-label'> Valeur d'assurance (Mobilier)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.inrap_domaine" /><label class='form-check-label'>Domaine</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_storage_locations" /><label class='form-check-label'>Lieu de stockage, Musée pour les musée</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.description" /><label class='form-check-label'>Description</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.dimensions" /><label class='form-check-label'>Dimension (mm)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.dimensions_cm" /><label class='form-check-label'>Dimension (cm)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.inrap_musee_chrono" /><label class='form-check-label'>Période chronologique (Musée)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.date_periode_chrono" /><label class='form-check-label'>Datation (Musée)</label></div>
            <div class="form-check"><input type='checkbox' class='form-check-input' value="ca_objects.inrap_musee_materiaux" /><label class='form-check-label'>Matière (Musée)</label></div>


        </div>


    </div>
    <div class="row" id="generalParameter">
        <h2>Paramètre généraux</h2>
        <p>Seul certains affichages permettent d'afficher jusqu'à 3 images, les autres eux peuvent n'en afficher qu'une. Le nombre d'image qu'ils peuvent afficher et inscrit entre [] derrière le texte.</p>

        <div class="col-md-6 form-check" style="margin-top:6px;">
            <input type="radio" name="style" id="style" class="form-check-input" value="texte-deux-images-gauche">
            <label class="form-check-label"><img class="style-pic" src="/app/plugins/exportInrap/assets/styles/texte-deux-images-gauche.png"> Un seul objet par page avec informations de l'objet à droite, images à gauche [3]</label>
        </div>
        <div class="col-md-6 form-check" style="margin-top:6px;">
            <input type="radio" class="form-check-input" id="style" name="style" value="texte-deux-images-droite">
            <label class="form-check-label"><img class="style-pic" src="/app/plugins/exportInrap/assets/styles/texte-deux-images-droite.png"> Un seul objet par page avec informations de l'objet à gauche, images à droite [3]</label>
        </div>
        <div class="col-md-6 form-check" style="margin-top:6px;">
            <input type="radio" class="form-check-input" id="style" name="style" value="ensemble-2-par-page-texte-bas">
            <label class="form-check-label"><img class="style-pic" src="/app/plugins/exportInrap/assets/styles/ensemble-2-par-page-texte-bas.png"> Deux objets, textes sous les images [1]</label>
        </div>
        <div class="col-md-6 form-check" style="margin-top:6px;">
            <input type="radio" class="form-check-input" id="style" name="style" value="ensemble-2-par-page-texte-droite">
            <label class="form-check-label"><img class="style-pic" src="/app/plugins/exportInrap/assets/styles/ensemble-2-par-page-texte-droite.png"> Deux objets, textes à droite les images. [3]</label>
        </div>
        <div class="col-md-6 form-check" style="margin-top:6px;">
            <input type="radio" class="form-check-input" id="style" name="style" value="ensemble-4-par-page-texte-droite">
            <label class="form-check-label"><img class="style-pic" src="/app/plugins/exportInrap/assets/styles/ensemble-4-par-page-texte-droite.png"> 4 objets par pages, texte à droite (recommandé uniquement si peu d'informations) [1]</label>
        </div>
        <div class="col-md-6 form-check" style="margin-top:6px;">
            <input type="radio" class="form-check-input" id="style" name="style" value="ensemble-4-par-page-texte-bas">
            <label class="form-check-label"><img class="style-pic" src="/app/plugins/exportInrap/assets/styles/ensemble-4-par-page-texte-bas.png"> 4 objets par pages, texte en dessous (recommandé uniquement si peu d'informations) [1]</label>
        </div>
        <?php if (!$occ_id){?>
        <div class="mt-3">
            <label for="exampleInputEmail1" class="form-label">Titre pour la page d'accueil</label>
            <input type="text" class="form-control" id="futurTitre">
            <div id="emailHelp" class="form-text">Si aucun titre ne sera remplis, par défaut le titre sera : "Export de l'opération"</div>
        </div>
        <?php } ?>
    </div>
    <div class="row mb-3" id="regroup">
        <h2>Regroupement des objets</h2>
        <div class="col-6">
            <div class="form-check">
                <input class="form-check-input" name="regroup" type="radio" value="mat">
                <label class="form-check-label" for="exampleRadios1">
                    Matière
                </label>
            </div>
            <div class="form-check">
                <input class="form-check-input" name="regroup" type="radio" value="dom">
                <label class="form-check-label" for="exampleRadios1">
                    Domaine
                </label>
            </div>
            <div class="form-check">
                <input class="form-check-input" name="regroup" type="radio" value="iso">
                <label class="form-check-label" for="exampleRadios1">
                    Numéro d'isolation
                </label>
            </div>
        </div>
        <div class="col-6">
            <div class="form-check">
                <input class="form-check-input" name="regroup" type="radio" value="id">
                <label class="form-check-label" for="exampleRadios1">
                    Identification
                </label>
            </div>
            <div class="form-check">
                <input class="form-check-input" name="regroup" type="radio" value="inv">
                <label class="form-check-label" for="exampleRadios1">
                    Trier par Numéro d'inventaire
                </label>
            </div>
        </div>
        <div class="col-6">
            <div class="form-check">
                <input class="form-check-input" name="regroup" type="radio" value="pcm">
                <label class="form-check-label" for="exampleRadios1">
                    Période chronologique (Musée)
                </label>
            </div>
            <div class="form-check">
                <input class="form-check-input" name="regroup" type="radio" value="matm">
                <label class="form-check-label" for="exampleRadios1">
                    Matériaux (Musée)
                </label>
            </div>
        </div>
        <div class="col-6">
            <div class="form-check">
                <input class="form-check-input" name="regroup" type="radio" value="pc">
                <label class="form-check-label" for="exampleRadios1">
                    Période chronologique (Mobilier)
                </label>
            </div>
            
        </div>
    </div>




    <button type="button" id="validation" class="btn btn-info col-2" data-toggle-next="1">Valider</button>

</div>


<form action="/index.php/exportInrap/Export/Export" method="POST" id="form">
    <?php if ($set_id) {
        print '<input type="hidden" id="set_id" name="set_id" value="' . $set_id . '" />';
    } else if ($occ_id){
        print '<input type="hidden" id="occ_id" name="occ_id" value="' . $occ_id . '" />';

    }else {print  '<input type="hidden" id="codeInrap" name="codeInrap" />';} ?>
    <input type="hidden" id="allExportedObject" name="allExportedObject" />
    <input type="hidden" id="allOperationMetadata" name="allOperationMetadata" />
    <input type="hidden" id="allExportedObjectMetadata" name="allExportedObjectMetadata" />
    <input type="hidden" id="stylePage" name="stylePage" />
    <input type="hidden" id="regroupVal" name="regroupVal" />
    <input type="hidden" id="titre" name="titre" />

</form>




<style>
    .btn-info {
        background-color: #1ab3c8;
        color: white;
    }


    h2 {
        margin-top: 20px !important;
        margin-bottom: 15px !important;
    }

    .style-pic {
        height: 40px;
        width: auto;
        border: 1px solid lightgrey;
    }

    .col-6, .col-md-6{
        width: 46%;
    }
</style>

<script>
   <?php if (!$set_id && !$occ_id){?> $("#show-object").click(function() {
        if (!$("#code_inrap").val()) {
            alert("Le code inrap doit être rempli");
            return false;
        }
        $("#code_inrap").attr("readonly", true);
        var param = {
            24: "mobilier",
            28: ""
        };
        $.get('/ajax/objectByCodeOp.php', {
            q: $("#code_inrap").val()
        }, function(data, status) {
            data = JSON.parse(data);
            for (const property in data) {
                $("div").each(function() {
                    if ($(this).data("type-id") == property) {
                        data[property].forEach(element => {
                            $(this).append("<div class='form-check'><input type='checkbox' class='form-check-input' value='" + element[0] + "'/>   <label class='form-check-label'>" + element[1] + "</label></div>");
                        })
                    }
                })
            }
            $("#parameters").show();
            $("#show-object").hide();
            $("#selectObject > div").each(function() {
                if ($(this).children().length == 3) {
                    $(this).css("display", "none");
                }
            })
        })
    });
    <?php } ?>

    $("#validation").click(function() {
        var allObjects = [];
        $("#selectObject").find("input:checkbox:checked").each(function() {
            allObjects.push($(this).val());
        })
        allObjects = allObjects.join("_&_");
        $("#allExportedObject").val(allObjects);
        var allObjectsMetadata = [];
        $("#selectObjectsMetadata").find("input:checkbox:checked").each(function() {
            allObjectsMetadata.push($(this).val());
        })
        allObjectsMetadata = allObjectsMetadata.join("_&_");
        $("#allExportedObjectMetadata").val(allObjectsMetadata);

        var allOperationMetadata = [];
        $("#selectOperationMetadata").find("input:checkbox:checked").each(function() {
            allOperationMetadata.push($(this).val());
        })
        allOperationMetadata = allOperationMetadata.join("_&_");
        $("#allOperationMetadata").val(allOperationMetadata);

        $("#codeInrap").val($("#code_inrap").val());

        $("#stylePage").val($("#generalParameter").find("input:checked").val());
        $("#regroupVal").val($("#regroup").find("input:checked").val());
        $("#titre").val($("#futurTitre").val());



       <?php if (!$set_id && !$occ_id){?> if (!$("#allExportedObject").val()) {
            alert("Veuillez sélectionner au moins un objet à exporter");
            return false;
        }
        if (!$("#allOperationMetadata").val()) {
            alert("Veuillez sélectionner au moins une métadonnée de l'opération à exporter");
            return false;
        }
        <?php } ?>
        if (!$("#allExportedObjectMetadata").val()) {
            alert("Veuillez sélectionner au moins une métadonnée d'objet à exporter");
            return false;
        }
        $("#form").submit();
    })

    $("#selectObject :radio").change(function() {
        if ($(this).val() == 1) {
            $("#" + $(this).data("check") + " input:checkbox").prop('checked', this.checked);
            $("#" + $(this).data("check") + " input[value='0']").removeProp('checked', this.checked);

        } else if ($(this).val() == 0) {
            $("#" + $(this).data("check") + " input:checkbox").removeProp('checked', this.checked);
            $("#" + $(this).data("check") + " input[value='1']").removeProp('checked', this.checked);
        }
    })

    $("#selectObjectsMetadata :radio").change(function() {
        if ($(this).val() == 1) {
            $("#selectObjectsMetadata input:checkbox").prop('checked', this.checked);
            $("#selectObjectsMetadata input[value='0']").removeProp('checked', this.checked);

        } else if ($(this).val() == 0) {
            $("#selectObjectsMetadata input:checkbox").removeProp('checked', this.checked);
            $("#selectObjectsMetadata input[value='1']").removeProp('checked', this.checked);
        }
    })
    $("#selectOperationMetadata :radio").change(function() {
        if ($(this).val() == 1) {
            $("#selectOperationMetadata input:checkbox").prop('checked', this.checked);
            $("#selectOperationMetadata input[value='0']").removeProp('checked', this.checked);

        } else if ($(this).val() == 0) {
            $("#selectOperationMetadata input:checkbox").removeProp('checked', this.checked);
            $("#selectOperationMetadata input[value='1']").removeProp('checked', this.checked);
        }
    })
</script>