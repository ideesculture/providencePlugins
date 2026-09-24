<?php
/* ----------------------------------------------------------------------
 * plugins/statisticsViewer/controllers/StatisticsController.php :
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2010 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * ----------------------------------------------------------------------
 */


// require_once(__CA_LIB_DIR__.'TaskQueue.php');
// require_once(__CA_LIB_DIR__.'Configuration.php');
require_once(__CA_LIB_DIR__ . '/Search/PlaceSearch.php');
require_once(__CA_LIB_DIR__ . '/Search/EntitySearch.php');
require_once(__CA_APP_DIR__ . "/plugins/SGA/lib/migration_functionlib.php");

// require_once(__CA_LIB_DIR__.'Browse/CollectionBrowse.php');
// require_once(__CA_LIB_DIR__.'Search/ObjectSearch.php');
// require_once(__CA_LIB_DIR__.'Browse/ObjectBrowse.php');
// require_once(__CA_MODELS_DIR__.'/ca_lists.php');
// require_once(__CA_MODELS_DIR__.'/ca_objects.php');
// require_once(__CA_MODELS_DIR__.'/ca_collections.php');
// require_once(__CA_MODELS_DIR__.'/ca_object_representations.php');
// require_once(__CA_MODELS_DIR__.'/ca_locales.php');

// require_once(__CA_LIB_DIR__.'ResultContext.php');

error_reporting(E_ERROR);
ini_set("display_errors", 1);

class SGAController extends ActionController
{
	# -------------------------------------------------------
	protected $opo_config,		// plugin configuration file
		$ops_plugin_name, $ops_plugin_path,
		$ops_user_groups, $opo_result_context;


	# -------------------------------------------------------
	# Constructor
	# -------------------------------------------------------

	/**
	 * Direction Inrap de SGA -> identifiant de l'entite direction dans Comodo. 24/09/2026 GM (ticket 8043) :
	 * une seule table pour Compare, Importer et Update (Compare portait « Direction régionale
	 * Auvergne-Rhône-Alpes » alors que SGA ecrit « Auvergne-Rhône-Alpes ») ; ajout de « Nouvelle Aquitaine
	 * et Outre Mer », libelle SGA de 10 332 operations qui n'etait pas reconnu (direction jamais ecrite).
	 */
	const DIRECTIONS = array("Centre Ile de France" => "DIR CIF", "Grand Ouest" => "Inrap DIR GO", "Grand Est" => "DIR GE",
		"Auvergne-Rhône-Alpes" => "DIR ARA", "Direction régionale Auvergne-Rhône-Alpes" => "DIR ARA", "Hauts-de-France" => "DIR HDF",
		"Midi-Méditerranée" => "DIR MIDIMED", "Outre-mer" => "DIR NAOM", "Nouvelle Aquitaine" => "DIR NAOM",
		"Nouvelle Aquitaine et Outre Mer" => "DIR NAOM", "Bourgogne-Franche-Comté" => "DIR BFC");

	public function __construct(&$po_request, &$po_response, $pa_view_paths = null)
	{
		global $allowed_universes;

		parent::__construct($po_request, $po_response, $pa_view_paths);

		$this->ops_plugin_name = "sga";
		$this->ops_plugin_path = __CA_APP_DIR__ . "/plugins/" . $this->ops_plugin_name;

		$vs_conf_file = $this->ops_plugin_path . "/conf/" . $this->ops_plugin_name . ".conf";
		if (is_file($vs_conf_file)) {
			$this->opo_config = Configuration::load($vs_conf_file);
		}

		$va_groups = $this->getRequest()->getUser()->getUserGroups();
		$this->ops_user_groups = [];
		foreach ($va_groups as $group) {
			if (in_array($group["code"], ["gestion", "admin"])) continue;
			$this->ops_user_groups[] = $group["code"];
		}
	}

	# -------------------------------------------------------
	# Functions to render views
	# -------------------------------------------------------

	public function Compare()
	{
		$o_data = new Db();
		$notInBase = false;
		$value = self::DIRECTIONS;   // 24/09/2026 (ticket 8043) : table commune a Compare, Importer et Update
		$different = [];
		$op_id = $this->getRequest()->getParameter("id", pInteger);
		$type = $this->getRequest()->getParameter("type", pString);

		if (!$type) {
			$qr_result = $o_data->query("SELECT * FROM _sga_comodo WHERE id = " . $op_id . "");
			while ($qr_result->nextRow()) {
				$col = new ca_collections();
				// 24/09/2026 (ticket 8043) : meme recherche que l'Importer (numero sans espaces, type 125 d'abord).
				if ($vn_ex = $this->_operationPourIdno($o_data, $qr_result->get("idno"))) { $col->load($vn_ex); }
				break;
			}
		} else {
			$col = new ca_collections($op_id);
			$qr_result = $o_data->query("SELECT * FROM _sga_comodo WHERE idno = ?", array(trim((string)$col->get("ca_collections.idno"))));
		}

		if (!$col->getPrimaryKey()) {
			$notInBase = true;
		} else {
			$op_id = $col->getPrimaryKey();
		}
		$result = $qr_result->getAllRows()[0];
		foreach ($result as $metadata => $data) {
			if ($metadata == "id" || $metadata == "ro_label" || empty($data)) {
				continue;
			}
			$data_to_compare = $col->getWithTemplate("^ca_collections." . $metadata);
			if ($metadata == "inrap_date_planification") {
				$data_to_compare = $col->getWithTemplate("^ca_collections.inrap_date_versement." . $metadata);
			}
			
			if ($metadata == "surface_OA" && (empty($data) && $data_to_compare == 0) && (empty($data_to_compare) && $data == 0)) {
				$data_to_compare = "";
				$data = "";
			}
			if ($metadata == "ro") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.idno</unit>");
			}
			if ($metadata == "sra") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='attribue' restrictToTypes='sra'>^ca_entities.idno</unit>");
			}
			
