
<link rel="stylesheet" href="//cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="//cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

<?php
$rows = $this->getVar('rows');
$user_id = $this->getVar("user_id");
$vt_user = new ca_users($user_id);
// retraite le tableau pour agréger les deleted, les à valider et les validés dans un sous-tableau par type
$statusCorrespondance = ["4" => "validé", "5" => "à valider", "6" => "à compléter"];
?>
    <h1>Contributions de l'utilisateur : <?=  $vt_user->get("fname")." ".$vt_user->get("lname") ?></h1>
    <table id="contributions">
        <thead>
            <tr>
				<th></th>
                <th>Identifiant</th>
				<th>Titre</th>
				<th>Type</th>

				<th>Statut</th>
            </tr>
        </thead>
        <tbody>
<?php
foreach ($rows as $row) {
	
    print '<tr>
                <td><a href="'.__CA_URL_ROOT__.'/index.php/editor/objects/ObjectEditor/Edit/object_id/'.$row["object_id"].'" target="_blank"> 📄 </a></td>
				<td>'.$row['idno'].'</td>
                <td>'.$row["objectLabel"].'</td>
				<td>'.$row["name_singular"].'</td>

		        <td>'.$statusCorrespondance[$row["status"]].'</td>

				</tr>';
}

?>
</tbody>
    </table>
<div style="height:120px"></div>	

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