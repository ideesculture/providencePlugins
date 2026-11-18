<?php
$result = $this->getVar("Result");
$total = $this->getVar("Total");

?>

<h1>Répartition des objets par statut de la relation et de l'emplacement</h1>

<table class="listtable">
    <thead>
        <th class="list-header-nosort">Emplacement</th>
        <th class="list-header-nosort">Nombre d'actuelle</th>
        <th class="list-header-nosort">Nombre de terminé</th>
        <th class="list-header-nosort">Total</th>
    </thead>
    <tbody>
        <?php foreach ($result as $emplacement_id => $emplacement) : ?>
            <tr>
                <td><?php $vt_emp = new ca_storage_locations($emplacement_id);
                    echo $vt_emp->getWithTemplate("^ca_storage_locations.preferred_labels") ?></td>
                <td><?php echo $emplacement["Actuelle"] ?></td>
                <td><?php echo $emplacement["Terminé"] ?></td>
                <td><?php echo $emplacement["Total"] ?></td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td>Total</td>
            <td colspan="5"><?php echo $total ?></td>
        </tr>
    </tbody>
</table>

<style>
    #leftNav {
        display: none;
    }

    #mainContent {
        width: 955px;
        border-left:2px solid #ddd;
        margin-left:4px;
    }
   
</style>