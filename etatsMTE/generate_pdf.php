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

	switch ($catalogue_type) {
		case 'deposant_tous_sites':
			$titre = "Catalogue par déposant – tous sites";
			if ($deposant_id) {
				$joins[] = "JOIN ca_objects_x_entities oxe ON o.object_id = oxe.object_id AND oxe.type_id = " . REL_DEPOSANT;
				$wheres[] = "oxe.entity_id = ?";
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
				$joins[] = "JOIN ca_objects_x_entities oxe ON o.object_id = oxe.object_id AND oxe.type_id = " . REL_DEPOSANT;
				$wheres[] = "oxe.entity_id = ?";
				$params[] = $deposant_id;
			}
			if ($site_id) {
				$joins[] = "JOIN ca_attributes a_site ON o.object_id = a_site.row_id AND a_site.table_num = 57";
				$joins[] = "JOIN ca_attribute_values av_site ON a_site.attribute_id = av_site.attribute_id AND av_site.element_id = 709";
				$wheres[] = "av_site.item_id = ?";
				$params[] = $site_id;
			}
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
				$joins[] = "JOIN ca_objects_x_entities oxe ON o.object_id = oxe.object_id AND oxe.type_id = " . REL_DEPOSANT;
				$wheres[] = "oxe.entity_id = ?";
				$params[] = $deposant_id;
			}
			break;

		case 'mte_objets_par_site':
			$titre = "Catalogue MTE des objets par site";
			// Catalogue MTE : biens dont le déposant est le MTE (type d'objet MTE = 3454)
			$wheres[] = "o.type_id = 3454";
			// ... et de classe "objet" (métadonnée calculée calc_objet_mobilier)
			$om_item = (int)$o_conf_mte->get('om_item_objet');
			if ($site_id) {
				$joins[] = "JOIN ca_attributes a_site ON o.object_id = a_site.row_id AND a_site.table_num = 57";
				$joins[] = "JOIN ca_attribute_values av_site ON a_site.attribute_id = av_site.attribute_id AND av_site.element_id = 709";
				$wheres[] = "av_site.item_id = ?";
				$params[] = $site_id;
			} else {
				$group_by_site = true; // Site "Tous" : regrouper par site
			}
			break;

		case 'mte_mobiliers_par_site':
			$titre = "Catalogue MTE des mobiliers par site";
			// Catalogue MTE : biens dont le déposant est le MTE (type d'objet MTE = 3454)
			$wheres[] = "o.type_id = 3454";
			// ... et de classe "mobilier" (métadonnée calculée calc_objet_mobilier)
			$om_item = (int)$o_conf_mte->get('om_item_mobilier');
			if ($site_id) {
				$joins[] = "JOIN ca_attributes a_site ON o.object_id = a_site.row_id AND a_site.table_num = 57";
				$joins[] = "JOIN ca_attribute_values av_site ON a_site.attribute_id = av_site.attribute_id AND av_site.element_id = 709";
				$wheres[] = "av_site.item_id = ?";
				$params[] = $site_id;
			} else {
				$group_by_site = true; // Site "Tous" : regrouper par site
			}
			break;

		case 'specifique_deposant_site_batiment_etage':
			$titre = "Catalogue par déposant + site + bâtiment + étage";
			if ($deposant_id) {
				$joins[] = "JOIN ca_objects_x_entities oxe ON o.object_id = oxe.object_id AND oxe.type_id = " . REL_DEPOSANT;
				$wheres[] = "oxe.entity_id = ?";
				$params[] = $deposant_id;
			}
			if ($site_id) {
				$joins[] = "JOIN ca_attributes a_site ON o.object_id = a_site.row_id AND a_site.table_num = 57";
				$joins[] = "JOIN ca_attribute_values av_site ON a_site.attribute_id = av_site.attribute_id AND av_site.element_id = 709";
				$wheres[] = "av_site.item_id = ?";
				$params[] = $site_id;
			}
			if ($batiment_id) {
				$joins[] = "JOIN ca_attributes a_bat ON o.object_id = a_bat.row_id AND a_bat.table_num = 57";
				$joins[] = "JOIN ca_attribute_values av_bat ON a_bat.attribute_id = av_bat.attribute_id AND av_bat.element_id = 800";
				$wheres[] = "av_bat.item_id = ?";
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
				$joins[] = "JOIN ca_objects_x_entities oxe ON o.object_id = oxe.object_id AND oxe.type_id = " . REL_DEPOSANT;
				$wheres[] = "oxe.entity_id = ?";
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
				$joins[] = "JOIN ca_objects_x_entities oxe ON o.object_id = oxe.object_id AND oxe.type_id = " . REL_DEPOSANT;
				$wheres[] = "oxe.entity_id = ?";
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
				$joins[] = "JOIN ca_objects_x_entities oxe ON o.object_id = oxe.object_id AND oxe.type_id = " . REL_DEPOSANT;
				$wheres[] = "oxe.entity_id = ?";
				$params[] = $deposant_id;
			} else {
				$group_by_deposant = true;
			}
			if ($site_id) {
				$joins[] = "JOIN ca_attributes a_site ON o.object_id = a_site.row_id AND a_site.table_num = 57";
				$joins[] = "JOIN ca_attribute_values av_site ON a_site.attribute_id = av_site.attribute_id AND av_site.element_id = 709";
				$wheres[] = "av_site.item_id = ?";
				$params[] = $site_id;
			}
			if ($batiment_id) {
				$joins[] = "JOIN ca_attributes a_bat ON o.object_id = a_bat.row_id AND a_bat.table_num = 57";
				$joins[] = "JOIN ca_attribute_values av_bat ON a_bat.attribute_id = av_bat.attribute_id AND av_bat.element_id = 800";
				$wheres[] = "av_bat.item_id = ?";
				$params[] = $batiment_id;
			}
			if ($etage_id) {
				$joins[] = "JOIN ca_attributes a_et ON o.object_id = a_et.row_id AND a_et.table_num = 57";
				$joins[] = "JOIN ca_attribute_values av_et ON a_et.attribute_id = av_et.attribute_id AND av_et.element_id = 712";
				$wheres[] = "av_et.item_id = ?";
				$params[] = $etage_id;
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
			$group_by_site = true;
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
		$q = $o_db_n->query("SELECT displayname FROM ca_entity_labels WHERE entity_id = ? AND is_preferred = 1", [(int)$deposant_id]);
		if ($q->nextRow()) { $filter_parts[] = $q->get('displayname'); }
	}
	if ($site_id)     { $filter_parts[] = $resolveListItem($site_id); }
	if ($batiment_id) { $filter_parts[] = $resolveListItem($batiment_id); }
	if ($etage_id)    { $filter_parts[] = $resolveListItem($etage_id); }
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
	if ($group_by_site || $group_by_deposant) {
		$fiches_grouped = [];
		foreach ($fiches as $fiche) {
			$gk = "";
			if ($group_by_site) {
				$gk .= ($fiche['site'] ?: 'Sans site');
			}
			if ($group_by_deposant) {
				$gk .= ($gk ? ' — ' : '') . ($fiche['deposant'] ?: 'Sans déposant');
			}
			$fiches_grouped[$gk][] = $fiche;
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

function buildFicheObjet($pn_object_id) {
	$obj = new ca_objects($pn_object_id);

	$vs_dim = $obj->getWithTemplate(
		"^ca_objects.dimensions.dimensions_height" .
		"<ifdef code='ca_objects.dimensions.dimensions_height'> (h) x </ifdef>" .
		"^ca_objects.dimensions.dimensions_width" .
		"<ifdef code='ca_objects.dimensions.dimensions_width'> (l) x </ifdef>" .
		"^ca_objects.dimensions.dimensions_depth" .
		"<ifdef code='ca_objects.dimensions.dimensions_depth'> (p)</ifdef>" .
		" ^ca_objects.dimensions.type_dimensions"
	);

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
		'numero_depot'   => $obj->getWithTemplate('^ca_objects.numero_depot'),
		'date_depot'     => $obj->getWithTemplate('^ca_objects.date_depot', ['dateFormat' => 'delimited']),
		'categorie'      => $obj->getWithTemplate('^ca_objects.domaine_logement'),
		'type'           => $obj->getWithTemplate('^ca_objects.denomination'),
		'titre'          => $obj->get('ca_objects.preferred_labels.name'),
		'auteur'         => $obj->getWithTemplate('<unit relativeTo="ca_entities" restrictToRelationshipTypes="creation_auteur">^ca_entities.preferred_labels.displayname</unit>'),
		'style'          => $obj->getWithTemplate('^ca_objects.style'),
		'dimensions'     => $vs_dim,
		'quantite'       => $obj->getWithTemplate('^ca_objects.appartenances_lot.lot_quantite'),
		'valeur_assurance' => '',
		'site'           => $obj->getWithTemplate('^ca_objects.site.site_nom1'),
		'adresse'        => $obj->getWithTemplate('^ca_objects.site.site_adresse1'),
		'batiment'       => $obj->getWithTemplate('^ca_objects.site.site_batiment1'),
		'etage'          => $obj->getWithTemplate('^ca_objects.site.site_etage'),
		'piece'          => $obj->getWithTemplate('^ca_objects.site.site_piece'),
		'situation'      => $obj->getWithTemplate('^ca_objects.inventaire_cont.inv_site > ^ca_objects.inventaire_cont.inv_etage > ^ca_objects.inventaire_cont.inv_piece'),
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

function dateToJulian($ps_date) {
	if (preg_match('!^(\d{1,2})/(\d{1,2})/(\d{4})$!', $ps_date, $m)) {
		return gregoriantojd((int)$m[2], (int)$m[1], (int)$m[3]);
	}
	if (preg_match('!^(\d{4})-(\d{1,2})-(\d{1,2})$!', $ps_date, $m)) {
		return gregoriantojd((int)$m[2], (int)$m[3], (int)$m[1]);
	}
	return 0;
}

function renderPDFHTML($fiches, $fiches_grouped, $titre, $group_by_site, $group_by_deposant) {
	ob_start();
?>
<html>
<head>
<style>
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
</style>
</head>
<body>
<?php
	$GLOBALS['_chapter_meta'] = [];
	$GLOBALS['_page_counter'] = 0;

	if (($group_by_site || $group_by_deposant) && is_array($fiches_grouped)) {
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

function renderFichePDF($f) {
?>
<div class="page">
	<div class="main-title">
		FICHE ŒUVRE N° <?= htmlspecialchars($f['idno']) ?>
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
			<td class="field-label">N° inv. déposant</td>
			<td class="field-value"><?= htmlspecialchars($f['idno']) ?></td>
			<td class="field-label">Valeur assurance (€)</td>
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
	<table class="fields">
		<tr><td class="field-label">Date d'inventaire</td><td class="field-value" colspan="3"><?= htmlspecialchars($f['inv_date']) ?></td></tr>
		<tr><td class="field-label">Constat présence</td><td class="field-value" colspan="3"><?= htmlspecialchars($f['inv_constat']) ?></td></tr>
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
