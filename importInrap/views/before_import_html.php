<?php
    $headers = $this->getVar("header");
    $type = $this->getVar("type");
    $sheet = $this->getVar("sheet");

    $name = $this->getVar("name");
    $mapping = $this->getVar("mapping");
    $file = $this->getVar("file");
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">

<h1>Importation du fichier : <?= $name ?></h1>

<form action="/index.php/importInrap/Import/SelectLineBeforeImport" method="POST">
    <input type="hidden" name="length" value="<?= sizeOf($headers)?>"/>
    <input type="hidden" name="file" value="<?= $file ?>"/>
    <input type="hidden" name="type" value="<?= $type ?>" />
    <input type="hidden" name="name" value="<?= $name ?>" />

    <input type="hidden" name="sheet" value="<?= $sheet ?>"/>

    <table class="table table-hover table-bordered">
        <thead>
            <tr>
                <th>Entête dans l'excel</th>
                <th>Champs correspondant dans Comodo</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($headers as $key=>$header){
                print "<tr>";
                print "<td class='header'>".$header."</td>";
                print "<input type='hidden' name='column".$key."' value=\"".$header."\" />";
                print "<td> <select class='form-control' name='data".$key."'><option value='' selected> Ne rien sélectionner</option>";
                foreach ($mapping[$type] as $key=>$value){
                    print "<option value='".$key."'>".$value["title"]."</option>";
                }            
                print "</select></td>";
                print "</tr>";
            }?>
        </tbody>
    </table>

    <button type="submit" class="btn btn-secondary">Valider</button>
</form>



<script>
    $(document).ready(function(){
        $("select").each(function(){
            let options = $(this).find("option");
            var arr = options.map(function(_, o) { return { t: $(o).text(), v: o.value }; }).get();
            arr.sort(function(o1, o2) { return o1.t > o2.t ? 1 : o1.t < o2.t ? -1 : 0; });
            options.each(function(i, o) {
                o.value = arr[i].v;
                $(o).text(arr[i].t);
            });
        });

        $("td.header").each(function(i) {
            let headerVal =  $(this).text();
            $("select").eq(i).find("option").each(function(){
                if ($(this).text().toLowerCase().includes(headerVal.toLowerCase())){
                    $(this).prop("selected", true);
                    return false;
                }
            })
        });
    });

    

</script>