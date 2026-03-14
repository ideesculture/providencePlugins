<?php
$va_fiches          = $this->getVar('fiches');
$va_fiches_grouped  = $this->getVar('fiches_grouped');
$vs_titre           = $this->getVar('titre');
$vb_group_by_site   = $this->getVar('group_by_site');
$vb_group_by_deposant = $this->getVar('group_by_deposant');
?>
<html>
<head>
<style>
	body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #333; }
	.page { page-break-after: always; }
	.page:last-child { page-break-after: auto; }

	.main-title {
		background: #2c3e50;
		color: white;
		padding: 8px 12px;
		font-weight: bold;
		font-size: 13px;
		margin-bottom: 8px;
	}
	.section-header {
		background: #ecf0f1;
		padding: 4px 8px;
		font-weight: bold;
		font-size: 10px;
		color: #2c3e50;
		text-transform: uppercase;
		margin: 8px 0 4px 0;
		border-left: 3px solid #1ab3c8;
	}
	.situation-sub-header {
		font-weight: bold;
		font-size: 9px;
		color: #555;
		margin: 4px 0 2px 8px;
		font-style: italic;
	}
	.fields {
		width: 100%;
		border-collapse: collapse;
		font-size: 9px;
	}
	.fields td {
		padding: 2px 5px;
		border-bottom: 1px solid #eee;
		vertical-align: top;
	}
	.field-label {
		font-weight: bold;
		color: #555;
		white-space: nowrap;
		width: 100px;
	}
	.field-value {
		color: #333;
	}
	.bloc-photo-empty {
		width: 120px;
		height: 90px;
		border: 1px dashed #ccc;
		text-align: center;
		line-height: 90px;
		color: #999;
		font-size: 11px;
	}
	.constats-box {
		border: 1px solid #ccc;
		padding: 4px 8px;
		margin: 4px 0;
		min-height: 30px;
		font-size: 9px;
	}
	.constats-label {
		font-weight: bold;
		font-size: 9px;
		color: #555;
		margin-bottom: 2px;
	}
	.group-header {
		background: #1ab3c8;
		color: white;
		padding: 6px 12px;
		font-size: 12px;
		font-weight: bold;
		margin: 10px 0;
		page-break-after: avoid;
	}
</style>
</head>
<body>

<?php
if ($vb_group_by_site || $vb_group_by_deposant) {
	if (is_array($va_fiches_grouped)) {
		foreach ($va_fiches_grouped as $group_label => $group_fiches) {
			echo '<div class="group-header">' . htmlspecialchars($group_label) . ' (' . count($group_fiches) . ' objets)</div>';
			foreach ($group_fiches as $fiche) {
				_renderFichePDF($fiche);
			}
		}
	}
} else {
	foreach ($va_fiches as $fiche) {
		_renderFichePDF($fiche);
	}
}

function _renderFichePDF($f) {
?>
<div class="page">

	<div class="main-title">
		FICHE ŒUVRE N° <?= htmlspecialchars($f['idno']) ?>
	</div>

	<table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
		<tr>
			<td style="width:135px; vertical-align:top; padding-right:8px;">
				<?php
				if (!empty($f['photo_path']) && file_exists($f['photo_path'])) {
					echo '<img src="'.$f['photo_path'].'" width="125" />';
				} else {
					echo '<div class="bloc-photo-empty">PHOTO</div>';
				}
				?>
			</td>
			<td style="vertical-align:top;">
				<table class="fields" style="width:100%;">
					<tr>
						<td class="field-label">Déposant</td>
						<td class="field-value"><?= htmlspecialchars($f['deposant']) ?></td>
					</tr>
					<tr>
						<td class="field-label">N° de dépôt</td>
						<td class="field-value"><?= htmlspecialchars($f['numero_depot']) ?></td>
					</tr>
					<tr>
						<td class="field-label">Date de dépôt</td>
						<td class="field-value"><?= htmlspecialchars($f['date_depot']) ?></td>
					</tr>
				</table>
			</td>
		</tr>
	</table>

	<div class="section-header">Désignation du bien</div>
	<table class="fields">
		<tr>
			<td class="field-label">Catégorie</td>
			<td class="field-value" style="width:30%;"><?= htmlspecialchars($f['categorie']) ?></td>
			<td class="field-label" style="width:60px;">Type</td>
			<td class="field-value"><?= htmlspecialchars($f['type']) ?></td>
		</tr>
		<tr>
			<td class="field-label">Titre</td>
			<td class="field-value" colspan="3"><?= htmlspecialchars($f['titre']) ?></td>
		</tr>
		<tr>
			<td class="field-label">Auteur</td>
			<td class="field-value"><?= htmlspecialchars($f['auteur']) ?></td>
			<td class="field-label">Style</td>
			<td class="field-value"><?= htmlspecialchars($f['style']) ?></td>
		</tr>
		<tr>
			<td class="field-label">Dimensions</td>
			<td class="field-value"><?= htmlspecialchars($f['dimensions']) ?></td>
			<td class="field-label">Quantité</td>
			<td class="field-value"><?= htmlspecialchars($f['quantite']) ?></td>
		</tr>
		<tr>
			<td class="field-label">N° inv. déposant</td>
			<td class="field-value"><?= htmlspecialchars($f['idno']) ?></td>
			<td class="field-label">Valeur assurance</td>
			<td class="field-value"><?= htmlspecialchars($f['valeur_assurance']) ?></td>
		</tr>
	</table>

	<div class="section-header">Dernière localisation du bien</div>
	<table class="fields">
		<tr>
			<td class="field-label">Site</td>
			<td class="field-value" style="width:25%;"><?= htmlspecialchars($f['site']) ?></td>
			<td class="field-label" style="width:55px;">Adresse</td>
			<td class="field-value" style="width:25%;"><?= htmlspecialchars($f['adresse']) ?></td>
			<td class="field-label" style="width:60px;">Bâtiment</td>
			<td class="field-value"><?= htmlspecialchars($f['batiment']) ?></td>
		</tr>
		<tr>
			<td class="field-label">Étage</td>
			<td class="field-value"><?= htmlspecialchars($f['etage']) ?></td>
			<td class="field-label">Pièce</td>
			<td class="field-value" colspan="3"><?= htmlspecialchars($f['piece']) ?></td>
		</tr>
	</table>

	<div class="section-header">Situation</div>

	<div class="situation-sub-header">Dernière situation d'inventaire</div>
	<table class="fields">
		<tr>
			<td class="field-label">Date d'inventaire</td>
			<td class="field-value" colspan="3"><?= htmlspecialchars($f['inv_date']) ?></td>
		</tr>
		<tr>
			<td class="field-label">Constat présence</td>
			<td class="field-value" colspan="3"><?= htmlspecialchars($f['inv_constat']) ?></td>
		</tr>
	</table>

	<div class="constats-box">
		<div class="constats-label">Constat / Observations – Description de l'état</div>
		<?= htmlspecialchars($f['inv_observations']) ?>
	</div>

	<div class="situation-sub-header" style="margin-top:5px;">Dernière situation de récolement</div>
	<table class="fields">
		<tr>
			<td class="field-label">Récolement</td>
			<td class="field-value"><?= htmlspecialchars($f['recol_fait']) ?></td>
		</tr>
		<tr>
			<td class="field-label">Date récolement</td>
			<td class="field-value"><?= htmlspecialchars($f['recol_date']) ?></td>
		</tr>
	</table>

</div>
<?php
}
?>

</body>
</html>
