<?php
/* ----------------------------------------------------------------------
 * app/plugins/etatsMTE/lib/fiche_catalogue.php
 * ----------------------------------------------------------------------
 * Rendu partagé d'une FICHE DE CATALOGUE MTE.
 *
 * , point « Uniformiser export PDF : fiche catalogue
 * MTE ». Deux chaînes produisaient auparavant deux mises en page différentes :
 * generate_pdf.php pour les catalogues, et le gabarit d'impression
 * app/printTemplates/results/checklist.php pour l'export des résultats de
 * recherche. La collecte des données, la feuille de style et le rendu d'une
 * fiche sont désormais ici, et les deux chaînes les appellent.
 *
 * Ce fichier ne contient que des fonctions : il peut être inclus aussi bien
 * depuis le script en ligne de commande que depuis une requête web.
 * ----------------------------------------------------------------------
 */

require_once(__CA_MODELS_DIR__ . '/ca_objects.php');
require_once(__CA_MODELS_DIR__ . '/ca_entities.php');
require_once(__CA_MODELS_DIR__ . '/ca_lists.php');

/**
 * Feuille de style de la fiche, sans les balises <style>.
 * L'appelant l'insère lui-même, ce qui lui laisse le choix d'y ajouter ses
 * propres règles de pagination.
 */
