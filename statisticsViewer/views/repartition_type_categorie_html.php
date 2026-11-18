<?php
$result = $this->getVar("Result");
$total = $this->getVar("Total");

?>

<h1>Répartition des objets par statut de la relation et de l'emplacement</h1>

<table class="listtable">
    <thead>
        <th class="list-header-nosort">Catégorie</th>
        <th class="list-header-nosort">Nombre</th>
        <th class="list-header-nosort">Pourcentage</th>
        <th class="list-header-nosort">Typologie</th>
        <th class="list-header-nosort">Nombre</th>

        <th class="list-header-nosort">Pourcentage</th>
    </thead>
    <tbody>
        <?php foreach ($result as $NomCatégorie => $SousCatégorie) : 
            $i=0;?>
            <tr>
                <td rowspan="<?= sizeof($SousCatégorie) -1?>"><?= $NomCatégorie ?></td>
                <td rowspan="<?= sizeof($SousCatégorie) -1?>"><?= $SousCatégorie["Total"] ?></td>
                <td rowspan="<?= sizeof($SousCatégorie) -1?>"><?= round($SousCatégorie["Total"]*100/$total, 2) ?></td>
                <?php 
                foreach($SousCatégorie as $NomSousCatégorie=>$nombre):
                    if($NomSousCatégorie == "Total"){
                        continue;
                    }
                    if($i>0){
                        echo "<tr>";
                    }
                    ?>
                    <td><?= $NomSousCatégorie ?></td>
                    <td><?= $nombre ?></td>
                    <td><?= round($nombre*100/$result[$NomCatégorie]["Total"], 2) ?></td>
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