<?php
/* ----------------------------------------------------------------------
 * plugins/etatsMTE/controllers/MediaController.php
 * ----------------------------------------------------------------------
 * Suppression ciblée d'un média d'ATTRIBUT (dt16) — ex. photos d'inventaire
 * (inv_photo_1/2/3) et de restauration. CollectiveAccess ne propose pas de
 * suppression "en place" d'un sous-champ média de conteneur (un média vide au
 * save = "conserver"). On supprime donc directement la/les ligne(s)
 * ca_attribute_values du média pour l'élément visé sur l'objet, ce qui
 * CONSERVE les autres sous-champs du conteneur.
 *
 * Endpoint : /etatsMTE/Media/RemovePhoto  (POST/GET ajax)
 *   object_id  : id de l'objet
 *   element_id : élément média (liste blanche ci-dessous)
 * ---------------------------------------------------------------------- */

require_once(__CA_MODELS_DIR__.'/ca_objects.php');
require_once(__CA_MODELS_DIR__.'/ca_attribute_values.php');

error_reporting(E_ERROR);

class MediaController extends ActionController {
	# éléments média dont la suppression est autorisée (photos inventaire + restauration)
	private $va_allowed = [781, 782, 783, 765, 766, 767, 769, 770, 771];

	public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
		parent::__construct($po_request, $po_response, $pa_view_paths);
	}

	# -------------------------------------------------------
	public function RemovePhoto() {
		$req = $this->getRequest();
		header('Content-Type: application/json; charset=utf-8');

		$vn_oid = (int)$req->getParameter('object_id', pInteger);
		$vn_eid = (int)$req->getParameter('element_id', pInteger);

		if (!$vn_oid || !in_array($vn_eid, $this->va_allowed, true)) {
			echo json_encode(['ok' => false, 'error' => 'paramètres invalides']); exit;
		}
		if (!$req->getUserID() || !$req->user->canDoAction('can_edit_ca_objects')) {
			echo json_encode(['ok' => false, 'error' => 'accès refusé']); exit;
		}
		$t = new ca_objects($vn_oid);
		if (!$t->getPrimaryKey() || (int)$t->get('deleted') === 1) {
			echo json_encode(['ok' => false, 'error' => 'objet introuvable']); exit;
		}

		$o_db = new Db();
		$qr = $o_db->query(
			"SELECT av.value_id
			 FROM ca_attribute_values av
			 JOIN ca_attributes a ON a.attribute_id = av.attribute_id
			 WHERE a.table_num = 57 AND a.row_id = ? AND av.element_id = ?",
			[$vn_oid, $vn_eid]
		);
		$vn_removed = 0;
		while ($qr->nextRow()) {
			$t_val = new ca_attribute_values($qr->get('value_id'));
			if ($t_val->getPrimaryKey()) {
				$t_val->setMode(ACCESS_WRITE);
				$t_val->delete();
				if (!$t_val->numErrors()) { $vn_removed++; }
			}
		}

		// Réindexation de l'objet (le média retiré n'affecte quasiment pas l'index,
		// mais on force une réindexation propre) + purge du cache applicatif.
		if ($vn_removed > 0) {
			try {
				require_once(__CA_LIB_DIR__.'/Search/SearchIndexer.php');
				$o_idx = new SearchIndexer();
				$o_idx->indexRow(57, $vn_oid, ['object_id' => $vn_oid], false, null, [$vn_oid => true]);
			} catch (Exception $e) { /* non bloquant */ }
		}

		echo json_encode(['ok' => true, 'removed' => $vn_removed]);
		exit;
	}
}
