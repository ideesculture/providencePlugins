<?php $id = $this->getVar("id");
$errors = $this->getVar("error");?>


<h1>Import SGA</h1>
<h2>Importation réussie <?php if (!empty($errors)){print "mais avec quelques erreurs";}?></h2>

Voir l'opération importée <a href="/index.php/editor/collections/CollectionEditor/Summary/collection_id/<?= $id ?>">ici</a>.
<br/>

<?php 
if (!empty($errors)){
    print "<hr>";
    print "Ces données sont différentes entre celles présentes dans le SGA et Comodo.";
    print "<table class='table table-bordered'> <thead><tr>
        <th>Nom du champ dans comodo</th>
        <th>Donnée présente dans le SGA</th>
        <th>Si donnée présente dans comodo après import</th>
    </tr></thead><tbody>";
    $table_variable = array(
        "idno" => "Code Inrap",
        "inrap_ancien_code" => "Ancien code Inrap",
        "inrap_op_rattachement" => "Opération de rattachement",
        "inrap_type_op.inrap_type_ope" => "Type de l'opération",
        "inrap_type_op.inrap_axe_analytique" => "Axe de l'opération",
        "oa_number" => "Numéro d'OA", 
        "ro" => "Responsable de l'opération",
        "commune" => "Commune",
        "lieudit" => "Lieu-dit",
        "parcelle" => "Parcelle",
        "centre_op" => "Centre Opération",
        "sra" => "SRA",
        "prescripteur" => "Agent Prescripteur",
        "numero_prescription" => "Numéro de prescription",
        "date_simple" => "Date de prescription",
        "surface_OA" => "Surface OA",
        "dir_inrap" => "Direction Inrap",
        "dir_adj_st" => "Directeur Adjoint Scientifique et Technique",
        "datesdeterrain.Datedeterrain_date" => "Date de terrain - date de début",
        "datesdeterrain.datedeterrain_datefin" => "Date de terrain -  date de fin",
        "autorisation_fouille" => "Autorisation titulaire intervention - numéro",
        "autorisation_date" => "Autorisation titulaire intervention - date",
        "inrap_date_planification" => "Date de planification",
        "date_prev_du_rapport" => "Date prévisionnelle de remise du rapport",
        "date_du_rapport" => "Date de remise du rapport	",
        "CodeINSEE" => "Code Insee",
        "AnneeDebutTerrain" => "Année d'intervation",
        "NomOpeRattachement" => "Opération de rattachement"
    );
    foreach ($errors as $error){
        print "<tr><td>".$table_variable[$error[0]]."</td> <td>".$error[1]."</td><td>".$error[2]."</td></tr>";
    }
    print "</tbody></table>";
    ?>
    Les données peuvent être considérées comme une erreur à cause des causes suivantes :
    <ul>
        <li>Il se peut que la donnée ne puisse être liée à l'opération, car elle n'est pas trouvée dans comodo :
            <ul>
                <li>Nom ou identifiant écrit de manière différente. (Exemple : Nom, Prénom dans celui du SGA et Nom Prénom dans Comodo. Ou pas d'accent dans comodo et dans le SGA si.)</li>
                <li>Personne ou lieu absent de la base de données et nécessite d'être créée manuellement.</li>
            </ul>
        </li>
        <li>Si on a bien deux données présentes, mais presque identiques (Exemple : Responsable d'opérations : Janes Dupont - Dupont, Janes). Ce n'est pas une erreur, juste qu'il identifie cela comme une erreur, car les noms sont écrits différemment.</li>
        <li>Si nous ne sommes dans aucun des cas précédents. Il peut y avoir un problème technique et dans ce cas-là je vous invite à contacter le support.</li>
    </ul>
    <?php
}
    ?>
    <style>
       .table-bordered {
        border: 1px solid #dee2e6;
    }
    .table {
        margin: 1rem 0;
        width: 100%;
        max-width: 100%;
        background-color: transparent;
    }
    table {
        border-collapse: collapse;
    }
    .table-bordered thead td, .table-bordered thead th {
    border-bottom-width: 2px;
    }
    .table thead th {
        vertical-align: bottom;
        border-bottom: 2px solid #dee2e6;
    }
    .table-bordered td, .table-bordered th {
        border: 1px solid #dee2e6;
    }
    .table td, .table th {
        padding: .75rem;
        vertical-align: top;
        border-top: 1px solid #dee2e6;
    }
    th {
        text-align: inherit;
    }
    </style>
    