function caFicheCatalogueStyles() {
	return <<<'CSS'
	body { font-family: Marianne, 'Marianne-Light', DejaVu Sans, sans-serif; font-size: 20px; color: #333; }
	.page { page-break-after: always; }
	.page:last-child { page-break-after: auto; }
	.main-title {
		background: #2c3e50;
		color: white;
		padding: 10px 14px;
		font-weight: bold;
		font-size: 26px;
		margin-bottom: 10px;
		text-align: center;
	}
	.section-header {
		background: #ecf0f1;
		padding: 6px 10px;
		font-weight: bold;
		font-size: 20px;
		color: #2c3e50;
		text-transform: uppercase;
		margin: 10px 0 6px 0;
		border-left: 4px solid #1ab3c8;
	}
	.situation-sub-header {
		font-weight: bold;
		font-size: 18px;
		color: #555;
		margin: 6px 0 4px 10px;
		font-style: italic;
	}
	.fields {
		width: 100%;
		border-collapse: collapse;
		font-size: 18px;
	}
	.fields td {
		padding: 4px 6px;
		border-bottom: 1px solid #eee;
		vertical-align: top;
	}
	.field-label {
		font-weight: bold;
		color: #555;
		white-space: nowrap;
		width: 140px;
	}
	.field-value { color: #333; }
	.bloc-photo-empty {
		width: 160px;
		height: 120px;
		border: 1px dashed #ccc;
		text-align: center;
		line-height: 120px;
		color: #999;
		font-size: 22px;
	}
	.constats-box {
		border: 1px solid #ccc;
		padding: 6px 10px;
		margin: 6px 0;
		min-height: 40px;
		font-size: 18px;
	}
	.constats-label {
		font-weight: bold;
		font-size: 18px;
		color: #555;
		margin-bottom: 4px;
	}
	.group-title-page {
		page-break-after: always;
		display: flex;
		align-items: center;
		justify-content: center;
		height: 100%;
		min-height: 800px;
		text-align: center;
	}
	.group-title-inner {
		padding: 40px 60px;
	}
	.group-title-inner h1 {
		font-size: 36px;
		color: #2c3e50;
		margin: 0 0 20px 0;
		text-transform: uppercase;
		border-bottom: 4px solid #1ab3c8;
		padding-bottom: 16px;
	}
	.group-title-inner .group-count {
		font-size: 22px;
		color: #666;
		margin-top: 10px;
	}
	.group-title-inner .group-catalogue-type {
		font-size: 18px;
		color: #999;
		margin-top: 30px;
	}
CSS;
}

function buildFicheObjet($pn_object_id) {
	$obj = new ca_objects($pn_object_id);

	// la longueur (élément 666) et le diamètre
	// (élément 157 « circumference », libellé « Diamètre » en français) étaient bien migrés
	// mais n'étaient jamais affichés. Assemblage en PHP pour éviter tout séparateur orphelin
	// quand une dimension manque (cas fréquent : diamètre + hauteur sans largeur ni profondeur).
	$va_dim_parts = [];
	foreach ([
		'dimensions_height' => 'h',
		'dimensions_width'  => 'l',
		'dimensions_depth'  => 'p',
		'longueur'          => 'long.',
		'circumference'     => 'diam.',
	] as $vs_dim_code => $vs_dim_abbr) {
		$vs_dim_val = trim((string)$obj->getWithTemplate('^ca_objects.dimensions.'.$vs_dim_code));
		if ($vs_dim_val !== '') { $va_dim_parts[] = $vs_dim_val.' ('.$vs_dim_abbr.')'; }
	}
	$vs_type_dim = trim((string)$obj->getWithTemplate('^ca_objects.dimensions.type_dimensions'));
	$vs_dim = join(' x ', $va_dim_parts);
	if ($vs_type_dim !== '') { $vs_dim = trim($vs_dim.' '.$vs_type_dim); }

	$va_reps = $obj->getRepresentations(['medium', 'thumbnail']);
	$vs_photo_path = '';
	if (!empty($va_reps)) {
		$rep = reset($va_reps);
		if (isset($rep['paths']['medium'])) {
			$vs_photo_path = $rep['paths']['medium'];
		}
	}

	return [
		'object_id'      => $pn_object_id,
		'idno'           => $obj->get('ca_objects.idno'),
		'photo_path'     => $vs_photo_path,
		'deposant'       => $obj->getWithTemplate('<unit relativeTo="ca_entities" restrictToRelationshipTypes="depositaire">^ca_entities.preferred_labels.displayname</unit>'),
		// recette 16/09/2026 : le code lisait 'numero_depot', champ inexistant (le vrai est 'num_depot')
		'numero_depot'   => $obj->getWithTemplate('^ca_objects.num_depot'),
		'numinv_deposant' => $obj->getWithTemplate('^ca_objects.numinv_deposant'),
		'date_depot'     => $obj->getWithTemplate('^ca_objects.date_depot', ['dateFormat' => 'delimited']),
		'categorie'      => $obj->getWithTemplate('^ca_objects.domaine_logement'),
		'type'           => $obj->getWithTemplate('^ca_objects.denomination'),
		'titre'          => $obj->get('ca_objects.preferred_labels.name'),
		'auteur'         => $obj->getWithTemplate('<unit relativeTo="ca_entities" restrictToRelationshipTypes="creation_auteur">^ca_entities.preferred_labels.displayname</unit>'),
		'style'          => $obj->getWithTemplate('^ca_objects.style'),
		'dimensions'     => $vs_dim,
		'quantite'       => $obj->getWithTemplate('^ca_objects.appartenances_lot.lot_quantite'),
		'valeur_assurance' => '',
		// demande MOA : « dans le catalogue, la case "Dernière
		// localisation du bien" est erronée ». Ce bloc affichait la localisation de DÉPÔT
		// (ca_objects.site.*). Il porte désormais la DERNIÈRE localisation d'INVENTAIRE, avec
		// repli sur le dépôt lorsque le bien n'a jamais fait l'objet d'un relevé — même règle
		// que les filtres. Le dépôt reste consultable dans la fiche de l'œuvre.
		'site'           => caCatDerniereLoc($obj, 777, 'site_nom1'),
		'adresse'        => caCatDerniereLoc($obj, 795, 'site_adresse1'),
		'batiment'       => caCatDerniereLoc($obj, 796, 'site_batiment1'),
		'etage'          => caCatDerniereLoc($obj, 778, 'site_etage'),
		'piece'          => caCatDerniereLoc($obj, 779, 'site_piece'),
		'situation'      => caCatLocalisationsInventaire($obj),
		// C4 (recette 22/04) : ne garder que la DERNIERE situation d'inventaire (derniere occurrence du conteneur)
		'inv_date'       => caCatLastValue($obj->get('ca_objects.inventaire_cont.inv_date', ['returnAsArray' => true, 'dateFormat' => 'delimited'])),
		'inv_constat'    => caCatLastValue($obj->get('ca_objects.inventaire_cont.inv_constat', ['returnAsArray' => true, 'convertCodesToDisplayText' => true])),
		'inv_observations' => $obj->getWithTemplate('^ca_objects.inventaire_cont.inv_comm_disparition'),
		'recol_date'     => $obj->getWithTemplate('^ca_objects.recolement_inv.der_date_reco', ['dateFormat' => 'delimited']),
		'recol_fait'     => $obj->getWithTemplate('^ca_objects.recolement_inv.real_O_N'),
	];
}


function caCatLastValue($va) {
	if (!is_array($va) || !count($va)) return '';
	$v = end($va);
	return is_string($v) ? $v : '';
}

// localisations d'INVENTAIRE, une par relevé.
// `getWithTemplate` ne convient PAS ici : sur un conteneur répétable il concatène les valeurs
// PAR SOUS-ÉLÉMENT (tous les sites, puis tous les étages, puis toutes les pièces), ce qui donne
// « Segur;Segur;Saint-Germain > 3e étage;Rez-de-chaussée;2e étage » — illisible, et les listes
// n'ont même pas la même longueur. On apparie donc les occurrences par index, comme le fait déjà
// etatsMTEPlugin.php pour calculer la dernière localisation.
// CORRIGÉ le 21/09/2026 après contrôle indépendant — DEUX défauts de la version précédente :
//
// 1. APPARIEMENT PAR INDEX : FAUX. La version initiale lisait les trois sous-éléments par
//    `get(..., returnAsArray)` puis alignait les listes par index. Or `get()` ne se comporte PAS
//    uniformément : pour un sous-élément TEXTE (inv_piece) il complète les relevés vides par "",
//    pour un sous-élément LISTE (inv_site, inv_etage) il SAUTE l'occurrence quand item_id est NULL.
//    Dès qu'un relevé est incomplet les longueurs divergent et l'index glisse. Mesuré : 12 œuvres
//    touchées, dont 5 affichaient dans le PDF une ligne ORPHELINE (une pièce détachée de son site).
//    Cas témoin, objet 13115 : sites=2, étages=2, pièces=3 pour 3 relevés.
//    → On itère désormais sur les OCCURRENCES du conteneur, qui conservent leur rang, relevé vide
//      compris. Plus aucun glissement possible.
//
// 2. `array_unique` DÉDOUBLONNAIT GLOBALEMENT, pas seulement les répétitions consécutives : sur
//    l'objet 13639 le relevé le plus ancien remontait en tête et le plus récent passait en bas,
//    sous une date d'inventaire appartenant à un autre relevé — un lecteur en concluait une
//    localisation fausse. → Seuls les doublons CONSÉCUTIFS sont désormais fusionnés.
//
// ATTENTION : sur une valeur lue par occurrence, la conversion des codes de liste en libellés
// s'obtient par ['output' => 'text']. L'option 'convertCodesToDisplayText', qui fonctionne avec
// get(), est ICI SANS EFFET et renvoie le code brut ('3460' au lieu de 'La Defense').
/**
 * valeur d'un sous-élément du DERNIER relevé d'inventaire.
 * « Dernier » = dernière occurrence du conteneur 736 portant une valeur pour ce sous-élément,
 * l'ordre des occurrences étant garanti par attribute_id croissant (vérifié le 21/09 par
 * contrôle indépendant sur 2 374 occurrences, 0 désaccord).
 * À défaut de tout relevé, repli sur la localisation de DÉPÔT ($ps_fallback), conformément à
 * la règle retenue en spécification fonctionnelle.
 */
function caCatDerniereLoc($po_obj, $pn_element_id, $ps_fallback) {
	// CORRIGÉ le jour même après contrôle indépendant.
	// Première version FAUSSE : elle gardait la dernière valeur non vide SOUS-ÉLÉMENT PAR
	// SOUS-ÉLÉMENT, ce qui mélangeait des relevés différents. Mesuré : 110 œuvres concernées ;
	// exemple 13639, dont la pièce du relevé de juillet s'affichait sous la date de septembre.
	// On retient désormais UNE SEULE occurrence — la dernière portant un site, celle-là même
	// sur laquelle portent les filtres — et on y lit tous les sous-éléments.
	static $va_cache = [];
	$vn_oid = (int)$po_obj->getPrimaryKey();
	if (!isset($va_cache[$vn_oid])) {
		$va_derniere = null;
		$va_attrs = $po_obj->getAttributesByElement(736);
		if (is_array($va_attrs)) {
			foreach ($va_attrs as $o_att) {                 // ordre = attribute_id croissant
				$va_vals = [];
				foreach ($o_att->getValues() as $o_val) {
					$va_vals[(int)$o_val->getElementID()] = trim((string)$o_val->getDisplayValue(['output' => 'text']));
				}
				// une occurrence ne compte que si elle porte un SITE (critère du filtre)
				if (!empty($va_vals[777])) { $va_derniere = $va_vals; }
			}
		}
		$va_cache[$vn_oid] = $va_derniere;
	}
	$va_d = $va_cache[$vn_oid];
	if (is_array($va_d) && isset($va_d[$pn_element_id]) && $va_d[$pn_element_id] !== '') {
		return $va_d[$pn_element_id];
	}
	// aucun relevé d'inventaire : repli sur la localisation de DÉPÔT (règle du client, ligne 11)
	if (is_array($va_d)) { return ''; }
	return trim((string)$po_obj->getWithTemplate('^ca_objects.site.'.$ps_fallback));
}


function caCatLocalisationsInventaire($po_obj) {
	$va_attrs = $po_obj->getAttributesByElement(736);   // inventaire_cont
	if (!is_array($va_attrs)) { return []; }

	$va_lignes = [];
	foreach ($va_attrs as $o_att) {
		$va_vals = [];
		foreach ($o_att->getValues() as $o_val) { $va_vals[(int)$o_val->getElementID()] = $o_val; }

		$va_part = [];
		// Le BÂTIMENT (796) est indispensable : c'est un critère de FILTRE au même titre que le
		// site. Sans lui, un catalogue « Bâtiment 5 » ne comportait pas une seule mention de
		// « Bâtiment 5 » dans la zone d'inventaire — le lecteur ne pouvait pas savoir pourquoi la
		// fiche s'y trouvait (relevé par le contrôle indépendant du 21/09, correction du même jour).
		// L'adresse d'inventaire (795) n'est PAS ajoutée : elle n'est pas un critère de filtre,
		// le filtre Adresse du catalogue spécifique portant sur la localisation de dépôt (799).
		foreach ([777, 796, 778, 779] as $vn_eid) {   // site > bâtiment > étage > pièce
			if (!isset($va_vals[$vn_eid])) { continue; }
			$vs = trim((string)$va_vals[$vn_eid]->getDisplayValue(['output' => 'text']));
			if ($vs !== '') { $va_part[] = $vs; }
		}
		if (!sizeof($va_part)) { continue; }          // relevé entièrement vide : aucune ligne

		$vs_ligne = join(' > ', $va_part);
		if (!sizeof($va_lignes) || end($va_lignes) !== $vs_ligne) { $va_lignes[] = $vs_ligne; }
	}
	return $va_lignes;
}

function renderFichePDF($f) {
?>
<div class="page">
	<div class="main-title">
		FICHE ŒUVRE N° <?= htmlspecialchars(!empty($f['numinv_deposant']) ? $f['numinv_deposant'] : $f['idno']) ?>
	</div>
	<div class="section-header">Identification du bien</div>
	<table style="width:100%; border-collapse:collapse; margin-bottom:4px;">
		<tr>
			<?php if (!empty($f['photo_path']) && file_exists($f['photo_path'])): ?>
			<td style="width:215px; vertical-align:top; padding-right:8px;">
				<img src="<?= $f['photo_path'] ?>" width="200" />
			</td>
			<?php endif; ?>
			<td style="vertical-align:top;">
				<table class="fields" style="width:100%;">
					<tr><td class="field-label">Déposant</td><td class="field-value"><?= htmlspecialchars($f['deposant']) ?></td></tr>
					<tr><td class="field-label">N° inv. déposant</td><td class="field-value"><?= htmlspecialchars($f['numinv_deposant']) ?></td></tr>
					<tr><td class="field-label">N° de dépôt</td><td class="field-value"><?= htmlspecialchars($f['numero_depot']) ?></td></tr>
					<tr><td class="field-label">Date de dépôt</td><td class="field-value"><?= htmlspecialchars($f['date_depot']) ?></td></tr>
				</table>
			</td>
		</tr>
	</table>
	<div class="section-header">Désignation du bien</div>
	<table class="fields">
		<col style="width:140px;"><col style="width:calc(62% - 140px);"><col style="width:100px;"><col style="width:calc(38% - 100px);">
		<tr>
			<td class="field-label">Catégorie</td>
			<td class="field-value"><?= htmlspecialchars($f['categorie']) ?></td>
			<td class="field-label">Type</td>
			<td class="field-value"><?= htmlspecialchars($f['type']) ?></td>
		</tr>
		<tr><td class="field-label">Titre</td><td class="field-value" colspan="3"><?= htmlspecialchars($f['titre']) ?></td></tr>
		<tr>
			<td class="field-label">Auteur</td>
			<td class="field-value"><?= htmlspecialchars($f['auteur']) ?></td>
			<td class="field-label">Style</td>
			<td class="field-value"><?= htmlspecialchars($f['style']) ?></td>
		</tr>
		<tr>
			<td class="field-label">Dimensions</td>
			<td class="field-value" style="white-space:nowrap;"><?= htmlspecialchars($f['dimensions']) ?></td>
			<td class="field-label">Quantité</td>
			<td class="field-value"><?= htmlspecialchars($f['quantite']) ?></td>
		</tr>
		<tr>
			<td class="field-label">Valeur assurance (€)</td>
			<td class="field-value" colspan="3"><?= htmlspecialchars($f['valeur_assurance']) ?></td>
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
	<table class="fields">
		<tr><td class="field-label">Date d'inventaire</td><td class="field-value" colspan="3"><?= htmlspecialchars($f['inv_date']) ?></td></tr>
		<tr><td class="field-label">Constat présence</td><td class="field-value" colspan="3"><?= htmlspecialchars($f['inv_constat']) ?></td></tr>
<?php /* correctif de LISIBILITÉ, règle retenue.
         Depuis la bascule des filtres sur la localisation d'INVENTAIRE, un catalogue
         « Saint-Germain / Bâtiment 5 » contenait 46 % de fiches affichant un AUTRE site :
         le bloc « Dernière localisation du bien » ci-dessus montre le DÉPÔT, alors que la
         sélection porte sur l'inventaire. Les comptes étaient justes, la lecture trompeuse.
         Le champ $f['situation'] existait déjà (ligne ~610, construit sur inv_site > inv_etage
         > inv_piece) mais n'était RENDU NULLE PART. On se contente donc de l'afficher :
         rien n'est retiré, le gabarit validé aux lignes 12 et 14 reste intact. */ ?>
<?php /* Ligne omise si le bien n'a aucun relevé d'inventaire : 201 fiches affichaient sinon un
         libellé suivi du vide (relevé par le contrôle indépendant du 21/09). */ ?>
<?php if (!empty($f['situation'])) { ?>
		<tr><td class="field-label">Localisation à l'inventaire</td><td class="field-value" colspan="3"><?= is_array($f['situation']) ? join('<br />', array_map('htmlspecialchars', $f['situation'])) : htmlspecialchars((string)$f['situation']) ?></td></tr>
<?php } ?>
	</table>
	<div class="constats-box">
		<div class="constats-label">Constat / Observations – Description de l'état</div>
		<?= htmlspecialchars($f['inv_observations']) ?>
	</div>
	<table class="fields">
		<tr><td class="field-label">Récolement</td><td class="field-value"><?= htmlspecialchars($f['recol_fait']) ?></td></tr>
		<tr><td class="field-label">Date récolement</td><td class="field-value"><?= htmlspecialchars($f['recol_date']) ?></td></tr>
	</table>
</div>
<?php
}

