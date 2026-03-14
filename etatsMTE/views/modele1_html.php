<?php
	$va_results = $this->getVar("results");
	$vs_titre = $this->getVar("titre");
	$vn_object_id = $this->getVar("objet");
?>
<div style='border:1px solid yellow;position:absolute;margin-left:-234px;background-color:white;border:1px solid #DDDDDD;padding:20px 20px 120px 20px;margin-top:-10px;min-height:100%;'>
<h1>
	<small style="color:gray;font-size:0.7em;">Constat d'état MTE</small>
	<br/>
	<?php print $vs_titre; ?>
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
			print "<TR>";
			foreach($row as $col => $cell) {
				print "<td>".htmlspecialchars($cell)."</td>\n";
			}
			print '<td><a class="btn button" href="'.__CA_URL_ROOT__.'/index.php/etatsMTE/Generer/ConstatDetatDoc/objet/'.$vn_object_id.'" style="cursor: pointer;border:1px solid #ccc;padding:10px; margin-bottom:10px;display:inline-block">Télécharger le fichier DOCX</a></td>';
			print "</TR>";
		}
	}
?>
	</TBODY>
</table>
</div>


<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/v/dt/dt-1.10.18/datatables.min.css"/>
<script type="text/javascript" src="https://cdn.datatables.net/v/dt/dt-1.10.18/datatables.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/buttons/1.5.2/js/dataTables.buttons.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/buttons/1.5.2/js/buttons.html5.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/buttons/1.5.2/js/buttons.print.min.js"></script>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>

<script>
	$(document).ready( function () {
    $('#table').DataTable(
	    {
            "pageLength": 25,
            "dom": 'Bfrtip',
			"buttons": [
				'copy', 'csv', 'excel', 'pdf', {
					extend: "print",
					customize: function(win)
					{
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
		        lengthMenu:    "Afficher _MENU_ &eacute;l&eacute;ments",
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
