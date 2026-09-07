<?php
// === D2026-0103 §5 — reprise des titres de versement : début ===
/**
 * inrap_0103_label_versement.inc.php
 *
 * SOURCE UNIQUE du titre automatique d'un versement (ca_movements, type 1796).
 *
 * Ce fichier ne fait qu'ACCUEILLIR, tel quel, le gabarit qui était écrit en ligne
 * dans support/bin/recalc_worker.php::recalc_movement_versement() (bloc marqué
 * « D2026-0103 §5.1 » du lot 3). Aucune règle n'a été modifiée : ni les chemins de
 * données, ni le séparateur, ni l'ordre des composants.
 *
 * Motif de l'extraction : la reprise de masse des 10 100 versements existants
 * (scratchpad/0103_reprise_titres_versements.php) doit composer le titre avec
 * EXACTEMENT le même code que le worker, faute de quoi un titre repris et un titre
 * nouvellement produit pourraient diverger. recalc_worker.php n'est pas incluable
 * (c'est un script : l'inclure lancerait la boucle de consommation de la file), d'où
 * ce fichier partagé, requis par les deux.
 *
 * Format (CDC 20260622 §5.1, ligne « Titre de versement ») :
 *   « Versement/DIR Inrap/N° identifiant/date/ versement automatique dans le titre
 *     du bordereau »
 * soit, avec le séparateur « / » entouré d'espaces retenu au lot 3 :
 *   Versement / <DIR Inrap> / <idno> / <date de versement>
 */

/**
 * Les trois composants variables du titre, tels que le worker les lit.
 *
 * @param ca_movements $mov instance chargée (type 1796)
 * @return array{dir:string,idno:string,date:string}
 */
function inrap_0103_composants_label_versement($mov) {
	// La DIR n'est pas portée par le versement (ca_movements_x_entities ne connaît que
	// mover / destinataire / authorizer / conservation) : on applique le repli « répertoire
	// en attendant » autorisé par le client, via l'OPÉRATION LIÉE, exactement comme le
	// D2026-0103 §4 le fait pour la page 1 du bordereau (relation DIR + type d'entité DIR,
	// la relation seule rattachant aussi des CRA). Un versement multi-opérations peut avoir
	// sa première opération non renseignée : on retient la première valeur NON VIDE.
	$va_dirs_0103 = array_values(array_filter(array_map('trim', explode(';', (string)$mov->getWithTemplate(
		"<unit relativeTo='ca_collections' delimiter=';'><unit relativeTo='ca_entities' restrictToTypes='DIR' restrictToRelationshipTypes='DIR'>^ca_entities.preferred_labels.displayname</unit></unit>"
	)))));
	return array(
		'dir'  => sizeof($va_dirs_0103) ? $va_dirs_0103[0] : '',
		'idno' => (string)$mov->getWithTemplate("^ca_movements.idno"),
		'date' => (string)$mov->getWithTemplate("^ca_movements.inrap_date_versement.inrap_date_versement_date"),
	);
}

/**
 * Le titre automatique du versement. Assemblage strictement identique à celui qui
 * était en ligne dans recalc_worker.php avant l'extraction.
 */
function inrap_0103_compose_label_versement($mov) {
	$c = inrap_0103_composants_label_versement($mov);
	return 'Versement / '.$c['dir'].' / '
		. $c['idno'].' / '
		. $c['date'];
}

/**
 * Le titre est-il composable ? Vrai seulement si les TROIS composants variables sont
 * renseignés.
 *
 * Règle arbitrée par l'INRAP le 07/08/2026 : mieux vaut conserver un ancien titre
 * imparfait que d'en écrire un amputé. Sans cette garde, un versement dont la date ou
 * la direction manque recevait un titre du genre « Versement /  / V-123 /  », et les
 * 404 fiches historiques réduites à «  /  /  /  » n'étaient que la trace de ce même
 * comportement dans la version antérieure.
 *
 * La règle est posée ICI, dans le fichier partagé, afin que le worker (écriture au fil
 * de l'eau) et la reprise de masse se comportent à l'identique : sans cela, les fiches
 * écartées par la reprise auraient fini par recevoir un titre amputé au premier
 * ré-enregistrement, et l'écart se serait résorbé dans le mauvais sens.
 *
 * @param ca_movements $mov instance chargée (type 1796)
 * @return bool
 */
function inrap_0103_label_versement_composable($mov) {
	$c = inrap_0103_composants_label_versement($mov);
	foreach (array('dir', 'idno', 'date') as $vs_cle) {
		if (trim((string)$c[$vs_cle]) === '') { return false; }
	}
	return true;
}

// === D2026-0103 §5 : fin ===
