<?php
/**
 * inrap_idno.inc.php — normalisation des numéros d'inventaire lus dans un tableur.
 *
 * 07/09/2026 GM (ticket 7987). Les cellules du tableur étaient reprises telles quelles :
 * un identifiant saisi avec un blanc en tête ou en queue entrait tel quel en base, et
 * surtout la recherche de la fiche existante échouait — l'import créait alors un doublon
 * au lieu de retrouver la fiche propre. Mesuré : 1 423 contenants et 208 opérations à
 * identifiant sale.
 *
 * trim() ne suffit pas. Il ne retire que les blancs ASCII, alors que les tableurs
 * produisent couramment des espaces insécables : la fiche 125479 porte l'identifiant
 * « espace / 1 U+00A0 », qu'un trim() laisserait à moitié sale — donc pire qu'avant,
 * puisque la valeur changerait sans devenir propre pour autant.
 *
 * On retire donc les blancs Unicode, mais UNIQUEMENT AUX DEUX EXTRÉMITÉS. Les blancs
 * INTERNES sont conservés tels quels : ils font partie de la valeur métier, et les
 * supprimer fabriquerait des collisions entre identifiants distincts.
 */

if (!function_exists('inrap_normaliser_idno')) {
	/**
	 * @param mixed $ps_idno la valeur brute issue de la cellule
	 * @return string l'identifiant débarrassé de ses blancs de bord
	 */
	function inrap_normaliser_idno($ps_idno) {
		$vs_idno = (string)$ps_idno;

		// Espace, tabulation, retours chariot/ligne, tabulation verticale, saut de page,
		// insécable (00A0), cadratin (1680), espaces typographiques (2000-200A), séparateurs
		// de ligne et de paragraphe (2028/2029), insécable étroite (202F), espace
		// mathématique (205F), idéographique (3000), marque d'ordre des octets (FEFF).
		$vs_blancs = '\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}'
		           . '\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

		$vs_resultat = preg_replace('/^['.$vs_blancs.']+|['.$vs_blancs.']+$/u', '', $vs_idno);

		// preg_replace rend null si la chaîne n'est pas de l'UTF-8 valide. Dans ce cas on
		// ne renvoie surtout pas null : on se rabat sur trim(), qui dégrade proprement.
		return is_null($vs_resultat) ? trim($vs_idno) : $vs_resultat;
	}
}
