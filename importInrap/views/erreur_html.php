<?php
/**
 * erreur_html.php — écran d'erreur de l'import.
 *
 * 14/09/2026 GM. Les échecs de téléversement et de lecture se terminaient par un die() brut :
 * message en anglais, page blanche hors du gabarit de l'application, aucun moyen de revenir en
 * arrière. Pour la gestionnaire, c'était indiscernable d'une « erreur fatale » du logiciel —
 * c'est un des sens possibles de ce mot dans les tickets 7042, 7536 et 7947.
 */
$vs_message = isset($message) ? $message : "Le fichier n'a pas pu être lu.";
$vs_detail  = isset($detail) ? $detail : '';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">

<h1>Import INRAP</h1>

<div class="alert alert-danger" role="alert">
    <h4 class="alert-heading">L'import n'a pas pu démarrer</h4>
    <p class="mb-0"><?= htmlspecialchars($vs_message, ENT_QUOTES, 'UTF-8') ?></p>
</div>

<?php if ($vs_detail !== '') { ?>
<details class="mb-3">
    <summary class="text-muted">Détail technique</summary>
    <pre class="small text-muted mt-2"><?= htmlspecialchars($vs_detail, ENT_QUOTES, 'UTF-8') ?></pre>
</details>
<?php } ?>

<a href="/index.php/importInrap/Import/Index" class="btn btn-secondary">Choisir un autre fichier</a>
