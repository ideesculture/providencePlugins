<?php
$result = $this->getVar("Result");
$total = $this->getVar("Total");

?>

<h1>Répartition des objets par statut de la relation et de l'emplacement</h1>

<table class="listtable">
    <thead>
        <th class="list-header-nosort">Emplacement</th>
        <th class="list-header-nosort">Nombre</th>
        <th class="list-header-nosort">Typologie</th>
        <th class="list-header-nosort">Nombre</th>

    </thead>
    <tbody>
        <?php foreach ($result as $NomEmplacement => $Catégorie) : 
            $i=0;?>
            <tr>
                <td rowspan="<?= sizeof($Catégorie) -1?>"><?= $NomEmplacement ?></td>
                <td rowspan="<?= sizeof($Catégorie) -1?>"><?= $Catégorie["Total"] ?></td>
                <?php 
                foreach($Catégorie as $NomCatégorie=>$nombre):
                    if($NomCatégorie == "Total"){
                        continue;
                    }
                    if($i>0){
                        echo "<tr>";
                    }
                    ?>
                    <td><?= $NomCatégorie ?></td>
                    <td><?= $nombre ?></td>
                    </tr>
                    <?php
                    
                    $i++;
                endforeach; 

         endforeach; ?>
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

    .listtable{
        margin-bottom: 100px;
    }
   
</style>