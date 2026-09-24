<?php
    $sheets = $this->getVar("sheets");
    $type = $this->getVar("type");
    $name = $this->getVar("name");
    $file = $this->getVar("file");
?>

<h1> Importation du fichier : <?= htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8') ?></h1>

<form action="/index.php/importInrap/Import/SelectBeforeImport" method="POST">
    <input type="hidden" name="type" value="<?= htmlspecialchars((string)$type, ENT_QUOTES, 'UTF-8') ?>" />
    <input type="hidden" name="file" value="<?= htmlspecialchars((string)$file, ENT_QUOTES, 'UTF-8') ?>" />
    <input type="hidden" name="name" value="<?= htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8') ?>" />
    <label> Sélectionner la feuille à importer sur l'excel </label>
    <select name="sheet">
        <?php
            foreach ($sheets as $key=>$sheet){
                print "<option value='".(int)$key."'>".htmlspecialchars((string)$sheet, ENT_QUOTES, 'UTF-8')."</option>";
            }
            ?>

    </select>
    <button type="submit">Valider</button>
</form>