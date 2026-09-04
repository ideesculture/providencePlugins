<?php
//$va_statistics <li>= $this->getVar('statistics_listing');
define("__ASSET_IMAGE_DIR__", __DIR__."/images");
define("__PLUGIN_URL__", __CA_URL_ROOT__."/index.php/etatsInrap/Generer");
$deplist=$this->getVar("deplist");
error_reporting(E_ERROR);
ini_set("display_errors",true);

$etats = [

/*
	DEPUIS UN RESULTAT DE RECHERCHE
	
	
	BOUTON "INRAP" => envoie les résultats de recherche OP, envoyer les critères de recherche, le tout dans Modele 1.
	
	DANS L'ONGLET ETAT, expliquer juste comment lancer l'état
*/
	
[
    "code"=>"diagnostics_en_cours_d_etude",
    "modele"=>"Modele1",
    "label"=>"diagnostics en cours d'étude", 
    "color" => "grey"
],
[
    "code"=>"diagnostics_en_attente_de_versement",
    "modele"=>"Modele1",
    "label"=>"diagnostics en attente de versement",
    "color" => "grey"
],
[
    "code"=>"diagnostics_en_garde_pour_inrap",
    "modele"=>"Modele1",
    "label"=>"diagnostics en garde pour l'Inrap",
    "color" => "grey"
],
[
    "code"=>"diagnostics_en_garde_pour_etat",
    "modele"=>"Modele1",
    "label"=>"diagnostics en garde pour l'Etat ",
    "color" => "grey"
],
[
	"code"=>"fouilles_en_cours_d_etude",
	"modele"=>"Modele1",
	"label"=>"fouilles en cours d'étude",
    "color" => "Maroon"
],
[
	"code"=>"fouilles_en_attente_de_versement",
	"modele"=>"Modele1",
	"label"=>"fouilles en attente de versement",
    "color" => "Maroon"

],
[
	"code"=>"fouilles_en_garde_pour_inrap",
	"modele"=>"Modele1",
	"label"=>"fouilles en garde pour l'Inrap",
    "color" => "Maroon"

],
[
	"code"=>"fouilles_en_garde_pour_etat",
	"modele"=>"Modele1",
	"label"=>"fouilles en garde pour l'Etat",
    "color" => "Maroon"

],
[
    "code"=>"operations_en_cours_d_etude",
    "modele"=>"Modele1",
    "label"=>"opérations en cours d'étude",
    "color"=> "Purple"
],
[
    "code"=>"operations_en_attente_de_versement",
    "modele"=>"Modele1",
    "label"=>"opérations en attente de versement",
    "color"=> "Purple"

],
[
    "code"=>"operations_en_garde_pour_inrap",
    "modele"=>"Modele1",
    "label"=>"opérations en garde pour l'Inrap",
    "color"=> "Purple"

],
[
    "code"=>"operations_en_garde_pour_etat",
    "modele"=>"Modele1",
    "label"=>"opérations en garde pour l'Etat",
    "color"=> "Purple"

],
[
	"code"=>"fouilles_programmees_en_cours_d_etude",
	"modele"=>"Modele1",
	"label"=>"fouilles programmées en cours d'étude",
    "color" =>"Green"
],
[
	"code"=>"fouilles_programmees_en_attente_de_versement",
	"modele"=>"Modele1",
	"label"=>"fouilles programmées en attente de versement",
    "color" =>"Green"
],
[
	"code"=>"fouilles_programmees_en_garde_pour_inrap",
	"modele"=>"Modele1",
	"label"=>"fouilles programmées en garde pour l'Inrap",
    "color" =>"Green"
],
[
	"code"=>"fouilles_programmees_en_garde_pour_etat",
	"modele"=>"Modele1",
	"label"=>"fouilles programmées en garde pour l'Etat",
    "color" =>"Green"
],
[
    "code"=>"diagnostics_non_defini",
    "modele"=>"Modele1",
    "label"=>"diagnostics non défini",
    "color" => "#FFC300"
],

[
    "code"=>"fouilles_non_defini",
    "modele"=>"Modele1",
    "label"=>"fouilles non défini",
    "color" => "#FFC300"
],
[
    "code"=>"operations_non_defini",
    "modele"=>"Modele1",
    "label"=>"operations non défini",
    "color" => "#FFC300"
],
[
    "code"=>"fouilles_programmees_non_defini",
    "modele"=>"Modele1",
    "label"=>"fouille programmées non défini",
    "color" => "#FFC300"
],

[
    "code"=>"operations_sorties",
    "modele"=>"Modele1",
    "label"=>"opérations versées",

],

/*
	DEUX ETAPES :
	- recherche => collection_id:COLL AND type_id:mobilier
	- bouton INRAP => même logique que pour Modele1, passe par un format d'affichage	
*//*
[
    "code"=>"inventaire_global_de_la_collection",
    "modele"=>"Modele2_help",
    "label"=>"Inventaire global de la collection"
],*/

// depuis un résultat de recherche de mobilier
/*[
    "code"=>"etiquette_temporaire",
    "modele"=>"Modele3",
    "label"=>"étiquette temporaire"
],*/ /*
[
    "code"=>"etiquette_définitive_conditionnement",
    "modele"=>"Modele3",
    "label"=>"étiquette mobilier"
],
[
    "code"=>"etiquette_définitive_contenant",
    "modele"=>"Modele3",
    "label"=>"étiquette contenant"
],
[
    "code"=>"bordereau_de_versement",
    "modele"=>"Modele4_help",
    "label"=>"bordereau de versement"
],*/

// depuis une fiche objet
/*
[
    "code"=>"constat_etat_simple",
    "modele"=>"Modele5",
    "label"=>"Constat d'état simple"
],*/
/*
[
    "code"=>"constat_etat_itinerance",
    "modele"=>"Modele5",
    "label"=>"Constat d'état itinérance"
],*//*
[
    "code"=>"condition_pret_objet_exposition",
    "modele"=>"Modele6_help",
    "label"=>"Condition de prêt objet exposition simple"
],
*/
//depuis la fiche expo
/*
[
    "code"=>"liste_objets_cadre_exposition",
    "modele"=>"Modele7",
    "label"=>"liste des objets dans cadre exposition"
],
[
    "code"=>"lettres_fonction_mouvement",
    "modele"=>"Modele8",
    "label"=>"Lettres en fonction mouvement"
],
[
    "code"=>"stock_centre",
    "modele"=>"Modele9",
    "label"=>"Stock centre"
]
*/
];
?>