			if ($metadata == "dir_inrap") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='DIR'>^ca_entities.preferred_labels.displayname</unit>");
			}
			if ($metadata == "prescripteur") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='suivi_par'>^ca_entities.preferred_labels.displayname</unit>");
			}
			if ($metadata == "autorisation_fouille") {
				$data_to_compare = $col->getWithTemplate("^ca_collections.autorisation_fouille.autorisation_num");
			}
			if ($metadata == "autorisation_date") {
				$data_to_compare = $col->getWithTemplate("^ca_collections.autorisation_fouille.autorisation_date");
			}

			if ($metadata == "dir_adj_st") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='dast'>^ca_entities.preferred_labels.displayname</unit>");
			}
			if ($metadata == "commune") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_places'>^ca_places.preferred_labels.name</unit>");
			}

			if ($data_to_compare == ";") {
				$data_to_compare = "";
			}
			if ($metadata == "CodeINSEE") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_places'>^ca_places.idno</unit>");
			}
			if ($metadata == "AnneeDebutTerrain"){
				$data_to_compare =  $col->getWithTemplate("^ca_collections.inrap_annee_inter");
			}
			if ($metadata == "NomOpeRattachement"){
				$data_to_compare =  $col->getWithTemplate("^ca_collections.inrap_op_rattachement.inrap_op_rattachement_txt");
			}
			if ($metadata == "ro") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.displayname</unit>");
				$data = $result["ro_label"];
			}
			array_push($different, [$metadata, $data, $data_to_compare]);
		}

		// 24/09/2026 GM (ticket 8043) — ON PREFERE LE SGA : les champs dont la valeur SGA differe de
		// Comodo sont coches d'office. Sauf le DAST d'une operation ancienne qui en a deja un : SGA donne
		// le DAST ACTUEL de la region, pas celui de l'epoque ; la case reste decochee, avec un
		// avertissement, et le gestionnaire la coche s'il le veut. « Ancienne » : annee d'intervention
		// anterieure a l'annee en cours moins 2, ou inconnue (decision GM du 24/09/2026).
		$va_a_cocher = array(); $va_avertissements = array(); $va_avertissements_multi = array();
		if (!$notInBase) {
			// Annee sur 4 chiffres ou qu'elle soit (« 2018 », « 06/03/2018 »…).
			$vn_annee = preg_match('!(1[89]|20)\d\d!', (string)$col->getWithTemplate("^ca_collections.inrap_annee_inter"), $va_an) ? (int)$va_an[0] : 0;
			$vb_dast_en_place = (trim((string)$col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='dast'>^ca_entities.entity_id</unit>")) !== '');
			$vb_dast_protege = $vb_dast_en_place && (!$vn_annee || ($vn_annee < ((int)date('Y') - 2)));
			$vs_dir_comodo = trim((string)$col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='DIR'>^ca_entities.idno</unit>"));
			foreach ($different as $va_ligne) {
				list($vs_m, $vs_sga, $vs_comodo) = $va_ligne;
				// Direction : identique si la correspondance donne l'entite en place ; sans correspondance,
				// Update n'ecrirait rien, donc on ne coche pas.
				if ($vs_m === 'dir_inrap' && (!isset($value[trim((string)$vs_sga)]) || ($value[trim((string)$vs_sga)] === $vs_dir_comodo))) { continue; }
				if ($this->_valeursIdentiques($vs_m, $vs_sga, $vs_comodo)) { continue; }
				// Plusieurs valeurs en place (2 communes, 2 responsables…) : SGA n'en donne qu'une et Update
				// les remplacerait toutes. On ne coche pas d'office ; le gestionnaire decide.
				if (in_array($vs_m, array('commune', 'CodeINSEE', 'ro', 'prescripteur', 'dir_adj_st'), true)
				    && (count(array_filter(array_map('trim', explode(';', strip_tags((string)$vs_comodo))), 'strlen')) > 1)) {
					$va_avertissements_multi[$vs_m] = "Plusieurs valeurs dans Comodo : cocher cette case les remplace toutes par celle du SGA.";
					continue;
				}
				// Lieu-dit qui ne differe que par la casse ou les accents : on garde la graphie de Comodo.
				if (($vs_m === 'lieudit') && ($this->_sansCasseNiAccents($vs_sga) === $this->_sansCasseNiAccents($vs_comodo))) { continue; }
				if ($vs_m === 'dir_adj_st' && $vb_dast_protege) {
					$va_avertissements[$vs_m] = "SGA donne le DAST actuel de la région. L'opération étant ancienne ("
						. ($vn_annee ? "intervention " . $vn_annee : "année d'intervention inconnue")
						. "), le DAST de l'époque est conservé, sauf si vous cochez la case.";
					continue;
				}
				$va_a_cocher[$vs_m] = true;
			}
		}
		$this->view->setVar("a_cocher", $va_a_cocher);
		$this->view->setVar("avertissements", $va_avertissements);
		$this->view->setVar("avertissements_multi", $va_avertissements_multi);
		$this->view->setVar("value", $different);
		$this->view->setVar("notInBase", $notInBase);
		$this->view->setVar("id", $op_id);
		$this->render("compare_html.php");
	}

	/**
	 * Meme valeur, a la presentation pres : nombres (« 1752.00000000 » = « 1 752 ») ; pour les champs de
	 * personne SEULEMENT, noms a mots tries (« BESSON, Claire » = « Claire besson »). Pas pour les dates :
	 * « 01/02/2020 » et « 02/01/2020 » ont les memes mots.
	 */
	private function _sansCasseNiAccents($ps)
	{
		$vs = trim(strip_tags((string)$ps)); $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $vs); if ($t !== false) { $vs = $t; }
		return strtolower(preg_replace('/\s+/', ' ', $vs));
	}
	private function _valeursIdentiques($ps_champ, $ps_a, $ps_b)
	{
		$a = trim(strip_tags((string)$ps_a)); $b = trim(strip_tags((string)$ps_b));
		if ($a === $b) { return true; }
		// Comparaison numerique pour les seuls champs numeriques : « 12,30 » et « 12,3 » sont deux parcelles.
		if (in_array($ps_champ, array('surface_OA', 'AnneeDebutTerrain'), true)) {
			$na = str_replace(array(' ', "\xc2\xa0", ','), array('', '', '.'), $a);
			$nb = str_replace(array(' ', "\xc2\xa0", ','), array('', '', '.'), $b);
			if (is_numeric($na) && is_numeric($nb)) { return (float)$na == (float)$nb; }
		}
		if (!in_array($ps_champ, array('ro', 'prescripteur', 'dir_adj_st'), true)) { return false; }
		return ($a !== '') && ($b !== '') && (sga_cle_de_nom_triee($a) === sga_cle_de_nom_triee($b));
	}

	/** Verrou MySQL propre a une operation (meme nom pour Importer et Update). Rend son nom, ou null s'il n'est pas obtenu en 30 s. */
	private function _verrouiller($o_db, $ps_idno)
	{
		$vs = 'sga_importer_' . md5(trim((string)$ps_idno));
		$qr = $o_db->query("SELECT GET_LOCK(?, 30) AS v", array($vs));
		return ($qr && $qr->nextRow() && ((int)$qr->get('v') === 1)) ? $vs : null;
	}
	private function _liberer($o_db, $ps_verrou)
	{
		if ($ps_verrou) { $o_db->query("SELECT RELEASE_LOCK(?)", array($ps_verrou)); }
	}
	/** Valeur en place d'un sous-element de conteneur, lue AVANT removeAttributes pour ne pas perdre la partie non mise a jour. */
	private function _valeurEnPlace($t_col, $ps_chemin)
	{
		// Premiere valeur non vide, item_id pour une liste : un conteneur en double rendait sinon
		// « Loi 2003;Loi 2003 », refuse sans message par addAttribute (relecture externe du 24/09/2026).
		$va = $t_col->get('ca_collections.' . $ps_chemin, array('returnAsArray' => true, 'alwaysReturnItemID' => true));
		foreach ((is_array($va) ? $va : array($va)) as $v) { $v = trim((string)$v); if ($v !== '') { return $v; } }
		return '';
	}
	/** Operation de Comodo pour un numero SGA : numero sans espaces, type « opération » (125) d'abord. Meme regle que l'Importer. */
	private function _operationPourIdno($o_db, $ps_idno)
	{
		$qr = $o_db->query("SELECT collection_id FROM ca_collections WHERE deleted = 0 AND TRIM(idno) = ? ORDER BY (type_id = 125) DESC, collection_id LIMIT 1", array(trim((string)$ps_idno)));
		return ($qr && $qr->nextRow()) ? (int)$qr->get('collection_id') : null;
	}

	public function IndexImporte($type = "")
	{
		$this->render('index_importe_html.php');
	}
	public function IndexNonImporte($type = "")
	{
		$this->render('index_non_import_html.php');
	}

	/**
	 * 24/09/2026 GM (ticket 8043) — IMPORT SGA SUSPENDU tant que « enabled = 0 » dans conf/sga.conf.
	 * L'import creait une nouvelle fiche de personne a chaque clic quand la recherche ne trouvait
	 * pas la personne (44 doublons le 24/09 au matin). Le temps de nettoyer les donnees et de
	 * corriger l'import, les deux actions qui ecrivent (Importer, Update) refusent d'agir.
	 * enabled = 0 masque aussi le menu et le lien de la fiche ; mais le routeur de CollectiveAccess
	 * atteint les controleurs d'un greffon meme desactive (une page deja ouverte, un favori) :
	 * d'ou ce garde-fou. Le fichier est lu par son vrai chemin : le constructeur cherche
	 * plugins/sga/ en minuscules et ne le trouve jamais.
	 * Pour reactiver : enabled = 1 dans conf/sga.conf.
	 */
	private function _importSuspendu($ps_retour)
	{
		$vs_conf = dirname(__DIR__) . '/conf/sga.conf';
		if (is_file($vs_conf) && (int)Configuration::load($vs_conf)->get('enabled')) { return false; }
		$o_n = new NotificationManager($this->getRequest());
		$o_n->addNotification("L'import depuis SGA est suspendu temporairement pour maintenance. Aucune donnee n'a ete modifiee.", __NOTIFICATION_TYPE_WARNING__);
		$this->getResponse()->setRedirect($ps_retour);
		return true;
	}

	public function Importer()
	{
		$op_id = $this->getRequest()->getParameter("id", pInteger);
		if ($this->_importSuspendu(caNavUrl($this->getRequest(), 'SGA', 'SGA', 'IndexNonImporte'))) { return; }
		$o_data = new Db();
		$qr_result = $o_data->query("SELECT * FROM _sga_comodo WHERE id = " . $op_id . "");
		$vs_verrou = null;
		while ($qr_result->nextRow()) {

			// 24/09/2026 GM (ticket 8043) — PAS DE SECONDE OPERATION AU MEME NUMERO. « Importer » est un
			// simple lien : un double clic, ou un retour arriere suivi d'un rechargement, lancait deux
			// requetes qui ne trouvaient ni l'une ni l'autre l'operation et la creaient chacune (paires
			// creees a quelques secondes d'intervalle). Et load(['idno' => …]) ne tolerait pas l'espace
			// de fin d'un numero (« D110821 »). La recherche se fait desormais sur le numero sans espaces,
			// l'operation « opération » (125) d'abord, et sous un verrou MySQL propre a ce numero : la
			// seconde requete attend la premiere et retrouve l'operation qu'elle vient de creer.
			$vs_idno = trim((string)$qr_result->get("idno"));
			$vs_verrou = 'sga_importer_' . md5($vs_idno);
			$qr_v = $o_data->query("SELECT GET_LOCK(?, 30) AS v", array($vs_verrou));
			if (!$qr_v || !$qr_v->nextRow() || ((int)$qr_v->get('v') !== 1)) {
				$o_n = new NotificationManager($this->getRequest());
				$o_n->addNotification("L'import de l'opération " . htmlspecialchars($vs_idno, ENT_QUOTES, 'UTF-8') . " est déjà en cours. Réessayez dans un instant.", __NOTIFICATION_TYPE_WARNING__);
				$this->getResponse()->setRedirect(caNavUrl($this->getRequest(), 'SGA', 'SGA', 'IndexNonImporte'));
				return;
			}
			$col = new ca_collections();
			$qr_ex = $o_data->query("SELECT collection_id FROM ca_collections WHERE deleted = 0 AND TRIM(idno) = ? ORDER BY (type_id = 125) DESC, collection_id LIMIT 1", array($vs_idno));
			if ($qr_ex && $qr_ex->nextRow()) {
				// L'operation existe deja (retour arriere, double clic, idno a espaces…) : on ne reecrit pas tout
				// sans les cases a cocher ; on renvoie vers l'ecran de comparaison.
				$o_data->query("SELECT RELEASE_LOCK(?)", array($vs_verrou));
				$o_n = new NotificationManager($this->getRequest());
				$o_n->addNotification("L'opération " . htmlspecialchars($vs_idno, ENT_QUOTES, 'UTF-8') . " existe déjà dans Comodo : vérifiez les champs avant de la mettre à jour.", __NOTIFICATION_TYPE_INFO__);
				$this->getResponse()->setRedirect(caNavUrl($this->getRequest(), 'SGA', 'SGA', 'Compare', array('id' => (int)$qr_ex->get('collection_id'), 'type' => 'edit')));
				return;
			}
			if (!$col->getPrimaryKey()) {
				$col->setMode(ACCESS_WRITE);
				$col->set(array('access' => 2, "idno" => $vs_idno, "status" => 3, "type_id" => 125));
				$col->insert();
			}
			// Le verrou est garde jusqu'a la fin de l'action : deux requetes simultanees ecriraient sinon
			// les memes liens en meme temps (liens en double, constate en preprod le 24/09/2026).
			$col->setMode(ACCESS_WRITE);
			if ($qr_result->get("inrap_ancien_code")) {
				$col->removeAttributes("inrap_ancien_code");
				$col->addAttribute(array("inrap_ancien_code" => $qr_result->get("inrap_ancien_code")), "inrap_ancien_code");
			}
			//TODO : inrap_op_rattachement
			if ($qr_result->get("AnneeDebutTerrain")) {
				$col->removeAttributes("inrap_annee_inter");
				$col->addAttribute(["inrap_annee_inter" => $qr_result->get("AnneeDebutTerrain")], "inrap_annee_inter");
			}

			if ($qr_result->get("NomOpeRattachement")){
				$col->removeAttributes("inrap_op_rattachement");
				$col->addAttribute([ "inrap_op_rattachement_txt" =>$qr_result->get("NomOpeRattachement")], "inrap_op_rattachement");
			}

			if ($qr_result->get("inrap_type_op.inrap_type_ope")) {
				$col->removeAttributes("inrap_type_op", ["force"=>true]);
				$col->addAttribute(array("inrap_type_ope" => $qr_result->get("inrap_type_op.inrap_type_ope"), "inrap_axe_analytique" => $qr_result->get("inrap_type_op.inrap_axe_analytique")), "inrap_type_op");
			}

			if ($qr_result->get("oa_number")) {
				$col->removeAttributes("oa_number");
				$col->addAttribute(array("oa_number" => $qr_result->get("oa_number")), "oa_number");
			}

			if ($qr_result->get("ro")) {
				$name_info = explode(',', $qr_result->get("ro_label"));
				$entity_id = getEntityIDByIdno($name_info[1] ?? '', $name_info[0], $qr_result->get("ro"), 1665);
				// 10/09/2026 GM (ticket 7988) : ne rien reecrire si le nom n'a pas ete resolu.
				// Sans cette garde, une entite prise au hasard etait rattachee, et la relation
				// legitime deja en place etait detruite juste avant par removeRelationships().
				if ($entity_id) {
					$col->removeRelationships("ca_entities", 124);
					$col->addRelationship("ca_entities", $entity_id, 124);
				}
			}

			if ($qr_result->get("centre_op")) {
				$col->removeAttributes("centre_op");
				$col->addAttribute(array("centre_op" => $qr_result->get("centre_op")), "centre_op");
			}

			if ($qr_result->get("commune")) {

				if (!$qr_result->get("CodeINSEE")) {
					$commune = new PlaceSearch();
					$result = $commune->search("ca_places:" . $qr_result->get("commune"));
					while ($result->nextHit()) {
						$name = $result->get("ca_places.preferred_labels.name");
						if (strtolower($name) == strtolower($qr_result->get("commune"))) {
							$col->removeRelationships("ca_places", 235);
							$col->addRelationship("ca_places", $result->get("ca_places.place_id"), 235);
							break;
						}
					}
				} else {
					$vt_place = new ca_places();
					$vt_place->load(["idno" => $qr_result->get("CodeINSEE"), "deleted" => 0]);
					if ($vt_place->getPrimaryKey()){
						$col->removeRelationships("ca_places", 235);
						$col->addRelationship("ca_places", $vt_place->getPrimaryKey(), 235);
						$col->update();
					}
					$col->removeAttributes("code_insee");
					$col->addAttribute(["code_insee" => $qr_result->get("CodeINSEE")], "code_insee");
				}
			}
			if ($qr_result->get("sra")) {
				$sra = new ca_entities();
				$sra->load(["idno" => $qr_result->get("sra"), "deleted" => 0]);
				$sraID = $sra->getPrimaryKey();
				// 24/09/2026 (ticket 8043) : ne retirer le SRA en place que si celui de SGA est trouve.
				if ($sraID) {
					// Seuls les liens « attribue » vers des SRA sont remplaces : ceux vers des CCE restent.
					foreach (($col->getRelatedItems('ca_entities', array('restrictToRelationshipTypes' => array('attribue'), 'restrictToTypes' => array('sra'))) ?: array()) as $va_rel) {
						$col->removeRelationship('ca_entities', $va_rel['relation_id']);
					}
					$col->addRelationship("ca_entities", $sraID, 121);
				}
			}

			if ($qr_result->get("lieudit")) {
				$col->removeAttributes("lieudit");
				$col->addAttribute(array("lieudit" => $qr_result->get("lieudit")), "lieudit");
			}

			if ($qr_result->get("parcelle")) {
				$col->removeAttributes("parcelle");
				$col->addAttribute(array("parcelle" => $qr_result->get("parcelle")), "parcelle");
			}

			if ($qr_result->get("prescripteur")) {
				$name_info = explode(',', $qr_result->get("prescripteur"));
				// 04/09/2026 GM : le type d'entité manquait. getEntityID() le déclare obligatoire,
				// et les trois autres appels du fichier passent bien 1665 (personne). En PHP 7
				// l'oubli n'était qu'un avertissement ; depuis le passage en PHP 8.4 du 26/08,
				// c'est un ArgumentCountError fatal — l'import SGA mourait donc entièrement dès
				// qu'une opération portait un prescripteur, sans que la direction soit écrite.
				// Constaté deux fois le 01/09 à 09:07 au journal, sur /SGA/SGA/Compare/id/298606.
				// 23/09/2026 GM (ticket 8045) : getEntityIDByIdno() et non getEntityID().
				// La premiere cherche par identifiant PUIS par nom, et cree la fiche absente ;
				// la seconde se contente de chercher. SGA ne transmet pas d'identifiant pour le
				// prescripteur ni pour le DAST — d'ou la chaine vide, qui fait prendre le libelle
				// comme identifiant a la creation. Decision GM du 23/09/2026.
				$entity_id = getEntityIDByIdno($name_info[1] ?? '', $name_info[0], '', 1665);
				if ($entity_id != false){
					$col->removeRelationships("ca_entities", 122);
					$col->addRelationship("ca_entities", $entity_id, 122);
				}
				
			}

			if ($qr_result->get("numero_prescription")) {
				$col->removeAttributes("numero_prescription");
				$col->addAttribute(array("numero_prescription" => $qr_result->get("numero_prescription")), "numero_prescription");
			}

			if ($qr_result->get("date_simple")) {
				$col->removeAttributes("date_simple");
				$col->addAttribute(array("date_simple" => $qr_result->get("date_simple")), "date_simple");
			}

			if ($qr_result->get("surface_OA")) {
				$col->removeAttributes("surface_OA");
				$col->addAttribute(array("surface_OA" => floatval($qr_result->get("surface_OA"))), "surface_OA");
			}

			if ($qr_result->get("dir_inrap")) {
				$value = self::DIRECTIONS;   // 24/09/2026 (ticket 8043) : table commune a Compare, Importer et Update
				// 23/09/2026 GM (ticket 8045) — DEUX DEFAUTS ICI, ET LA DIRECTION NE S'ECRIVAIT JAMAIS.
				//
				// 1. Le guillemet ouvrant n'etait pas referme : la requete valait
				//    `ca_entities:"DIR MIDIMED` et rendait zero. Refermee, elle rend 6 fiches
				//    dont l'entite 26 « DIR MIDIMED » en tete, que la boucle retient.
				// 2. La table de correspondance etait interrogee sans verifier la cle. Une
				//    direction que SGA nomme autrement produisait un avertissement PHP 8 puis
				//    une recherche sur la chaine vide. On sort maintenant proprement.
				// Direction inconnue de la table de correspondance : on ne devine pas, on ne
				// touche a rien. Surtout pas de `break` ici — la boucle englobante est le
				// `while ($qr_result->nextRow())` ligne 193, en sortir abandonnerait tous les
				// champs suivants ET tous les enregistrements suivants.
				$vs_cible = $value[$qr_result->get("dir_inrap")] ?? null;
				if ($vs_cible !== null) {
					$entity = new EntitySearch();
					$result = $entity->search('ca_entities:"' . $vs_cible . '"');
					while ($result->nextHit()) {
						$name = $result->get("ca_entities.preferred_labels.displayname");
						if ($name == $vs_cible) {
							$col->removeRelationships("ca_entities", 236);
							$col->addRelationship("ca_entities", $result->get("ca_entities.entity_id"), 236);
							break;
						}
					}
				}
			}

			if ($qr_result->get("dir_adj_st")) {
				$name_info = explode(',', $qr_result->get("dir_adj_st"));
				$entity_id = getEntityIDByIdno($name_info[1] ?? '', $name_info[0], '', 1665);
				// 10/09/2026 GM (ticket 7988) : ne rien reecrire si le nom n'a pas ete resolu.
				// Sans cette garde, une entite prise au hasard etait rattachee, et la relation
				// legitime deja en place etait detruite juste avant par removeRelationships().
				if ($entity_id) {
					$col->removeRelationships("ca_entities", 242);
					$col->addRelationship("ca_entities", $entity_id, 242);
				}
			}

			if ($qr_result->get("datesdeterrain.Datedeterrain_date")) {
				$col->removeAttributes("datesdeterrain");
				$col->addAttribute(array("Datedeterrain_date" => $qr_result->get("datesdeterrain.Datedeterrain_date"), "datedeterrain_datefin" => $qr_result->get("datesdeterrain.datedeterrain_datefin")), "datesdeterrain");
			}

			if ($qr_result->get("autorisation_fouille") || $qr_result->get("autorisation_date") != 0) {
				$col->removeAttributes("autorisation_fouille");
				$col->addAttribute(array("autorisation_num" => $qr_result->get("autorisation_fouille"), "autorisation_date" => $qr_result->get("autorisation_date")), "autorisation_fouille");
			}

			if ($qr_result->get("inrap_date_planification")) {
				$col->removeAttributes("inrap_date_planification");
				$col->addAttribute(array("inrap_date_planification_date" => $qr_result->get("inrap_date_planification")), "inrap_date_planification");
			}

			if ($qr_result->get("date_prev_du_rapport") != 0) {
				$col->removeAttributes("date_prev_du_rapport");
				$col->addAttribute(array("date_prev_du_rapport" => $qr_result->get("date_prev_du_rapport")), "date_prev_du_rapport");
			}

			if ($qr_result->get("date_du_rapport") != 0) {
				$col->removeAttributes("date_du_rapport");
				$col->addAttribute(array("date_du_rapport" => $qr_result->get("date_du_rapport")), "date_du_rapport");
			}

			if ($col->numErrors()) {
				// 09/09/2026 (ticket 8008) : un var_dump suivi d'un die() vidait un tableau PHP brut
				// en pleine page et arretait le traitement sans un mot pour l'utilisateur. On journalise
				// et on affiche un message exploitable.
				$vs_err = join(' ; ', $col->getErrors());
				@error_log('[SGA] mise a jour operation #' . $col->getPrimaryKey() . ' : ' . $vs_err);
				if (class_exists('NotificationManager') && $this->getRequest()) {
					$o_n = new NotificationManager($this->getRequest());
					$o_n->addNotification("La mise a jour de cette operation a echoue : " . htmlspecialchars($vs_err, ENT_QUOTES, 'UTF-8')
						. " Les autres operations du lot ne sont pas affectees.", __NOTIFICATION_TYPE_ERROR__);
				}
				// on rend quand même la page : sans cela l'utilisateur reçoit un écran blanc,
				// ce qui n'était pas mieux que le vidage brut d'avant.
				if ($vs_verrou) { $o_data->query("SELECT RELEASE_LOCK(?)", array($vs_verrou)); }
				$this->render("import_html.php");
				return;
			}

			$col->update();

			$label = $col->getWithTemplate("^ca_places.preferred_labels.name / ^ca_collections.lieudit / ^ca_collections.inrap_annee_inter%trim=1 / <unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.displayname</unit>");
			$col->removeAllLabels();
			$col->update();
			$col->addLabel(array('name' => $label), 2, null, true);
			$col->update();
		}
		$different = [];
		$result = $qr_result->getAllRows()[0];
		foreach ($result as $metadata => $data) {
			if ($metadata == "id" || $metadata == "ro_label" || empty($data)) {
				continue;
			}
			$data_to_compare = $col->getWithTemplate("^ca_collections." . $metadata);
			if ($metadata == "inrap_date_planification") {
				$data_to_compare = $col->getWithTemplate("^ca_collections.inrap_date_versement." . $metadata);
			}
			if ($metadata == "surface_OA" && (empty($data) && $data_to_compare == 0) && (empty($data_to_compare) && $data == 0)) {
				$data_to_compare = "";
				$data = "";
			}
			if ($metadata == "ro") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.idno</unit>");
			}
			if ($metadata == "sra") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='attribue' restrictToTypes='sra'>^ca_entities.idno</unit>");
			}
			if ($metadata == "dir_inrap") {
				$value = array_flip($value);
				$data_to_compare = $value[$col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='DIR'>^ca_entities.preferred_labels.displayname</unit>")];
			}
			if ($metadata == "prescripteur") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='suivi_par'>^ca_entities.preferred_labels.displayname</unit>");
			}
			if ($metadata == "autorisation_fouille") {
				$data_to_compare = $col->getWithTemplate("^ca_collections.autorisation_fouille.autorisation_num");
			}
			if ($metadata == "autorisation_date") {
				$data_to_compare = $col->getWithTemplate("^ca_collections.autorisation_fouille.autorisation_date");
			}

			if ($metadata == "dir_adj_st") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='dast'>^ca_entities.preferred_labels.displayname</unit>");
			}
			if ($metadata == "commune") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_places'>^ca_places.preferred_labels.name</unit>");
			}

			if ($data_to_compare == ";") {
				$data_to_compare = "";
			}
			if ($metadata == "CodeINSEE") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_places'>^ca_places.idno</unit>");
			}
			if ($metadata == "AnneeDebutTerrain"){
				$data_to_compare =  $col->getWithTemplate("^ca_collections.inrap_annee_inter");
			}
			if ($metadata == "NomOpeRattachement"){
				$data_to_compare =  $col->getWithTemplate("^ca_collections.inrap_op_rattachement.inrap_op_rattachement_txt");
			}
			if (str_replace(' ', '', strtolower($data)) != str_replace(' ', '', strtolower($data_to_compare))) {
				if ($metadata == "ro") {
					$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.displayname</unit>");
					$data = $result["ro_label"];
				}
				array_push($different, [$metadata, $data, $data_to_compare]);
			}
		}
		$opo_app_plugin_manager = new ApplicationPluginManager();
		$opo_app_plugin_manager->hookSaveItem(
			array(
				'id' => $col->getPrimaryKey(),
				'table_num' => $col->tableNum(),
				'table_name' => $col->tableName(),
				'instance' => $col
			)
		);
		if ($vs_verrou) { $o_data->query("SELECT RELEASE_LOCK(?)", array($vs_verrou)); }
		$this->view->setVar("error", $different);
		$this->view->setVar("id", $col->getPrimaryKey());
		$this->render("import_html.php");
	}

	public function Update()
	{
		$op_id = $this->getRequest()->getParameter("id", pInteger);
		if ($this->_importSuspendu(caNavUrl($this->getRequest(), 'editor/collections', 'CollectionEditor', 'Summary', ['collection_id' => $op_id]))) { return; }
		$col = new ca_collections($op_id);
		$o_data = new Db();
		// 24/09/2026 (ticket 8043) : meme verrou que l'Importer ; un double envoi creait des liens en double.
		$vs_verrou = $this->_verrouiller($o_data, $col->get("ca_collections.idno"));
		if (!$vs_verrou) {
			$o_n = new NotificationManager($this->getRequest());
			$o_n->addNotification("La mise à jour de cette opération est déjà en cours. Réessayez dans un instant.", __NOTIFICATION_TYPE_WARNING__);
			$this->getResponse()->setRedirect(caNavUrl($this->getRequest(), 'editor/collections', 'CollectionEditor', 'Summary', array('collection_id' => $op_id)));
			return;
		}
		$qr_result = $o_data->query("SELECT * FROM _sga_comodo WHERE idno = ?", array(trim((string)$col->get("ca_collections.idno"))));
		while ($qr_result->nextRow()) {
			$col->setMode(ACCESS_WRITE);
			if ($qr_result->get("inrap_ancien_code") && isset($_POST["inrap_ancien_code"])) {
				$col->removeAttributes("inrap_ancien_code");
				$col->addAttribute(array("inrap_ancien_code" => $qr_result->get("inrap_ancien_code")), "inrap_ancien_code");
			}
			//TODO : inrap_op_rattachement
			if ($qr_result->get("AnneeDebutTerrain") && isset($_POST["AnneeDebutTerrain"])) {
				$col->removeAttributes("inrap_annee_inter");
				$col->addAttribute(["inrap_annee_inter" => $qr_result->get("AnneeDebutTerrain")], "inrap_annee_inter");
			}
			if ($qr_result->get("NomOpeRattachement") && (isset($_POST["inrap_op_rattachement"]) || isset($_POST["NomOpeRattachement"]))){
				$vs_abrev = $this->_valeurEnPlace($col, 'inrap_op_rattachement.inrap_op_abreviation_usuelle');   // abreviation gardee (ticket 8043)
				$col->removeAttributes("inrap_op_rattachement");
				$col->addAttribute([ "inrap_op_rattachement_txt" =>$qr_result->get("NomOpeRattachement"), "inrap_op_abreviation_usuelle" => $vs_abrev], "inrap_op_rattachement");
			}
			// 24/09/2026 (ticket 8043) — TYPE ET AXE : UNE SEULE ECRITURE. Les trois branches d'avant
			// s'enchainaient : cocher le seul type ecrivait un conteneur « type seul », puis la branche
			// suivante voyait son ajout type+axe refuse (un seul conteneur par fiche) : l'axe disparaissait.
			// On lit les deux valeurs en place, on ne remplace que la partie cochee.
			$vb_type = isset($_POST["inrap_type_op_inrap_type_ope"]); $vb_axe = isset($_POST["inrap_type_op_inrap_axe_analytique"]);
			if ($qr_result->get("inrap_type_op.inrap_type_ope") && ($vb_type || $vb_axe)) {
				$vs_type_en_place = $this->_valeurEnPlace($col, 'inrap_type_op.inrap_type_ope');
				$vs_axe_en_place = $this->_valeurEnPlace($col, 'inrap_type_op.inrap_axe_analytique');
				$vs_axe_sga = trim((string)$qr_result->get("inrap_type_op.inrap_axe_analytique"));
				$col->removeAttributes("inrap_type_op", ["force"=>true]);
				$col->addAttribute(array(
					"inrap_type_ope" => ($vb_type || ($vs_type_en_place === '')) ? $qr_result->get("inrap_type_op.inrap_type_ope") : $vs_type_en_place,
					"inrap_axe_analytique" => ($vb_axe && ($vs_axe_sga !== '')) ? $vs_axe_sga : $vs_axe_en_place
				), "inrap_type_op");
			}

			if ($qr_result->get("oa_number") && isset($_POST["oa_number"])) {
				$col->removeAttributes("oa_number");
				$col->addAttribute(array("oa_number" => $qr_result->get("oa_number")), "oa_number");
			}

			if ($qr_result->get("ro") && isset($_POST["ro"])) {
				$name_info = explode(',', $qr_result->get("ro_label"));
				$entity_id = getEntityIDByIdno($name_info[1] ?? '', $name_info[0], $qr_result->get("ro"), 1665);
				// 10/09/2026 GM (ticket 7988) : ne rien reecrire si le nom n'a pas ete resolu.
				// Sans cette garde, une entite prise au hasard etait rattachee, et la relation
				// legitime deja en place etait detruite juste avant par removeRelationships().
				if ($entity_id) {
					$col->removeRelationships("ca_entities", 124);
					$col->addRelationship("ca_entities", $entity_id, 124);
				}
			}

			if ($qr_result->get("commune") && (isset($_POST["commune"]) || isset($_POST["CodeINSEE"]))) {
				if (!$qr_result->get("CodeINSEE")) {
					$commune = new PlaceSearch();
					$result = $commune->search("ca_places:" . $qr_result->get("commune"));
					while ($result->nextHit()) {
						$name = $result->get("ca_places.preferred_labels.name");
						if (strtolower($name) == strtolower($qr_result->get("commune"))) {
							$col->removeRelationships("ca_places", 235);
							$col->addRelationship("ca_places", $result->get("ca_places.place_id"), 235);
							break;
						}
					}
				} else {
					$vt_place = new ca_places();
					$vt_place->load(["idno" => $qr_result->get("CodeINSEE"), "deleted" => 0]);

					if ($vt_place->getPrimaryKey()){
						$col->removeRelationships("ca_places", 235);
						$col->addRelationship("ca_places", $vt_place->getPrimaryKey(), 235);
						
					}
					$col->removeAttributes("code_insee");
					$col->addAttribute(["code_insee" => $qr_result->get("CodeINSEE")], "code_insee");
				}
			}
			if ($qr_result->get("sra") && isset($_POST["sra"])) {
				$sra = new ca_entities();
				$sra->load(["idno" => $qr_result->get("sra"), "deleted" => 0]);
				$sraID = $sra->getPrimaryKey();
				// 24/09/2026 (ticket 8043) : ne retirer le SRA en place que si celui de SGA est trouve.
				if ($sraID) {
					// Seuls les liens « attribue » vers des SRA sont remplaces : ceux vers des CCE restent.
					foreach (($col->getRelatedItems('ca_entities', array('restrictToRelationshipTypes' => array('attribue'), 'restrictToTypes' => array('sra'))) ?: array()) as $va_rel) {
						$col->removeRelationship('ca_entities', $va_rel['relation_id']);
					}
					$col->addRelationship("ca_entities", $sraID, 121);
				}
			}

			if ($qr_result->get("lieudit") && isset($_POST["lieudit"])) {
				$col->removeAttributes("lieudit");
				$col->addAttribute(array("lieudit" => $qr_result->get("lieudit")), "lieudit");
			}

			if ($qr_result->get("parcelle") && isset($_POST["parcelle"])) {
				$col->removeAttributes("parcelle");
				$col->addAttribute(array("parcelle" => $qr_result->get("parcelle")), "parcelle");
			}

			if ($qr_result->get("prescripteur") && isset($_POST["prescripteur"])) {
				$name_info = explode(',', $qr_result->get("prescripteur"));
				$entity_id = getEntityIDByIdno($name_info[1] ?? '', $name_info[0], '', 1665);
				// 10/09/2026 GM (ticket 7988) : ne rien reecrire si le nom n'a pas ete resolu.
				// Sans cette garde, une entite prise au hasard etait rattachee, et la relation
				// legitime deja en place etait detruite juste avant par removeRelationships().
				if ($entity_id) {
					$col->removeRelationships("ca_entities", 122);
					$col->addRelationship("ca_entities", $entity_id, 122);
				}
			}

			if ($qr_result->get("numero_prescription") && isset($_POST["numero_prescription"])) {
				$col->removeAttributes("numero_prescription");
				$col->addAttribute(array("numero_prescription" => $qr_result->get("numero_prescription")), "numero_prescription");
			}

			if ($qr_result->get("date_simple") && isset($_POST["date_simple"])) {
				$col->removeAttributes("date_simple");
				$col->addAttribute(array("date_simple" => $qr_result->get("date_simple")), "date_simple");
			}

			if ($qr_result->get("surface_OA") && isset($_POST["surface_OA"])) {
				$col->removeAttributes("surface_OA");
				$col->addAttribute(array("surface_OA" => floatval($qr_result->get("surface_OA"))), "surface_OA");
			}
			$value = self::DIRECTIONS;   // 24/09/2026 (ticket 8043) : table commune a Compare, Importer et Update
			if ($qr_result->get("dir_inrap") && isset($_POST["dir_inrap"])) {
				$entity = new EntitySearch();

				// 24/09/2026 (ticket 8043) : une direction SGA sans correspondance (« SIEGE », « LGVSEA »…)
				// n'est pas cherchee, et rien n'est touche.
				$vs_search = $value[$qr_result->get("dir_inrap")] ?? null;

				$result = $vs_search ? $entity->search($vs_search) : null;
				
				while ($result && $result->nextHit()) {
					$name = $result->get("ca_entities.preferred_labels.displayname");
					$idno = $result->get("ca_entities.idno");
					// 09/09/2026 (ticket 8008) : deux var_dump de mise au point tournaient ICI, donc à
					// chaque résultat de la recherche d'entité, sans condition. Ils imprimaient des
					// « string(n) "..." » en plein milieu de la page de mise à jour SGA — c'est le
					// « code erreur » que les gestionnaires signalaient, alors que la mise à jour
					// aboutissait normalement.
					if ($name == $vs_search || trim($idno) == trim($vs_search)) {
						$col->removeRelationships("ca_entities", 236);
						$rel = $col->addRelationship("ca_entities", $result->get("ca_entities.entity_id"), 236 );
						
						$col->update();
						if ($col->numErrors()) {
							// 09/09/2026 (ticket 8008) : un var_dump suivi d'un die() vidait un tableau PHP brut
							// en pleine page et arretait le traitement sans un mot pour l'utilisateur. On journalise
							// et on affiche un message exploitable.
							$vs_err = join(' ; ', $col->getErrors());
							@error_log('[SGA] mise a jour operation #' . $col->getPrimaryKey() . ' : ' . $vs_err);
							if (class_exists('NotificationManager') && $this->getRequest()) {
								$o_n = new NotificationManager($this->getRequest());
								$o_n->addNotification("La mise a jour de cette operation a echoue : " . htmlspecialchars($vs_err, ENT_QUOTES, 'UTF-8')
									. " Les autres operations du lot ne sont pas affectees.", __NOTIFICATION_TYPE_ERROR__);
							}
							break;   // on cesse d'essayer cette entité ; la page se rend normalement
						}
						break;
					}
				}
			}

			if ($qr_result->get("dir_adj_st")  && isset($_POST["dir_adj_st"])) {
				$name_info = explode(',', $qr_result->get("dir_adj_st"));
				$entity_id = getEntityIDByIdno($name_info[1] ?? '', $name_info[0], '', 1665);
				// 10/09/2026 GM (ticket 7988) : ne rien reecrire si le nom n'a pas ete resolu.
				// Sans cette garde, une entite prise au hasard etait rattachee, et la relation
				// legitime deja en place etait detruite juste avant par removeRelationships().
				if ($entity_id) {
					$col->removeRelationships("ca_entities", 242);
					$col->addRelationship("ca_entities", $entity_id, 242);
				}
			}

			// 24/09/2026 (ticket 8043) — DATES DE TERRAIN : seulement les cases cochees. La condition d'avant
			// (« date de debut SGA OU case fin cochee ») reecrivait les dates a chaque mise a jour, case decochee,
			// et effacait la date de fin quand SGA n'en a pas (280 operations).
			$vb_deb = isset($_POST["datesdeterrain_Datedeterrain_date"]); $vb_fin = isset($_POST["datesdeterrain_datedeterrain_datefin"]);
			$vs_deb_sga = trim((string)$qr_result->get("datesdeterrain.Datedeterrain_date")); $vs_fin_sga = trim((string)$qr_result->get("datesdeterrain.datedeterrain_datefin"));
			if (($vb_deb && ($vs_deb_sga !== '')) || ($vb_fin && ($vs_fin_sga !== ''))) {
				$vs_deb = ($vb_deb && ($vs_deb_sga !== '')) ? $vs_deb_sga : $this->_valeurEnPlace($col, 'datesdeterrain.Datedeterrain_date');
				$vs_fin = ($vb_fin && ($vs_fin_sga !== '')) ? $vs_fin_sga : $this->_valeurEnPlace($col, 'datesdeterrain.datedeterrain_datefin');
				$col->removeAttributes("datesdeterrain");
				$col->addAttribute(array("Datedeterrain_date" => $vs_deb, "datedeterrain_datefin" => $vs_fin), "datesdeterrain");
			}

			// 24/09/2026 (ticket 8043) : numero et date d'autorisation, seule la partie cochee et fournie par SGA
			// est remplacee ; l'autre garde sa valeur (8 numeros et 5 dates auraient ete effaces).
			$vb_num = isset($_POST["autorisation_fouille"]); $vb_dat = isset($_POST["autorisation_date"]);
			$vs_num_sga = trim((string)$qr_result->get("autorisation_fouille")); $vs_dat_sga = trim((string)$qr_result->get("autorisation_date"));
			if ($vs_dat_sga === '0') { $vs_dat_sga = ''; }
			if (($vb_num && ($vs_num_sga !== '')) || ($vb_dat && ($vs_dat_sga !== ''))) {
				$vs_num = ($vb_num && ($vs_num_sga !== '')) ? $vs_num_sga : $this->_valeurEnPlace($col, 'autorisation_fouille.autorisation_num');
				$vs_dat = ($vb_dat && ($vs_dat_sga !== '')) ? $vs_dat_sga : $this->_valeurEnPlace($col, 'autorisation_fouille.autorisation_date');
				$col->removeAttributes("autorisation_fouille");
				$col->addAttribute(array("autorisation_num" => $vs_num, "autorisation_date" => $vs_dat), "autorisation_fouille");
			}

			if ($qr_result->get("inrap_date_planification") && isset($_POST["inrap_date_planification"])) {
				$col->removeAttributes("inrap_date_planification");
				$col->addAttribute(array("inrap_date_planification_date" => $qr_result->get("inrap_date_planification")), "inrap_date_planification");
			}


			if ($qr_result->get("date_prev_du_rapport") != 0 && isset($_POST["date_prev_du_rapport"])) {
				$col->removeAttributes("date_prev_du_rapport");
				$col->addAttribute(array("date_prev_du_rapport" => $qr_result->get("date_prev_du_rapport")), "date_prev_du_rapport");
			}

			if ($qr_result->get("date_du_rapport") != 0 && isset($_POST["date_du_rapport"])) {
				$col->removeAttributes("date_du_rapport");
				$col->addAttribute(array("date_du_rapport" => $qr_result->get("date_du_rapport")), "date_du_rapport");
			}

			if ($qr_result->get("centre_op") && isset($_POST["centre_op"])) {
				$col->removeAttributes("centre_op");
				$col->addAttribute(array("centre_op" => $qr_result->get("centre_op")), "centre_op");
			}

			if ($col->numErrors()) {
				// 09/09/2026 (ticket 8008) : un var_dump suivi d'un die() vidait un tableau PHP brut
				// en pleine page et arretait le traitement sans un mot pour l'utilisateur. On journalise
				// et on affiche un message exploitable.
				$vs_err = join(' ; ', $col->getErrors());
				@error_log('[SGA] mise a jour operation #' . $col->getPrimaryKey() . ' : ' . $vs_err);
				if (class_exists('NotificationManager') && $this->getRequest()) {
					$o_n = new NotificationManager($this->getRequest());
					$o_n->addNotification("La mise a jour de cette operation a echoue : " . htmlspecialchars($vs_err, ENT_QUOTES, 'UTF-8')
						. " Les autres operations du lot ne sont pas affectees.", __NOTIFICATION_TYPE_ERROR__);
				}
				// on rend quand même la page : sans cela l'utilisateur reçoit un écran blanc,
				// ce qui n'était pas mieux que le vidage brut d'avant.
				$this->_liberer($o_data, $vs_verrou);
				$this->render("update_html.php");
				return;
			}

			$col->update();
			if ($col->numErrors()) {
				// 09/09/2026 (ticket 8008) : un var_dump suivi d'un die() vidait un tableau PHP brut
				// en pleine page et arretait le traitement sans un mot pour l'utilisateur. On journalise
				// et on affiche un message exploitable.
				$vs_err = join(' ; ', $col->getErrors());
				@error_log('[SGA] mise a jour operation #' . $col->getPrimaryKey() . ' : ' . $vs_err);
				if (class_exists('NotificationManager') && $this->getRequest()) {
					$o_n = new NotificationManager($this->getRequest());
					$o_n->addNotification("La mise a jour de cette operation a echoue : " . htmlspecialchars($vs_err, ENT_QUOTES, 'UTF-8')
						. " Les autres operations du lot ne sont pas affectees.", __NOTIFICATION_TYPE_ERROR__);
				}
				// on rend quand même la page : sans cela l'utilisateur reçoit un écran blanc,
				// ce qui n'était pas mieux que le vidage brut d'avant.
				$this->_liberer($o_data, $vs_verrou);
				$this->render("update_html.php");
				return;
			}

			$label = $col->getWithTemplate("^ca_places.preferred_labels.name / ^ca_collections.lieudit / ^ca_collections.inrap_annee_inter%trim=1 / <unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.displayname</unit>");
			$col->removeAllLabels();
			$col->update();
			$col->addLabel(array('name' => $label), 2, null, true);
			$col->update();
		}
		$different = [];
		$result = $qr_result->getAllRows()[0];
		foreach ($result as $metadata => $data) {
			if ($metadata == "id" || $metadata == "ro_label" || empty($data)) {
				continue;
			}
			$data_to_compare = $col->getWithTemplate("^ca_collections." . $metadata);
			if ($metadata == "inrap_date_planification") {
				$data_to_compare = $col->getWithTemplate("^ca_collections.inrap_date_versement." . $metadata);
			}
			if ($metadata == "surface_OA" && (empty($data) && $data_to_compare == 0) && (empty($data_to_compare) && $data == 0)) {
				$data_to_compare = "";
				$data = "";
			}
			if ($metadata == "ro") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.idno</unit>");
			}
			if ($metadata == "sra") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='attribue' restrictToTypes='sra'>^ca_entities.idno</unit>");
			}
			if ($metadata == "dir_inrap") {
				$value = array_flip($value);
				$data_to_compare = $value[$col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='DIR'>^ca_entities.preferred_labels.displayname</unit>")];
			}
			if ($metadata == "prescripteur") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='suivi_par'>^ca_entities.preferred_labels.displayname</unit>");
			}
			if ($metadata == "autorisation_fouille") {
				$data_to_compare = $col->getWithTemplate("^ca_collections.autorisation_fouille.autorisation_num");
			}
			if ($metadata == "autorisation_date") {
				$data_to_compare = $col->getWithTemplate("^ca_collections.autorisation_fouille.autorisation_date");
			}

			if ($metadata == "dir_adj_st") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='dast'>^ca_entities.preferred_labels.displayname</unit>");
			}
			if ($metadata == "commune") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_places'>^ca_places.preferred_labels.name</unit>");
			}
			if ($data_to_compare == ";") {
				$data_to_compare = "";
			}

			if ($metadata == "CodeINSEE") {
				$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_places'>^ca_places.idno</unit>");
			}
			if ($metadata == "AnneeDebutTerrain"){
				$data_to_compare =  $col->getWithTemplate("^ca_collections.inrap_annee_inter");
			}
			if ($metadata == "NomOpeRattachement"){
				$data_to_compare =  $col->getWithTemplate("^ca_collections.inrap_op_rattachement.inrap_op_rattachement_txt");
			}
			if (str_replace(' ', '', strtolower($data)) != str_replace(' ', '', strtolower($data_to_compare))) {
				if ($metadata == "ro") {
					$data_to_compare = $col->getWithTemplate("<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.displayname</unit>");
					$data = $result["ro_label"];
				}

				array_push($different, [$metadata, $data, $data_to_compare]);
			}
		}

		$opo_app_plugin_manager = new ApplicationPluginManager();
		$opo_app_plugin_manager->hookSaveItem(
			array(
				'id' => $col->getPrimaryKey(),
				'table_num' => $col->tableNum(),
				'table_name' => $col->tableName(),
				'instance' => $col
			)
		);
		$this->view->setVar("error", $different);
		$this->view->setVar("id", $col->getPrimaryKey());
		$this->_liberer($o_data, $vs_verrou);
		$this->render("update_html.php");
	}
}
