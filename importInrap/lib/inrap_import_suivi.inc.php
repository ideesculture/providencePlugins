<?php
/**
 * inrap_import_suivi.inc.php — suivi d'un import côté serveur : état persistant, reprise, bilan.
 *
 * 23/09/2026 GM (ticket 8047).
 *
 * LE DÉFAUT. L'import avance d'une ligne par requête HTTP, chaque page renvoyant la suivante au bout
 * d'une demi-seconde. Tout son état — lignes cochées, fiches traitées, lignes en échec — voyageait
 * dans des champs cachés de la page. Si le navigateur cessait d'enchaîner (onglet fermé, clic
 * ailleurs, veille du poste), l'import s'arrêtait net, sans trace côté serveur et sans que personne
 * le sache : la page affichait « Import en cours… » indéfiniment. C'est ce qui est arrivé le
 * 21/09/2026 à 14 h 40 : arrêt après la ligne 139 sur 253, jamais signalé.
 *
 * Relancé ensuite, le même fichier présentait les 29 objets préexistants comme de simples
 * « doublons » ; exclus, ils n'ont jamais été rattachés au mouvement. Et la page de fin annonçait
 * « N mobiliers importés avec succès » sans rien dire des lignes du fichier restées sans effet.
 *
 * LE REMÈDE.
 *  1. L'état de chaque import est tenu côté serveur, dans temp/<id>.etat.json, à côté du JSON
 *     intermédiaire. Un import interrompu y reste « en cours » : l'accueil de l'import le signale
 *     et permet de le reprendre exactement où il s'est arrêté.
 *  2. Le pointeur de progression est celui du serveur, pas celui de la page : une requête rejouée
 *     (rechargement, double clic, reprise depuis une vieille page) ne retraite jamais une ligne.
 *  3. À la fin, un BILAN DE CONTRÔLE relit la base : pour chaque ligne du fichier qui demande un
 *     rattachement à un mouvement, la fiche y est-elle rattachée ? Les lignes qui ne le sont pas
 *     sont nommées, quelle qu'en soit la cause — non cochée, en échec, mouvement introuvable.
 */

if (!defined('INRAP_IMPORT_ETAT_VERSION')) {
	define('INRAP_IMPORT_ETAT_VERSION', 1);
	// Délai au-delà duquel un import « en cours » dont l'état n'a pas bougé est tenu pour interrompu.
	// Une ligne réelle prend quelques secondes ; deux minutes sans nouvelle, c'est un arrêt.
	define('INRAP_IMPORT_DELAI_INTERRUPTION', 120);
}

/**
 * Répertoire des fichiers temporaires de l'import.
 */
function inrap_import_dossier() {
	return __CA_APP_DIR__.'/plugins/importInrap/temp/';
}

/**
 * Valide un identifiant d'import : l'horodatage du téléversement, rien d'autre.
 * @return string|null
 */
function inrap_import_id_valide($ps_id) {
	$ps_id = (string)$ps_id;
	return preg_match('/^[0-9]{6,12}$/', $ps_id) ? $ps_id : null;
}

/**
 * Déduit l'identifiant d'import d'un chemin de JSON intermédiaire reçu de la page, en refusant tout
 * chemin qui ne désigne pas un fichier « <chiffres>.json » du répertoire temporaire du greffon.
 * Auparavant ce chemin, pris tel quel dans la requête, était passé à file_get_contents().
 * @return string|null
 */
function inrap_import_id_depuis_json($ps_chemin) {
	$ps_chemin = (string)$ps_chemin;
	if (!preg_match('/^([0-9]{6,12})\.json$/', basename($ps_chemin), $va_m)) { return null; }
	$vs_dossier = realpath(inrap_import_dossier());
	$vs_reel = realpath($ps_chemin);
	if (!$vs_dossier || !$vs_reel || dirname($vs_reel) !== $vs_dossier) { return null; }
	return $va_m[1];
}

/**
 * Valide un chemin de classeur téléversé reçu de la page : il doit se trouver dans le répertoire
 * temporaire du greffon. Rend le chemin réel, ou null.
 */
