<?php
/* ----------------------------------------------------------------------
 * plugins/selectionContenants/controllers/SelectionController.php
 * ----------------------------------------------------------------------
 * === D2026-0103 — écran de sélection des contenants : début ===
 *
 * Deux actions :
 *  - SelectObject : fragment HTML du popup de sélection (chargé en AJAX) ;
 *  - Validate     : enregistre la sélection = pose/retire les relations
 *                   mouvement→objet (ca_movements_x_objects) puis enfile le
 *                   versement dans la file _inrap_recalc_queue. Le worker
 *                   (support/bin/recalc_worker.php, cron 2 min) fait le reste :
 *                   bascule des types « versé », propagation aux opérations,
 *                   statuts, titre. AUCUN changement de type ni recalcul ici.
 *
 * Corrections par rapport au plugin d'origine (oldPlugins/BordereauVersement) :
 *  - requêtes exclusivement paramétrées (l'ancien Validate concaténait) ;
 *  - plus de var_dump()/die() : erreurs collectées et renvoyées proprement ;
 *  - contrôle des droits (authentifié + can_edit_ca_movements) sur chaque action ;
 *  - types résolus par leur code de liste, jamais d'item_id en dur ;
 *  - plus d'écriture d'inrap_volume_total sur le mouvement : l'élément 504
 *    est restreint à ca_collections sur cette base (0 valeur sur les
 *    mouvements), et le volume par opération est déjà recalculé par
 *    prepopulateInrap — le cumul affiché dans le popup est purement indicatif.
 * === D2026-0103 — écran de sélection des contenants : fin ===
 * ----------------------------------------------------------------------
 */

require_once(__CA_MODELS_DIR__.'/ca_movements.php');
require_once(__CA_MODELS_DIR__.'/ca_objects.php');
require_once(__CA_MODELS_DIR__.'/ca_collections.php');

