<?php
// === D2026-7895 §F2 — garde-fou générique « titre composable » : début ===
/**
 * inrap_7895_label_composable.inc.php
 *
 * GÉNÉRALISATION du garde-fou déjà en service sur les SEULS versements
 * (inrap_0103_label_versement.inc.php :: inrap_0103_label_versement_composable()).
 *
 * Doctrine arbitrée avec l'INRAP le 07/08/2026, mot pour mot :
 *   « mieux vaut conserver un ancien titre imparfait que d'en écrire un amputé ».
 *
 * Sur les versements la règle a tenu : au 10/09/2026, sur 10 242 versements,
 * ZÉRO titre courant amputé. Mais elle n'a jamais quitté le chemin d'écriture des
 * versements, et prepopulateInrapPlugin.php répète ailleurs, cinq fois, le motif
 *     removeAllLabels() puis addLabel(<gabarit>)
 * sans regarder ce que le gabarit a produit. Quand tous les crans du gabarit sont
 * vides, la fiche reçoit pour titre la seule ponctuation du gabarit — « /  /  / » —
 * et le perd définitivement : le titre suivant sera comparé à cette chaîne-là, plus
 * au titre d'origine.
 *
 * Constat au 10/09/2026 : 1 067 fiches vivantes portent un tel titre
 * (722 ca_movements, 277 ca_collections, 64 ca_objects, 4 ca_occurrences), dont
 * 6 opérations créées APRÈS le 07/08/2026, la dernière le 09/09/2026 à 07h22.
 *
 * Fichier volontairement calqué sur son aîné — mêmes conventions, même granularité
 * (composants / composition / composable) — pour que les deux puissent fusionner le
 * jour où le gabarit des versements rejoindra le régime commun.
 *
 * ---------------------------------------------------------------------------
 * DEUX NIVEAUX DE SÉVÉRITÉ, un seul interrupteur
 * ---------------------------------------------------------------------------
 * INRAP_7895_EXIGER_TOUS_LES_CRANS = false  (défaut, déploiement sans effet de bord)
 *     On refuse d'écrire un titre réduit à la ponctuation, et on refuse d'échanger
 *     un titre porteur de sens contre un titre dégénéré. Rien d'autre.
 *     Effet mesuré au 10/09/2026 : bloque 100 % du mécanisme qui a produit les
 *     1 067 fiches, et ZÉRO écriture légitime.
 *
 * INRAP_7895_EXIGER_TOUS_LES_CRANS = true   (doctrine du 07/08/2026 à la lettre)
 *     On exige en plus que TOUS les crans obligatoires du gabarit soient renseignés,
 *     comme inrap_0103_label_versement_composable() le fait pour les versements.
 *     Effet mesuré au 10/09/2026 : gèlerait aussi le retitrage des fiches à titre
 *     partiellement amputé — de l'ordre de 1 915 opérations type 125, 1 332
 *     mouvements type 81 et 5 610 objets type 24. Leur titre courant, imparfait,
 *     serait conservé tel quel : c'est exactement ce que dit la doctrine, mais
 *     c'est une décision de gestion, pas une correction de bogue. D'où le défaut
 *     à false et l'arbitrage laissé à l'INRAP.
 */

if (!defined('INRAP_7895_EXIGER_TOUS_LES_CRANS')) { define('INRAP_7895_EXIGER_TOUS_LES_CRANS', false); }

/**
 * Un titre est-il « dégénéré », c'est-à-dire réduit à la ponctuation du gabarit ?
 *
 * Test volontairement large : barres obliques, tirets et blancs — y compris les blancs
 * Unicode (insécable, fine, tabulation numérique), déjà rencontrés sur ce parc.
 *
 * @param string $ps_label
 * @return bool
 */
function inrap_7895_label_degenere($ps_label) {
	$vs = preg_replace('![/\-–—[:space:]\x{00A0}\x{202F}\x{2007}]+!u', '', (string)$ps_label);
	return (trim((string)$vs) === '');
}

/**
 * Tous les composants obligatoires du gabarit sont-ils renseignés ?
 *
 * On raisonne sur les COMPOSANTS, jamais sur la chaîne assemblée : un gabarit qui porte
 * un libellé fixe (« Versement / … », « A valider : … ») produit toujours une chaîne non
 * vide, ce qui masquerait l'amputation.
 *
 * @param array $pa_composants valeurs des crans obligatoires, indexées par nom de cran
 * @return bool
 */
function inrap_7895_composants_complets(array $pa_composants) {
	foreach ($pa_composants as $vm_valeur) {
		if (trim((string)$vm_valeur) === '') { return false; }
	}
	return true;
}

/**
 * Liste des crans vides, pour le journal.
 */
function inrap_7895_crans_vides(array $pa_composants) {
	$va = array();
	foreach ($pa_composants as $vs_cle => $vm_valeur) {
		if (trim((string)$vm_valeur) === '') { $va[] = $vs_cle; }
	}
	return $va;
}

/**
 * Faut-il écrire ce titre ? POINT D'ENTRÉE UNIQUE, à appeler AVANT tout removeAllLabels().
 *
 * Trois filets, du plus grossier au plus fin :
 *   1. le titre assemblé n'est pas lui-même dégénéré ;
 *   2. on n'échange jamais un titre porteur de sens contre un titre dégénéré ;
 *   3. si INRAP_7895_EXIGER_TOUS_LES_CRANS, tous les crans obligatoires sont renseignés.
 *
 * Le cas de REPRISE reste ouvert : une fiche qui porte aujourd'hui « /  /  / » et dont les
 * données sont enfin arrivées reçoit bien son vrai titre — les filets sont alors satisfaits.
 *
 * @param BundlableLabelableBaseModelWithAttributes $pt_item fiche chargée
 * @param string $ps_label titre assemblé par le gabarit
 * @param array  $pa_composants valeurs des crans obligatoires, indexées par nom de cran
 * @param string $ps_trace étiquette pour le journal (table/type), facultatif
 * @return bool
 */
function inrap_7895_label_ecrivable($pt_item, $ps_label, array $pa_composants = array(), $ps_trace = '') {
	$vb_ok = !inrap_7895_label_degenere($ps_label);
	if ($vb_ok && INRAP_7895_EXIGER_TOUS_LES_CRANS && sizeof($pa_composants)) {
		$vb_ok = inrap_7895_composants_complets($pa_composants);
	}
	if ($vb_ok) { return true; }

	$vs_actuel = (string)$pt_item->get(get_class($pt_item).'.preferred_labels');
	if (($vs_actuel !== '') && ($vs_actuel !== '[VIDE]') && !inrap_7895_label_degenere($vs_actuel)) {
		// La fiche tient un titre exploitable : on le garde, sans bruit. Cas nominal d'une
		// fiche en cours de saisie dont un cran n'est pas encore renseigné.
		return false;
	}

	// La fiche n'a rien à perdre (pas de titre, « [VIDE] », ou déjà dégénérée). On n'écrit
	// pas de ponctuation pour autant : on trace, pour qu'elle soit rattrapable, et on laisse
	// le titre en l'état. Il vaut mieux une fiche sans titre — repérable, et déjà traitée par
	// la famille F1 du ticket 7895 — qu'une fiche dont le titre ment.
	@error_log('[7895] titre non composable, écriture abandonnée : '
		.($ps_trace !== '' ? $ps_trace.' ' : '')
		.get_class($pt_item).'/'.$pt_item->getPrimaryKey()
		.' — titre proposé = ['.$ps_label.']'
		.' — crans vides = '.join(',', inrap_7895_crans_vides($pa_composants)));
	return false;
}

// === D2026-7895 §F2 : fin ===
