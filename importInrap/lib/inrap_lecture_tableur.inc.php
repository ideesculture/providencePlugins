<?php
/**
 * inrap_lecture_tableur.inc.php — lecture d'une plage de tableur qui ne meurt pas sur une formule.
 *
 * 14/09/2026 GM (tickets 7042 / 7536 / 7947, famille « import de fichiers Excel »).
 *
 * LE DÉFAUT. Les deux lectures du plugin appelaient rangeToArray() avec le calcul des formules
 * activé. Or Excel type en FORMULE toute cellule dont le texte commence par « = » — y compris
 * une saisie qui n'a rien d'un calcul. PhpSpreadsheet tente alors d'évaluer ce texte, échoue,
 * et lève une Calculation\Exception. Comme rangeToArray() lit la plage entière d'un bloc, cette
 * exception emporte TOUT : une seule cellule fautive et l'import ne démarre pas, sur une erreur
 * fatale en anglais. C'est le mécanisme symétrique de celui du ticket 7951, où la même valeur
 * commençant par « = » faisait échouer l'ÉCRITURE du classeur exporté.
 *
 * Fréquence mesurée sur le corpus réel : rare mais bien présent — 1 cas avéré
 * (« 1777965966.xlsx », 05/05/2026) sur les 60 fichiers d'import les plus récents, 0 sur les
 * 136 d'octobre-novembre 2025, 0 sur un échantillon de 94 autres. Rare, donc, mais total
 * quand il frappe : il n'y a pas d'import partiel, il n'y a pas d'import du tout.
 *
 * LE REMÈDE. On relit la plage cellule par cellule au lieu d'un bloc. Le calcul reste activé —
 * les classeurs qui portent de vraies formules continuent de rendre leur RÉSULTAT, comme avant.
 * Mais si le calcul d'une cellule échoue, on se rabat sur son texte brut et on poursuit : la
 * cellule fautive donne « =toto » au lieu de faire tomber les 2 000 lignes qui la suivent.
 *
 * Les cellules concernées sont consignées dans $pa_incidents pour être affichées à la
 * gestionnaire : une valeur reprise en brut est un fait à signaler, pas à masquer.
 *
 * Le tableau rendu est rigoureusement celui de rangeToArray($plage, null, true, false) :
 * lignes et colonnes indexées à partir de 0, cellule absente ou vide rendue null. Les appelants
 * s'appuient sur ces positions (array_flip() de la ligne d'en-tête), elles ne doivent pas bouger.
 */

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

if (!function_exists('inrap_plage_en_tableau')) {
	/**
	 * @param object $po_feuille la feuille PhpSpreadsheet à lire
	 * @param string $ps_plage la plage, au format « A1:F120 »
	 * @param array &$pa_incidents rempli des cellules dont la formule n'a pas pu être calculée
	 * @return array les valeurs, indexées [ligne][colonne] à partir de 0
	 */
	function inrap_plage_en_tableau($po_feuille, $ps_plage, &$pa_incidents = null) {
		if (!is_array($pa_incidents)) { $pa_incidents = []; }
		$va_valeurs = [];

		[$va_debut, $va_fin] = Coordinate::rangeBoundaries($ps_plage);
		$vs_col_min = Coordinate::stringFromColumnIndex($va_debut[0]);
		$vn_ligne_min = $va_debut[1];
		$vs_col_max = Coordinate::stringFromColumnIndex($va_fin[0]);
		$vn_ligne_max = $va_fin[1];

		// Incrément « à la tableur » : la borne de sortie est la colonne SUIVANTE, et $col
		// s'incrémente comme une chaîne (Z → AA). C'est l'idiome de rangeToArray() ; on le
		// reprend tel quel pour ne pas introduire d'écart sur les classeurs très larges.
		++$vs_col_max;

		$vo_cellules = $po_feuille->getCellCollection();

		$vn_l = -1;
		for ($vn_ligne = $vn_ligne_min; $vn_ligne <= $vn_ligne_max; ++$vn_ligne) {
			$vn_l++;
			$vn_c = -1;
			for ($vs_col = $vs_col_min; $vs_col != $vs_col_max; ++$vs_col) {
				$vn_c++;
				$va_valeurs[$vn_l][$vn_c] = null;

				// getCell() créerait la cellule si elle n'existe pas ; on interroge donc
				// directement la collection, comme le fait PhpSpreadsheet lui-même.
				if (!$vo_cellules->has($vs_col . $vn_ligne)) { continue; }

				$vo_cellule = $vo_cellules->get($vs_col . $vn_ligne);
				$vm_brut = $vo_cellule->getValue();
				if ($vm_brut === null) { continue; }

				if ($vm_brut instanceof RichText) {
					$va_valeurs[$vn_l][$vn_c] = $vm_brut->getPlainText();
					continue;
				}

				try {
					$va_valeurs[$vn_l][$vn_c] = $vo_cellule->getCalculatedValue();
				} catch (\Throwable $e) {
					// On rattrape \Throwable et pas seulement \Exception : un TypeError levé
					// dans le moteur de calcul serait sinon fatal, ce qui est précisément
					// ce qu'on cherche à éviter ici.
					$va_valeurs[$vn_l][$vn_c] = $vm_brut;
					$pa_incidents[] = [
						'cellule' => $vs_col . $vn_ligne,
						'valeur'  => (string)$vm_brut,
						'motif'   => $e->getMessage(),
					];
				}
			}
		}

		return $va_valeurs;
	}
}

if (!function_exists('inrap_journaliser_incidents')) {
	/**
	 * Trace les cellules non calculées dans le journal de l'application. Sans cela, une valeur
	 * reprise en brut passerait totalement inaperçue une fois l'import terminé.
	 *
	 * @param array $pa_incidents
	 * @param string $ps_fichier le classeur concerné, pour retrouver le cas a posteriori
	 * @return void
	 */
	function inrap_journaliser_incidents($pa_incidents, $ps_fichier) {
		if (!is_array($pa_incidents) || !sizeof($pa_incidents)) { return; }
		foreach ($pa_incidents as $va_i) {
			error_log(sprintf('importInrap : formule non calculée dans %s, cellule %s (%s) — valeur reprise en brut : %s',
				basename((string)$ps_fichier), $va_i['cellule'], $va_i['motif'], $va_i['valeur']));
		}
	}
}
