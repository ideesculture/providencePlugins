
<link rel="stylesheet" href="//cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="//cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

<?php
$rows = $this->getVar('rows');
// retraite le tableau pour agréger les deleted, les à valider et les validés dans un sous-tableau par type

$contributions = array();
foreach ($rows as $row) {
	if(!isset($contributions[$row["user_id"]]["contributions"])){
		// Si pas défini, préremplir
		$contributions[$row["user_id"]]["contributions"] = ["supprimé"=>0, "à valider"=>0, "validés"=>0];
	}
	//var_dump($row);
	//die();
	if($row["deleted"]==1) {
		$contributions[$row["user_id"]]["contributions"]['supprimé'] += 1;
	} else {
		if($row["status"]==4) {
			$contributions[$row["user_id"]]["contributions"]['validé'] += 1;
		}	
		if($row["status"]==5) {
			$contributions[$row["user_id"]]["contributions"]['à valider'] += 1;
		}	
		if($row["status"]==6) {
			$contributions[$row["user_id"]]["contributions"]['à compléter'] += 1;
		}	
	}
	$contributions[$row["user_id"]]["user_id"] = $row["user_id"];
	$contributions[$row["user_id"]]["user_name_display"] = $row["user_name_display"];
}
//die();

// Génère le code HTML
$html = '
    <h1>Nombre de fiches cataloguées</h1>
    <table id="contributions">
        <thead>
            <tr>
                <th>Nom/Prénom</th>
                <th>Contributions</th>
				<th>Total contributions</th>
            </tr>
        </thead>
        <tbody>';

foreach ($contributions as $row) {
    $html .= '<tr>
                <td><a href="/gestion/index.php/administrate/access/Users/Edit/user_id/' . $row['user_id'] . '">' . $row['user_name_display'] . '</a></td>
                <td>
                    <table>
                        <tbody>';

    foreach ($row['contributions'] as $key => $value) {
		$html .= '<tr>
                    <td style="width:65%">' . $key . '</td>
                    <td style="width:35%;text-align:right">' . $value . '</td>
                </tr>';
		$total += $value;
    }

    $html .= '</tbody>
            </table>
        </td>
		<td style="text-align:right;">' . $total . '</td>
    </tr>';
	$total = 0;
}

$html .= '</tbody>
    </table>
<div style="height:120px"></div>	
';

echo $html;

?>
<style>
	table {
		width:100%;
	}
	td {
		padding:6px;
		border:1px solid lightgray;
	}
</style>
<script>
	let table = new DataTable('#contributions');
</script>