#!/usr/bin/env php
<?php
/* ----------------------------------------------------------------------
 * generate_pdf.php : Background PDF generation for etatsMTE plugin
 * ----------------------------------------------------------------------
 * Usage: php generate_pdf.php <job_file.json>
 *
 * The job file contains all parameters needed to generate the catalogue.
 * A .status file is created alongside to track progress.
 * The resulting PDF is written to the same directory.
 * ----------------------------------------------------------------------
 */

if (php_sapi_name() !== 'cli') {
	die("This script must be run from the command line.\n");
}

ini_set('memory_limit', '4G');
set_time_limit(0);

if (!isset($argv[1]) || !file_exists($argv[1])) {
	die("Usage: php generate_pdf.php <job_file.json>\n");
}

$job_file = $argv[1];
$job = json_decode(file_get_contents($job_file), true);
if (!$job) {
	die("Invalid job file.\n");
}

$job_id = $job['job_id'];
$output_dir = dirname($job_file);
$status_file = $output_dir . '/' . $job_id . '.status';
$pdf_file = $output_dir . '/' . $job_id . '.pdf';

// Write initial status
file_put_contents($status_file, json_encode(['status' => 'running', 'started' => date('c')]));

// Bootstrap CollectiveAccess
define("__CA_APP_TYPE__", "PROVIDENCE");
require_once($job['ca_base_dir'] . '/setup.php');
require_once(__CA_LIB_DIR__ . '/Configuration.php');
require_once(__CA_MODELS_DIR__ . '/ca_objects.php');
require_once(__CA_MODELS_DIR__ . '/ca_entities.php');
require_once(__CA_MODELS_DIR__ . '/ca_lists.php');

// la collecte, la feuille de style et le rendu
// d'une fiche sont partagés avec le gabarit d'export PDF des résultats.
require_once(__DIR__ . '/lib/fiche_catalogue.php');
require_once(__CA_MODELS_DIR__ . '/ca_object_representations.php');
require_once(__CA_MODELS_DIR__ . '/ca_locales.php');
require_once(__CA_LIB_DIR__ . '/Print/PDFRenderer.php');

// Required global for wkhtmltopdf plugin cleanup
global $file_cleanup_list;
if (!is_array($file_cleanup_list)) { $file_cleanup_list = []; }

// Clear cached renderer detection so wkhtmltopdf is found
CompositeCache::delete("mediahelper_wkhtmltopdf_installed", "mediaPluginInfo");

error_reporting(E_ERROR);

// Relationship type IDs
define('REL_DEPOSANT', 172);
define('REL_AUTEUR', 164);

