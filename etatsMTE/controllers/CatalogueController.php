<?php
/* ----------------------------------------------------------------------
 * plugins/etatsMTE/controllers/CatalogueController.php
 * ----------------------------------------------------------------------
 * Génération des catalogues MTE (standards et spécifiques)
 * Une fiche par objet, format PDF via le moteur de rendu CA
 * ----------------------------------------------------------------------
 */

require_once(__CA_MODELS_DIR__.'/ca_objects.php');
require_once(__CA_MODELS_DIR__.'/ca_entities.php');
require_once(__CA_MODELS_DIR__.'/ca_lists.php');
require_once(__CA_MODELS_DIR__.'/ca_object_representations.php');
require_once(__CA_MODELS_DIR__.'/ca_locales.php');

error_reporting(E_ERROR);

class CatalogueController extends ActionController {
	# -------------------------------------------------------
	protected $opo_config;
	protected $ops_plugin_name;
	protected $ops_plugin_path;

	// List IDs
	const LIST_DOMAINE   = 157;  // domaine_logement (Objet/Mobilier/…)
	const LIST_SITE      = 160;  // site_nom1
	const LIST_BATIMENT  = 164;  // site_batiment1
	const LIST_ETAGE     = 163;  // site_etage
	const LIST_CONSTAT   = 114;  // inv_constat (Vu/Non vu/Manquant/Détruit)
	const LIST_RECOLEMENT = 155; // real_O_N (Oui/Non)

	// Relationship type IDs for ca_objects_x_entities
	const REL_DEPOSANT = 172;
	const REL_AUTEUR   = 164;

	# -------------------------------------------------------
	public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
		parent::__construct($po_request, $po_response, $pa_view_paths);

		$this->ops_plugin_name = "etatsMTE";
		$this->ops_plugin_path = __CA_APP_DIR__."/plugins/".$this->ops_plugin_name;

