<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">


<h1>Import INRAP</h1>

<form action="/index.php/importInrap/Import/SelectSheet" method="POST" enctype="multipart/form-data">
    <div class="mb-3">
        <label for="formFile" class="form-label">Sélectionner le type à importer</label>

        <select class="form-select" aria-label="Default select example" name="type">
            <option selected value="mobilier">Mobilier</option>
            <option value="musee">Musée</option>
            <option value="operation">Opération</option>
            <option value="documentation_numerique">Documentation numérique</option>
            <option value="documentation_ecrite">Documentation écrite</option>
            <option value="contenant_num">Contenant Numérique</option>
            <option value="contenant_doc">Contenant Document</option>
            <option value="contenant_mobilier">Contenant Mobilier</option>
            
        </select>

    </div>
    <div class="mb-3">
        <label for="formFile" class="form-label">Sélectionner le fichier excel à importer</label>
        <input class="form-control" type="file" id="formFile" name="file">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-secondary mb-3">Valider</button>
    </div>
</form>