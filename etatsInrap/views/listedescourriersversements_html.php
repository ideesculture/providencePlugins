<?php
$vs_widget_id = 1;
$results = $this->getVar("results");
$region_label = $this->getVar("region_label");
?>
<div style="float:right"><a href="/">Retour</a></div>
<h1>Liste des courriers de versements pour <?= $region_label ?></h1>
<link href="//cdn.datatables.net/2.1.8/css/dataTables.dataTables.min.css" rel="stylesheet" />
<script src="//cdn.datatables.net/2.1.8/js/dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.2.0/js/dataTables.buttons.js"></script>
<script src="https://cdn.datatables.net/buttons/3.2.0/js/buttons.dataTables.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/3.2.0/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.2.0/js/buttons.print.min.js"></script>

<div class="dashboardWidgetContentContainer">
	<table class="listeDesVersementsTable" id="table<?= $vs_widget_id ?>">
		<thead>
			<tr>
				<th>titre courrier</th>
				<th>identifiant</th>
				<th>region</th>
				<th>date du courrier</th>
				<th>numéro OA</th>
				<th>date remise rapport</th>
				<th>opérations liées</th>
			</tr>
		</thead>
		<tbody>
			<?php
			foreach ($results as $result):
				// reformat date DD/MM/YYYY in $result[4] to a YYYY-MM-DD format
				//$date_order = substr($result[4], 6, 4) . "-" . substr($result[4], 3, 2) . "-" . substr($result[4], 0, 2);
			?>
				<tr>
					<td><?= $result[0] ?></td>
					<td><?= $result[1] ?></td>
					<td><?= $result[2] ?></td>
					<td><?= $result[3] ?></td>
					<td><?= $result[5] ?></td>
					
					<td><?= $result[9] ?></td>
					<td><?= $result[10] ?></td>
				</tr>
			<?php
			endforeach;
			?>
		</tbody>
		<tfoot>
			<tr>
				<th>titre courrier</th>
				<th>identifiant</th>
				<th>region</th>
				<th>date du courrier</th>
				<th>numéro OA</th>
				<th>date remise rapport</th>
				<th>opérations liées</th>
			</tr>
		</tfoot>
	</table>
</div>
<style>
	#main {
		width: 90% !important;
	}

	#mainContent {
		width: 100% !important;
		margin-left: 0 !important;
	}

	#leftNav {
		display: none !important;
	}

	.listeDesVersementsTable tr {
		border-bottom: 1px solid gray;
	}

	table.dataTable>tbody>tr>th,
	table.dataTable>tbody>tr>td {
		padding: 3px;
	}

	table.dataTable>thead>tr>th,
	table.dataTable>thead>tr>td {
		padding: 4px;
	}

	table.dataTable tfoot {
		/*display:none;*/
	}

	div.dt-container {
		position: relative;
		clear: both;
		margin-left: -15px;
	}
</style>
<script>
	let table<?= $vs_widget_id ?> = new DataTable('#table<?= $vs_widget_id ?>', {
		layout: {
			topStart: {
				buttons: ['copy', 'csv', 'excel', 'print']
			}
		},
		responsive: true,
		order: [
			[2, 'desc']
		],
		pageLength: 20,
		lengthMenu: [5, 10, 20, 50, 100],
		language: {
			url: '/dataTables.fr_FR.json'
		},
		/* allows to filter, but having a problem with the screen width*/
		initComplete: function() {
		}
	});
</script>

<style>
	td {
		vertical-align: top;
	}
</style>