		$vs_conf_file = $this->ops_plugin_path."/conf/".$this->ops_plugin_name.".conf";
		if(is_file($vs_conf_file)) {
			$this->opo_config = Configuration::load($vs_conf_file);
		}
	}

	# -------------------------------------------------------
	# Index — redirige vers Standards par défaut
	# -------------------------------------------------------
	public function Index() {
		return $this->Standards();
	}

	# -------------------------------------------------------
	# Standards — catalogues standards
	# -------------------------------------------------------
	public function Standards() {
		$this->_loadFilterLists();
		$this->render('catalogue_standards_html.php');
	}

	# -------------------------------------------------------
	# Specifiques — catalogues spécifiques
	# -------------------------------------------------------
	public function Specifiques() {
		$this->_loadFilterLists();
		$this->render('catalogue_specifiques_html.php');
	}

	# -------------------------------------------------------
	private function _loadFilterLists() {
		$this->view->setVar('deposants', $this->_getDeposants());
		$this->view->setVar('sites', $this->_getListItems(self::LIST_SITE));
		$this->view->setVar('batiments', $this->_getListItems(self::LIST_BATIMENT));
		$this->view->setVar('etages', $this->_getListItems(self::LIST_ETAGE));
		$this->view->setVar('types', $this->_getListItems(self::LIST_DOMAINE));
		$this->view->setVar('constats', $this->_getListItems(self::LIST_CONSTAT));
	}

	# -------------------------------------------------------
	# Generate — génération du catalogue PDF
	# -------------------------------------------------------
	public function Generate() {
		$vs_catalogue_type = $this->getRequest()->getParameter("catalogue_type", pString);
		$vn_deposant_id    = $this->getRequest()->getParameter("deposant", pInteger);
		$vn_site_id        = $this->getRequest()->getParameter("site", pInteger);
		$vn_batiment_id    = $this->getRequest()->getParameter("batiment", pInteger);
		$vn_etage_id       = $this->getRequest()->getParameter("etage", pInteger);
		$vn_type_id        = $this->getRequest()->getParameter("type_domaine", pInteger);
		$vn_constat_id     = $this->getRequest()->getParameter("constat", pInteger);
		$vs_date_debut     = $this->getRequest()->getParameter("date_debut", pString);
		$vs_date_fin       = $this->getRequest()->getParameter("date_fin", pString);

		// Construction de la requête SQL
		$va_wheres = ["o.deleted = 0"];
		$va_joins = [];
		$va_params = [];
		$vs_titre = "Catalogue";
		$vs_group_by_deposant = false;
		$vs_group_by_site = false;

		switch($vs_catalogue_type) {
			// ======= CATALOGUES STANDARDS =======
			case 'deposant_tous_sites':
				$vs_titre = "Catalogue par déposant – tous sites";
				if ($vn_deposant_id) {
					$va_joins[] = "JOIN ca_objects_x_entities oxe ON o.object_id = oxe.object_id AND oxe.type_id = ".self::REL_DEPOSANT;
					$va_wheres[] = "oxe.entity_id = ?";
					$va_params[] = $vn_deposant_id;
				}
				break;

			case 'deposant_par_site':
				$vs_titre = "Catalogue par déposant par site";
				if ($vn_deposant_id) {
					$va_joins[] = "JOIN ca_objects_x_entities oxe ON o.object_id = oxe.object_id AND oxe.type_id = ".self::REL_DEPOSANT;
					$va_wheres[] = "oxe.entity_id = ?";
					$va_params[] = $vn_deposant_id;
				}
				if ($vn_site_id) {
					$va_joins[] = "JOIN ca_attributes a_site ON o.object_id = a_site.row_id AND a_site.table_num = 57";
					$va_joins[] = "JOIN ca_attribute_values av_site ON a_site.attribute_id = av_site.attribute_id AND av_site.element_id = 709";
					$va_wheres[] = "av_site.item_id = ?";
					$va_params[] = $vn_site_id;
				}
				break;

			case 'biens_disparus':
				$vs_titre = "Catalogue des biens disparus";
				// Constat = "Manquant" (548) ou "Détruit" (549)
				$va_joins[] = "JOIN ca_attributes a_inv ON o.object_id = a_inv.row_id AND a_inv.table_num = 57";
				$va_joins[] = "JOIN ca_attribute_values av_inv ON a_inv.attribute_id = av_inv.attribute_id AND av_inv.element_id = 776";
				$va_wheres[] = "av_inv.item_id IN (548, 549)";
				if ($vn_deposant_id) {
					$va_joins[] = "JOIN ca_objects_x_entities oxe ON o.object_id = oxe.object_id AND oxe.type_id = ".self::REL_DEPOSANT;
					$va_wheres[] = "oxe.entity_id = ?";
					$va_params[] = $vn_deposant_id;
				}
				break;

			case 'mte_objets_par_site':
				$vs_titre = "Catalogue MTE des objets par site";
				if ($vn_site_id) {
					$va_joins[] = "JOIN ca_attributes a_site ON o.object_id = a_site.row_id AND a_site.table_num = 57";
					$va_joins[] = "JOIN ca_attribute_values av_site ON a_site.attribute_id = av_site.attribute_id AND av_site.element_id = 709";
					$va_wheres[] = "av_site.item_id = ?";
					$va_params[] = $vn_site_id;
				}
				break;

			case 'mte_mobiliers_par_site':
				$vs_titre = "Catalogue MTE des mobiliers par site";
				if ($vn_site_id) {
					$va_joins[] = "JOIN ca_attributes a_site ON o.object_id = a_site.row_id AND a_site.table_num = 57";
					$va_joins[] = "JOIN ca_attribute_values av_site ON a_site.attribute_id = av_site.attribute_id AND av_site.element_id = 709";
					$va_wheres[] = "av_site.item_id = ?";
					$va_params[] = $vn_site_id;
				}
				break;

			// ======= CATALOGUES SPÉCIFIQUES =======
			case 'specifique_deposant_site_batiment_etage':
				$vs_titre = "Catalogue par déposant + site + bâtiment + étage";
				if ($vn_deposant_id) {
					$va_joins[] = "JOIN ca_objects_x_entities oxe ON o.object_id = oxe.object_id AND oxe.type_id = ".self::REL_DEPOSANT;
					$va_wheres[] = "oxe.entity_id = ?";
					$va_params[] = $vn_deposant_id;
				}
				if ($vn_site_id) {
					$va_joins[] = "JOIN ca_attributes a_site ON o.object_id = a_site.row_id AND a_site.table_num = 57";
					$va_joins[] = "JOIN ca_attribute_values av_site ON a_site.attribute_id = av_site.attribute_id AND av_site.element_id = 709";
					$va_wheres[] = "av_site.item_id = ?";
					$va_params[] = $vn_site_id;
				}
				if ($vn_batiment_id) {
					$va_joins[] = "JOIN ca_attributes a_bat ON o.object_id = a_bat.row_id AND a_bat.table_num = 57";
					$va_joins[] = "JOIN ca_attribute_values av_bat ON a_bat.attribute_id = av_bat.attribute_id AND av_bat.element_id = 800";
					$va_wheres[] = "av_bat.item_id = ?";
					$va_params[] = $vn_batiment_id;
				}
				if ($vn_etage_id) {
					$va_joins[] = "JOIN ca_attributes a_et ON o.object_id = a_et.row_id AND a_et.table_num = 57";
					$va_joins[] = "JOIN ca_attribute_values av_et ON a_et.attribute_id = av_et.attribute_id AND av_et.element_id = 712";
					$va_wheres[] = "av_et.item_id = ?";
					$va_params[] = $vn_etage_id;
				}
				break;

			case 'specifique_par_type':
				$vs_titre = "Catalogue par type";
				if ($vn_type_id) {
					$va_joins[] = "JOIN ca_attributes a_dom ON o.object_id = a_dom.row_id AND a_dom.table_num = 57";
					$va_joins[] = "JOIN ca_attribute_values av_dom ON a_dom.attribute_id = av_dom.attribute_id AND av_dom.element_id = (SELECT element_id FROM ca_metadata_elements WHERE element_code='domaine_logement')";
					$va_wheres[] = "av_dom.item_id = ?";
					$va_params[] = $vn_type_id;
				}
				$vs_group_by_site = true;
				$vs_group_by_deposant = true;
				break;

			case 'specifique_vu_non_vu':
				$vs_titre = "Catalogue des biens VU/NON VU";
				if ($vn_constat_id) {
					$va_joins[] = "JOIN ca_attributes a_cst ON o.object_id = a_cst.row_id AND a_cst.table_num = 57";
					$va_joins[] = "JOIN ca_attribute_values av_cst ON a_cst.attribute_id = av_cst.attribute_id AND av_cst.element_id = 776";
					$va_wheres[] = "av_cst.item_id = ?";
					$va_params[] = $vn_constat_id;
				}
				$vs_group_by_site = true;
				$vs_group_by_deposant = true;
				break;

			case 'specifique_recoles_periode':
				$vs_titre = "Catalogue des biens récolés sur une période";
				$va_joins[] = "JOIN ca_attributes a_rec ON o.object_id = a_rec.row_id AND a_rec.table_num = 57";
				$va_joins[] = "JOIN ca_attribute_values av_rec ON a_rec.attribute_id = av_rec.attribute_id AND av_rec.element_id = 659";
				if ($vs_date_debut) {
					$va_wheres[] = "av_rec.value_decimal1 >= ?";
					$va_params[] = $this->_dateToJulian($vs_date_debut);
				}
				if ($vs_date_fin) {
					$va_wheres[] = "av_rec.value_decimal1 <= ?";
					$va_params[] = $this->_dateToJulian($vs_date_fin);
				}
				if ($vn_deposant_id) {
					$va_joins[] = "JOIN ca_objects_x_entities oxe ON o.object_id = oxe.object_id AND oxe.type_id = ".self::REL_DEPOSANT;
					$va_wheres[] = "oxe.entity_id = ?";
					$va_params[] = $vn_deposant_id;
				} else {
					$vs_group_by_deposant = true;
				}
				$vs_group_by_site = true;
				break;

			case 'specifique_inventories_periode':
				$vs_titre = "Catalogue des biens inventoriés sur une période";
				$va_joins[] = "JOIN ca_attributes a_invent ON o.object_id = a_invent.row_id AND a_invent.table_num = 57";
				$va_joins[] = "JOIN ca_attribute_values av_invent ON a_invent.attribute_id = av_invent.attribute_id AND av_invent.element_id = 775";
				if ($vs_date_debut) {
					$va_wheres[] = "av_invent.value_decimal1 >= ?";
					$va_params[] = $this->_dateToJulian($vs_date_debut);
				}
				if ($vs_date_fin) {
					$va_wheres[] = "av_invent.value_decimal1 <= ?";
					$va_params[] = $this->_dateToJulian($vs_date_fin);
				}
				if ($vn_deposant_id) {
					$va_joins[] = "JOIN ca_objects_x_entities oxe ON o.object_id = oxe.object_id AND oxe.type_id = ".self::REL_DEPOSANT;
					$va_wheres[] = "oxe.entity_id = ?";
					$va_params[] = $vn_deposant_id;
				} else {
					$vs_group_by_deposant = true;
				}
				$vs_group_by_site = true;
				break;

			default:
				$this->view->setVar("message", "Type de catalogue inconnu.");
				return $this->render("error_html.php");
		}

		// Exécution de la requête
		$vs_sql = "SELECT DISTINCT o.object_id FROM ca_objects o " . implode(" ", $va_joins) . " WHERE " . implode(" AND ", $va_wheres) . " ORDER BY o.idno";
		$o_db = new Db();
		$qr_result = $o_db->query($vs_sql, $va_params);

		$va_object_ids = [];
		while($qr_result->nextRow()) {
			$va_object_ids[] = $qr_result->get("object_id");
		}

		if (empty($va_object_ids)) {
			$this->view->setVar("message", "Aucun objet trouvé pour les critères sélectionnés.");
			return $this->render("error_html.php");
		}

		// Charger les objets et préparer les fiches
		$va_fiches = [];
		foreach ($va_object_ids as $vn_object_id) {
			$va_fiches[] = $this->_buildFicheObjet($vn_object_id);
		}

		// Regroupement si nécessaire
		if ($vs_group_by_site || $vs_group_by_deposant) {
			$va_fiches_grouped = [];
			foreach ($va_fiches as $fiche) {
				$group_key = "";
				if ($vs_group_by_site) {
					$group_key .= ($fiche['site'] ?: 'Sans site');
				}
				if ($vs_group_by_deposant) {
					$group_key .= ($group_key ? ' — ' : '') . ($fiche['deposant'] ?: 'Sans déposant');
				}
				$va_fiches_grouped[$group_key][] = $fiche;
			}
			$this->view->setVar('fiches_grouped', $va_fiches_grouped);
		}

		$this->view->setVar('fiches', $va_fiches);
		$this->view->setVar('titre', $vs_titre);
		$this->view->setVar('group_by_site', $vs_group_by_site);
		$this->view->setVar('group_by_deposant', $vs_group_by_deposant);
		$this->view->setVar('nb_objets', count($va_object_ids));

		$vs_output = $this->getRequest()->getParameter("output", pString);
		if ($vs_output === 'pdf') {
			return $this->_renderPDF();
		}

		$this->render('catalogue_results_html.php');
	}

	# -------------------------------------------------------
	# Private helper: construire une fiche objet
	# -------------------------------------------------------
	private function _buildFicheObjet($pn_object_id) {
		$obj = new ca_objects($pn_object_id);

		// Dimensions formatées
		$vs_dim = $obj->getWithTemplate(
			"^ca_objects.dimensions.dimensions_height" .
			"<ifdef code='ca_objects.dimensions.dimensions_height'> (h) x </ifdef>" .
			"^ca_objects.dimensions.dimensions_width" .
			"<ifdef code='ca_objects.dimensions.dimensions_width'> (l) x </ifdef>" .
			"^ca_objects.dimensions.dimensions_depth" .
			"<ifdef code='ca_objects.dimensions.dimensions_depth'> (p)</ifdef>" .
			" ^ca_objects.dimensions.type_dimensions"
		);

		// Photo
		$va_reps = $obj->getRepresentations(['medium', 'thumbnail']);
		$vs_photo_tag = '';
		if (!empty($va_reps)) {
			$rep = reset($va_reps);
			$vs_photo_tag = isset($rep['tags']['medium']) ? $rep['tags']['medium'] : (isset($rep['tags']['thumbnail']) ? $rep['tags']['thumbnail'] : '');
			$vs_photo_tag = preg_replace('/width="\d+"/', 'width="125"', $vs_photo_tag);
			$vs_photo_tag = preg_replace('/height="\d+"/', '', $vs_photo_tag);
		}

		// Photo path (for PDF)
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
			'photo_tag'      => $vs_photo_tag,
			'photo_path'     => $vs_photo_path,
			'deposant'       => $obj->getWithTemplate('<unit relativeTo="ca_entities" restrictToRelationshipTypes="depositaire">^ca_entities.preferred_labels.displayname</unit>'),
			'numero_depot'   => $obj->get('ca_objects.numero_depot'),
			'date_depot'     => $obj->get('ca_objects.date_depot'),
			'categorie'      => $obj->get('ca_objects.domaine_logement'),
			'type'           => $obj->get('ca_objects.denomination'),
			'titre'          => $obj->get('ca_objects.preferred_labels.name'),
			'auteur'         => $obj->getWithTemplate('<unit relativeTo="ca_entities" restrictToRelationshipTypes="creation_auteur">^ca_entities.preferred_labels.displayname</unit>'),
			'style'          => $obj->get('ca_objects.style'),
			'dimensions'     => $vs_dim,
			'quantite'       => $obj->getWithTemplate('^ca_objects.appartenances_lot.lot_quantite'),
			'valeur_assurance' => '',
			'site'           => $obj->getWithTemplate('^ca_objects.site.site_nom1'),
			'adresse'        => $obj->getWithTemplate('^ca_objects.site.site_adresse1'),
			'batiment'       => $obj->getWithTemplate('^ca_objects.site.site_batiment1'),
			'etage'          => $obj->getWithTemplate('^ca_objects.site.site_etage'),
			'piece'          => $obj->getWithTemplate('^ca_objects.site.site_piece'),
			'situation'      => $obj->getWithTemplate('^ca_objects.inventaire_cont.inv_site > ^ca_objects.inventaire_cont.inv_etage > ^ca_objects.inventaire_cont.inv_piece'),
			'inv_date'       => $obj->getWithTemplate('^ca_objects.inventaire_cont.inv_date'),
			'inv_constat'    => $obj->getWithTemplate('^ca_objects.inventaire_cont.inv_constat'),
			'inv_observations' => $obj->getWithTemplate('^ca_objects.inventaire_cont.inv_comm_disparition'),
			'recol_date'     => $obj->getWithTemplate('^ca_objects.recolement_inv.der_date_reco'),
			'recol_fait'     => $obj->getWithTemplate('^ca_objects.recolement_inv.real_O_N'),
		];
	}

	# -------------------------------------------------------
	# Private: rendu PDF
	# -------------------------------------------------------
	private function _renderPDF() {
		$va_template_info = [
			"name"            => "Catalogue MTE",
			"type"            => "page",
			"pageSize"        => "A4",
			"pageOrientation" => "portrait",
			"marginLeft"      => "1.5cm",
			"marginRight"     => "1.5cm",
			"marginTop"       => "1.5cm",
			"marginBottom"    => "1cm",
		];

		try {
			$vs_base_path = $this->ops_plugin_path.'/views';
			$this->view->addViewPath([$vs_base_path]);

			$o_pdf = new PDFRenderer();
			$this->view->setVar('PDFRenderer', $o_pdf->getCurrentRendererCode());

			$vs_content = $this->render($this->ops_plugin_path.'/views/catalogue_pdf.php');

			$o_pdf->setPage(
				caGetOption('pageSize', $va_template_info, 'A4'),
				caGetOption('pageOrientation', $va_template_info, 'portrait'),
				caGetOption('marginTop', $va_template_info, '1.5cm'),
				caGetOption('marginRight', $va_template_info, '1.5cm'),
				caGetOption('marginBottom', $va_template_info, '1cm'),
				caGetOption('marginLeft', $va_template_info, '1.5cm')
			);

			$o_pdf->render($vs_content, [
				'stream'   => true,
				'filename' => 'catalogue_mte.pdf'
			]);

			exit;
		} catch (Exception $e) {
			$this->view->setVar("message", "Erreur lors de la génération du PDF : " . $e->getMessage());
			return $this->render("error_html.php");
		}
	}

	# -------------------------------------------------------
	# Private helpers
	# -------------------------------------------------------
	private function _getDeposants() {
		$o_db = new Db();
		$qr = $o_db->query(
			"SELECT DISTINCT e.entity_id, el.displayname
			 FROM ca_objects_x_entities oxe
			 JOIN ca_entities e ON oxe.entity_id = e.entity_id
			 JOIN ca_entity_labels el ON e.entity_id = el.entity_id
			 WHERE oxe.type_id = ? AND el.is_preferred = 1 AND e.deleted = 0
			 ORDER BY el.displayname",
			self::REL_DEPOSANT
		);
		$va_items = [];
		while($qr->nextRow()) {
			$va_items[$qr->get("entity_id")] = $qr->get("displayname");
		}
		return $va_items;
	}

	private function _getListItems($pn_list_id) {
		$o_db = new Db();
		$qr = $o_db->query(
			"SELECT li.item_id, ll.name_singular
			 FROM ca_list_items li
			 JOIN ca_list_item_labels ll ON li.item_id = ll.item_id
			 WHERE li.list_id = ? AND li.parent_id IS NOT NULL AND ll.is_preferred = 1 AND li.is_enabled = 1
			 ORDER BY ll.name_singular",
			$pn_list_id
		);
		$va_items = [];
		while($qr->nextRow()) {
			$va_items[$qr->get("item_id")] = $qr->get("name_singular");
		}
		return $va_items;
	}

	private function _dateToJulian($ps_date) {
		// Convert dd/mm/yyyy or yyyy-mm-dd to Julian day number for CA date storage
		if (preg_match('!^(\d{1,2})/(\d{1,2})/(\d{4})$!', $ps_date, $va_matches)) {
			return gregoriantojd((int)$va_matches[2], (int)$va_matches[1], (int)$va_matches[3]);
		}
		if (preg_match('!^(\d{4})-(\d{1,2})-(\d{1,2})$!', $ps_date, $va_matches)) {
			return gregoriantojd((int)$va_matches[2], (int)$va_matches[3], (int)$va_matches[1]);
		}
		return 0;
	}
}
?>
