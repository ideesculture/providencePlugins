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
	const LIST_NATURE_RETRAIT = 166; // nature_retrait : Don / Destruction / Restitution / Vente 

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
		// permettre la recherche des biens donnés, détruits, restitués ou vendus
		$this->view->setVar('natures_retrait', $this->_getListItems(self::LIST_NATURE_RETRAIT));
		// message affiché si le contrôle serveur a rejeté l'intervalle de dates
		$this->view->setVar('erreur', ($this->getRequest()->getParameter('err', pString) === 'dates')
			? 'La date de fin doit être postérieure ou égale à la date de début.' : '');
		$this->view->setVar('traitement_url', caNavUrl($this->getRequest(), "etatsMTE", "RechercheAvancee", "Traitement"));
		$this->render('recherche_avancee_html.php');
	}

	# -------------------------------------------------------
	# Traitement — construit la requête de recherche puis page d'attente + redirection
	# (moteur : SqlSearch2, cf. app.conf:880 — ni SOLR ni Elasticsearch sur cette instance)
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
		// le filtre Site porte sur la localisation
		// d'INVENTAIRE (inv_site, 777) et non plus de dépôt (site_nom1, 709).
		// Chemin volontairement à 3 segments : le sous-conteneur inventaire_ligne2 doit être
		// SAUTÉ. Une 4e composante serait silencieusement ignorée par SqlSearch2.
		// RÈGLE DE REPLI (règle retenue, reprise de la règle retenue
		// en spécification fonctionnelle) : on interroge la localisation
		// d'INVENTAIRE ; si le bien n'a jamais fait l'objet d'un inventaire, on retient sa
		// localisation de DÉPÔT. Le champ calculé calc_inventorie (806), alimenté par le plugin
		// à chaque enregistrement, vaut "Non" pour les 201 biens sans relevé — c'est lui qui
		// rend la règle exprimable, la négation d'absence n'étant pas supportée par SqlSearch2.
		// Mesuré le 20/09 sur 7 cas (4 sites, 3 bâtiments) : concordance exacte avec le SQL.
		// le repli élargit le résultat biens ; « Bâtiment 5 » reste à 70.
		// demande MOA : « la liste des œuvres affichées devrait
		// retourner la DERNIÈRE localisation ». L'ancienne clause retenait n'importe quel relevé
		// d'inventaire : MTE_729, dont le dernier relevé est aux Réserves de Nanterre, ressortait
		// sur Saint-Germain + Bâtiment 5. SqlSearch2 ne sait pas exprimer « dernière occurrence »,
		// d'où le champ calculé calc_derniere_loc_codes (812) : il porte le code du site et du
		// bâtiment du DERNIER relevé d'inventaire — ou, à défaut de tout relevé, ceux du DÉPÔT,
		// conformément à la règle retenue en spécification fonctionnelle.
		// Codes « S<item_id> » / « B<item_id> » : aucun risque d'homonymie, contrairement à un
		// filtrage sur le libellé (mesuré : 3 faux positifs sur « Fontenoy »).
		// Le champ est maintenu par etatsMTEPlugin à chaque enregistrement.
		if (($id = (int)$req->getParameter('site', pInteger)) > 0)      { $q[] = 'ca_objects.calc_derniere_loc_codes:"S'.$id.'"'; }
		if (($id = (int)$req->getParameter('batiment', pInteger)) > 0)  { $q[] = 'ca_objects.calc_derniere_loc_codes:"B'.$id.'"'; }
		// Étage : VOLONTAIREMENT laissé sur la localisation de dépôt (site_etage, 712).
		// Décision la maîtrise d’ouvrage du 20/09/2026 : s'en tenir à ce que le client demande (site et bâtiment).
		// Incohérence assumée, ne pas « corriger » sans demande explicite.
		if (($id = (int)$req->getParameter('etage', pInteger)) > 0)     { $q[] = 'ca_objects.site.site_etage:'.$id; }
		if (($id = (int)$req->getParameter('constat', pInteger)) > 0)   { $q[] = 'ca_objects.inventaire_cont.inv_constat:'.$id; }
		// nature du retrait (Don, Destruction, Restitution, Vente).
		// Recherche par LIBELLÉ : mesuré le 16/09/2026 (moteur SqlSearch2), la recherche par libellé
		// couvre au moins autant de valeurs que la recherche par item_id (identique sur catégorie,
		// type, site, bâtiment, étage ; inv_constat : 1257 par libellé contre 1234 par id, certaines
		// valeurs étant stockées en texte). Le libellé est donc le critère le plus sûr ici.
		// Valeur conservée dans une variable dédiée (et non dans $id, réutilisé par les filtres
		// voisins) : elle sert plus bas à rattacher l'intervalle de dates au bon champ.
		$f_nature = (int)$req->getParameter('nature_retrait', pInteger);
		if ($f_nature > 0) {
			if (($lbl = $this->_itemLabel($f_nature)) !== '') { $q[] = 'ca_objects.restitution_cont2.nature_retrait:"'.$this->esc($lbl).'"'; }
		}

		// --- Cases à cocher (champs Oui/Non calculés) ---
		$f_inv  = (int)$req->getParameter('f_inventorie', pInteger);
		$f_rec  = (int)$req->getParameter('f_recole', pInteger);
		$f_res  = (int)$req->getParameter('f_restaure', pInteger);
		$f_rest = (int)$req->getParameter('f_restitue', pInteger);
		if ($f_inv)  { $q[] = 'ca_objects.calc_inventorie:"Oui"'; }
		if ($f_rec)  { $q[] = 'ca_objects.calc_recole:"Oui"'; }
		if ($f_res)  { $q[] = 'ca_objects.calc_restaure:"Oui"'; }
		if ($f_rest) { $q[] = 'ca_objects.calc_restitue:"Oui"'; }

		// « Recherche des œuvres disparues ou don ou destruction ».
		// La NATURE du retrait n'a jamais été transmise (colonne source vide sur les 3 767 lignes,
		// élément 804 à zéro en recette comme en production) : un filtre sur ce champ ne peut donc
		// rien retourner. En revanche le FAIT du retrait et celui de la disparition sont, eux, bien
		// migrés — on filtre donc sur eux, ce qui rend la ligne 10 réellement exploitable.
		//   « Bien retiré »  : date de retrait renseignée      -> 71 biens (mesuré le 21/09)
		//   « Bien disparu » : date de disparition renseignée  -> 12 biens (mesuré le 21/09)
		// Forme d'intervalle BORNÉE obligatoire : le joker ( :* ) renvoie les 1 463 œuvres, donc un
		// filtre silencieusement ignoré, et [1 TO 3000] renvoie 0. Chemin à 3 segments (conteneur
		// racine + code du sous-élément) : une 4e composante serait ignorée sans erreur.
		$f_ret  = (int)$req->getParameter('f_retire', pInteger);
		$f_disp = (int)$req->getParameter('f_disparu', pInteger);
		$vs_ret  = 'ca_objects.restitution_cont2.restitution_date:[1900 TO 2100]';
		$vs_disp = 'ca_objects.inventaire_cont.inv_date_disp:[1900 TO 2100]';
		// CORRECTIF du 21/09/2026 (2e contrôle indépendant) : ces DEUX cases se combinent en OU,
		// pas en ET comme les quatre autres. Motif : la ligne 10 demande « tous les biens disparus,
		// OU don OU destruction ». Combinées en ET elles renvoyaient 0 — aucun bien ne porte à la
		// fois une date de retrait et une date de disparition — c'est-à-dire la pire réponse
		// possible à la question posée. En OU : 71 + 12 = 83 biens, les deux ensembles étant
		// disjoints. Exception assumée et volontaire au comportement des autres cases.
		if ($f_ret && $f_disp) { $q[] = '('.$vs_ret.' OR '.$vs_disp.')'; }
		elseif ($f_ret)        { $q[] = $vs_ret; }
		elseif ($f_disp)       { $q[] = $vs_disp; }

		// --- Intervalle de dates (règle MTE, cf. PV) ---
		$dd = $this->normDate($req->getParameter('date_debut', pString));
		$df = $this->normDate($req->getParameter('date_fin', pString));
		// filet serveur si le contrôle navigateur a été contourné
		if ($dd !== '' && $df !== '' && $df < $dd) {
			$this->getResponse()->setRedirect(
				caNavUrl($this->getRequest(), 'etatsMTE', 'RechercheAvancee', 'Formulaire', ['err' => 'dates'])
			);
			return;
		}
		if ($dd !== '' || $df !== '') {
			// l'intervalle ne portait que sur les dates d'inventaire
			// et de récolement. Il porte désormais aussi sur les dates de restauration et de
			// restitution, et, si aucune case n'est cochée, sur les dates de dépôt (règle MTE).
			//
			// Borne basse par champ : le moteur ignore silencieusement une borne située hors des
			// dateRangeBoundaries de l'élément (mesuré le 18/09/2026 :
			// restitution_date:[1800-01-01 TO 2100] -> 0 résultat, [1900-01-01 TO 2100-12-31] -> 70).
			$FAR = '2100';
			$va_champs = [];
			if ($f_inv)  { $va_champs[] = ['ca_objects.inventaire_cont.inv_date',                   '1800-01-01']; }
			if ($f_rec)  { $va_champs[] = ['ca_objects.recolement_inv.der_date_reco',               '1800-01-01']; }
			if ($f_res)  { $va_champs[] = ['ca_objects.restauration_cont2.date_restauration_date',  '1900-01-01']; }
			if ($f_rest) { $va_champs[] = ['ca_objects.restitution_cont2.restitution_date',         '1900-01-01']; }
			// CORRECTIF issu du contrôle indépendant.
			// Sans ces deux lignes, cocher « Bien retiré » avec une période faisait porter
			// l'intervalle sur la DATE DE DÉPÔT (cas de repli ci-dessous), sans aucun signal :
			// « biens retirés en 2020 » renvoyait 12 biens alors qu'aucun retrait n'a eu lieu
			// cette année-là. Pire, la même recherche renvoyait 0 — la réponse juste — dès que
			// « Bien restitué » était cochée en plus : une case sans rapport changeait le sens
			// de la question. Bornes basses calées sur les dateRangeBoundaries des éléments
			// (01/01/1900), sans quoi le moteur ignore silencieusement l'intervalle.
			if ($f_ret)  { $va_champs[] = ['ca_objects.restitution_cont2.restitution_date',         '1900-01-01']; }
			if ($f_disp) { $va_champs[] = ['ca_objects.inventaire_cont.inv_date_disp',              '1900-01-01']; }
			// Menu « Nature du retrait » (règle retenue : le menu est CONSERVÉ).
			// Sans cette ligne, choisir une nature ET une période faisait porter l'intervalle sur
			// la DATE DE DÉPÔT — le même défaut silencieux que celui corrigé ce jour pour les deux
			// cases. Il est aujourd'hui MASQUÉ par l'absence de données (la nature du retrait n'est
			// renseignée pour aucun bien : 0 Don, 0 Destruction, 0 Restitution, 0 Vente dans le
			// fichier source), et surgirait dès la première saisie. La période porte donc sur la
			// date de retrait, seul champ daté qui ait un sens avec ce critère.
			if ($f_nature) { $va_champs[] = ['ca_objects.restitution_cont2.restitution_date',        '1900-01-01']; }
			if (!$va_champs) { $va_champs[] = ['ca_objects.date_depot', '1800-01-01']; }

			$va_clauses = [];
			foreach ($va_champs as $va_champ) {
				list($vs_champ, $vs_borne_min) = $va_champ;
				$vs_low  = ($dd !== '') ? $dd : $vs_borne_min;
				$vs_high = ($df !== '') ? $df : $FAR;

				// Correction du 18/09/2026 (contrôle indépendant) : remonter la borne basse au minimum
				// du champ produisait un intervalle INVERSÉ lorsque la période demandée est antérieure
				// à ce minimum (ex. restitution, minimum 1900, recherche 01/01/1800 -> 02/01/1800).
				// Or le moteur IGNORE silencieusement un intervalle inversé : il ne restait que la case
				// cochée et la recherche renvoyait 71 œuvres au lieu d'aucune. Mesuré ce jour :
				//   date seule [1900-01-01 TO 1800-01-02] -> 0, mais combinée en AND -> 71.
				// Quand la période demandée ne recoupe pas la plage couverte par le champ, il n'y a
				// par définition aucune œuvre à retourner : on le dit explicitement au moteur.
				if ($vs_high < $vs_borne_min) {
					// L'intervalle doit rester DANS les bornes du champ pour ne pas être neutralisé :
					// une date hors bornes (9999) est ignorée exactement comme un intervalle inversé.
					// On vise donc la fin de la plage autorisée, où aucune donnée n'existe.
					$va_clauses[] = $vs_champ.':[2100-12-30 TO 2100-12-31]';
					continue;
				}
				if ($vs_low < $vs_borne_min) { $vs_low = $vs_borne_min; }
				$va_clauses[] = $vs_champ.':['.$vs_low.' TO '.$vs_high.']';
			}
			$q[] = (count($va_clauses) > 1) ? '('.join(' OR ', $va_clauses).')' : $va_clauses[0];
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
			 -- li.deleted = 0 est INDISPENSABLE. Les types retirés
			 -- le 18/09 (AUTRE, Gobelins, INV, MNAC) le sont par deleted = 1, leur is_enabled restant
			 -- à 1 : le menu « Nouveau » natif les masquait, ce formulaire les affichait encore.
			 WHERE li.parent_id IS NOT NULL AND li.is_enabled = 1 AND li.deleted = 0
			 ORDER BY ll.name_singular"
		);
		$va = [];
		while($qr->nextRow()) { $va[$qr->get("item_id")] = $qr->get("name_singular"); }
		return $va;
	}

	# -------------------------------------------------------
	# Libellé préféré d'un item de liste (la recherche se fait par libellé, cf. Traitement)
	private function _itemLabel($pn_item_id) {
		$pn_item_id = (int)$pn_item_id;
		if ($pn_item_id <= 0) { return ''; }
		$o_db = new Db();
		$qr = $o_db->query(
			"SELECT l.name_singular FROM ca_list_item_labels l
			 JOIN ca_list_items i ON i.item_id = l.item_id AND i.deleted = 0
			 WHERE l.item_id = ? AND l.is_preferred = 1 LIMIT 1",
			[$pn_item_id]
		);
		return $qr->nextRow() ? trim((string)$qr->get('name_singular')) : '';
	}

	# -------------------------------------------------------
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
