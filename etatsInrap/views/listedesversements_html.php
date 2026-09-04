<?php
	require_once(__CA_MODELS_DIR__."/ca_storage_locations.php");
	$vt_location = new ca_storage_locations($this->request->getParameter('location', pInteger));
	$vs_widget_id = 1;
	$results 				= $this->getVar("results");
?>
<div style="float:right"><a href="/">Retour</a></div>
<h1>Liste des versements</h1>

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
	<!-- <p>
		<select name='type'>
			<option value="">Type...</option>
			<option value="à valider" selected="selected">A valider</option>
			<option value="prévu">Prévu</option>
			<option value="previsionnel">Prévisionnel</option>
			<option value="-">- (non défini)</option>
 		</select>
		 <select name='echeance'>
			<option value="">Par échéance...</option>
			<option value="10" selected="selected">A 10j</option>
			<option value="30">A 30j</option>
			<option value="all">Tous</option>
 		</select>
		Accepté 
		<select name='accepte'>
			<option value="0" selected="selected">non</option>
			<option value="1">oui</option>
 		</select>
	</p>-->
	<p>
		Versement pour <?=  $vt_location->getWithTemplate("<l>^ca_storage_locations.preferred_labels</l>") ?>
	</p>

		<table class="listeDesVersementsTable" id="table<?= $vs_widget_id ?>">
			<thead>
				<tr>
					<th>Type</th>
					<th>Versement</th>
					<th>Date prévi</th>
					<th>Date départ</th>
					<th>OP</th>
					<th>Accepté</th>
					<th>Statut collection</th>
					<th>Organisme</th>
					<th>Destinataire</th>
 				</tr>
 			</thead>
			<tbody>
	<?php
		foreach($results as $result):
			// reformat date DD/MM/YYYY in $result[4] to a YYYY-MM-DD format
			$date_order = substr($result[4], 6, 4) . "-" . substr($result[4], 3, 2) . "-" . substr($result[4], 0, 2);
	?>
	<tr>
		<td><?php 
			if($result[7] == "Versement prévisionnel") {
				print "Prévisionnel";
			} else print "";
		?></td>
		<td><?= $result[3] ?></td>
		<td data-order="<?= $date_order ?>"><?= $result[4] ?></td>
		<td><?= $result[5] ?></td>
		<td><?= $result[7] ?></td>
		<td><?= $result[8] ?></td>
		<td><?= $result[9] ?></td>
		<td><?= $result[10] ?></td>
		<td><?= $result[11] ?></td>
	</tr>
	<?php
		endforeach;
	?>
</tbody>
<tfoot>
            <tr>
			<th>Type</th>
					<th>Versement</th>
					<th>Date</th>
					<th>OP</th>
					<th>Accepté</th>
					<th>Statut versement</th>
            </tr>
        </tfoot>
	</table>
</div>
<style>
	.listeDesVersementsTable tr {
		border-bottom:1px solid gray;
	}
	table.dataTable>tbody>tr>th, table.dataTable>tbody>tr>td {
    padding: 3px;
}
table.dataTable>thead>tr>th, table.dataTable>thead>tr>td {
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
		order: [[2, 'desc']],
		pageLength: 20,
		lengthMenu: [5, 10, 20, 50, 100],
        language: {
			url: '/dataTables.fr_FR.json'
		},
		/* allows to filter, but having a problem with the screen width*/
		initComplete: function () {
			this.api()
				.columns()
				.every(function (index) {
					if(index % 2 == 1) return false;
					let column = this;
	
					// Create select element
					let select = document.createElement('select');
					const opt1 = document.createElement("option");
					opt1.value = "";
					opt1.text = "-";
					select.add(opt1, null);
					column.footer().replaceChildren(select);
	
					// Apply listener for user change in value
					select.addEventListener('change', function () {
						column
							.search(select.value, {exact: true})
							.draw();
					});
	
					// Add list of options
					column
						.data()
						.unique()
						.sort()
						.each(function (d, j) {
							select.add(new Option(d));
						});
				});
			} 
	});
</script>