function inrap_import_classeur_valide($ps_chemin) {
	$vs_dossier = realpath(inrap_import_dossier());
	$vs_reel = realpath((string)$ps_chemin);
	if (!$vs_dossier || !$vs_reel || dirname($vs_reel) !== $vs_dossier || !is_file($vs_reel)) { return null; }
	if (!preg_match('/^[0-9]{6,12}\.(xlsx|xls|xlsm|csv|ods)$/', basename($vs_reel))) { return null; }
	return $vs_reel;
}

function inrap_import_chemin_json($ps_id) { return inrap_import_dossier().$ps_id.'.json'; }
function inrap_import_chemin_selection($ps_id) { return inrap_import_dossier().$ps_id.'.selection.json'; }

/**
 * Jeton de sélection DÉLIVRÉ PAR LE SERVEUR à l'affichage de l'écran de sélection, avec l'utilisateur
 * à qui il a été remis. Un import ne peut démarrer que sur présentation de ce jeton par ce même
 * utilisateur : une requête forgée depuis un autre site (CSRF), ou par un autre utilisateur, ne peut
 * ni lancer un import ni écraser l'état d'un import existant.
 */
function inrap_import_delivrer_jeton($ps_id, $pn_user_id, $ps_empreinte, $ps_type) {
	$vs_jeton = bin2hex(random_bytes(8));
	$vs_f = inrap_import_chemin_selection($ps_id);
	$va = is_file($vs_f) ? json_decode((string)file_get_contents($vs_f), true) : null;
	if (!is_array($va) || !isset($va['utilisateurs']) || !is_array($va['utilisateurs'])) { $va = ['utilisateurs' => []]; }
	// Jetons rangés PAR UTILISATEUR : l'affichage de l'écran par un collègue n'invalide pas ceux
	// d'un autre. Plusieurs jetons peuvent coexister (retour arrière, second affichage) : on garde
	// les 10 derniers de chacun.
	$vs_u = (string)(int)$pn_user_id;
	$va['utilisateurs'][$vs_u][$vs_jeton] = ['empreinte' => $ps_empreinte, 'type' => $ps_type, 'emis' => time()];
	$va['utilisateurs'][$vs_u] = array_slice($va['utilisateurs'][$vs_u], -10, null, true);
	$vs_tmp = $vs_f.'.'.getmypid().'.tmp';
	if (file_put_contents($vs_tmp, json_encode($va)) === false || !rename($vs_tmp, $vs_f)) { return null; }
	return $vs_jeton;
}

/**
 * Le jeton a-t-il été délivré par le serveur, pour cet import, à cet utilisateur ?
 * @return array|null les caractéristiques de la sélection (empreinte, type) ou null
 */
function inrap_import_jeton_delivre($ps_id, $pn_user_id, $ps_jeton) {
	if (!($ps_id = inrap_import_id_valide($ps_id)) || !preg_match('/^[a-f0-9]{16}$/', (string)$ps_jeton)) { return null; }
	$vs_f = inrap_import_chemin_selection($ps_id);
	$va = is_file($vs_f) ? json_decode((string)file_get_contents($vs_f), true) : null;
	if (!is_array($va)) { return null; }
	return $va['utilisateurs'][(string)(int)$pn_user_id][$ps_jeton] ?? null;
}
function inrap_import_chemin_etat($ps_id) { return inrap_import_dossier().$ps_id.'.etat.json'; }

/**
 * Lit l'état d'un import. Rend null s'il n'existe pas ou s'il est illisible.
 */
function inrap_import_etat_lire($ps_id) {
	if (!($ps_id = inrap_import_id_valide($ps_id))) { return null; }
	$vs_f = inrap_import_chemin_etat($ps_id);
	if (!is_file($vs_f)) { return null; }
	$va = json_decode((string)file_get_contents($vs_f), true);
	return is_array($va) ? $va : null;
}

/**
 * Écrit l'état d'un import de façon atomique : fichier temporaire puis rename(), pour qu'une
 * lecture concurrente ne voie jamais un état à moitié écrit.
 */
