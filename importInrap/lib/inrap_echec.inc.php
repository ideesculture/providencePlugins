<?php
/**
 * inrap_echec.inc.php — signaler l'échec d'une ligne d'import sans tuer l'import.
 *
 * 14/09/2026 GM (tickets 7042 / 7536 / 7947).
 *
 * Les bibliothèques d'import signalaient toute erreur de modèle par un var_dump() suivi d'un
 * die() : vingt sites au total. Pour la gestionnaire, cela donnait un mur de texte anglais au
 * milieu d'un import à moitié fait — sans savoir ce qui était passé, ni où reprendre. C'est
 * l'un des sens du mot « fatale » dans les tickets.
 *
 * Un die() n'est pas rattrapable : le filet posé dans ImportController ne pouvait rien pour
 * ces cas-là. On lève donc une exception à la place, que ce filet intercepte pour mettre la
 * ligne de côté, la journaliser, et poursuivre avec les suivantes.
 *
 * Le message reprend les erreurs du modèle, qui disent précisément ce qui a été refusé
 * (« Field X is required », valeur hors liste, identifiant en double…).
 */

if (!function_exists('inrap_echec_ligne')) {
	/**
	 * @param string $ps_contexte ce qu'on tentait de faire, en français, pour situer l'échec
	 * @param mixed $pm_erreurs un modèle CollectiveAccess, un tableau d'erreurs, ou un message
	 * @throws Exception toujours
	 * @return void
	 */
	function inrap_echec_ligne($ps_contexte, $pm_erreurs = null) {
		$va_msgs = [];
		if (is_object($pm_erreurs) && method_exists($pm_erreurs, 'getErrors')) {
			$va_msgs = $pm_erreurs->getErrors();
		} elseif (is_array($pm_erreurs)) {
			foreach ($pm_erreurs as $vm_e) {
				// Selon l'appelant, on reçoit des descriptions déjà converties en chaîne ou
				// les objets Error eux-mêmes.
				if (is_object($vm_e) && method_exists($vm_e, 'getErrorDescription')) {
					$va_msgs[] = $vm_e->getErrorDescription();
				} else {
					$va_msgs[] = (string)$vm_e;
				}
			}
		} elseif (!is_null($pm_erreurs) && (string)$pm_erreurs !== '') {
			$va_msgs[] = (string)$pm_erreurs;
		}

		$va_msgs = array_values(array_filter(array_map('trim', array_map('strval', $va_msgs)), function($vs) { return $vs !== ''; }));
		$vs_detail = sizeof($va_msgs) ? join(' ; ', array_slice($va_msgs, 0, 5)) : 'aucun détail fourni par le modèle';
		if (sizeof($va_msgs) > 5) { $vs_detail .= ' (et '.(sizeof($va_msgs) - 5).' autre(s))'; }

		throw new Exception($ps_contexte.' — '.$vs_detail);
	}
}