<h1>Etats Inrap</h1>



<div class="etatsInrap container">

	<div style="padding-left:40px;">
    <p>Possibilité de réaliser l’édition des états de deux façons distinctes. En sélectionnant uniquement la direction territoriale vous aurez l’ensemble des opérations rattachées à celle-ci. Vous pouvez également affiner votre sélection par critère géographique de la région actuelle au département au <u><b>moyen</b></u> du groupe : Région >Ancienne région>département.</p>

    <hr>
    <label id="label_region">Direction</label>
	<select id="dir" name="dir">
        <option>France</option>
		<option value="19">Auvergne-Rhône-Alpes (ARA)</option>
		<option value="21">Centre-Île de France (CIF)</option>
		<option value="22">Grand-Est (GE)</option>
		<option value="23">Grand Ouest (GO)</option>
		<option value="24">Nouvelle Aquitaine (NAOM)</option>
		<option value="25">Hauts de France (HDF)</option>
		<option value="26">Méditerranée (MED)</option>
		<option value="20">Bourgogne Franche Comté (BFC)</option>
	</select> 
    <hr>
    <label id="label_region">Région</label>
    <select id="region" name="region">
        <option>-----</option>
    </select><br>
    <label id="label_old_region">Ancienne région</label>
    <select id="old_region" name="old_reigon">
        <option>-----</option>
    </select><br>
    <label id="label_old_region">Département</label>
    <select id="dep" name="dep">
        <option>-----</option>
    </select>
    <hr>



	
		<!-- Département
		<select id="departement" name="departement">
			<option value="-">-</option>
			<?php foreach($deplist as $id=>$dep) : ?>
			<option value="<?php print $id; ?>"><?php $dep = str_replace("dep","",$dep); print ( ($dep*1 > 0) && ($dep*1 < 10) ? "0".$dep : $dep); ?></option>
			<?php endforeach; ?>
		</select> -->
	</div><ul class="liste-etats">

<?php
foreach($etats as $etat) {
    print "<li class='".$etat["modele"]."' style='background-color:".$etat["color"]."'><a data-href='".__PLUGIN_URL__."/".$etat["modele"]."/etat/".$etat["code"]."'>".$etat["label"]."</a>";
}
?>

</ul>
    
</div>
<div style="margin-bottom:100px;clear:both;"></div>

