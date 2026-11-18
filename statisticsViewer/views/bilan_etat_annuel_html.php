<?php
$result = $this->getVar("Result");
$total = $this->getVar("Total");

?>

<h1>Bilan d'état annuel</h1>

<table class="listtable">
    <thead>
        <th class="list-header-nosort">Emplacement</th>
        <th class="list-header-nosort">Nombre de non vu</th>
        <th class="list-header-nosort">Nombre de vu</th>
        <th class="list-header-nosort">Nombre de retour temporaire</th>
        <th class="list-header-nosort">Nombre de non récolé</th>
        <th class="list-header-nosort">Total</th>
    </thead>
    <tbody>
        <?php foreach ($result as $emplacement_id => $emplacement) : ?>
            <tr>
                <td><?php $vt_emp = new ca_storage_locations($emplacement_id);
                    echo $vt_emp->getWithTemplate("^ca_storage_locations.preferred_labels") ?></td>
                <td><?php echo $emplacement["Vu"] ?></td>
                <td><?php echo $emplacement["Non vu"] ?></td>
                <td><?php echo $emplacement["Retour Temporaire"] ?></td>
                <td><?= $emplacement["Non récolé"] ?></td>
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