<?php
/**
 * erreur_html.php — écran d'erreur de l'import.
 *
 * 14/09/2026 GM. Les échecs de téléversement et de lecture se terminaient par un die() brut :
 * message en anglais, page blanche hors du gabarit de l'application, aucun moyen de revenir en
 * arrière. Pour la gestionnaire, c'était indiscernable d'une « erreur fatale » du logiciel —
 * c'est un des sens possibles de ce mot dans les tickets 7042, 7536 et 7947.
 */
// 23/09/2026 GM (ticket 8047) : CollectiveAccess n'extrait pas les variables de vue ; $message et
// $detail n'existaient donc jamais ici, et TOUTES les erreurs d'import s'affichaient sous le message
// par défaut « Le fichier n'a pas pu être lu. », détail technique compris — perdu.
$vs_message = (string)$this->getVar("message");
if ($vs_message === '') { $vs_message = "Le fichier n'a pas pu être lu."; }
$vs_detail  = (string)$this->getVar("detail");
$vs_titre   = (string)$this->getVar("titre");
if ($vs_titre === '') { $vs_titre = "L'import n'a pas pu démarrer"; }
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">

<h1>Import INRAP</h1>

<div class="alert alert-danger" role="alert">
    <h4 class="alert-heading"><?= htmlspecialchars($vs_titre, ENT_QUOTES, 'UTF-8') ?></h4>
    <p class="mb-0"><?= htmlspecialchars($vs_message, ENT_QUOTES, 'UTF-8') ?></p>
</div>

<?php if ($vs_detail !== '') { ?>
<details class="mb-3">
    <summary class="text-muted">Détail technique</summary>
    <pre class="small text-muted mt-2"><?= htmlspecialchars($vs_detail, ENT_QUOTES, 'UTF-8') ?></pre>
</details>
<?php } ?>

<a href="/index.php/importInrap/Import/Index" class="btn btn-secondary">Retour à l'accueil de l'import</a>