<style>
	ul.liste-etats {
		display: block;
	}
	ul.liste-etats li {
		display:inline-block;
		float:left;
		height:50px;
		width:135px;
		margin-right:20px;
		margin-bottom:20px;
		padding:10px 10px 40px 10px;
		background-color:#77C1D1;
		font-size:15px;
	}
    ul.liste-etats li.Modele2 {
        background-color:#67B1C1;
    }
    ul.liste-etats li.Modele3 {
        background-color:#57A1B1;
    }
    ul.liste-etats li.Modele4,
    ul.liste-etats li.Modele4_help {
        background-color:#4791A1;
    }
    ul.liste-etats li.Modele5 {
        background-color:#378191;
    }
    ul.liste-etats li.Modele6,
    ul.liste-etats li.Modele6_help {
        background-color:#277181;
    }
    ul.liste-etats li.Modele7 {
        background-color:#176171;
    }
    ul.liste-etats li.Modele8 {
        background-color:#075161;
    }
    ul.liste-etats li.Modele9 {
        background-color:#024151;
    }
	ul.liste-etats li a {
		font-weight: bold;
		text-decoration: none;
		color:white;
	}
    .suiviInventaire > div,
    .suiviInventaire > div > div {
        /*border:1px solid red;*/
    }
    .suiviInventaire h2 {
        font-size:18px;
    }
    .suiviInventaire .row {
        width:100%;
        clear:both;
    }
    .col-md-6 {
        width:46%;
        margin-right:3%;
        margin-left:0;
        float:left;
        /*border:1px solid blue;*/
        min-height: 40px;
    }
    .col-md-6:last-child {
        margin-right:0;
    }
    .col-md-6:first-child {
        margin-left:1.5%;
    }
    .chart-container {
        text-align: center;
    }
    .suiviInventaire img {
        max-width: 80%;
        height: auto;
    }
    #cipar img {
        width: auto;
        max-height: 240px;
    }
    table.cipar_table {
        margin:auto;
        margin-bottom:40px;

    }
    table tr:nth-child(2n+1) {
        background-color:lightgrey;
    }
    table td {
        padding:10px 20px;
    }
	.liste-etats a {
		cursor: pointer;
	}
</style>
<script>
	jQuery(document).ready(function() {
		$(".liste-etats a").on("click", function() {
			if($("select#dep").val() == "-----") {
                if ($("#old_region").val() == "-----"){
                    console.log($("#region").val());
                    if($("#region").val() == "-----"){
                        window.location.href=jQuery(this).data("href")+"/dir/"+jQuery("#dir").val();
                    }else{
                        window.location.href=jQuery(this).data("href")+"/region/"+jQuery("#region").val();
                    }
                } else{
                    window.location.href=jQuery(this).data("href")+"/old_region/"+jQuery("#old_region").val();
                }
			} else {
				window.location.href=jQuery(this).data("href")+"/dep/"+jQuery("#dep").val();
			}
		});
        var json = $.getJSON("/app/plugins/etatsInrap/results.json", function(data){
	        data.region.sort(function(a,b){
			    return a.name.localeCompare(b.name);
	        })
            data.region.forEach(element => {
                $("#region").append("<option value='"+element.place_id+"'>"+element.name+"</option>");
            });
            data.departement.sort(function(a,b){
		    	return a.name.localeCompare(b.name);

	        })
            data.departement.forEach(element => {
                $("#dep").append("<option value='"+element.place_id+"' data-parent='"+element.parent_id+"' style='display:none;'>"+element.name+"</option>");
            });
            data.ancienne_region.sort(function(a,b){
	            return a.name.localeCompare(b.name);

	        })
            data.ancienne_region.forEach(element => {
                $("#old_region").append("<option value='"+element.place_id+"' data-parent='"+element.parent_id+"' style='display:none;'>"+element.name+"</option>")
            });
        });
        $("#region").change(function(){
            $("#dep").find("option").each(function(){
                var val = $("#region").val();
                if ($(this).data("parent") != val && $(this).text() != "-----"){
                    $(this).css("display", "none");
                } 
                else{
                    $(this).css("display", "block");
                }
            });
             
            
            $("#old_region").find("option").each(function(){
                var val = $("#region").val();
                if ($(this).data("parent") != val && $(this).text() != "-----"){
                    $(this).css("display", "none");
                } 
                else{
                    $(this).css("display", "block");
                }
            });
                    });
        $("#old_region").change(function(){
            $("#dep").find("option").each(function(){
                var val = $("#old_region").val();
                if ($(this).data("parent") != val && $(this).text() != "-----"){
                    $(this).css("display", "none");
                } 
                else{
                    $(this).css("display", "block");
                }
            });
             
        });
       
             
		
	});
</script>