try {
	// -------------------------------------------------------
	// 1. Build the SQL query from job parameters
	// -------------------------------------------------------
	$catalogue_type = $job['catalogue_type'];
	$deposant_id    = (int)($job['deposant'] ?? 0);
	$site_id        = (int)($job['site'] ?? 0);
	$batiment_id    = (int)($job['batiment'] ?? 0);
	$etage_id       = (int)($job['etage'] ?? 0);
	$adresse_id     = (int)($job['adresse'] ?? 0);   // Adresse (site_adresse1) — catalogue spécifique
	$type_id        = (int)($job['type_domaine'] ?? 0);   // Catégorie (domaine_logement)
	$denomination_id = (int)($job['denomination'] ?? 0);  // Type (denomination)
	$constat_id     = (int)($job['constat'] ?? 0);
	$date_debut     = $job['date_debut'] ?? '';
	$date_fin       = $job['date_fin'] ?? '';
	$f_recole       = (int)($job['recole'] ?? 0);
	$f_inventorie   = (int)($job['inventorie'] ?? 0);
	$f_restitue     = (int)($job['restitue'] ?? 0);
	$f_restaure     = (int)($job['restaure'] ?? 0);
	$objet_mobilier = strtolower(trim($job['objet_mobilier'] ?? '')); // '', 'objet' ou 'mobilier'

	// Config plugin (mapping Objet/Mobilier)
	$o_conf_mte = Configuration::load($job['ca_base_dir'] . '/app/plugins/etatsMTE/conf/etatsMTE.conf');
	$om_item = 0;
	if ($objet_mobilier === 'objet')    { $om_item = (int)$o_conf_mte->get('om_item_objet'); }
	elseif ($objet_mobilier === 'mobilier') { $om_item = (int)$o_conf_mte->get('om_item_mobilier'); }

	$wheres = ["o.deleted = 0"];
	$joins = [];
	$params = [];
	$titre = "Catalogue";
	$group_by_deposant = false;
	$group_by_site = false;
	$group_by_batiment = false;

	switch ($catalogue_type) {
		case 'deposant_tous_sites':
			$titre = "Catalogue par déposant – tous sites";
			if ($deposant_id) {
								$wheres[] = "o.type_id = ?";   // la spécification (06/10/2026) : filtrage par TYPE, comme en recherche avancée
				$params[] = $deposant_id;
			} else {
				$group_by_deposant = true;
					// C1 (recette 22/04) : "Tous" les deposants exclut le MTE (type MTE = 3454)
					$wheres[] = "o.type_id <> 3454";
			}
			break;

		case 'deposant_par_site':
			$titre = "Catalogue par déposant par site";
			if ($deposant_id) {
								$wheres[] = "o.type_id = ?";   // la spécification (06/10/2026) : filtrage par TYPE, comme en recherche avancée
				$params[] = $deposant_id;
			}
			if ($site_id) {
				// RÈGLE DE REPLI (règle retenue, reprise de la règle retenue
				// en spécification fonctionnelle) : localisation d'INVENTAIRE ; à défaut de
				// tout relevé d'inventaire, localisation de DÉPÔT (709). Mesuré : le site concerné
				// élargit le résultat biens. Sous-requêtes corrélées : pas de jointure, donc
				// aucune multiplication de lignes.
				$wheres[] = "(EXISTS (SELECT 1 FROM ca_attributes xa JOIN ca_attribute_values xav ON xav.attribute_id = xa.attribute_id AND xav.element_id = 777 WHERE xa.table_num = 57 AND xa.row_id = o.object_id AND xav.item_id = ? AND xa.attribute_id = (SELECT MAX(la.attribute_id) FROM ca_attributes la JOIN ca_attribute_values lav ON lav.attribute_id = la.attribute_id AND lav.element_id = 777 WHERE la.table_num = 57 AND la.row_id = o.object_id AND la.element_id = 736 AND lav.item_id IS NOT NULL))"
					. " OR (EXISTS (SELECT 1 FROM ca_attributes ya JOIN ca_attribute_values yav ON yav.attribute_id = ya.attribute_id AND yav.element_id = 806 WHERE ya.table_num = 57 AND ya.row_id = o.object_id AND yav.item_id = 3656)"
					. " AND EXISTS (SELECT 1 FROM ca_attributes za JOIN ca_attribute_values zav ON zav.attribute_id = za.attribute_id AND zav.element_id = 709 WHERE za.table_num = 57 AND za.row_id = o.object_id AND zav.item_id = ?)))";
				$params[] = $site_id;
				$params[] = $site_id;
			}
			// Recette 07/10/2026 (besoin exprimé) : chapitrage « site puis batiment »,
			// a l'identique du catalogue specifique (ex. « Varennes, 5 objets »).
			// On ne regroupe jamais sur une dimension deja filtree : le chapitre
			// contredirait la couverture (cf. commentaire du cas 'specifique').
			$group_by_site     = !$site_id;
			$group_by_batiment = !$batiment_id;
			break;

		case 'biens_disparus':
			$titre = "Catalogue des biens disparus";
			// Critère 1 : constat = Manquant (548) ou Détruit (549)
			// Critère 2 : date de disparition (element 785) renseignée et différente de "sans date"
			$joins[] = "LEFT JOIN ca_attributes a_inv ON o.object_id = a_inv.row_id AND a_inv.table_num = 57";
			$joins[] = "LEFT JOIN ca_attribute_values av_inv ON a_inv.attribute_id = av_inv.attribute_id AND av_inv.element_id = 776";
			$joins[] = "LEFT JOIN ca_attributes a_disp ON o.object_id = a_disp.row_id AND a_disp.table_num = 57";
			$joins[] = "LEFT JOIN ca_attribute_values av_disp ON a_disp.attribute_id = av_disp.attribute_id AND av_disp.element_id = 785";
			$wheres[] = "(av_inv.item_id IN (548, 549) OR (av_disp.value_longtext1 IS NOT NULL AND av_disp.value_longtext1 != '' AND av_disp.value_longtext1 != 'sans date'))";
			if ($deposant_id) {
								$wheres[] = "o.type_id = ?";   // la spécification (06/10/2026) : filtrage par TYPE, comme en recherche avancée
				$params[] = $deposant_id;
			}
			// Recette 07/10/2026 (besoin exprimé) : chapitrage « site puis batiment »,
			// a l'identique du catalogue specifique (ex. « Varennes, 5 objets »).
			// On ne regroupe jamais sur une dimension deja filtree : le chapitre
			// contredirait la couverture (cf. commentaire du cas 'specifique').
			$group_by_site     = !$site_id;
			$group_by_batiment = !$batiment_id;
			break;

		case 'mte_objets_par_site':
			$titre = "Catalogue MTE des objets par site";
			// Catalogue MTE : biens dont le déposant est le MTE (type d'objet MTE = 3454)
			$wheres[] = "o.type_id = 3454";
			// ... et de classe "objet" (métadonnée calculée calc_objet_mobilier)
			$om_item = (int)$o_conf_mte->get('om_item_objet');
			if ($site_id) {
				// RÈGLE DE REPLI (règle retenue, reprise de la règle retenue
				// en spécification fonctionnelle) : localisation d'INVENTAIRE ; à défaut de
				// tout relevé d'inventaire, localisation de DÉPÔT (709). Mesuré : le site concerné
				// élargit le résultat biens. Sous-requêtes corrélées : pas de jointure, donc
				// aucune multiplication de lignes.
				$wheres[] = "(EXISTS (SELECT 1 FROM ca_attributes xa JOIN ca_attribute_values xav ON xav.attribute_id = xa.attribute_id AND xav.element_id = 777 WHERE xa.table_num = 57 AND xa.row_id = o.object_id AND xav.item_id = ? AND xa.attribute_id = (SELECT MAX(la.attribute_id) FROM ca_attributes la JOIN ca_attribute_values lav ON lav.attribute_id = la.attribute_id AND lav.element_id = 777 WHERE la.table_num = 57 AND la.row_id = o.object_id AND la.element_id = 736 AND lav.item_id IS NOT NULL))"
					. " OR (EXISTS (SELECT 1 FROM ca_attributes ya JOIN ca_attribute_values yav ON yav.attribute_id = ya.attribute_id AND yav.element_id = 806 WHERE ya.table_num = 57 AND ya.row_id = o.object_id AND yav.item_id = 3656)"
					. " AND EXISTS (SELECT 1 FROM ca_attributes za JOIN ca_attribute_values zav ON zav.attribute_id = za.attribute_id AND zav.element_id = 709 WHERE za.table_num = 57 AND za.row_id = o.object_id AND zav.item_id = ?)))";
				$params[] = $site_id;
				$params[] = $site_id;
			} else {
				$group_by_site = true; // Site "Tous" : regrouper par site
			}
			// Recette 07/10/2026 (besoin exprimé) : chapitrage « site puis batiment »,
			// a l'identique du catalogue specifique (ex. « Varennes, 5 objets »).
			// On ne regroupe jamais sur une dimension deja filtree : le chapitre
			// contredirait la couverture (cf. commentaire du cas 'specifique').
			$group_by_batiment = !$batiment_id;
			break;

		case 'mte_mobiliers_par_site':
			$titre = "Catalogue MTE des mobiliers par site";
			// Catalogue MTE : biens dont le déposant est le MTE (type d'objet MTE = 3454)
			$wheres[] = "o.type_id = 3454";
			// ... et de classe "mobilier" (métadonnée calculée calc_objet_mobilier)
			$om_item = (int)$o_conf_mte->get('om_item_mobilier');
			if ($site_id) {
				// RÈGLE DE REPLI (règle retenue, reprise de la règle retenue
				// en spécification fonctionnelle) : localisation d'INVENTAIRE ; à défaut de
				// tout relevé d'inventaire, localisation de DÉPÔT (709). Mesuré : le site concerné
				// élargit le résultat biens. Sous-requêtes corrélées : pas de jointure, donc
				// aucune multiplication de lignes.
				$wheres[] = "(EXISTS (SELECT 1 FROM ca_attributes xa JOIN ca_attribute_values xav ON xav.attribute_id = xa.attribute_id AND xav.element_id = 777 WHERE xa.table_num = 57 AND xa.row_id = o.object_id AND xav.item_id = ? AND xa.attribute_id = (SELECT MAX(la.attribute_id) FROM ca_attributes la JOIN ca_attribute_values lav ON lav.attribute_id = la.attribute_id AND lav.element_id = 777 WHERE la.table_num = 57 AND la.row_id = o.object_id AND la.element_id = 736 AND lav.item_id IS NOT NULL))"
					. " OR (EXISTS (SELECT 1 FROM ca_attributes ya JOIN ca_attribute_values yav ON yav.attribute_id = ya.attribute_id AND yav.element_id = 806 WHERE ya.table_num = 57 AND ya.row_id = o.object_id AND yav.item_id = 3656)"
					. " AND EXISTS (SELECT 1 FROM ca_attributes za JOIN ca_attribute_values zav ON zav.attribute_id = za.attribute_id AND zav.element_id = 709 WHERE za.table_num = 57 AND za.row_id = o.object_id AND zav.item_id = ?)))";
				$params[] = $site_id;
				$params[] = $site_id;
			} else {
				$group_by_site = true; // Site "Tous" : regrouper par site
			}
			// Recette 07/10/2026 (besoin exprimé) : chapitrage « site puis batiment »,
			// a l'identique du catalogue specifique (ex. « Varennes, 5 objets »).
			// On ne regroupe jamais sur une dimension deja filtree : le chapitre
			// contredirait la couverture (cf. commentaire du cas 'specifique').
			$group_by_batiment = !$batiment_id;
			break;

		case 'specifique_deposant_site_batiment_etage':
			$titre = "Catalogue par déposant + site + bâtiment + étage";
			if ($deposant_id) {
								$wheres[] = "o.type_id = ?";   // la spécification (06/10/2026) : filtrage par TYPE, comme en recherche avancée
				$params[] = $deposant_id;
			}
			if ($site_id) {
				// RÈGLE DE REPLI (règle retenue, reprise de la règle retenue
				// en spécification fonctionnelle) : localisation d'INVENTAIRE ; à défaut de
				// tout relevé d'inventaire, localisation de DÉPÔT (709). Mesuré : le site concerné
				// élargit le résultat biens. Sous-requêtes corrélées : pas de jointure, donc
				// aucune multiplication de lignes.
				$wheres[] = "(EXISTS (SELECT 1 FROM ca_attributes xa JOIN ca_attribute_values xav ON xav.attribute_id = xa.attribute_id AND xav.element_id = 777 WHERE xa.table_num = 57 AND xa.row_id = o.object_id AND xav.item_id = ? AND xa.attribute_id = (SELECT MAX(la.attribute_id) FROM ca_attributes la JOIN ca_attribute_values lav ON lav.attribute_id = la.attribute_id AND lav.element_id = 777 WHERE la.table_num = 57 AND la.row_id = o.object_id AND la.element_id = 736 AND lav.item_id IS NOT NULL))"
					. " OR (EXISTS (SELECT 1 FROM ca_attributes ya JOIN ca_attribute_values yav ON yav.attribute_id = ya.attribute_id AND yav.element_id = 806 WHERE ya.table_num = 57 AND ya.row_id = o.object_id AND yav.item_id = 3656)"
					. " AND EXISTS (SELECT 1 FROM ca_attributes za JOIN ca_attribute_values zav ON zav.attribute_id = za.attribute_id AND zav.element_id = 709 WHERE za.table_num = 57 AND za.row_id = o.object_id AND zav.item_id = ?)))";
				$params[] = $site_id;
				$params[] = $site_id;
			}
			if ($batiment_id) {
				// Même règle de repli pour le bâtiment : bâtiment d'INVENTAIRE (796), à défaut de
				// tout relevé d'inventaire, bâtiment de DÉPÔT (800). « Bâtiment 5 » reste à 70.
				$wheres[] = "(EXISTS (SELECT 1 FROM ca_attributes xb JOIN ca_attribute_values xbv ON xbv.attribute_id = xb.attribute_id AND xbv.element_id = 796 WHERE xb.table_num = 57 AND xb.row_id = o.object_id AND xbv.item_id = ? AND xb.attribute_id = (SELECT MAX(lb.attribute_id) FROM ca_attributes lb JOIN ca_attribute_values lbv ON lbv.attribute_id = lb.attribute_id AND lbv.element_id = 796 WHERE lb.table_num = 57 AND lb.row_id = o.object_id AND lb.element_id = 736 AND lbv.item_id IS NOT NULL))"
					. " OR (EXISTS (SELECT 1 FROM ca_attributes yb JOIN ca_attribute_values ybv ON ybv.attribute_id = yb.attribute_id AND ybv.element_id = 806 WHERE yb.table_num = 57 AND yb.row_id = o.object_id AND ybv.item_id = 3656)"
					. " AND EXISTS (SELECT 1 FROM ca_attributes zb JOIN ca_attribute_values zbv ON zbv.attribute_id = zb.attribute_id AND zbv.element_id = 800 WHERE zb.table_num = 57 AND zb.row_id = o.object_id AND zbv.item_id = ?)))";
				$params[] = $batiment_id;
				$params[] = $batiment_id;
			}
			if ($etage_id) {
				$joins[] = "JOIN ca_attributes a_et ON o.object_id = a_et.row_id AND a_et.table_num = 57";
				$joins[] = "JOIN ca_attribute_values av_et ON a_et.attribute_id = av_et.attribute_id AND av_et.element_id = 712";
				$wheres[] = "av_et.item_id = ?";
				$params[] = $etage_id;
			}
			break;

		case 'specifique_par_type':
			$titre = "Catalogue par type";
			if ($type_id) {
				$joins[] = "JOIN ca_attributes a_dom ON o.object_id = a_dom.row_id AND a_dom.table_num = 57";
				$joins[] = "JOIN ca_attribute_values av_dom ON a_dom.attribute_id = av_dom.attribute_id AND av_dom.element_id = (SELECT element_id FROM ca_metadata_elements WHERE element_code='domaine_logement')";
				$wheres[] = "av_dom.item_id = ?";
				$params[] = $type_id;
			}
			$group_by_site = true;
			$group_by_deposant = true;
			break;

		case 'specifique_vu_non_vu':
			$titre = "Catalogue des biens VU/NON VU";
			if ($constat_id) {
				$joins[] = "JOIN ca_attributes a_cst ON o.object_id = a_cst.row_id AND a_cst.table_num = 57";
				$joins[] = "JOIN ca_attribute_values av_cst ON a_cst.attribute_id = av_cst.attribute_id AND av_cst.element_id = 776";
				$wheres[] = "av_cst.item_id = ?";
				$params[] = $constat_id;
			}
			$group_by_site = true;
			$group_by_deposant = true;
			break;

		case 'specifique_recoles_periode':
			$titre = "Catalogue des biens récolés sur une période";
			$joins[] = "JOIN ca_attributes a_rec ON o.object_id = a_rec.row_id AND a_rec.table_num = 57";
			$joins[] = "JOIN ca_attribute_values av_rec ON a_rec.attribute_id = av_rec.attribute_id AND av_rec.element_id = 659";
			if ($date_debut) {
				$wheres[] = "av_rec.value_decimal1 >= ?";
				$params[] = dateToJulian($date_debut);
			}
			if ($date_fin) {
				$wheres[] = "av_rec.value_decimal1 <= ?";
				$params[] = dateToJulian($date_fin);
			}
			if ($deposant_id) {
								$wheres[] = "o.type_id = ?";   // la spécification (06/10/2026) : filtrage par TYPE, comme en recherche avancée
				$params[] = $deposant_id;
			} else {
				$group_by_deposant = true;
			}
			$group_by_site = true;
			break;

		case 'specifique_inventories_periode':
			$titre = "Catalogue des biens inventoriés sur une période";
			$joins[] = "JOIN ca_attributes a_invent ON o.object_id = a_invent.row_id AND a_invent.table_num = 57";
			$joins[] = "JOIN ca_attribute_values av_invent ON a_invent.attribute_id = av_invent.attribute_id AND av_invent.element_id = 775";
			if ($date_debut) {
				$wheres[] = "av_invent.value_decimal1 >= ?";
				$params[] = dateToJulian($date_debut);
			}
			if ($date_fin) {
				$wheres[] = "av_invent.value_decimal1 <= ?";
				$params[] = dateToJulian($date_fin);
			}
			if ($deposant_id) {
								$wheres[] = "o.type_id = ?";   // la spécification (06/10/2026) : filtrage par TYPE, comme en recherche avancée
				$params[] = $deposant_id;
			} else {
				$group_by_deposant = true;
			}
			$group_by_site = true;
			break;

		case 'specifique':
			// Catalogue spécifique unifié : piloté par les filtres actifs (plus de boutons radio).
			$titre = "Catalogue spécifique";
			if ($deposant_id) {
								$wheres[] = "o.type_id = ?";   // la spécification (06/10/2026) : filtrage par TYPE, comme en recherche avancée
				$params[] = $deposant_id;
			} else {
				$group_by_deposant = true;
			}
			if ($site_id) {
				// RÈGLE DE REPLI (règle retenue, reprise de la règle retenue
				// en spécification fonctionnelle) : localisation d'INVENTAIRE ; à défaut de
				// tout relevé d'inventaire, localisation de DÉPÔT (709). Mesuré : le site concerné
				// élargit le résultat biens. Sous-requêtes corrélées : pas de jointure, donc
				// aucune multiplication de lignes.
				$wheres[] = "(EXISTS (SELECT 1 FROM ca_attributes xa JOIN ca_attribute_values xav ON xav.attribute_id = xa.attribute_id AND xav.element_id = 777 WHERE xa.table_num = 57 AND xa.row_id = o.object_id AND xav.item_id = ? AND xa.attribute_id = (SELECT MAX(la.attribute_id) FROM ca_attributes la JOIN ca_attribute_values lav ON lav.attribute_id = la.attribute_id AND lav.element_id = 777 WHERE la.table_num = 57 AND la.row_id = o.object_id AND la.element_id = 736 AND lav.item_id IS NOT NULL))"
					. " OR (EXISTS (SELECT 1 FROM ca_attributes ya JOIN ca_attribute_values yav ON yav.attribute_id = ya.attribute_id AND yav.element_id = 806 WHERE ya.table_num = 57 AND ya.row_id = o.object_id AND yav.item_id = 3656)"
					. " AND EXISTS (SELECT 1 FROM ca_attributes za JOIN ca_attribute_values zav ON zav.attribute_id = za.attribute_id AND zav.element_id = 709 WHERE za.table_num = 57 AND za.row_id = o.object_id AND zav.item_id = ?)))";
				$params[] = $site_id;
				$params[] = $site_id;
			}
			if ($batiment_id) {
				// Même règle de repli pour le bâtiment : bâtiment d'INVENTAIRE (796), à défaut de
				// tout relevé d'inventaire, bâtiment de DÉPÔT (800). « Bâtiment 5 » reste à 70.
				$wheres[] = "(EXISTS (SELECT 1 FROM ca_attributes xb JOIN ca_attribute_values xbv ON xbv.attribute_id = xb.attribute_id AND xbv.element_id = 796 WHERE xb.table_num = 57 AND xb.row_id = o.object_id AND xbv.item_id = ? AND xb.attribute_id = (SELECT MAX(lb.attribute_id) FROM ca_attributes lb JOIN ca_attribute_values lbv ON lbv.attribute_id = lb.attribute_id AND lbv.element_id = 796 WHERE lb.table_num = 57 AND lb.row_id = o.object_id AND lb.element_id = 736 AND lbv.item_id IS NOT NULL))"
					. " OR (EXISTS (SELECT 1 FROM ca_attributes yb JOIN ca_attribute_values ybv ON ybv.attribute_id = yb.attribute_id AND ybv.element_id = 806 WHERE yb.table_num = 57 AND yb.row_id = o.object_id AND ybv.item_id = 3656)"
					. " AND EXISTS (SELECT 1 FROM ca_attributes zb JOIN ca_attribute_values zbv ON zbv.attribute_id = zb.attribute_id AND zbv.element_id = 800 WHERE zb.table_num = 57 AND zb.row_id = o.object_id AND zbv.item_id = ?)))";
				$params[] = $batiment_id;
				$params[] = $batiment_id;
			}
			// Critère Adresse (site_adresse1, liste 165) — remplace Étage au catalogue spécifique
			if ($adresse_id) {
				$joins[] = "JOIN ca_attributes a_adr ON o.object_id = a_adr.row_id AND a_adr.table_num = 57";
				$joins[] = "JOIN ca_attribute_values av_adr ON a_adr.attribute_id = av_adr.attribute_id AND av_adr.element_id = 799";
				$wheres[] = "av_adr.item_id = ?";
				$params[] = $adresse_id;
			}
			// Critère Catégorie (domaine_logement, liste 157)
			if ($type_id) {
				$joins[] = "JOIN ca_attributes a_dom ON o.object_id = a_dom.row_id AND a_dom.table_num = 57";
				$joins[] = "JOIN ca_attribute_values av_dom ON a_dom.attribute_id = av_dom.attribute_id AND av_dom.element_id = (SELECT element_id FROM ca_metadata_elements WHERE element_code='domaine_logement')";
				$wheres[] = "av_dom.item_id = ?";
				$params[] = $type_id;
			}
			// Critère Type (denomination, liste 168)
			if ($denomination_id) {
				$joins[] = "JOIN ca_attributes a_den ON o.object_id = a_den.row_id AND a_den.table_num = 57";
				$joins[] = "JOIN ca_attribute_values av_den ON a_den.attribute_id = av_den.attribute_id AND av_den.element_id = (SELECT element_id FROM ca_metadata_elements WHERE element_code='denomination')";
				$wheres[] = "av_den.item_id = ?";
				$params[] = $denomination_id;
			}
			if ($constat_id) {
				$joins[] = "JOIN ca_attributes a_cst ON o.object_id = a_cst.row_id AND a_cst.table_num = 57";
				$joins[] = "JOIN ca_attribute_values av_cst ON a_cst.attribute_id = av_cst.attribute_id AND av_cst.element_id = 776";
				$wheres[] = "av_cst.item_id = ?";
				$params[] = $constat_id;
			}
			// Période : sur la date de récolement (659) si "récolé" coché, sinon sur la date d'inventaire (775) si "inventorié" coché
			if ($date_debut || $date_fin) {
				if ($f_recole) {
					$joins[] = "JOIN ca_attributes a_rec ON o.object_id = a_rec.row_id AND a_rec.table_num = 57";
					$joins[] = "JOIN ca_attribute_values av_rec ON a_rec.attribute_id = av_rec.attribute_id AND av_rec.element_id = 659";
					if ($date_debut) { $wheres[] = "av_rec.value_decimal1 >= ?"; $params[] = dateToJulian($date_debut); }
					if ($date_fin)   { $wheres[] = "av_rec.value_decimal1 <= ?"; $params[] = dateToJulian($date_fin); }
				} elseif ($f_inventorie) {
					$joins[] = "JOIN ca_attributes a_invent ON o.object_id = a_invent.row_id AND a_invent.table_num = 57";
					$joins[] = "JOIN ca_attribute_values av_invent ON a_invent.attribute_id = av_invent.attribute_id AND av_invent.element_id = 775";
					if ($date_debut) { $wheres[] = "av_invent.value_decimal1 >= ?"; $params[] = dateToJulian($date_debut); }
					if ($date_fin)   { $wheres[] = "av_invent.value_decimal1 <= ?"; $params[] = dateToJulian($date_fin); }
				}
			}
			// les filtres Site et Bâtiment portent
			// désormais sur la localisation d'INVENTAIRE, alors que le chapitrage regroupe
			// sur le site de DÉPÔT ($fiche['site'], cf. plus bas). Regrouper un catalogue
			// déjà restreint à une localisation produisait un sommaire contredisant sa
			// couverture : « Saint-Germain / Bâtiment 5 » s'ouvrait sur un chapitre
			// « LA DEFENSE ». On ne regroupe donc que si aucune localisation n'est filtrée
			// — c'est la règle que les cas mte_objets_par_site / mte_mobiliers_par_site
			// appliquent déjà plus haut.
			$group_by_site = !$site_id && !$batiment_id;
			break;

		default:
			file_put_contents($status_file, json_encode(['status' => 'error', 'message' => 'Type de catalogue inconnu']));
			exit(1);
	}

	// -------------------------------------------------------
	// Filtres communs "etats" (cases a cocher) : ne garder que les biens dont
	// le champ calcule Oui/Non vaut "oui" (item_id 3655 = oui_calc).
	//   calc_recole=807  calc_inventorie=806  calc_restitue=809  calc_restaure=808
	// -------------------------------------------------------
	$calc_filters = [
		807 => $f_recole,      // Bien recole
		806 => $f_inventorie,  // Bien inventorie
		809 => $f_restitue,    // Bien restitue
		808 => $f_restaure,    // Bien restaure
	];
	$calc_i = 0;
	foreach ($calc_filters as $element_id => $is_on) {
		if (!$is_on) { continue; }
		$calc_i++;
		$a_alias  = "a_calc{$calc_i}";
		$av_alias = "av_calc{$calc_i}";
		$joins[] = "JOIN ca_attributes {$a_alias} ON o.object_id = {$a_alias}.row_id AND {$a_alias}.table_num = 57";
		$joins[] = "JOIN ca_attribute_values {$av_alias} ON {$a_alias}.attribute_id = {$av_alias}.attribute_id AND {$av_alias}.element_id = {$element_id}";
		$wheres[] = "{$av_alias}.item_id = 3655";
	}

	// -------------------------------------------------------
	// Filtre Objet / Mobilier (métadonnée calculée calc_objet_mobilier, pivot depuis la dénomination)
	// -------------------------------------------------------
	if ($om_item) {
		$joins[] = "JOIN ca_attributes a_om ON o.object_id = a_om.row_id AND a_om.table_num = 57";
		$joins[] = "JOIN ca_attribute_values av_om ON a_om.attribute_id = av_om.attribute_id AND av_om.element_id = (SELECT element_id FROM ca_metadata_elements WHERE element_code='calc_objet_mobilier')";
		$wheres[] = "av_om.item_id = ?";
		$params[] = $om_item;
	}

	// -------------------------------------------------------
	// C3 (recette 22/04) : enrichir le titre (= nom du fichier) selon les filtres choisis
	// -------------------------------------------------------
	$o_db_n = new Db();
	$resolveListItem = function($pn_item_id) use ($o_db_n) {
		if (!$pn_item_id) return '';
		$q = $o_db_n->query("SELECT name_singular FROM ca_list_item_labels WHERE item_id = ? AND is_preferred = 1", [(int)$pn_item_id]);
		return $q->nextRow() ? $q->get('name_singular') : '';
	};
	$filter_parts = [];
	if ($deposant_id) {
		// $deposant_id est un item_id de object_types, plus un
		// entity_id. Résoudre dans ca_entity_labels renvoyait 0 ligne et faisait disparaître le nom
		// du déposant du titre ET du nom de fichier du PDF, sans aucune erreur.
		$filter_parts[] = $resolveListItem($deposant_id);
	}
	if ($site_id)     { $filter_parts[] = $resolveListItem($site_id); }
	if ($batiment_id) { $filter_parts[] = $resolveListItem($batiment_id); }
	if ($etage_id)    { $filter_parts[] = $resolveListItem($etage_id); }
	if ($adresse_id)  { $filter_parts[] = $resolveListItem($adresse_id); }
	if ($type_id)     { $filter_parts[] = $resolveListItem($type_id); }
	if ($denomination_id) { $filter_parts[] = $resolveListItem($denomination_id); }
	if ($constat_id)  { $filter_parts[] = $resolveListItem($constat_id); }
	if ($om_item)     { $filter_parts[] = $resolveListItem($om_item); }
	$filter_parts = array_values(array_filter($filter_parts, function($v){ return trim($v) !== ''; }));
	if (!empty($filter_parts)) { $titre .= ' - ' . implode(' - ', $filter_parts); }

	// -------------------------------------------------------
	// 2. Execute query
	// -------------------------------------------------------
	$sql = "SELECT DISTINCT o.object_id FROM ca_objects o " . implode(" ", $joins) . " WHERE " . implode(" AND ", $wheres) . " ORDER BY o.idno";
	$o_db = new Db();
	$qr = $o_db->query($sql, $params);

	$object_ids = [];
	while ($qr->nextRow()) {
		$object_ids[] = $qr->get("object_id");
	}

	$nb = count($object_ids);
	if ($nb === 0) {
		file_put_contents($status_file, json_encode(['status' => 'error', 'message' => 'Aucun objet trouvé pour les critères sélectionnés.']));
		exit(0);
	}

	file_put_contents($status_file, json_encode(['status' => 'running', 'total' => $nb, 'processed' => 0]));

	// -------------------------------------------------------
	// 3. Build fiches
	// -------------------------------------------------------
	$fiches = [];
	$processed = 0;
	foreach ($object_ids as $oid) {
		$fiches[] = buildFicheObjet($oid);
		$processed++;
		if ($processed % 10 === 0) {
			file_put_contents($status_file, json_encode(['status' => 'running', 'total' => $nb, 'processed' => $processed]));
		}
	}

	// -------------------------------------------------------
	// 4. Group if needed
	// -------------------------------------------------------
	$fiches_grouped = null;
	if ($group_by_site || $group_by_batiment || $group_by_deposant) {
		$fiches_grouped = [];
		foreach ($fiches as $fiche) {
			$gk = "";
			if ($group_by_site) {
				$gk .= ($fiche['site'] ?: 'Sans site');
			}
			if ($group_by_batiment) {
				$gk .= ($gk ? ' — ' : '') . ($fiche['batiment'] ?: 'Sans bâtiment');
			}
			if ($group_by_deposant) {
				$gk .= ($gk ? ' — ' : '') . ($fiche['deposant'] ?: 'Sans déposant');
			}
			$fiches_grouped[$gk][] = $fiche;
		}
		// Les fiches arrivent triees par idno : sans tri des cles, les chapitres
		// sortaient dans l'ordre de premiere rencontre, donc un site pouvait
		// reapparaitre plus loin. On ordonne site puis batiment, « Sans ... » en fin.
		// Le tri ne s'applique qu'au chapitrage par batiment, c'est-a-dire aux
		// seuls catalogues de l'onglet standard, objet de la demande du 07/10.
		// Les catalogues 'specifique' n'activent pas ce drapeau : leur ordre de
		// chapitres reste donc exactement celui d'avant.
		if ($group_by_batiment) {
			uksort($fiches_grouped, function($a, $b) {
				$ra = (strpos($a, 'Sans ') === 0) ? 1 : 0;
				$rb = (strpos($b, 'Sans ') === 0) ? 1 : 0;
				if ($ra !== $rb) { return $ra - $rb; }
				return strnatcasecmp($a, $b);
			});
		}
	}

	file_put_contents($status_file, json_encode(['status' => 'rendering', 'total' => $nb, 'processed' => $nb]));

	// -------------------------------------------------------
	// 5. Render PDF
	// -------------------------------------------------------
	$html = renderPDFHTML($fiches, $fiches_grouped, $titre, $group_by_site, $group_by_deposant);
	$chapter_meta = $GLOBALS['_chapter_meta'];

	$o_pdf = new PDFRenderer();
	$o_pdf->setPage('A4', 'portrait', '1.5cm', '1.5cm', '1.5cm', '1.5cm');

	$pdf_content = $o_pdf->render($html, [
		'stream'   => false,
	]);

	if ($pdf_content) {
		// Write raw PDF
		$pdf_raw = $pdf_file . '.raw';
		file_put_contents($pdf_raw, $pdf_content);

		// Write chapter metadata
		$chapters_json = $output_dir . '/' . $job_id . '.chapters.json';
		file_put_contents($chapters_json, json_encode($chapter_meta));

		// Stamp footers with Python script
		$stamp_script = dirname(__FILE__) . '/stamp_pages.py';
		$stamp_cmd = 'python3 ' . escapeshellarg($stamp_script) . ' '
			. escapeshellarg($pdf_raw) . ' '
			. escapeshellarg($chapters_json) . ' '
			. escapeshellarg($pdf_file) . ' 2>&1';
		$stamp_output = [];
		$stamp_ret = 0;
		exec($stamp_cmd, $stamp_output, $stamp_ret);

		if ($stamp_ret !== 0 || !file_exists($pdf_file)) {
			// Fallback: use raw PDF without footers
			rename($pdf_raw, $pdf_file);
		} else {
			@unlink($pdf_raw);
		}
		@unlink($chapters_json);
	} else {
		throw new Exception("Le moteur de rendu PDF n'a produit aucun contenu.");
	}

	file_put_contents($status_file, json_encode([
		'status' => 'done',
		'total' => $nb,
		'file' => $pdf_file,
		'titre' => $titre,
		'finished' => date('c')
	]));

} catch (Exception $e) {
	file_put_contents($status_file, json_encode([
		'status' => 'error',
		'message' => $e->getMessage()
	]));
	exit(1);
}

