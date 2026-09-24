<?php
/**
 * progress_html.php — page de progression de l'import, renvoyée après chaque ligne traitée.
 *
 * 23/09/2026 GM (ticket 8047). L'état de l'import est tenu côté serveur : cette page ne transporte
 * plus que l'identifiant de l'import et son jeton. Elle se renvoie seule, mais :
 *  - elle dit où en est l'import et qu'il faut garder l'onglet ouvert ;
 *  - son bouton « Continuer » est visible (il était blanc sur blanc) ;
 *  - si elle ne s'est pas renvoyée au bout de quelques secondes, elle le signale ;
 *  - quitter la page déclenche un avertissement du navigateur.
 * Si l'import s'arrête malgré tout, l'accueil de l'import le signale et permet de le reprendre.
 */
    $etat = $this->getVar("etat");
    $jsonPath = $this->getVar("jsonPath");
    $prochaine_ligne = $this->getVar("prochaine_ligne");
    $h = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $nb_sel = sizeof((array)($etat['selection'] ?? []));
    $nb_faites = inrap_import_nb_faites((array)$etat);
    $nb_err = (int)($etat['erreurs_total'] ?? 0);
    $nb_avert = (int)($etat['avertissements_total'] ?? 0);
    $pct = $nb_sel ? min(100, round($nb_faites / $nb_sel * 100)) : 0;
?>
<style>
.progressbar-wrapper { background-color: #dfe6e9; color: white; width: 100%; }
.progressbar { background-color: #54b1c5; color: white; padding: 0.5rem; text-align: right; font-size: 14px; }
</style>
<div class="container">
     <h3>Import en cours — « <?= $h($etat['nom'] ?? '') ?> »</h3>
     <div style="margin:.5rem 0 1rem; padding:.75rem 1rem; border:1px solid #ffecb5; background:#fff3cd; color:#664d03;">
        <strong>&#9888; Ne fermez pas cet écran : si vous le fermez, l'import s'interrompt.</strong>
        Gardez cette fenêtre ouverte et au premier plan jusqu'à l'écran « Import terminé » ; un onglet laissé
        en arrière-plan ou un ordinateur mis en veille interrompt aussi l'import. En cas d'interruption, rien
        n'est perdu : reprenez l'import depuis l'accueil de l'import, là où il s'est arrêté.
     </div>
     <p><?= $nb_faites ?> ligne(s) traitée(s) sur <?= $nb_sel ?> cochée(s)<?php if ($prochaine_ligne) { ?> — prochaine : ligne <?= (int)$prochaine_ligne ?> du tableur<?php } ?>.</p>
     <?php if ($nb_err) { ?>
     <p style="color:#842029;"><?= $nb_err ?> ligne(s) mise(s) de côté pour le moment — le détail s'affichera à la fin.</p>
     <?php } ?>
     <?php if ($nb_avert) { ?>
     <p style="color:#664d03;"><?= $nb_avert ?> avertissement(s) — le détail s'affichera à la fin.</p>
     <?php } ?>
     <div class="progressbar-wrapper">
      <div title="downloading" class="progressbar" style="width:<?= (int)$pct ?>%"><?= (int)$pct ?>%</div>
     </div>
     <div id="importArrete" style="display:none; margin-top:1rem; padding:1rem; border:1px solid #f5c2c7; background:#f8d7da; color:#842029;">
        <strong>L'import ne progresse plus depuis une minute et demie.</strong> Cliquez sur « Continuer l'import » pour le relancer
        à partir de la ligne où il s'est arrêté : les lignes déjà faites ne seront pas refaites. Si l'on vous répond qu'il est encore
        en cours, patientez : la ligne en cours peut être longue ; l'accueil de l'import permet aussi de le reprendre.
     </div>
</div>
<form method="post" id="continue" action="/index.php/importInrap/Import/Import">
    <input type="hidden" name="json" value="<?= $h($jsonPath) ?>">
    <input type="hidden" name="jeton" value="<?= $h($etat['jeton'] ?? '') ?>">
    <input type="hidden" name="start" value="<?= (int)($etat['prochaine'] ?? 0) ?>">
    <input type="hidden" name="type" value="<?= $h($etat['type'] ?? '') ?>">
    <button type="submit" class="btn btn-secondary" style="margin-top: 20px;">Continuer l'import</button>
</form>

<script>
	var inrapEnvoi = false;
	function submitForm() {
		inrapEnvoi = true;
		document.getElementById('continue').submit();
	}
	document.getElementById('continue').addEventListener('submit', function() { inrapEnvoi = true; });
	// Quitter la page arrête l'import : le navigateur demande confirmation.
	window.addEventListener('beforeunload', function(e) {
		if (inrapEnvoi) { return; }
		e.preventDefault();
		e.returnValue = "L'import est en cours. Si vous quittez cette page, il s'arrêtera ; vous pourrez le reprendre depuis l'accueil de l'import.";
		return e.returnValue;
	});
	// Soumettre le formulaire après 300 ms
	setTimeout(submitForm, 300);
	// Pendant le traitement d'une ligne (quelques secondes), cette page reste affichée : ce n'est
	// pas un arrêt. Si elle est toujours là après 90 s — au-delà, le relais Cloudflare aurait de
	// toute façon abandonné la requête —, c'est que l'enchaînement s'est rompu.
	setTimeout(function() { document.getElementById('importArrete').style.display = 'block'; }, 90000);
</script>
