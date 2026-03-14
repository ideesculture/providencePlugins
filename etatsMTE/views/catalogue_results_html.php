<?php
$va_fiches          = $this->getVar('fiches');
$va_fiches_grouped  = $this->getVar('fiches_grouped');
$vs_titre           = $this->getVar('titre');
$vb_group_by_site   = $this->getVar('group_by_site');
$vb_group_by_deposant = $this->getVar('group_by_deposant');
$vn_nb_objets       = $this->getVar('nb_objets');

// Récupérer l'URL actuelle pour le bouton PDF
$vs_current_url = $_SERVER['REQUEST_URI'];
$vs_pdf_url = $vs_current_url . (strpos($vs_current_url, '?') !== false ? '&' : '?') . 'output=pdf';
// Si output=pdf n'est pas déjà dans l'URL
if (strpos($vs_current_url, 'output=pdf') === false) {
	$vs_pdf_separator = (strpos($vs_current_url, '?') !== false) ? '&' : '/output/pdf';
	$vs_pdf_url = $vs_current_url . $vs_pdf_separator;
}
?>
<div style="position:absolute;margin-left:-234px;background-color:white;border:1px solid #DDDDDD;padding:20px 30px 60px 30px;margin-top:-10px;min-height:100%;width:calc(100% + 234px);">

<div style="display:flex;justify-content:space-between;align-items:center;">
	<h1><?= htmlspecialchars($vs_titre) ?></h1>
	<div>
		<span style="color:#666;margin-right:15px;"><?= $vn_nb_objets ?> objet(s)</span>
		<a href="<?= __CA_URL_ROOT__ ?>/index.php/etatsMTE/Catalogue/Index" class="btn-catalogue" style="text-decoration:none;">Retour</a>
	</div>
</div>

<?php
if ($vb_group_by_site || $vb_group_by_deposant) {
	// Affichage regroupé
	if (is_array($va_fiches_grouped)) {
		foreach ($va_fiches_grouped as $group_label => $group_fiches) {
			echo "<h2 style='margin-top:30px;color:#2c3e50;border-bottom:2px solid #1ab3c8;padding-bottom:5px;'>" . htmlspecialchars($group_label) . " (" . count($group_fiches) . " objets)</h2>";
			foreach ($group_fiches as $fiche) {
				_renderFicheHTML($fiche);
			}
		}
	}
} else {
	// Affichage simple
	foreach ($va_fiches as $fiche) {
		_renderFicheHTML($fiche);
	}
}

