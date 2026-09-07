<?php
    $sheets = $this->getVar("sheets");
    $type = $this->getVar("type");
    $name = $this->getVar("name");
    $file = $this->getVar("file");
?>

<h1> Importation du fichier : <?= $name ?></h1>

<form action="/index.php/importInrap/Import/SelectBeforeImport" method="POST">
    <input type="hidden" name="type" value="<?= $type?>" />
    <input type="hidden" name="file" value="<?= $file ?>" />
    <input type="hidden" name="name" value="<?= $name ?>" />
    <label> Sélectionner la feuille à importer sur l'excel </label>
    <select name="sheet">
        <?php
            foreach ($sheets as $key=>$sheet){
                var_dump($key);
                print "<option value='".$key."'>".$sheet."</option>";
            }
            ?>

    </select>
    <button type="submit">Valider</button>
</form>