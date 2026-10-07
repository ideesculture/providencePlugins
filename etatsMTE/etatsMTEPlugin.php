<?php
/* ----------------------------------------------------------------------
 * etatsMTEPlugin.php :
 * ----------------------------------------------------------------------
 * Plugin d'etats pour le MTE (Mobiliers Classés)
 * Adapté depuis etatObs (Observatoire) pour le contexte MTE
 * ----------------------------------------------------------------------
 */

	class etatsMTEPlugin extends BaseApplicationPlugin {
		# -------------------------------------------------------
		protected $description = "Plugin Etats MTE";
		# -------------------------------------------------------
		private $opo_config;
		private $ops_plugin_path;
		# -------------------------------------------------------
		public function __construct($ps_plugin_path) {
			$this->ops_plugin_path = $ps_plugin_path;
			$this->description = _t("Etats MTE - Mobiliers Classés");
			parent::__construct();
			$this->opo_config = Configuration::load($ps_plugin_path.'/conf/etatsMTE.conf');
		}
		# -------------------------------------------------------
		/**
		 * Override checkStatus() to return true
		 */
		public function checkStatus() {
			return array(
				'description' => $this->getDescription(),
				'errors' => array(),
				'warnings' => array(),
				'available' => true
			);
		}

		/**
		 * Inspecteur ObjectEditor (barre de gauche) : produit l'élément source #last_loc_mte
		 * contenant la « Dernière localisation » calculée (métadonnée calc_derniere_localisation).
		 * Le JS de themes/mte/.../pageFooter.php recopie ce contenu dans le span #last_loc_replace
		 * placé au-dessus de l'image (cf. displayHelpers.php). Élément masqué : il ne sert que de source.
		 */
		public function hookAppendToEditorInspector(array $va_params = array()) {
			$t_item = $va_params["t_item"] ?? null;
			if ($t_item && $t_item->tableName() === "ca_objects" && $t_item->getPrimaryKey()) {
				$vs_loc = trim((string)$t_item->get('ca_objects.calc_derniere_localisation'));
				// le bloc est désormais injecté même lorsque la
				// localisation est vide (fiche nouvellement créée), afin que le script de la page
				// remplace toujours le contenu de la zone au lieu de laisser l'affichage précédent.
				$vs_buf = "<div id='last_loc_mte' style='display:none;'><span>".htmlspecialchars($vs_loc, ENT_QUOTES, 'UTF-8')."</span></div>";
				$va_params["caEditorInspectorAppend"] = ($va_params["caEditorInspectorAppend"] ?? '') . $vs_buf;
			}
			return $va_params;
		}

		# -------------------------------------------------------
		/**
		 * After save: auto-assign deposant based on object type
		 */
		public function hookSaveItem(array $va_params = array()) {
			$t_item = $va_params['instance'];
			if (!$t_item || $t_item->tableName() !== 'ca_objects') return $va_params;

			// --- Champs Oui/Non calculés (recette 22/04, RA1) : inventorié / récolé / restauré / restitué ---
			// Garde anti-récursion : le update() interne re-déclenche ce hook, on l'ignore alors.
			static $vb_in_calc = false;
			if (!$vb_in_calc) {
				$vb_in_calc = true;
				// correction du 18/09/2026 (contrôle indépendant) :
				// à la CRÉATION, l'instance transmise par l'éditeur ne porte pas encore les valeurs
				// qui viennent d'être écrites (localisation, date de dépôt), si bien que la dernière
				// localisation était calculée à vide : la zone du menu de gauche restait alors vide et
				// l'utilisateur lisait le titre juste en dessous. Il fallait un second enregistrement.
				// On recharge donc l'objet depuis la base avant de calculer.
				$vn_oid = (int)$t_item->getPrimaryKey();
				$t_calc = $t_item;
				if ($vn_oid) {
					$t_frais = new ca_objects($vn_oid);
					if ($t_frais->getPrimaryKey()) { $t_calc = $t_frais; }
				}
				$this->updateCalcFields($t_calc);
				$this->updateObjetMobilier($t_calc);
				$this->updateDerniereLocalisation($t_calc);
				$vb_in_calc = false;
			}

			// --- Numéro d'inventaire du déposant (type MTE) : filet de sécurité au save ---
			// Normalement pré-rempli côté éditeur (JS pageFooter -> Catalogue/NextDeposantNum) avec MTE_<max+1>.
			// Si le champ (obligatoire) est resté vide (JS désactivé, import, API…), on le renseigne ici avec le même schéma.
			static $vb_in_numinv = false;
			if (!$vb_in_numinv && (int)$t_item->getTypeID() === 3454) {
				$vs_cur = trim((string)$t_item->get('ca_objects.numinv_deposant'));
				if ($vs_cur === '') {
					$vb_in_numinv = true;
					$t_item->setMode(ACCESS_WRITE);
					$t_item->replaceAttribute(['numinv_deposant' => $this->nextDeposantNum('MTE')], 'numinv_deposant');
					// Champ CALCULÉ par le serveur : voir ecrireSansControleConcurrence().
					$this->ecrireSansControleConcurrence($t_item);
					$vb_in_numinv = false;
				}
			}

			// Mapping type_id => NOM du déposant (la spécification, 16/09/2026).
			// Les entity_id étaient écrits en dur ; la migration du 25/07/2026 a recréé les entités
			// sous de nouveaux identifiants, donc plus aucune création d'œuvre ne recevait son déposant.
			// On résout désormais l'entity_id à l'exécution, par le nom, qui lui est stable.
			$va_type_to_deposant_name = [
				3454 => 'Ministère de la Transition écologique', // MTE
				3581 => 'Mobilier National',
				3582 => 'Fonds National d\'Art Contemporain',    // CNAP
				3647 => 'Manufacture Nationale de Sèvres',       // Sèvres
				3648 => 'Musée du Château de Versailles',        // Versailles
				3650 => 'Musée National de la Marine',           // MM
			];

			$vn_type_id = (int)$t_item->getTypeID();
			if (!isset($va_type_to_deposant_name[$vn_type_id])) return $va_params;

			$va_type_to_deposant = $this->deposantEntityIds($va_type_to_deposant_name);
			// Déposant absent de la base : on ne crée pas de relation vers le vide
			if (!isset($va_type_to_deposant[$vn_type_id])) return $va_params;

			$vn_expected_entity_id = $va_type_to_deposant[$vn_type_id];

			// Check existing deposant relationship
			$va_rels = $t_item->getRelatedItems('ca_entities', ['restrictToRelationshipTypes' => ['depositaire']]);
			if (is_array($va_rels) && count($va_rels) > 0) {
				// Check if current deposant matches expected for this type
				$va_first = array_shift($va_rels);
				$vn_current_entity_id = (int)$va_first['entity_id'];

				if ($vn_current_entity_id === $vn_expected_entity_id) return $va_params; // Already correct

				// Type changed: if current deposant is one of our managed deposants, replace it
				if (in_array($vn_current_entity_id, array_values($va_type_to_deposant))) {
					$t_item->removeRelationship('ca_entities', $va_first['relation_id']);
					$t_item->addRelationship('ca_entities', $vn_expected_entity_id, 172);
				}
				// If current deposant is NOT in our mapping, leave it (manually set)
				return $va_params;
			}

			// No deposant: add the expected one
			$t_item->addRelationship('ca_entities', $vn_expected_entity_id, 172);

			return $va_params;
		}

		# -------------------------------------------------------
		/**
		 * Résout les entités déposantes par leur NOM et renvoie [type_id => entity_id].
		 * Les types dont le déposant n'existe pas en base sont simplement absents du résultat.
		 * Résultat mis en cache pour la durée de la requête.
		 */
		private function deposantEntityIds($pa_type_to_name) {
			static $va_cache = null;
			if (is_array($va_cache)) { return $va_cache; }
			$va_cache = [];
			$o_db = new Db();
			foreach ($pa_type_to_name as $vn_type_id => $vs_name) {
				$qr = $o_db->query(
					"SELECT l.entity_id FROM ca_entity_labels l
					 JOIN ca_entities e ON e.entity_id = l.entity_id AND e.deleted = 0
					 WHERE l.is_preferred = 1 AND l.displayname = ? LIMIT 1",
					[$vs_name]
				);
				if ($qr->nextRow()) { $va_cache[(int)$vn_type_id] = (int)$qr->get('entity_id'); }
			}
			return $va_cache;
		}

		# -------------------------------------------------------
		/**
		 * Recalcule les 4 champs Oui/Non (présence d'au moins un enregistrement du type concerné).
		 * Valeurs : item idno 'oui_calc' (=1) / 'non_calc' (=0) de la liste oui_non_calc.
		 */
		private function updateCalcFields($t_item) {
			$vn_oid = (int)$t_item->getPrimaryKey();
			if (!$vn_oid) { return; }
			// champ calculé => element_id de la DATE d'intervention. Un bien est "inventorié /
			// récolé / restauré / restitué" seulement s'il porte une DATE RÉELLE (parseable) sur
			// le champ correspondant — pas seulement un conteneur créé vide à la migration, ni un
			// texte-placeholder du type "sansdate" (value_decimal1 renseignée = vraie date).
			// Recalé sur les comptages MTE (10/07/2026) : inventorié 1262, récolé 276,
			// restauré 0, restitué 71.
			$va_map = [
				'calc_inventorie' => 775, // inventaire_cont > inv_date
				'calc_recole'     => 659, // recolement_inv > der_date_reco
				'calc_restaure'   => 757, // restauration_cont2 > date_restauration_date
				'calc_restitue'   => 738, // restitution_cont2 > restitution_date
			];
			// item_id de la liste oui_non_calc : oui_calc=3655, non_calc=3656
			$o_db = new Db();
			$vb_changed = false;
			foreach ($va_map as $vs_code => $vn_date_eid) {
				$qr = $o_db->query("SELECT 1 FROM ca_attribute_values v JOIN ca_attributes a ON a.attribute_id = v.attribute_id WHERE a.table_num = 57 AND a.row_id = ? AND v.element_id = ? AND v.value_decimal1 IS NOT NULL LIMIT 1", [$vn_oid, $vn_date_eid]);
				$vn_target = $qr->nextRow() ? 3655 : 3656;
				// valeur RÉELLEMENT stockée (returnAsArray n'applique PAS la valeur par défaut de la liste)
				$va_cur = $t_item->get('ca_objects.'.$vs_code, ['returnAsArray' => true]);
				$vn_current = (is_array($va_cur) && count($va_cur)) ? (int)$va_cur[0] : 0;
				if ($vn_current !== $vn_target) {
					$t_item->replaceAttribute([$vs_code => $vn_target], $vs_code);
					$vb_changed = true;
				}
			}
			if ($vb_changed) {
				$t_item->setMode(ACCESS_WRITE);
				// Champ CALCULÉ par le serveur : voir ecrireSansControleConcurrence().
				$this->ecrireSansControleConcurrence($t_item);
			}
		}

		# -------------------------------------------------------
		/**
		 * Métadonnée calculée Objet/Mobilier (pivot) : déduit calc_objet_mobilier
		 * de la dénomination de l'objet, selon le mapping de conf/etatsMTE.conf.
		 * (feuille "Types" du fichier de migration → objet_denomination_ids / mobilier_denomination_ids)
		 */
		private function updateObjetMobilier($t_item) {
			$va_denom = $t_item->get('ca_objects.denomination', ['returnAsArray' => true]);
			$vn_denom = (is_array($va_denom) && count($va_denom)) ? (int)$va_denom[0] : 0;
			if (!$vn_denom) { return; } // pas de dénomination : rien à calculer

			$va_objet = array_map('intval', (array)$this->opo_config->getList('objet_denomination_ids'));
			$va_mob   = array_map('intval', (array)$this->opo_config->getList('mobilier_denomination_ids'));

			$vn_target = 0;
			if (in_array($vn_denom, $va_objet, true))      { $vn_target = (int)$this->opo_config->get('om_item_objet'); }
			elseif (in_array($vn_denom, $va_mob, true))    { $vn_target = (int)$this->opo_config->get('om_item_mobilier'); }
			if (!$vn_target) { return; } // dénomination non classée : on laisse en l'état

			$va_cur = $t_item->get('ca_objects.calc_objet_mobilier', ['returnAsArray' => true]);
			$vn_current = (is_array($va_cur) && count($va_cur)) ? (int)$va_cur[0] : 0;
			if ($vn_current !== $vn_target) {
				$t_item->setMode(ACCESS_WRITE);
				$t_item->replaceAttribute(['calc_objet_mobilier' => $vn_target], 'calc_objet_mobilier');
				// Champ CALCULÉ par le serveur : voir ecrireSansControleConcurrence().
				$this->ecrireSansControleConcurrence($t_item);
			}
		}

		# -------------------------------------------------------
		/**
		 * Métadonnée calculée « Dernière localisation » (calc_derniere_localisation) :
		 * localisation rattachée à l'événement DATÉ le plus récent parmi ceux qui portent un lieu :
		 *   - dépôt        : date_depot            -> conteneur ca_objects.site
		 *   - inventaire   : inventaire_cont.inv_date        -> inv_site / inv_site_bat / inv_etage / inv_piece
		 *   - restitution  : restitution_cont2.restitution_date -> der_loc_cont.restauration_site / rest_batiment / restauration_etage / restauration_piece
		 * (restauration et récolement n'ont pas de champ localisation : ignorés.)
		 * Stockée en métadonnée pour être affichée dans l'inspecteur (hookAppendToEditorInspector).
		 */
		private function updateDerniereLocalisation($t_item) {
			if (!(int)$t_item->getPrimaryKey()) { return; }

			$DD = ['returnAsArray' => true, 'getDirectDate' => true];             // date -> décimal historique (début), triable
			$DT = ['returnAsArray' => true, 'convertCodesToDisplayText' => true]; // listes -> libellés

			$best_d = null; $best_loc = '';
			$mkLoc = function($parts, $i) {
				$bits = [];
				foreach ($parts as $arr) {
					$v = (is_array($arr) && isset($arr[$i])) ? trim((string)$arr[$i]) : '';
					if ($v !== '') { $bits[] = $v; }
				}
				return implode(' › ', $bits);
			};
			$consider = function($dates, $parts) use (&$best_d, &$best_loc, $mkLoc) {
				if (!is_array($dates)) { return; }
				foreach ($dates as $i => $d) {
					$d = (float)$d;
					if ($d <= 0) { continue; }
					$loc = $mkLoc($parts, $i);
					if ($loc === '') { continue; }
					if ($best_d === null || $d > $best_d) { $best_d = $d; $best_loc = $loc; }
				}
			};

			// 1. Dépôt : date_depot (haut niveau) rattaché au conteneur site (unique)
			// la spécification (16/09/2026) : format attendu Site › Adresse › Bâtiment › Étage › Pièce
			$site_loc = $mkLoc([
				$t_item->get('ca_objects.site.site_nom1', $DT),
				$t_item->get('ca_objects.site.site_adresse1', $DT),
				$t_item->get('ca_objects.site.site_batiment1', $DT),
				$t_item->get('ca_objects.site.site_etage', $DT),
				$t_item->get('ca_objects.site.site_piece', $DT),
			], 0);
			if ($site_loc !== '') {
				$dep = $t_item->get('ca_objects.date_depot', $DD);
				if (is_array($dep)) { foreach ($dep as $d) { $consider([$d], [[$site_loc]]); } }
			}

			// 2. Inventaire (occurrences alignées : inv_date <-> inv_site/…)
			$consider(
				$t_item->get('ca_objects.inventaire_cont.inv_date', $DD),
				[
					$t_item->get('ca_objects.inventaire_cont.inv_site', $DT),
					$t_item->get('ca_objects.inventaire_cont.inv_site_adresse', $DT),
					$t_item->get('ca_objects.inventaire_cont.inv_site_bat', $DT),
					$t_item->get('ca_objects.inventaire_cont.inv_etage', $DT),
					$t_item->get('ca_objects.inventaire_cont.inv_piece', $DT),
				]
			);

			// 3. Restitution (occurrences alignées : restitution_date <-> der_loc_cont.*)
			$consider(
				$t_item->get('ca_objects.restitution_cont2.restitution_date', $DD),
				[
					$t_item->get('ca_objects.restitution_cont2.der_loc_cont.restauration_site', $DT),
					$t_item->get('ca_objects.restitution_cont2.der_loc_cont.rest_adresse', $DT),
					$t_item->get('ca_objects.restitution_cont2.der_loc_cont.rest_batiment', $DT),
					$t_item->get('ca_objects.restitution_cont2.der_loc_cont.restauration_etage', $DT),
					$t_item->get('ca_objects.restitution_cont2.der_loc_cont.restauration_piece', $DT),
				]
			);

			// la colonne « Dernière localisation »
			// doit suivre EXACTEMENT la même règle que les filtres Site et Bâtiment, sinon elle les
			// contredit à l'écran. Mesuré avant alignement : 108 œuvres dont le dernier relevé par
			// RANG est plus ancien qu'un relevé précédent (ex. MTE_1006, relevé Saint-Germain/Bât 5
			// daté 01/07/2024 au rang 1, La Défense daté 07/02/2022 au rang 2) : le filtre les
			// classait à La Défense pendant que cette colonne affichait Saint-Germain.
			// Règle commune : DERNIER relevé d'inventaire par rang d'occurrence ; à défaut de tout
			// relevé, localisation de DÉPÔT. La restitution n'entre plus dans ce calcul.
			$va_inv811 = $t_item->getAttributesByElement(736);
			$vs_rang = '';
			if (is_array($va_inv811)) {
				foreach ($va_inv811 as $o_a811) {                  // ordre = attribute_id croissant
					$va_v811 = [];
					foreach ($o_a811->getValues() as $o_v811) {
						$va_v811[(int)$o_v811->getElementID()] = trim((string)$o_v811->getDisplayValue(['output' => 'text']));
					}
					if (empty($va_v811[777])) { continue; }        // occurrence sans site : ignorée
					$va_p = [];
					foreach ([777, 795, 796, 778, 779] as $vn_e) { // site > adresse > bâtiment > étage > pièce
						if (!empty($va_v811[$vn_e])) { $va_p[] = $va_v811[$vn_e]; }
					}
					$vs_rang = join(' › ', $va_p);
				}
			}
			if ($vs_rang === '') {                                  // aucun relevé : repli sur le DÉPÔT
				$va_p = [];
				foreach (['site_nom1','site_adresse1','site_batiment1','site_etage','site_piece'] as $vs_f) {
					$vs_v = trim((string)$t_item->getWithTemplate('^ca_objects.site.'.$vs_f));
					if ($vs_v !== '') { $va_p[] = $vs_v; }
				}
				$vs_rang = join(' › ', $va_p);
			}
			$vs_new = $vs_rang;
			$vs_cur = trim((string)$t_item->get('ca_objects.calc_derniere_localisation'));
			if ($vs_cur !== $vs_new) {
				$t_item->setMode(ACCESS_WRITE);
				$t_item->replaceAttribute(['calc_derniere_localisation' => $vs_new], 'calc_derniere_localisation');
				// Champ CALCULÉ par le serveur : voir ecrireSansControleConcurrence().
				$this->ecrireSansControleConcurrence($t_item);
			}

			// -------------------------------------------------------------------------------
			// calc_derniere_loc_codes (812).
			// Champ TECHNIQUE, non affiché : il porte « S<item_id> B<item_id> » du DERNIER relevé
			// d'inventaire, ou ceux du DÉPÔT lorsque le bien n'a jamais fait l'objet d'un relevé.
			// Il existe parce que SqlSearch2 ne sait pas exprimer « dernière occurrence » : sans
			// lui, la recherche avancée retenait n'importe quel relevé et MTE_729 ressortait sur
			// Saint-Germain alors que son dernier relevé est aux Réserves de Nanterre.
			// NE PAS confondre avec calc_derniere_localisation (811), calculé par DATE et incluant
			// la restitution : les deux n'ont ni la même règle ni le même usage.
			// Des codes et non des libellés : un filtrage par libellé produit des faux positifs
			// (mesuré : 3 sur « Fontenoy », dont le nom apparaît dans d'autres niveaux).
			$vn_site_code = null; $vn_bat_code = null;
			$va_inv = $t_item->getAttributesByElement(736);
			if (is_array($va_inv)) {
				foreach ($va_inv as $o_att) {                       // ordre = attribute_id croissant
					$vn_s = null; $vn_b = null;
					foreach ($o_att->getValues() as $o_val) {
						$vn_eid = (int)$o_val->getElementID();
						if ($vn_eid === 777) { $vn_s = (int)$o_val->getItemID(); }
						if ($vn_eid === 796) { $vn_b = (int)$o_val->getItemID(); }
					}
					// une occurrence ne compte que si elle porte un SITE : c'est le critère du filtre
					if ($vn_s) { $vn_site_code = $vn_s; $vn_bat_code = $vn_b ?: null; }
				}
			}
			if (!$vn_site_code) {                                    // aucun relevé : repli sur le DÉPÔT
				$vn_site_code = (int)$t_item->get('ca_objects.site.site_nom1', ['returnIdno' => false, 'returnAsArray' => false, 'idsOnly' => true]) ?: null;
				$vn_bat_code  = (int)$t_item->get('ca_objects.site.site_batiment1', ['returnIdno' => false, 'returnAsArray' => false, 'idsOnly' => true]) ?: null;
			}
			$vs_codes = trim(($vn_site_code ? 'S'.$vn_site_code : '').' '.($vn_bat_code ? 'B'.$vn_bat_code : ''));
			$vs_codes_cur = trim((string)$t_item->get('ca_objects.calc_derniere_loc_codes'));
			if ($vs_codes_cur !== $vs_codes) {
				$t_item->setMode(ACCESS_WRITE);
				if ($vs_codes_cur === '') { $t_item->addAttribute(['calc_derniere_loc_codes' => $vs_codes], 'calc_derniere_loc_codes'); }
				else { $t_item->replaceAttribute(['calc_derniere_loc_codes' => $vs_codes], 'calc_derniere_loc_codes'); }
				$this->ecrireSansControleConcurrence($t_item);
			}
		}

		# -------------------------------------------------------
		/**
		 * Prochain numéro d'inventaire déposant = <PREFIX>_<max+1>, calculé sur les
		 * numinv_deposant existants de forme <PREFIX>_<n>. Même logique que
		 * CatalogueController::NextDeposantNum (utilisée côté JS pour le pré-remplissage).
		 */
		# -------------------------------------------------------
		/**
		 * Écrit un champ CALCULÉ par le serveur en désarmant le contrôle d'édition
		 * concurrente du noyau, qui ne s'applique pas à une valeur dérivée.
		 *
		 * Deux garde-fous doivent être levés :
		 *  - ['force' => true] désarme le contrôle sur ca_objects (BaseModel ~2929) ;
		 *  - mais _commitAttributes() écrit ca_attributes via ca_attributes::editAttribute(),
		 *    qui reçoit $pa_options et appelle update() SANS les transmettre (ca_attributes.php:363).
		 *    Le contrôle s'y rearme et fait échouer l'écriture EN SILENCE (update() renvoie
		 *    false, sans exception ni journal). Or il ne s'arme que si $_REQUEST['form_timestamp']
		 *    existe : on le retire le temps de l'appel.
		 *
		 * Le rétablissement est garanti par finally, y compris si update() lève une exception.
		 * Même procédé que le noyau lui-même (cf. ElementsController).
		 *
		 * @param BaseModelWithAttributes $pt_item objet à enregistrer
		 * @return bool valeur de retour de update()
		 */
		private function ecrireSansControleConcurrence($pt_item) {
			$vs_ft = $_REQUEST['form_timestamp'] ?? null;
			unset($_REQUEST['form_timestamp']);
			try {
				return $pt_item->update(['force' => true]);
			} finally {
				if ($vs_ft !== null) { $_REQUEST['form_timestamp'] = $vs_ft; }
			}
		}

		private function nextDeposantNum($prefix = 'MTE') {
			$prefix = preg_replace('/[^A-Za-z0-9]/', '', $prefix);
			$o_db = new Db();
			$qr = $o_db->query(
				"SELECT MAX(CAST(SUBSTRING(av.value_longtext1, ".(strlen($prefix) + 2).") AS UNSIGNED)) mx
				 FROM ca_attribute_values av
				 JOIN ca_metadata_elements e ON e.element_id = av.element_id
				 WHERE e.element_code = 'numinv_deposant' AND av.value_longtext1 REGEXP ?",
				['^'.$prefix.'_[0-9]+$']
			);
			$mx = 0;
			if ($qr->nextRow()) { $mx = (int)$qr->get('mx'); }
			return $prefix.'_'.($mx + 1);
		}

		# -------------------------------------------------------
		/**
		 * Vrai si au moins un des chemins donnés a une valeur non vide (toutes occurrences confondues).
		 */
		private function hasAnyValue($t_item, $va_paths) {
			foreach ($va_paths as $vs_path) {
				$va_vals = $t_item->get($vs_path, ['returnAsArray' => true]);
				if (is_array($va_vals)) {
					foreach ($va_vals as $vm) { if (trim((string)$vm) !== '') return true; }
				}
			}
			return false;
		}

		# -------------------------------------------------------
		/**
		 * Insert menu bar "Catalogues" with two entries
		 */
		public function hookRenderMenuBar($pa_menu_bar) {
			if ($o_req = $this->getRequest()) {
				// menu reserve aux profils autorises.
				if (!$o_req->isLoggedIn() || !$o_req->user->canDoAction('can_use_etatsMTE_catalogues')) {
					return $pa_menu_bar;
				}

				$va_menu_items = array();

				$va_menu_items['catalogues_standards'] = array(
					'displayName' => _t('Standard'),
					'default' => array(
						'module' => 'etatsMTE',
						'controller' => 'Catalogue',
						'action' => 'Standards'
					)
				);

				$va_menu_items['catalogues_specifiques'] = array(
					'displayName' => _t('Spécifique'),
					'default' => array(
						'module' => 'etatsMTE',
						'controller' => 'Catalogue',
						'action' => 'Specifiques'
					)
				);

				$va_menu_items['catalogues_telechargements'] = array(
					'displayName' => _t('Téléchargements'),
					'default' => array(
						'module' => 'etatsMTE',
						'controller' => 'Catalogue',
						'action' => 'Telechargements'
					)
				);

				$pa_menu_bar['etatsMTE_catalogues'] = array(
					'displayName' => _t('Catalogue'),
					'navigation' => $va_menu_items
				);
			}

			// écran d'enrôlement en double
			// authentification, accessible à tout utilisateur connecté pour son
			// propre compte. Aucun droit particulier n'est exigé : chacun doit
			// pouvoir protéger son accès.
			if ($o_req = $this->getRequest()) {
				if ($o_req->isLoggedIn()) {
					$pa_menu_bar['etatsMTE_securite'] = array(
						'displayName' => _t('My account'),
						'navigation' => array(
							'otp' => array(
								'displayName' => _t('Two-factor authentication'),
								'default' => array(
									'module'     => 'etatsMTE',
									'controller' => 'Securite',
									'action'     => 'Index'
								)
							)
						)
					);
				}
			}

			return $pa_menu_bar;
		}

		# -------------------------------------------------------
		/**
		 * Add plugin user actions
		 */
		static function getRoleActionList() {
			// le profil « consultant » ne doit pas generer
			// de catalogue. Le plugin declare donc son propre droit, pour que le menu
			// ET les controleurs puissent etre conditionnes profil par profil.
			return array(
				'can_use_etatsMTE_catalogues' => array(
					'label' => _t('Peut générer les catalogues MTE'),
					'description' => _t("L'utilisateur voit le menu « Catalogue » et peut générer les catalogues standards et spécifiques.")
				)
			);
		}
	}
