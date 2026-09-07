
<h1>Listes des opérations importées du SGA</h1>
<?php
	// 07/09/2026 GM (ticket 7913) : la page était construite par « include "table_importe.html" »
	// — 5,9 Mo injectés dans le HTML — puis un DataTable() côté client sur 27 639 lignes. Le
	// serveur répondait sans erreur, mais le navigateur n'aboutissait jamais : c'est le « le
	// script ne va pas jusqu'au bout » signalé par le client.
	//
	// On reprend exactement le montage de index_non_import_html.php, éprouvé en production sur
	// la table jumelle (59 961 lignes) : le tableau est chargé dans une iframe, donc en document
	// séparé que le navigateur affiche au fil de l'eau, et le DataTable() n'est pas armé.
	//
	// Ce n'est pas la solution de fond : à cette volumétrie (88 645 opérations au SGA) il faudra
	// une pagination côté serveur sur _sga_comodo. Voir le ticket.
	//include "table_importe.html";
?>
<iframe src="/app/plugins/SGA/views/table_importe.html" style="border:none;width:100%;height:1000px;">

</iframe>

<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.10.25/css/jquery.dataTables.css">
<script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.10.25/js/jquery.dataTables.js"></script>

<script>
$(document).ready( function () {
    //$('#sga').DataTable();
} );
</script>