function inrap_import_etat_ecrire($ps_id, array $pa_etat) {
	if (!($ps_id = inrap_import_id_valide($ps_id))) { return false; }
	$pa_etat['maj'] = time();
	$vs_f = inrap_import_chemin_etat($ps_id);
	$vs_tmp = $vs_f.'.'.getmypid().'.tmp';
	$vs_json = json_encode($pa_etat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
	if ($vs_json === false || file_put_contents($vs_tmp, $vs_json) === false) { return false; }
	return rename($vs_tmp, $vs_f);
}

/**
 * Verrou exclusif sur un import, pour que deux requêtes (deux onglets, un double envoi) ne traitent
 * jamais le même import en même temps. Attend au plus $pn_attente secondes.
 * @return resource|null le descripteur à passer à inrap_import_deverrouiller(), ou null
 */
function inrap_import_verrouiller($ps_id, $pn_attente = 60) {
	if (!($ps_id = inrap_import_id_valide($ps_id))) { return null; }
	$vr = @fopen(inrap_import_dossier().$ps_id.'.verrou', 'c');
	if (!$vr) { return null; }
	$vn_fin = microtime(true) + $pn_attente;
	do {
		if (flock($vr, LOCK_EX | LOCK_NB)) { return $vr; }
		usleep(200000);
	} while (microtime(true) < $vn_fin);
	fclose($vr);
	return null;
}

function inrap_import_deverrouiller($pr_verrou) {
	if (is_resource($pr_verrou)) { flock($pr_verrou, LOCK_UN); fclose($pr_verrou); }
}

/**
 * Liste les imports d'un utilisateur, du plus récent au plus ancien.
 * @param int $pn_user_id
 * @param int $pn_jours ancienneté maximale
 * @return array de tableaux d'état, chacun complété de 'interrompu' (bool)
 */
function inrap_import_etats_utilisateur($pn_user_id, $pn_jours = 30) {
	$va_res = [];
	$vn_limite = time() - $pn_jours * 86400;
	foreach (glob(inrap_import_dossier().'*.etat.json') ?: [] as $vs_f) {
		if (@filemtime($vs_f) < $vn_limite) { continue; }
		$va = json_decode((string)@file_get_contents($vs_f), true);
		if (!is_array($va) || (int)($va['user_id'] ?? 0) !== (int)$pn_user_id) { continue; }
		$va['interrompu'] = (($va['statut'] ?? '') === 'en_cours') && ((time() - (int)($va['maj'] ?? 0)) > INRAP_IMPORT_DELAI_INTERRUPTION);
		$va_res[] = $va;
	}
	usort($va_res, function ($a, $b) { return ((int)($b['debut'] ?? 0)) <=> ((int)($a['debut'] ?? 0)); });
	return $va_res;
}

/**
 * Ajoute une entrée à une liste bornée de l'état (erreurs, avertissements), en tenant le compte
 * total à part : un import de plusieurs milliers de lignes en échec ne doit pas faire enfler l'état.
 */
function inrap_import_etat_consigner(array &$pa_etat, $ps_liste, array $pa_entree, $pn_max = 500) {
	if (!isset($pa_etat[$ps_liste]) || !is_array($pa_etat[$ps_liste])) { $pa_etat[$ps_liste] = []; }
	$pa_etat[$ps_liste.'_total'] = (int)($pa_etat[$ps_liste.'_total'] ?? 0) + 1;
	if (sizeof($pa_etat[$ps_liste]) < $pn_max) { $pa_etat[$ps_liste][] = $pa_entree; }
}

/**
 * Table de la fiche importée et table de relation avec les mouvements, selon le type d'import.
 */
function inrap_import_tables($ps_type) {
	if ($ps_type === 'operation') {
		return ['table' => 'ca_collections', 'pk' => 'collection_id', 'rel_mouvement' => 'ca_movements_x_collections'];
	}
	return ['table' => 'ca_objects', 'pk' => 'object_id', 'rel_mouvement' => 'ca_movements_x_objects'];
}

/**
 * Nom de la colonne du fichier qui porte l'identifiant de mouvement, d'après le mappage du type.
 * @return string|null
 */
function inrap_import_colonne_mouvement($pa_mapping_type) {
	foreach ((array)$pa_mapping_type as $vs_cle => $va_def) {
		if (is_array($va_def) && (($va_def['relation'] ?? '') === 'ca_movements')) { return $vs_cle; }
	}
	return null;
}

/**
 * Résout en lot des identifiants de fiches vivantes. Rend [idno TEL QUE DEMANDÉ => pk].
 *
 * La comparaison est faite PAR MYSQL, avec la collation de la colonne idno (utf8mb3_general_ci :
 * insensible à la casse, blancs finaux ignorés), exactement comme load(['idno' => …]) qui sert à
 * l'import. Une comparaison en PHP aurait déclaré « introuvable » un mouvement saisi « mvt-1 »
 * alors que la base porte « MVT-1 », et que l'import, lui, le trouve.
 */
function inrap_import_resoudre_idnos($ps_table, $ps_pk, array $pa_idnos) {
	$va_res = [];
	$pa_idnos = array_values(array_unique(array_filter(array_map('strval', $pa_idnos), 'strlen')));
	if (!sizeof($pa_idnos)) { return $va_res; }
	$o_db = new Db();
	foreach (array_chunk($pa_idnos, 300) as $va_lot) {
		// Chaque valeur demandée porte son rang : c'est sur lui qu'on regroupe, car un regroupement
		// sur la valeur elle-même fusionnerait « MVT-1 » et « mvt-1 », égales pour la collation.
		$va_sql = []; $va_params = [];
		foreach ($va_lot as $vn_k => $vs_v) { $va_sql[] = 'SELECT ? AS v, '.(int)$vn_k.' AS k'; $va_params[] = $vs_v; }
		$qr = $o_db->query("SELECT i.k, MIN(t.{$ps_pk}) AS pk FROM (".join(' UNION ALL ', $va_sql).") i INNER JOIN {$ps_table} t ON t.idno = i.v AND t.deleted = 0 GROUP BY i.k", $va_params);
		while ($qr->nextRow()) { $va_res[$va_lot[(int)$qr->get('k')]] = (int)$qr->get('pk'); }
	}
	return $va_res;
}

/**
 * Résout en lot des identifiants de mouvements vivants. Rend [idno => movement_id].
 */
function inrap_import_resoudre_mouvements(array $pa_idnos) {
	return inrap_import_resoudre_idnos('ca_movements', 'movement_id', $pa_idnos);
}

/**
 * Couples (mouvement, fiche) déjà rattachés, parmi ceux demandés. Rend un ensemble "mvt:pk" => true.
 */
function inrap_import_rattachements_existants($ps_rel_table, $ps_pk, array $pa_couples) {
	$va_res = [];
	$va_par_mvt = [];
	foreach ($pa_couples as $va_c) { $va_par_mvt[(int)$va_c[0]][] = (int)$va_c[1]; }
	if (!sizeof($va_par_mvt)) { return $va_res; }
	$o_db = new Db();
	foreach ($va_par_mvt as $vn_mvt => $va_pks) {
		foreach (array_chunk(array_values(array_unique($va_pks)), 500) as $va_lot) {
			$qr = $o_db->query("SELECT {$ps_pk} pk FROM {$ps_rel_table} WHERE movement_id = ? AND {$ps_pk} IN (?)", [$vn_mvt, $va_lot]);
			while ($qr->nextRow()) { $va_res[$vn_mvt.':'.(int)$qr->get('pk')] = true; }
		}
	}
	return $va_res;
}

/**
 * Situation de chaque ligne du fichier AVANT import, pour l'écran de sélection : fiche existante ?
 * rattachée au mouvement demandé ? mouvement introuvable ?
 *
 * @param array $pa_donnees le JSON intermédiaire [n° de ligne de données => [colonne => valeur]]
 * @return array [n° de ligne => ['statut' => nouveau|doublon|deja_rattache|a_rattacher|mouvement_introuvable,
 *                 'pk' => int|null, 'mouvement' => idno|null]]
 */
function inrap_import_situation_lignes(array $pa_donnees, $ps_type, $pa_mapping_type) {
	$va_t = inrap_import_tables($ps_type);
	$vs_col_mvt = inrap_import_colonne_mouvement($pa_mapping_type);
	$va_idnos = []; $va_mvts = [];
	foreach ($pa_donnees as $vn_l => $va_ligne) {
		$vs_idno = inrap_normaliser_idno($va_ligne['idno'] ?? '');
		if ($vs_idno === '') { continue; }
		$va_idnos[] = $vs_idno;
		if ($vs_col_mvt && ($vs_m = inrap_normaliser_idno($va_ligne[$vs_col_mvt] ?? '')) !== '') { $va_mvts[] = $vs_m; }
	}
	$va_pks = inrap_import_resoudre_idnos($va_t['table'], $va_t['pk'], $va_idnos);
	$va_mvt_ids = inrap_import_resoudre_mouvements($va_mvts);

	$va_couples = [];
	foreach ($pa_donnees as $vn_l => $va_ligne) {
		$vs_idno = inrap_normaliser_idno($va_ligne['idno'] ?? '');
		if ($vs_idno === '' || !isset($va_pks[$vs_idno]) || !$vs_col_mvt) { continue; }
		$vs_m = inrap_normaliser_idno($va_ligne[$vs_col_mvt] ?? '');
		if ($vs_m !== '' && isset($va_mvt_ids[$vs_m])) { $va_couples[] = [$va_mvt_ids[$vs_m], $va_pks[$vs_idno]]; }
	}
	$va_rattaches = inrap_import_rattachements_existants($va_t['rel_mouvement'], $va_t['pk'], $va_couples);

	$va_res = [];
	foreach ($pa_donnees as $vn_l => $va_ligne) {
		$vs_idno = inrap_normaliser_idno($va_ligne['idno'] ?? '');
		if ($vs_idno === '') { continue; }
		$vn_pk = $va_pks[$vs_idno] ?? null;
		$vs_m = $vs_col_mvt ? inrap_normaliser_idno($va_ligne[$vs_col_mvt] ?? '') : '';
		if ($vs_m !== '' && !isset($va_mvt_ids[$vs_m])) {
			$vs_statut = 'mouvement_introuvable';
		} elseif (!$vn_pk) {
			$vs_statut = 'nouveau';
		} elseif ($vs_m === '') {
			$vs_statut = 'doublon';
		} elseif (isset($va_rattaches[$va_mvt_ids[$vs_m].':'.$vn_pk])) {
			$vs_statut = 'deja_rattache';
		} else {
			$vs_statut = 'a_rattacher';
		}
		$va_res[$vn_l] = ['statut' => $vs_statut, 'pk' => $vn_pk, 'mouvement' => ($vs_m !== '' ? $vs_m : null)];
	}
	return $va_res;
}

/**
 * BILAN DE CONTRÔLE d'un import : relit la base pour chaque ligne du fichier.
 *
 * Ce bilan ne se fie pas à ce que l'import croit avoir fait : il constate. Une ligne qui demande un
 * rattachement à un mouvement est « conforme » si, et seulement si, la fiche existe et y est
 * rattachée en base au moment du bilan.
 *
 * @return array [
 *   'lignes' => nb de lignes du fichier portant un identifiant,
 *   'selectionnees', 'traitees', 'en_echec',
 *   'avec_mouvement' => nb de lignes demandant un rattachement,
 *   'rattachees' => nb de celles-ci effectivement rattachées,
 *   'mouvements' => [idno => ['id' => int|null, 'demandees' => n, 'rattachees' => n]],
 *   'ecarts' => [['ligne' => n° de ligne du tableur, 'idno', 'mouvement', 'cause'], …],
 *   'absentes' => [['ligne', 'idno', 'cause'], …]  (fiches inexistantes en base, fichier sans mouvement)
 * ]
 */
function inrap_import_bilan(array $pa_etat, array $pa_donnees, $pa_mapping_type) {
	$va_t = inrap_import_tables($pa_etat['type'] ?? 'mobilier');
	$vs_col_mvt = inrap_import_colonne_mouvement($pa_mapping_type);
	$va_sel = array_flip(array_map('intval', (array)($pa_etat['selection'] ?? [])));
	$va_traitees = array_flip(array_map('intval', (array)($pa_etat['traitees'] ?? [])));
	$va_pk_traitees = (array)($pa_etat['fiches'] ?? []);   // [row => pk] pour les lignes traitées
	$va_echecs = [];
	// L'ensemble COMPLET des lignes en échec (la liste détaillée des erreurs est plafonnée à 200).
	foreach ((array)($pa_etat['lignes_en_echec'] ?? []) as $vn_r) { $va_echecs[(int)$vn_r] = "voir le journal de l'application"; }
	foreach ((array)($pa_etat['erreurs'] ?? []) as $va_e) { if (isset($va_e['row'])) { $va_echecs[(int)$va_e['row']] = (string)($va_e['message'] ?? ''); } }

	// Identifiants tels qu'ils ont été ÉCRITS : avec le préfixe éventuel, pour les lignes traitées.
	$va_idnos = []; $va_mvts = [];
	$vn_row = 0;
	$va_lignes = [];
	foreach ($pa_donnees as $vn_l => $va_ligne) {
		$vn_r = $vn_row++;
		$vs_idno = inrap_normaliser_idno($va_ligne['idno'] ?? '');
		if ($vs_idno === '') { continue; }
		$vs_m = $vs_col_mvt ? inrap_normaliser_idno($va_ligne[$vs_col_mvt] ?? '') : '';
		$va_lignes[$vn_r] = ['l' => $vn_l, 'idno' => $vs_idno, 'm' => $vs_m];
		$va_idnos[] = $vs_idno;
		if ($vs_m !== '') { $va_mvts[] = $vs_m; }
	}
	$va_pks = inrap_import_resoudre_idnos($va_t['table'], $va_t['pk'], $va_idnos);
	$va_mvt_ids = inrap_import_resoudre_mouvements($va_mvts);

	// Ligne traitée dont la fiche n'a pas été mémorisée (reprise d'une page de l'ancienne version) :
	// si un préfixe a été appliqué, c'est la fiche préfixée qu'il faut contrôler.
	$va_prefixees = [];
	$vs_prefixe = (string)($pa_etat['idno_prefix'] ?? '');
	if ($vs_prefixe !== '') {
		$va_cand = [];
		foreach ($va_lignes as $vn_r => $va) {
			if (isset($va_traitees[$vn_r]) && !isset($va_pk_traitees[$vn_r])) { $va_cand[] = inrap_normaliser_idno($vs_prefixe.$va['idno']); }
		}
		$va_prefixees = inrap_import_resoudre_idnos($va_t['table'], $va_t['pk'], $va_cand);
	}
	$va_couples = [];
	foreach ($va_lignes as $vn_r => $va) {
		$vs_pref = ($vs_prefixe !== '') ? inrap_normaliser_idno($vs_prefixe.$va['idno']) : null;
		if (isset($va_pk_traitees[$vn_r])) { $vn_pk = (int)$va_pk_traitees[$vn_r]; }
		elseif ($vs_pref !== null && isset($va_prefixees[$vs_pref])) { $vn_pk = $va_prefixees[$vs_pref]; }
		else { $vn_pk = $va_pks[$va['idno']] ?? null; }
		$va_lignes[$vn_r]['pk'] = $vn_pk;
		if ($vn_pk && $va['m'] !== '' && isset($va_mvt_ids[$va['m']])) { $va_couples[] = [$va_mvt_ids[$va['m']], $vn_pk]; }
	}
	// Une fiche traitée peut avoir été supprimée depuis : on vérifie qu'elle vit encore.
	$va_vivantes = [];
	$va_pks_a_verifier = array_values(array_unique(array_filter(array_column($va_lignes, 'pk'))));
	if (sizeof($va_pks_a_verifier)) {
		$o_db = new Db();
		foreach (array_chunk($va_pks_a_verifier, 500) as $va_lot) {
			$qr = $o_db->query("SELECT {$va_t['pk']} pk FROM {$va_t['table']} WHERE deleted = 0 AND {$va_t['pk']} IN (?)", [$va_lot]);
			while ($qr->nextRow()) { $va_vivantes[(int)$qr->get('pk')] = true; }
		}
	}
	$va_rattaches = inrap_import_rattachements_existants($va_t['rel_mouvement'], $va_t['pk'], $va_couples);

	$va_bilan = ['lignes' => sizeof($va_lignes), 'selectionnees' => 0, 'traitees' => 0, 'en_echec' => 0,
		'avec_mouvement' => 0, 'rattachees' => 0, 'mouvements' => [], 'ecarts' => [], 'absentes' => [],
		// Le type d'import prévoit-il un rattachement à un mouvement, et la colonne a-t-elle été
		// associée ? Sinon le bilan doit le dire, et non afficher « conforme » sans un mot.
		'mouvement_prevu' => ($vs_col_mvt !== null),
		'colonne_mouvement_associee' => false];
	if ($vs_col_mvt !== null) {
		foreach ($pa_donnees as $va_ligne) { if (is_array($va_ligne) && array_key_exists($vs_col_mvt, $va_ligne)) { $va_bilan['colonne_mouvement_associee'] = true; break; } }
	}
	foreach ($va_lignes as $vn_r => $va) {
		$vb_sel = isset($va_sel[$vn_r]);
		$vb_traitee = isset($va_traitees[$vn_r]);
		$vb_echec = isset($va_echecs[$vn_r]);
		if ($vb_sel) { $va_bilan['selectionnees']++; }
		if ($vb_traitee) { $va_bilan['traitees']++; }
		if ($vb_echec) { $va_bilan['en_echec']++; }
		$vn_pk = $va['pk'] && isset($va_vivantes[$va['pk']]) ? $va['pk'] : null;
		$vn_ligne_tableur = ((int)$va['l']) + 1;

		if ($vb_echec) { $vs_cause = "en échec : ".$va_echecs[$vn_r]; }
		elseif (!$vb_sel) { $vs_cause = "ligne non cochée"; }
		elseif (!$vb_traitee) { $vs_cause = "ligne cochée mais pas encore traitée (import interrompu ?)"; }
		else { $vs_cause = null; }

		if ($va['m'] !== '') {
			$va_bilan['avec_mouvement']++;
			$vn_mid = $va_mvt_ids[$va['m']] ?? null;
			if (!isset($va_bilan['mouvements'][$va['m']])) { $va_bilan['mouvements'][$va['m']] = ['id' => $vn_mid, 'demandees' => 0, 'rattachees' => 0]; }
			$va_bilan['mouvements'][$va['m']]['demandees']++;
			if ($vn_mid && $vn_pk && isset($va_rattaches[$vn_mid.':'.$vn_pk])) {
				$va_bilan['rattachees']++;
				$va_bilan['mouvements'][$va['m']]['rattachees']++;
				continue;
			}
			if (!$vn_mid) { $vs_c = "le mouvement « ".$va['m']." » n'existe pas dans Comodo"; }
			elseif (!$vn_pk) { $vs_c = "la fiche n'existe pas dans Comodo".($vs_cause ? " — ".$vs_cause : ''); }
			else { $vs_c = $vs_cause ?: "fiche traitée, mais le rattachement n'est pas en base"; }
			$va_bilan['ecarts'][] = ['ligne' => $vn_ligne_tableur, 'idno' => $va['idno'], 'mouvement' => $va['m'], 'cause' => $vs_c];
		} elseif (!$vn_pk) {
			$va_bilan['absentes'][] = ['ligne' => $vn_ligne_tableur, 'idno' => $va['idno'], 'cause' => $vs_cause ?: "fiche traitée mais introuvable en base"];
		}
	}
	return $va_bilan;
}

/**
 * Consigne le bilan au journal de l'application, pour qu'un écart reste traçable même si personne
 * n'a lu l'écran.
 */
function inrap_import_journaliser_bilan(array $pa_etat, array $pa_bilan) {
	$vs_msg = sprintf('importInrap : bilan de l\'import %s (« %s », %s) — %d ligne(s), %d cochée(s), %d traitée(s), %d en échec ; rattachements %d/%d ; %d écart(s), %d fiche(s) absente(s)',
		$pa_etat['id'] ?? '?', $pa_etat['nom'] ?? '?', $pa_etat['user_name'] ?? '?',
		$pa_bilan['lignes'], $pa_bilan['selectionnees'], $pa_bilan['traitees'], $pa_bilan['en_echec'],
		$pa_bilan['rattachees'], $pa_bilan['avec_mouvement'], sizeof($pa_bilan['ecarts']), sizeof($pa_bilan['absentes']));
	if (sizeof($pa_bilan['ecarts'])) {
		$vs_msg .= ' — lignes non rattachées : '.join(', ', array_slice(array_column($pa_bilan['ecarts'], 'ligne'), 0, 60));
	}
	error_log($vs_msg);
}

/**
 * Raccourcit un identifiant pour l'affichage. Un numéro d'inventaire aberrant (plusieurs centaines
 * de caractères, collage malheureux) noyait le message d'erreur qui dit pourquoi la ligne a échoué.
 */
function inrap_import_court($ps_texte, $pn_max = 80) {
	$ps_texte = (string)$ps_texte;
	return (mb_strlen($ps_texte) > $pn_max) ? mb_substr($ps_texte, 0, $pn_max).'…' : $ps_texte;
}

/**
 * Message d'une ligne, avec l'identifiant raccourci partout où il apparaît.
 */
function inrap_import_message_court($ps_message, $ps_idno, $pn_max = 600) {
	$ps_message = (string)$ps_message;
	$ps_idno = (string)$ps_idno;
	if ($ps_idno !== '' && mb_strlen($ps_idno) > 80) { $ps_message = str_replace($ps_idno, inrap_import_court($ps_idno), $ps_message); }
	return inrap_import_court($ps_message, $pn_max);
}

/**
 * Nombre de lignes cochées déjà faites (traitées ou en échec), sans double compte.
 */
function inrap_import_nb_faites(array $pa_etat) {
	$va = array_flip(array_map('intval', (array)($pa_etat['traitees'] ?? [])));
	foreach ((array)($pa_etat['lignes_en_echec'] ?? []) as $vn_r) { $va[(int)$vn_r] = true; }
	return sizeof($va);
}

/**
 * Met en file la RÉINDEXATION SEULE (moteur de recherche) d'une fiche, sans la réenregistrer : code
 * 1000 + numéro de table dans la file de réindexation différée du greffon Meilisearch, que le worker
 * traite par une simple indexation (recalc_worker.php, reindex_document()). Sert au « rattachement
 * seul » : sans elle, les facettes « mouvement » du moteur pourraient omettre la fiche. Ne fait rien
 * si la file n'est pas configurée ; n'échoue jamais.
 */
function inrap_import_reindexer_plus_tard($pn_table_num, $pn_row_id) {
	try {
		$vs_conf = __CA_APP_DIR__.'/plugins/Meilisearch/conf/meilisearch.conf';
		if (!is_file($vs_conf)) { return; }
		$vs_file = (string)Configuration::load($vs_conf)->get('deferred_reindex_queue');
		if ($vs_file === '' || !preg_match('!^[A-Za-z0-9_]+$!', $vs_file)) { return; }
		$o_db = new Db();
		$o_db->query("INSERT INTO {$vs_file} (table_num, row_id, status, enqueued_at) VALUES (?, ?, 'pending', ?)
			ON DUPLICATE KEY UPDATE status='pending', enqueued_at=VALUES(enqueued_at), error_msg=NULL",
			[1000 + (int)$pn_table_num, (int)$pn_row_id, time()]);
	} catch (\Throwable $e) {
		error_log('importInrap : mise en file de réindexation impossible ('.$e->getMessage().')');
	}
}

