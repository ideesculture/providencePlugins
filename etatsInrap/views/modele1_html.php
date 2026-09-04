<?php
	$vs_etat = $this->getVar("etat");
	$va_results = $this->getVar("results");
	$vs_titre = $this->getVar("titre");
	$num_results = $this->getVar("num_results");
	$departement = $this->getVar("departement");
	$region = $this->getVar("region");

	switch($region) {
		case 19:
			$region="Auvergne Rhône Alpes (ARA)";
			break;
		case 21:
			$region="Centre Ile de France (CIF)";
			break;
		case 22:
			$region="Grand Est (GE)";
			break;
		case 23:
			$region="Grand Ouest (GO)";
			break;
		case 24:
			$region="Nouvelle Aquitaine (NAOM)";
			break;
		case 25:
			$region="Hauts de France (HDF)";
			break;
		case 26:
			$region="Méditerranée (MED)";
			break;
		case 20:
			$region="Bourgogne Franche Comté (BFC)";
			break;
	}

	if($departement) {
		//$vs_label =$vs_titre." ".$departement;
	} else {
		$vs_label =$vs_titre." ".$region;
	}
?>
<div style='border:1px solid yellow;position:absolute;margin-left:-234px;background-color:white;border:1px solid #DDDDDD;padding:20px 20px 120px 20px;margin-top:-10px;min-height:100%;'>
<h1>
	<small style="color:gray;font-size:0.7em;">Etats INRAP</small>
	<br/>
	<?php print $vs_label; ?> <small>(<?php print $num_results." résultats"; ?>)</small>
</h1>

<table id="table">
<?php 
	foreach($va_results as $index => $row) {
		if($index == 0) {
			print "<THEAD><TR>";
			foreach($row as $col => $cell) {
				print "<TD>".$cell."</TD>";
			}
			print "</TR></THEAD>\n<TBODY>";
		} else {
			$row_tag = "";
			print "<TR>";
			foreach($row as $col => $cell) {
				print "<td>".$cell."</td>\n";
			}			
			print "</TR>";
		}
	}
?>
	</TBODY>
</table>
</div>
<div class="clear:both;"></div>

<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/v/dt/dt-1.10.18/datatables.min.css"/>
<script type="text/javascript" src="https://cdn.datatables.net/v/dt/dt-1.10.18/datatables.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/buttons/1.5.2/js/dataTables.buttons.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/buttons/1.5.2/js/buttons.html5.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/buttons/1.5.2/js/buttons.print.min.js"></script>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.22.2/moment.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/plug-ins/1.10.19/sorting/datetime-moment.js"></script>

<script>
	$(document).ready( function () {
	$.fn.dataTable.moment( 'DD/MM/YYYY' );
    $('#table').DataTable(
	    {
            "pageLength": 25,
            //"dom": 'Bfrtip',
            "dom": "B<'row'<'col-sm-3'l><'col-sm-3'f><'col-sm-6'p>>" + "<'row'<'col-sm-12'tr>>" + "<'row'<'col-sm-5'i><'col-sm-7'p>>",
			"buttons": [
				'copy', 'csv', 'pdf',
				{
					extend: 'excelHtml5',
					title: "<?php print $vs_label; ?>"
				},
				{
					extend: "print",
					title: "<?php print $vs_label; ?>",
					customize: function(win)
					{

						var last = null;
						var current = null;
						var bod = [];

						var css = '@page { size: landscape; }',
							head = win.document.head || win.document.getElementsByTagName('head')[0],
							style = win.document.createElement('style');

						style.type = 'text/css';
						style.media = 'print';

						if (style.styleSheet)
						{
							style.styleSheet.cssText = css;
						}
						else
						{
							style.appendChild(win.document.createTextNode(css));
						}

						head.appendChild(style);
					}
				}
			],
		    language: {
		        processing:     "Traitement en cours...",
		        search:         "Rechercher&nbsp;:",
		        lengthMenu:     "Afficher _MENU_ &eacute;l&eacute;ments",
		        info:           "Affichage de l'&eacute;lement _START_ &agrave; _END_ sur _TOTAL_ &eacute;l&eacute;ments",
		        infoEmpty:      "Affichage de l'&eacute;lement 0 &agrave; 0 sur 0 &eacute;l&eacute;ments",
		        infoFiltered:   "(filtr&eacute; de _MAX_ &eacute;l&eacute;ments au total)",
		        infoPostFix:    "",
		        loadingRecords: "Chargement en cours...",
		        zeroRecords:    "Aucun &eacute;l&eacute;ment &agrave; afficher",
		        emptyTable:     "Aucune donnée disponible dans le tableau",
		        paginate: {
		            first:      "<<",
		            previous:   "<",
		            next:       ">",
		            last:       ">>"
		        },
		        aria: {
		            sortAscending:  ": activer pour trier la colonne par ordre croissant",
		            sortDescending: ": activer pour trier la colonne par ordre décroissant"
		        }
		    }
	    }
    );
} );
</script>