class SelectionController extends ActionController {
	# -------------------------------------------------------
	protected $opo_config;
	protected $ops_plugin_path;
	# -------------------------------------------------------
	public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
		parent::__construct($po_request, $po_response, $pa_view_paths);
		$this->ops_plugin_path = __CA_APP_DIR__."/plugins/selectionContenants";
		$this->opo_config = Configuration::load($this->ops_plugin_path."/conf/selectionContenants.conf");
	}
	# -------------------------------------------------------
	/**
	 * Contrôle des droits : utilisateur authentifié ET autorisé à éditer les
	 * mouvements (même action que l'éditeur de fiche versement).
	 */
	private function checkUserCanEditMovements() {
		return ($this->request && $this->request->isLoggedIn()
			&& $this->request->user && $this->request->user->canDoAction('can_edit_ca_movements'));
	}
	# -------------------------------------------------------
	private function sendJSON($pa_data) {
		$this->view->setVar('json_payload', $pa_data);
		$this->render('validate_json.php');
	}
	# -------------------------------------------------------
	/**
	 * item_id des types de contenants, résolus PAR CODE dans la liste
	 * object_types (jamais d'id en dur : ils changent d'une base à l'autre).
	 * @return array code => item_id
	 */
	private function getContenantTypeIDs($po_db) {
		$va_codes = $this->opo_config->getList('contenant_type_codes');
		if (!is_array($va_codes) || !sizeof($va_codes)) { return array(); }
		$vs_ph = implode(',', array_fill(0, sizeof($va_codes), '?'));
		$qr = $po_db->query("
			SELECT li.item_id, li.idno
			FROM ca_list_items li
			INNER JOIN ca_lists l ON l.list_id = li.list_id
			WHERE l.list_code = 'object_types' AND li.deleted = 0 AND li.idno IN ({$vs_ph})
		", $va_codes);
		$va_map = array();
		while ($qr->nextRow()) { $va_map[$qr->get('idno')] = (int)$qr->get('item_id'); }
		return $va_map;
	}
	# -------------------------------------------------------
	/**
	 * Libellés préférés (locale courante ou première disponible) d'items de liste.
	 */
	private function getListItemLabels($po_db, $pa_item_ids) {
		$va_labels = array();
		$pa_item_ids = array_values(array_unique(array_filter(array_map('intval', $pa_item_ids))));
		if (!sizeof($pa_item_ids)) { return $va_labels; }
		$vs_ph = implode(',', array_fill(0, sizeof($pa_item_ids), '?'));
		$qr = $po_db->query("
			SELECT lil.item_id, lil.name_singular
			FROM ca_list_item_labels lil
			WHERE lil.item_id IN ({$vs_ph}) AND lil.is_preferred = 1
		", $pa_item_ids);
		while ($qr->nextRow()) { $va_labels[(int)$qr->get('item_id')] = $qr->get('name_singular'); }
		return $va_labels;
	}
	# -------------------------------------------------------
	/**
	 * Périmètre de l'écran : contenants des opérations liées au versement
	 * + contenants déjà rattachés au versement (même hors de ces opérations,
	 * pour qu'aucun rattachement existant ne soit retiré à l'insu de l'agent).
	 *
	 * @return array [ops, contenants, linked_ids, type_ids]
	 */
	private function loadScope($po_db, $pn_movement_id) {
		$va_type_map = $this->getContenantTypeIDs($po_db);
		$va_type_ids = array_values($va_type_map);
		if (!sizeof($va_type_ids)) {
			throw new Exception(_t("Types de contenants introuvables dans la liste object_types"));
		}
		$vs_ph_types = implode(',', array_fill(0, sizeof($va_type_ids), '?'));

		// Opérations (ca_collections) liées au versement
		$va_ops = array();
		$qr = $po_db->query("
			SELECT mxc.collection_id, c.idno, cl.name
			FROM ca_movements_x_collections mxc
			INNER JOIN ca_collections c ON c.collection_id = mxc.collection_id AND c.deleted = 0
			LEFT JOIN ca_collection_labels cl ON cl.collection_id = c.collection_id AND cl.is_preferred = 1
			WHERE mxc.movement_id = ?
			GROUP BY mxc.collection_id
			ORDER BY c.idno
		", array($pn_movement_id));
		while ($qr->nextRow()) {
			$va_ops[(int)$qr->get('collection_id')] = array(
				'idno' => $qr->get('idno'),
				'label' => $qr->get('name'),
				'contenants' => array()
			);
		}

		// Contenants des opérations
		$va_contenants = array(); // object_id => données
		if (sizeof($va_ops)) {
			$va_op_ids = array_keys($va_ops);
			$vs_ph_ops = implode(',', array_fill(0, sizeof($va_op_ids), '?'));
			$qr = $po_db->query("
				SELECT oxc.collection_id, o.object_id, o.idno, o.type_id
				FROM ca_objects_x_collections oxc
				INNER JOIN ca_objects o ON o.object_id = oxc.object_id AND o.deleted = 0
				WHERE oxc.collection_id IN ({$vs_ph_ops}) AND o.type_id IN ({$vs_ph_types})
				GROUP BY oxc.collection_id, o.object_id
				ORDER BY o.idno
			", array_merge($va_op_ids, $va_type_ids));
			while ($qr->nextRow()) {
				$vn_oid = (int)$qr->get('object_id');
				$vn_cid = (int)$qr->get('collection_id');
				if (!isset($va_contenants[$vn_oid])) {
					$va_contenants[$vn_oid] = array(
						'object_id' => $vn_oid,
						'idno' => $qr->get('idno'),
						'type_id' => (int)$qr->get('type_id'),
					);
				}
				$va_ops[$vn_cid]['contenants'][] = $vn_oid;
			}
		}

		// Contenants déjà rattachés au versement (pré-cochés) — y compris hors opérations
		$va_linked = array(); // object_id => relation_id
		$va_hors_ops = array();
		$qr = $po_db->query("
			SELECT mxo.relation_id, o.object_id, o.idno, o.type_id
			FROM ca_movements_x_objects mxo
			INNER JOIN ca_objects o ON o.object_id = mxo.object_id AND o.deleted = 0
			WHERE mxo.movement_id = ? AND o.type_id IN ({$vs_ph_types})
		", array_merge(array($pn_movement_id), $va_type_ids));
		while ($qr->nextRow()) {
			$vn_oid = (int)$qr->get('object_id');
			$va_linked[$vn_oid] = (int)$qr->get('relation_id');
			if (!isset($va_contenants[$vn_oid])) {
				$va_contenants[$vn_oid] = array(
					'object_id' => $vn_oid,
					'idno' => $qr->get('idno'),
					'type_id' => (int)$qr->get('type_id'),
				);
				$va_hors_ops[] = $vn_oid;
			}
		}

		return array($va_ops, $va_contenants, $va_linked, $va_hors_ops, $va_type_map);
	}
	# -------------------------------------------------------
	/**
	 * Affichage du popup de sélection (fragment HTML, chargé en AJAX).
	 */
	public function SelectObject() {
		if (!$this->checkUserCanEditMovements()) {
			$this->view->setVar('error', _t("Accès refusé : vous devez être connecté et autorisé à éditer les mouvements."));
			$this->render('select_object_error_html.php');
			return;
		}

		$vn_movement_id = (int)$this->request->getParameter('movement_id', pInteger);
		$t_mov = new ca_movements($vn_movement_id);
		if (!$t_mov->getPrimaryKey() || ($t_mov->getTypeCode() !== 'versement') || (bool)$t_mov->get('deleted')) {
			$this->view->setVar('error', _t("Versement introuvable (mouvement #%1)", $vn_movement_id));
			$this->render('select_object_error_html.php');
			return;
		}

		$o_db = new Db();
		try {
			list($va_ops, $va_contenants, $va_linked, $va_hors_ops, $va_type_map) = $this->loadScope($o_db, $vn_movement_id);

			$va_oids = array_keys($va_contenants);

			// ----- Attributs affichés : volume, description, référentiel, matière —
			// éléments résolus par code (les element_id diffèrent entre bases).
			$va_el = array();
			$qr = $o_db->query("
				SELECT element_id, element_code FROM ca_metadata_elements
				WHERE element_code IN ('volume_caisse','description','referentiel','inrap_materiaux') AND deleted = 0
			");
			while ($qr->nextRow()) { $va_el[$qr->get('element_code')] = (int)$qr->get('element_id'); }

			$va_list_item_ids = array();
			if (sizeof($va_oids) && sizeof($va_el)) {
				$vs_ph_oids = implode(',', array_fill(0, sizeof($va_oids), '?'));
				$vs_ph_els  = implode(',', array_fill(0, sizeof($va_el), '?'));
				$qr = $o_db->query("
					SELECT a.row_id, av.element_id, av.value_longtext1, av.value_decimal1, av.item_id
					FROM ca_attributes a
					INNER JOIN ca_attribute_values av ON av.attribute_id = a.attribute_id
					WHERE a.table_num = 57 AND a.row_id IN ({$vs_ph_oids}) AND av.element_id IN ({$vs_ph_els})
				", array_merge($va_oids, array_values($va_el)));
				$va_codes_by_el = array_flip($va_el);
				while ($qr->nextRow()) {
					$vn_oid = (int)$qr->get('row_id');
					$vs_code = $va_codes_by_el[(int)$qr->get('element_id')] ?? null;
					if (!$vs_code || !isset($va_contenants[$vn_oid])) { continue; }
					switch ($vs_code) {
						case 'volume_caisse':
							$va_contenants[$vn_oid]['volume'] = (float)$qr->get('value_decimal1');
							break;
						case 'referentiel':
						case 'inrap_materiaux':
							if ($vn_item = (int)$qr->get('item_id')) {
								$va_contenants[$vn_oid][$vs_code.'_items'][] = $vn_item;
								$va_list_item_ids[] = $vn_item;
							}
							break;
						default: // description
							$vs_val = trim((string)$qr->get('value_longtext1'));
							if ($vs_val !== '') { $va_contenants[$vn_oid][$vs_code][] = $vs_val; }
					}
				}
			}

			// Libellés des items de liste (types + référentiels + matières)
			$va_labels = $this->getListItemLabels($o_db, array_merge($va_list_item_ids, array_values($va_type_map)));

			// ----- Contenu lié : « au moins un enregistrement lié » = objets liés
			// (2 sens), occurrences ou représentations. Les relations à l'opération
			// (toujours présentes), aux entités/lieux/emplacements (héritées quasi
			// systématiquement : 231 000/228 000/154 000 relations constatées sur
			// les contenants) et aux mouvements (le rattachement lui-même) ne sont
			// PAS des « contenus » : les compter rendrait le filtre inopérant.
			$va_has_content = array();
			if (sizeof($va_oids)) {
				$vs_ph_oids = implode(',', array_fill(0, sizeof($va_oids), '?'));
				$qr = $o_db->query("
					SELECT r.object_left_id AS oid FROM ca_objects_x_objects r
						INNER JOIN ca_objects o2 ON o2.object_id = r.object_right_id AND o2.deleted = 0
						WHERE r.object_left_id IN ({$vs_ph_oids})
					UNION
					SELECT r.object_right_id FROM ca_objects_x_objects r
						INNER JOIN ca_objects o2 ON o2.object_id = r.object_left_id AND o2.deleted = 0
						WHERE r.object_right_id IN ({$vs_ph_oids})
					UNION
					SELECT r.object_id FROM ca_objects_x_occurrences r
						INNER JOIN ca_occurrences oc ON oc.occurrence_id = r.occurrence_id AND oc.deleted = 0
						WHERE r.object_id IN ({$vs_ph_oids})
					UNION
					SELECT r.object_id FROM ca_objects_x_object_representations r
						WHERE r.object_id IN ({$vs_ph_oids})
				", array_merge($va_oids, $va_oids, $va_oids, $va_oids));
				while ($qr->nextRow()) { $va_has_content[(int)$qr->get('oid')] = true; }
			}

			$this->view->setVar('movement_id', $vn_movement_id);
			$this->view->setVar('movement_idno', $t_mov->get('idno'));
			$this->view->setVar('movement_label', $t_mov->getLabelForDisplay());
			$this->view->setVar('ops', $va_ops);
			$this->view->setVar('contenants', $va_contenants);
			$this->view->setVar('linked_ids', array_keys($va_linked));
			$this->view->setVar('hors_ops', $va_hors_ops);
			$this->view->setVar('type_map', $va_type_map);          // code => item_id
			$this->view->setVar('item_labels', $va_labels);          // item_id => libellé
			$this->view->setVar('has_content', $va_has_content);    // object_id => true
			$this->view->setVar('validate_url', caNavUrl($this->request, "selectionContenants", "Selection", "Validate"));
			$this->view->setVar('editor_url_base', caNavUrl($this->request, "editor/objects", "ObjectEditor", "Summary"));
			$this->view->setVar('screen132_url', caNavUrl($this->request, "editor/movements", "MovementEditor", "Edit/Screen132", array("movement_id" => $vn_movement_id)));
			$this->render('select_object_html.php');
		} catch (Exception $e) {
			$this->view->setVar('error', _t("Erreur lors de la préparation de l'écran : %1", $e->getMessage()));
			$this->render('select_object_error_html.php');
		}
	}
	# -------------------------------------------------------
	/**
	 * Validation unique de la sélection : pose/retire les relations
	 * mouvement→objet puis ENFILE LE VERSEMENT dans _inrap_recalc_queue.
	 * La bascule des types « versé » et les recalculs sont faits par le
	 * worker (recalc_worker.php, cron 2 min) — jamais ici.
	 */
	public function Validate() {
		if (!$this->checkUserCanEditMovements()) {
			$this->sendJSON(array('ok' => false, 'error' => _t("Accès refusé")));
			return;
		}
		if ($this->request->getRequestMethod() !== 'POST') {
			$this->sendJSON(array('ok' => false, 'error' => _t("Méthode non autorisée")));
			return;
		}

		$vn_movement_id = (int)$this->request->getParameter('movement_id', pInteger);
		$vs_objects = (string)$this->request->getParameter('objects', pString);

		$t_mov = new ca_movements($vn_movement_id);
		if (!$t_mov->getPrimaryKey() || ($t_mov->getTypeCode() !== 'versement') || (bool)$t_mov->get('deleted')) {
			$this->sendJSON(array('ok' => false, 'error' => _t("Versement introuvable (mouvement #%1)", $vn_movement_id)));
			return;
		}

		$va_wanted = array();
		foreach (preg_split('/[;,]/', $vs_objects, -1, PREG_SPLIT_NO_EMPTY) as $vs_id) {
			if (($vn = (int)trim($vs_id)) > 0) { $va_wanted[$vn] = true; }
		}

		$o_db = new Db();
		try {
			list($va_ops, $va_contenants, $va_linked, , ) = $this->loadScope($o_db, $vn_movement_id);
		} catch (Exception $e) {
			$this->sendJSON(array('ok' => false, 'error' => $e->getMessage()));
			return;
		}

		// La sélection est bornée au périmètre de l'écran : contenants des
		// opérations du versement + contenants déjà rattachés. Tout id hors
		// périmètre est ignoré (et signalé), jamais rattaché.
		$va_out_of_scope = array_diff(array_keys($va_wanted), array_keys($va_contenants));
		$va_wanted = array_intersect_key($va_wanted, $va_contenants);

		$va_to_add = array_diff(array_keys($va_wanted), array_keys($va_linked));
		$va_to_remove = array_diff(array_keys($va_linked), array_keys($va_wanted));

		$vs_rel_type = (string)$this->opo_config->get('movement_object_relationship_type');
		if (!$vs_rel_type) { $vs_rel_type = 'related'; }

		$t_mov->setMode(ACCESS_WRITE);
		$va_errors = array();
		$vn_added = $vn_removed = 0;

		foreach ($va_to_add as $vn_oid) {
			$t_mov->clearErrors();
			$vm_rel = $t_mov->addRelationship('ca_objects', $vn_oid, $vs_rel_type);
			if (!$vm_rel || $t_mov->numErrors()) {
				$va_errors[] = _t("Rattachement impossible du contenant #%1 : %2", $vn_oid, join('; ', $t_mov->getErrors()));
			} else {
				$vn_added++;
			}
		}
		foreach ($va_to_remove as $vn_oid) {
			$t_mov->clearErrors();
			$t_mov->removeRelationship('ca_objects', $va_linked[$vn_oid]);
			if ($t_mov->numErrors()) {
				$va_errors[] = _t("Retrait impossible du contenant #%1 : %2", $vn_oid, join('; ', $t_mov->getErrors()));
			} else {
				$vn_removed++;
			}
		}

		// File du worker : même mécanisme que prepopulateInrapPlugin (hookSaveItem,
		// case ca_movements/1796) — coalescence par UNIQUE(table_num, row_id).
		if ($vn_added || $vn_removed) {
			try {
				$o_db->query("
					INSERT INTO _inrap_recalc_queue (table_num, row_id, status, enqueued_at)
					VALUES (?, ?, 'pending', ?)
					ON DUPLICATE KEY UPDATE status='pending', enqueued_at=VALUES(enqueued_at), error_msg=NULL
				", array($t_mov->tableNum(), $vn_movement_id, time()));
			} catch (Exception $e) {
				$va_errors[] = _t("Relations enregistrées mais mise en file du recalcul impossible : %1", $e->getMessage());
			}
		}

		$this->sendJSON(array(
			'ok' => !sizeof($va_errors),
			'added' => $vn_added,
			'removed' => $vn_removed,
			'total' => sizeof($va_wanted),
			'ignored_out_of_scope' => array_values($va_out_of_scope),
			'errors' => $va_errors
		));
	}
	# -------------------------------------------------------
}
/* === D2026-0103 — écran de sélection des contenants : fin === */
