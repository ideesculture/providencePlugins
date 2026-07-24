<?php
/* ----------------------------------------------------------------------
 * plugins/etatsMTE/controllers/RechercheAvanceeController.php
 * ----------------------------------------------------------------------
 * Recherche avancée MTE : formulaire custom -> requête SOLR -> recherche simple.
 * Flux : Formulaire (POST) -> Traitement (construit la requête + page d'attente
 *        qui redirige vers find/SearchObjects/Index/search/<REQUÊTE>).
 * ----------------------------------------------------------------------
 */

require_once(__CA_MODELS_DIR__.'/ca_lists.php');

error_reporting(E_ERROR);

class RechercheAvanceeController extends ActionController {
	# -------------------------------------------------------
	protected $ops_plugin_name;
	protected $ops_plugin_path;

	// Listes
	const LIST_SITE      = 160;  // site_nom1
	const LIST_BATIMENT  = 164;  // site_batiment1
	const LIST_ETAGE     = 163;  // site_etage
	const LIST_CATEGORIE = 157;  // domaine_logement
	const LIST_TYPE      = 168;  // denomination (converti texte -> liste le 18/06)
	const LIST_CONSTAT   = 114;  // inv_constat (présence du bien)

	# -------------------------------------------------------
	public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
		parent::__construct($po_request, $po_response, $pa_view_paths);
		$this->ops_plugin_name = "etatsMTE";
		$this->ops_plugin_path = __CA_APP_DIR__."/plugins/".$this->ops_plugin_name;
	}

	# -------------------------------------------------------
	# Formulaire — affiche l'écran de recherche avancée
	# -------------------------------------------------------
	public function Formulaire() {
		$this->view->setVar('deposants',  $this->_getTypes());               // Nom du déposant = type d'objet
		$this->view->setVar('categories', $this->_getListItems(self::LIST_CATEGORIE));
		$this->view->setVar('types',      $this->_getListItems(self::LIST_TYPE));  // Type = liste (denomination)
		$this->view->setVar('sites',      $this->_getListItems(self::LIST_SITE));
		$this->view->setVar('batiments',  $this->_getListItems(self::LIST_BATIMENT));
		$this->view->setVar('etages',     $this->_getListItems(self::LIST_ETAGE));
		// Constat présence : exclure -- (546) / Manquant (548) / Détruit (549) du menu recherche (retour MTE 03/07 ; liste 114 inchangée par ailleurs)
		$va_constats = $this->_getListItems(self::LIST_CONSTAT);
		unset($va_constats[546], $va_constats[548], $va_constats[549]);
		$this->view->setVar('constats',   $va_constats);
		$this->view->setVar('traitement_url', caNavUrl($this->getRequest(), "etatsMTE", "RechercheAvancee", "Traitement"));
		$this->render('recherche_avancee_html.php');
	}

	# -------------------------------------------------------
	# Traitement — construit la requête SOLR puis page d'attente + redirection
	# -------------------------------------------------------
	public function Traitement() {
		$req = $this->getRequest();
		$q = [];

		// --- Champs texte ---
		if (($v = trim($req->getParameter('numinv', pString))) !== '')      { $q[] = 'ca_objects.numinv_deposant:"'.$this->esc($v).'"'; }
		if (($v = trim($req->getParameter('titre', pString))) !== '')       { $q[] = 'ca_objects.preferred_labels:"'.$this->esc($v).'"'; }

		// --- Listes (item_id) ---
		if (($id = (int)$req->getParameter('deposant', pInteger)) > 0)  { $q[] = 'ca_objects.type_id:'.$id; }
		if (($id = (int)$req->getParameter('categorie', pInteger)) > 0) { $q[] = 'ca_objects.domaine_logement:'.$id; }
		if (($id = (int)$req->getParameter('denomination', pInteger)) > 0) { $q[] = 'ca_objects.denomination:'.$id; }
		if (($id = (int)$req->getParameter('site', pInteger)) > 0)      { $q[] = 'ca_objects.site.site_nom1:'.$id; }
		if (($id = (int)$req->getParameter('batiment', pInteger)) > 0)  { $q[] = 'ca_objects.site.site_batiment1:'.$id; }
		if (($id = (int)$req->getParameter('etage', pInteger)) > 0)     { $q[] = 'ca_objects.site.site_etage:'.$id; }
		if (($id = (int)$req->getParameter('constat', pInteger)) > 0)   { $q[] = 'ca_objects.inventaire_cont.inv_constat:'.$id; }

		// --- Cases à cocher (champs Oui/Non calculés) ---
		$f_inv  = (int)$req->getParameter('f_inventorie', pInteger);
		$f_rec  = (int)$req->getParameter('f_recole', pInteger);
		$f_res  = (int)$req->getParameter('f_restaure', pInteger);
		$f_rest = (int)$req->getParameter('f_restitue', pInteger);
		if ($f_inv)  { $q[] = 'ca_objects.calc_inventorie:"Oui"'; }
		if ($f_rec)  { $q[] = 'ca_objects.calc_recole:"Oui"'; }
		if ($f_res)  { $q[] = 'ca_objects.calc_restaure:"Oui"'; }
		if ($f_rest) { $q[] = 'ca_objects.calc_restitue:"Oui"'; }

		// --- Intervalle de dates (règle MTE, cf. PV) ---
		$dd = $this->normDate($req->getParameter('date_debut', pString));
		$df = $this->normDate($req->getParameter('date_fin', pString));
		if ($dd !== '' || $df !== '') {
			$INV = 'ca_objects.inventaire_cont.inv_date';
			$REC = 'ca_objects.recolement_inv.der_date_reco';
			$FAR = '2100';
			$LOW = '0000';
			$date_clause = null;
			if ($f_inv && $f_rec) {
				// Les deux cochés : (inv>=deb OR rec>=deb) AND (inv>=fin OR rec>=fin)
				$parts = [];
				if ($dd !== '') { $parts[] = '('.$INV.':['.$dd.' TO '.$FAR.'] OR '.$REC.':['.$dd.' TO '.$FAR.'])'; }
				if ($df !== '') { $parts[] = '('.$INV.':['.$df.' TO '.$FAR.'] OR '.$REC.':['.$df.' TO '.$FAR.'])'; }
				if ($parts) { $date_clause = implode(' AND ', $parts); }
			} elseif ($f_inv) {
				$date_clause = $INV.':['.($dd !== '' ? $dd : $LOW).' TO '.($df !== '' ? $df : $FAR).']';
			} elseif ($f_rec) {
				$date_clause = $REC.':['.($dd !== '' ? $dd : $LOW).' TO '.($df !== '' ? $df : $FAR).']';
			}
			// Si ni inventorié ni récolé coché : l'intervalle est ignoré (cf. règle MTE)
			if ($date_clause) { $q[] = $date_clause; }
		}

		$query = implode(' AND ', $q);

		// URL de recherche simple : /find/SearchObjects/Index/search/<REQUÊTE encodée>
		$base = caNavUrl($this->getRequest(), 'find', 'SearchObjects', 'Index');
		$search_url = $query !== '' ? ($base.'/search/'.rawurlencode($query)) : ($base.'/reset/save');

		$this->view->setVar('search_url', $search_url);
		$this->view->setVar('query', $query);
		$this->render('recherche_traitement_html.php');
	}

	# -------------------------------------------------------
	# Helpers
	# -------------------------------------------------------
	private function esc($s) {
		// Échappe les guillemets pour ne pas casser la clause "..."
		return str_replace('"', '\\"', $s);
	}

	private function normDate($s) {
		$s = trim((string)$s);
		if ($s === '') { return ''; }
		// input type=date -> aaaa-mm-jj (déjà bon). Si jj/mm/aaaa -> convertir.
		if (preg_match('!^(\d{1,2})/(\d{1,2})/(\d{4})$!', $s, $m)) {
			return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
		}
		return $s;
	}

	private function _getTypes() {
		$o_db = new Db();
		$qr = $o_db->query(
			"SELECT li.item_id, ll.name_singular
			 FROM ca_list_items li
			 JOIN ca_lists l ON li.list_id = l.list_id AND l.list_code = 'object_types'
			 JOIN ca_list_item_labels ll ON li.item_id = ll.item_id AND ll.is_preferred = 1
			 WHERE li.parent_id IS NOT NULL AND li.is_enabled = 1
			 ORDER BY ll.name_singular"
		);
		$va = [];
		while($qr->nextRow()) { $va[$qr->get("item_id")] = $qr->get("name_singular"); }
		return $va;
	}

	private function _getListItems($pn_list_id) {
		$o_db = new Db();
		$qr = $o_db->query(
			"SELECT li.item_id, ll.name_singular
			 FROM ca_list_items li
			 JOIN ca_list_item_labels ll ON li.item_id = ll.item_id AND ll.is_preferred = 1
			 WHERE li.list_id = ? AND li.parent_id IS NOT NULL AND li.is_enabled = 1
			 ORDER BY ll.name_singular",
			$pn_list_id
		);
		$va = [];
		while($qr->nextRow()) { $va[$qr->get("item_id")] = $qr->get("name_singular"); }
		return $va;
	}
}
?>
</content>
