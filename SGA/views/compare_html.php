<?php
$id = $this->getVar("id");
$values = $this->getVar("value");
$notInBase = $this->getVar("notInBase");

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
    "AnneeDebutTerrain" => "Année d'intervention",
    "NomOpeRattachement" => "Opération de rattachement"
);
?>

<h1>Avant Import du SGA</h1>
<?php if(!$notInBase){?>
<p> Voici un tableau des données présentes dans Comodo et dans le SGA, veillez à vérifier avant d'importer que ça n'écrasera pas des données importantes</p>
<form method="POST" action="/index.php/SGA/SGA/Update/id/<?= $id ?>">
<?php }else{
    print "<p> L'opération n'existe pas dans Comodo et toutes ces données vont être ajoutés à l'opération crée</p>"; 
}?>
<table class='table table-bordered'> 
    <thead>
        <tr>
            <th>Nom du champ dans comodo</th>
            <th>Donnée présente dans le SGA</th>
            <th>Donnée présente dans comodo</th>
            <th><input type="checkbox" id="checkAll"></th>
        </tr>
    </thead>
    <tbody>
<?php foreach ($values as $value){
    print "<tr><td>".$table_variable[$value[0]]."</td> <td>".$value[1]."</td><td>".$value[2]."</td>".((!$notInBase)? "<td><input type='checkbox' class='checkClass' id='".$value[0]."' name='".$value[0]."' value='1'></td>" : "")."</tr>";
}?>
    </tbody>
</table>

<?php if ($notInBase == true){
    ?>
<a class='btn btn-info' href="/index.php/SGA/SGA/Importer/id/<?= $id ?>"> Importer </a>
<?php
} else {
    ?>
<button class="btn btn-info" type="submit"> Mettre à jour </button></form>

    <?php
}?>

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
    .btn:not(:disabled):not(.disabled) {
    cursor: pointer;
}
[type=reset], [type=submit], button, html [type=button] {
    -webkit-appearance: button;
}
.btn-info {
    color: #fff !important;
    text-decoration: none !important;
    background-color: #17a2b8;
    border-color: #17a2b8;
}
.btn {
    display: inline-block;
    font-weight: 400;
    text-align: center;
    white-space: nowrap;
    vertical-align: middle;
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
    border: 1px solid transparent;
    padding: .375rem .75rem;
    font-size: 1rem;
    line-height: 1.5;
    border-radius: .25rem;
}
    </style>
    
    <?php
    if (!$notInBase){
        ?>

        <script>
            $(document).ready(function(){
                $("#checkAll").click(() => {
                    $(".checkClass").prop("checked", true);

                })
            })
        </script>
        <?php
    }