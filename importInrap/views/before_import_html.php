<?php
// 14/09/2026 GM : une cellule dont la formule n'a pas pu etre calculee est reprise en texte
// brut plutot que de faire echouer tout l'import. C'est un fait a signaler, pas a masquer.
// 23/09/2026 GM (ticket 8047) : ce signalement ne s'affichait JAMAIS — $formules n'existe pas
// dans une vue CollectiveAccess, qui n'extrait pas ses variables. On le lit par getVar().
$formules = $this->getVar("formules");
if (!empty($formules) && is_array($formules)) { ?>
<div class="alert alert-warning">
    <strong><?= sizeof($formules) ?> cellule(s)</strong> commencent par « = » et ont été prises pour des formules par Excel.
    Leur texte a été repris tel quel. Vérifiez ces valeurs :
    <ul class="mb-0">
        <?php foreach (array_slice($formules, 0, 10) as $va_f) { ?>
        <li><code><?= htmlspecialchars($va_f['cellule'], ENT_QUOTES, 'UTF-8') ?></code> : <?= htmlspecialchars(mb_substr($va_f['valeur'], 0, 120), ENT_QUOTES, 'UTF-8') ?></li>
        <?php } ?>
    </ul>
    <?php if (sizeof($formules) > 10) { ?><span class="small">… et <?= sizeof($formules) - 10 ?> autre(s).</span><?php } ?>
</div>
<?php } ?>
<?php
    $headers = $this->getVar("header");
    $type = $this->getVar("type");
    $sheet = $this->getVar("sheet");

    $name = $this->getVar("name");
    $mapping = $this->getVar("mapping");
    $file = $this->getVar("file");
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">

<h1>Importation du fichier : <?= htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8') ?></h1>

<form action="/index.php/importInrap/Import/SelectLineBeforeImport" method="POST">
    <input type="hidden" name="length" value="<?= sizeOf($headers)?>"/>
    <?php /* 23/09/2026 GM (ticket 8047) : indices exacts des colonnes, voir SelectLineBeforeImport(). */ ?>
    <input type="hidden" name="colonnes" value="<?= htmlspecialchars(join(',', array_map('intval', array_keys((array)$headers))), ENT_QUOTES, 'UTF-8') ?>"/>
    <input type="hidden" name="file" value="<?= htmlspecialchars((string)$file, ENT_QUOTES, 'UTF-8') ?>"/>
    <input type="hidden" name="type" value="<?= htmlspecialchars((string)$type, ENT_QUOTES, 'UTF-8') ?>" />
    <input type="hidden" name="name" value="<?= htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8') ?>" />

    <input type="hidden" name="sheet" value="<?= (int)$sheet ?>"/>

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
                // 23/09/2026 GM (ticket 8047) : en-têtes du tableur échappés (ils étaient injectés tels quels).
                print "<td class='header'>".htmlspecialchars((string)$header, ENT_QUOTES, 'UTF-8')."</td>";
                print "<input type='hidden' name='column".(int)$key."' value=\"".htmlspecialchars((string)$header, ENT_QUOTES, 'UTF-8')."\" />";
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