function _renderFicheHTML($f) {
?>
<div class="fiche-objet" style="page-break-inside:avoid;border:1px solid #ccc;margin:15px 0;padding:15px;background:#fff;">

	<div class="section-header-cat" style="background:#2c3e50;color:white;padding:8px 12px;font-weight:bold;font-size:14px;margin:-15px -15px 15px -15px;">
		FICHE ŒUVRE N° <?= htmlspecialchars($f['idno']) ?>
	</div>

	<table style="width:100%;border-collapse:collapse;">
		<tr>
			<td style="width:135px;vertical-align:top;padding-right:10px;">
				<?php if ($f['photo_tag']): ?>
					<?= $f['photo_tag'] ?>
				<?php else: ?>
					<div style="width:125px;height:100px;border:1px dashed #ccc;display:flex;align-items:center;justify-content:center;color:#999;font-size:12px;">PHOTO</div>
				<?php endif; ?>
			</td>
			<td style="vertical-align:top;">
				<table class="fields-cat">
					<tr><td class="fl">Déposant</td><td class="fv"><?= htmlspecialchars($f['deposant']) ?></td></tr>
					<tr><td class="fl">N° de dépôt</td><td class="fv"><?= htmlspecialchars($f['numero_depot']) ?></td></tr>
					<tr><td class="fl">Date de dépôt</td><td class="fv"><?= htmlspecialchars($f['date_depot']) ?></td></tr>
				</table>
			</td>
		</tr>
	</table>

	<div class="section-sub-cat">Désignation du bien</div>
	<table class="fields-cat">
		<tr>
			<td class="fl">Catégorie</td><td class="fv"><?= htmlspecialchars($f['categorie']) ?></td>
			<td class="fl">Type</td><td class="fv"><?= htmlspecialchars($f['type']) ?></td>
		</tr>
		<tr>
			<td class="fl">Titre</td><td class="fv" colspan="3"><?= htmlspecialchars($f['titre']) ?></td>
		</tr>
		<tr>
			<td class="fl">Auteur</td><td class="fv"><?= htmlspecialchars($f['auteur']) ?></td>
			<td class="fl">Style</td><td class="fv"><?= htmlspecialchars($f['style']) ?></td>
		</tr>
		<tr>
			<td class="fl">Dimensions</td><td class="fv"><?= htmlspecialchars($f['dimensions']) ?></td>
			<td class="fl">Quantité</td><td class="fv"><?= htmlspecialchars($f['quantite']) ?></td>
		</tr>
		<tr>
			<td class="fl">N° inv. déposant</td><td class="fv"><?= htmlspecialchars($f['idno']) ?></td>
			<td class="fl">Valeur assurance</td><td class="fv"><?= htmlspecialchars($f['valeur_assurance']) ?></td>
		</tr>
	</table>

	<div class="section-sub-cat">Dernière localisation du bien</div>
	<table class="fields-cat">
		<tr>
			<td class="fl">Site</td><td class="fv"><?= htmlspecialchars($f['site']) ?></td>
			<td class="fl">Adresse</td><td class="fv"><?= htmlspecialchars($f['adresse']) ?></td>
			<td class="fl">Bâtiment</td><td class="fv"><?= htmlspecialchars($f['batiment']) ?></td>
		</tr>
		<tr>
			<td class="fl">Étage</td><td class="fv"><?= htmlspecialchars($f['etage']) ?></td>
			<td class="fl">Pièce</td><td class="fv" colspan="3"><?= htmlspecialchars($f['piece']) ?></td>
		</tr>
		<tr>
			<td class="fl">Situation</td><td class="fv" colspan="5"><?= htmlspecialchars($f['situation']) ?></td>
		</tr>
	</table>

	<div class="section-sub-cat">Situation</div>
	<table class="fields-cat">
		<tr><td class="fl">Date d'inventaire</td><td class="fv"><?= htmlspecialchars($f['inv_date']) ?></td></tr>
		<tr><td class="fl">Constat présence objet</td><td class="fv"><?= htmlspecialchars($f['inv_constat']) ?></td></tr>
		<tr><td class="fl">Constat / Observations</td><td class="fv"><?= htmlspecialchars($f['inv_observations']) ?></td></tr>
		<tr><td class="fl">Récolement</td><td class="fv"><?= htmlspecialchars($f['recol_fait']) ?></td></tr>
		<tr><td class="fl">Date récolement</td><td class="fv"><?= htmlspecialchars($f['recol_date']) ?></td></tr>
	</table>
</div>
<?php
}
?>

</div>

<style>
.section-sub-cat {
	background: #ecf0f1;
	padding: 5px 10px;
	font-weight: bold;
	font-size: 12px;
	color: #2c3e50;
	margin: 10px 0 5px 0;
	text-transform: uppercase;
}
.fields-cat {
	width: 100%;
	border-collapse: collapse;
	font-size: 12px;
}
.fields-cat td {
	padding: 3px 6px;
	border-bottom: 1px solid #eee;
}
.fields-cat .fl {
	font-weight: bold;
	color: #555;
	white-space: nowrap;
	width: 120px;
}
.fields-cat .fv {
	color: #333;
}
.btn-catalogue {
	background-color: #1ab3c8;
	color: white;
	padding: 8px 16px;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: 13px;
	font-weight: bold;
}
</style>
