<?php
    $sheet = $this->getVar("sheets");
    $type = $this->getVar("type");
    $file = $this->getVar("file");
    $idnos = $this->getVar("idnos");
    $name = $this->getVar("name");
    $jsonPath = $this->getVar("data");


?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">

<h1>Import du fichier : <?= $name ?></h1>

<div class="form-check">
    <label for="allChecked" class="form-check-label">Tout sélectionner</label> 
    <input type='checkbox' onclick="checkedAll()" class='form-check-input' id='allChecked'>
</div>
<div class="form-check">
    <label for="allUnchecked" class="form-check-label">Tout désélectionner</label> 
    <input type='checkbox' onclick="uncheckedAll()" class='form-check-input' id='allUnchecked'>
</div>

<button class="btn btn-secondary" id="Submit" onclick="submitForm()"> Valider </button>

<form action="/index.php/importInrap/Import/Import" id='form' method="POST">
    <input type="hidden" name="file" value="<?= $file ?>"/>
    <input type="hidden" name="json" value="<?= $jsonPath ?>"/>
    <input type="hidden" name="type" value="<?= $type ?>" />
    <input type="hidden" name="name" value="<?= $name ?>" />

    <input type="hidden" name="sheet" value="<?= $sheet ?>"/>
    <table class="table table-hover table-bordered" id="tableForm">
        <thead>
            <tr>
                <th>Importer</th>
                <th>Numéro d'inventaire</th>
            </tr>
        </thead>
        <tbody>
            <?php
            foreach ($idnos as $key => $idno){
                if ($idno != ""){
                    print "<tr><td><input type='checkbox' class='form-check-input isPresent' name='".$key."' /> </td><td>".$idno."</td></tr>";
                }
            }        
            ?>
        </tbody>
    </table>
    <input type="hidden" value="" id="allRow" name="allRows" />
</form>


<script>

    function submitForm(){
        let isOneChecked = false;
        $("#tableForm").find(":checkbox").each(function(){
            if ($(this).is(":checked")){
                $("#allRow").val($("#allRow").val() + ";" +  $(this).attr("name"));
                isOneChecked = true;
            }
        })
        if (isOneChecked == false){
            alert("Vous devez choisir au moins une ligne à importer");
            return false;
        }
        $("#form").submit();
    }

    function checkedAll(){
        $(".isPresent").each(function () { 
            $(this).attr('checked', true);
         });
        $("#allUnchecked").attr("checked", false);

    }
    function uncheckedAll(){
        $(".isPresent").each(function () { 
            $(this).attr('checked', false);
        });
        $("#allChecked").attr("checked", false);
    }
</script>