// ===================================================================
// Helper functions
// ===================================================================

function dateToJulian($ps_date) {
	// Les dates CA (value_decimal1) sont stockées au format historique décimal YYYY.MMDDHHMMSS,
	// PAS en jour julien. gregoriantojd() renvoyait ~2,45 M -> comparaisons value_decimal1>=/<=
	// toujours fausses/vraies -> filtres de période cassés. Corrigé 26/07/2026 : format CA YYYY.MMDD.
	if (preg_match('!^(\d{1,2})/(\d{1,2})/(\d{4})$!', $ps_date, $m)) {
		return (int)$m[3] + (int)$m[2]/100.0 + (int)$m[1]/10000.0;
	}
	if (preg_match('!^(\d{4})-(\d{1,2})-(\d{1,2})$!', $ps_date, $m)) {
		return (int)$m[1] + (int)$m[2]/100.0 + (int)$m[3]/10000.0;
	}
	return 0;
}

function renderPDFHTML($fiches, $fiches_grouped, $titre, $group_by_site, $group_by_deposant) {
	ob_start();
?>
<html>
<head>
<style>
<?= caFicheCatalogueStyles() ?>
</style>
</head>
<body>
<?php
	$GLOBALS['_chapter_meta'] = [];
	$GLOBALS['_page_counter'] = 0;

	if (is_array($fiches_grouped) && sizeof($fiches_grouped)) {
		foreach ($fiches_grouped as $group_label => $group_fiches) {
			// Title page for this chapter
			$GLOBALS['_page_counter']++;
			$GLOBALS['_chapter_meta'][] = [
				'title' => $group_label,
				'start_page' => $GLOBALS['_page_counter'],
				'index' => 0,
				'count' => count($group_fiches),
				'is_title_page' => true,
			];
			echo '<div class="group-title-page"><div class="group-title-inner">';
			echo '<h1>' . htmlspecialchars($group_label) . '</h1>';
			echo '<div class="group-count">' . count($group_fiches) . ' objet' . (count($group_fiches) > 1 ? 's' : '') . '</div>';
			echo '<div class="group-catalogue-type">' . htmlspecialchars($titre) . '</div>';
			echo '</div></div>';

			$group_total = count($group_fiches);
			$group_idx = 0;
			foreach ($group_fiches as $fiche) {
				$group_idx++;
				$GLOBALS['_page_counter']++;
				$GLOBALS['_chapter_meta'][] = [
					'title' => $group_label,
					'start_page' => $GLOBALS['_page_counter'],
					'index' => $group_idx,
					'count' => $group_total,
					'is_title_page' => false,
				];
				renderFichePDF($fiche);
			}
		}
	} else {
		$total = count($fiches);
		$idx = 0;
		foreach ($fiches as $fiche) {
			$idx++;
			$GLOBALS['_page_counter']++;
			$GLOBALS['_chapter_meta'][] = [
				'title' => $titre,
				'start_page' => $GLOBALS['_page_counter'],
				'index' => $idx,
				'count' => $total,
				'is_title_page' => false,
			];
			renderFichePDF($fiche);
		}
	}
?>
</body>
</html>
<?php
	return ob_get_clean();
}

