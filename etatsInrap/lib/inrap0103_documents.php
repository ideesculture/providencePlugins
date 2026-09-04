<?php
/**
 * Outillage commun aux vues d'édition Word du plugin etatsInrap.
 *
 * Marché 036SE2025 — D2026-0103 §4 — correction des trois défauts relevés par la
 * vérification consolidée du 09/08/2026 :
 *
 *   KO-1  un lieu-dit portant une esperluette nue produisait un word/document.xml
 *         mal formé (versements 12912, 10016, 14804). Seules les trois listes de
 *         contenants et le bloc direction/SRA étaient échappés ; les autres valeurs
 *         ne passaient que par strip_tags(), qui ne neutralise ni « & » ni « < ».
 *
 *   KO-2  la lettre vierge imprimait l'adresse du destinataire mots collés : un
 *         strip_tags() appliqué APRÈS la pose du saut OOXML effaçait ce saut.
 *
 *   KO-3  le nom du fichier produit n'était horodaté qu'à la seconde et déposé dans
 *         un répertoire servi en statique : deux éditions simultanées partageaient
 *         nom et URL, et l'utilisateur pouvait recevoir le document d'un autre.
 *
 * Ce fichier est le point de passage UNIQUE des quatre vues concernées
 * (modele4, modele4b, modele10, modele11), pour qu'aucune n'ait plus sa propre
 * variante d'échappement ni son propre schéma de nommage.
 *
 * Les blocs sont marqués selon la convention du marché ; ce fichier étant créé par
 * le D2026-0103, il n'entre en concurrence avec aucun code des devis 0101 et 0102.
 */

// === D2026-0103 §4 — échappement XML de toutes les valeurs injectées : début ===
//
// Ordre imposé, identique à GenererController::enSuspensClean() (D2026-0102), avec
// une étape supplémentaire que la recette a rendue nécessaire :
//
//   1. repérer les sauts de ligne HTML  (<br>) et les convertir en littéral neutre
//   2. nettoyer le HTML résiduel        (strip_tags)
//   3. DÉCODER les entités HTML         (html_entity_decode)
//   4. échapper pour XML                (htmlspecialchars, ENT_QUOTES|ENT_XML1)
//   5. poser le saut OOXML              (fermeture puis réouverture de <w:t>)
//
// L'étape 3 n'existait pas et son absence est démontrable sur le parc : la base
// mélange deux écritures du même caractère. Le lieu-dit du versement 12912 est
// stocké « PA de la Gaultière Tr 1&2 » (esperluette nue → XML cassé, KO-1) tandis
// que celui du versement 3673 est stocké « Garoutier 1 &amp; 2 » (entité HTML déjà
// encodée, qui s'imprimait correctement « & » parce que la valeur était injectée
// telle quelle). Échapper sans décoder aurait réparé le premier en cassant
// l'affichage du second — « Garoutier 1 &amp; 2 » imprimé en toutes lettres. Idem
// pour les emplacements « Dépôt du Faou -&gt; bureau AF Cherel » (6 libellés) et
// pour les observations de mouvement, où le double échappement est DÉJÀ visible
// aujourd'hui (mouvement 3055 : « Devis19.54 -&gt; opération annulée »).
//
// Décoder puis ré-échapper ramène les deux écritures à une seule et même sortie,
// et rend l'opération idempotente : appliquée deux fois, elle donne le même
// résultat qu'appliquée une fois. C'est le garde-fou contre le double échappement.
if (!function_exists('inrap_0103_xml')) {
	/**
	 * Nettoie et échappe une valeur destinée à un nœud de texte de word/document.xml.
	 * Ne pose AUCUN saut de ligne : utiliser inrap_0103_valeur_word() pour cela, ou
	 * cette fonction seule quand l'appelant a besoin de découper sur « sautdeligne »
	 * (bloc adresse multi-couleurs de la page 1 du bordereau de versement).
	 *
	 * @param mixed $pm_val  valeur brute issue de getWithTemplate()
	 * @param bool  $pb_trim retirer les blancs de tête et de queue
	 * @return string
	 */
	function inrap_0103_xml($pm_val, $pb_trim = false) {
		$vs = strip_tags((string)$pm_val);
		$vs = html_entity_decode($vs, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if ($pb_trim) { $vs = trim($vs); }
		return htmlspecialchars($vs, ENT_QUOTES | ENT_XML1, 'UTF-8');
	}
}

if (!function_exists('inrap_0103_valeur_word')) {
	/**
	 * Chaîne complète : saut repéré → HTML nettoyé → entités décodées → échappement
	 * XML → saut OOXML posé. Le saut doit FERMER l'élément de texte courant et en
	 * rouvrir un : un « <w:br/> » glissé à l'intérieur d'un <w:t> n'est pris en
	 * compte ni par Word ni par LibreOffice.
	 *
	 * Le saut est posé EN DERNIER, après l'échappement : c'est exactement l'erreur
	 * inverse qui produisait le KO-2, où un strip_tags() postérieur effaçait le
	 * balisage tout juste inséré.
	 *
	 * @param mixed $pm_val
	 * @param bool  $pb_regle_adresse  supprime la virgule de fin de ligne d'adresse
	 *                                 (« ,sautdeligne »), comme enSuspensClean().
	 *                                 À laisser à false pour les libellés de
	 *                                 contenants, dont la virgule est légitime.
	 * @return string
	 */
	function inrap_0103_valeur_word($pm_val, $pb_regle_adresse = false) {
		$vs = str_replace(['<br>', '<br/>', '<br />'], 'sautdeligne', (string)$pm_val);
		$vs = inrap_0103_xml($vs, true);
		if ($pb_regle_adresse) { $vs = str_replace(',sautdeligne', 'sautdeligne', $vs); }
		return str_replace('sautdeligne', '</w:t><w:br/><w:t xml:space="preserve">', $vs);
	}
}
// === D2026-0103 §4 : fin ===


// === D2026-0103 §4 — nom non ambigu et diffusion du document produit : début ===
//
// Défaut corrigé (KO-3) : le document était écrit sous « <préfixe><time()>.docx »
// dans un répertoire servi en statique par Apache, et l'utilisateur y était renvoyé
// par une redirection 302. Deux éditions dans la même seconde partageaient nom et
// URL ; la seconde écrasait la première et tout cache HTTP — Cloudflare est en
// frontal — pouvait servir au second demandeur le document du premier. Reproduit le
// 10/08/2026 : deux requêtes simultanées sur les versements 13140 et 16804 ont reçu
// la même URL et le même fichier (md5 70af2d96…).
//
// Ce qui identifie un document, ce n'est pas l'instant de sa production mais LA
// FICHE dont il est l'édition. Le nom remis à l'utilisateur porte donc la référence
// de la fiche (idno du versement, du mouvement ou du courrier) et l'horodatage à la
// seconde ; le nom du fichier de travail y ajoute un jeton aléatoire, qui rend toute
// collision impossible et l'URL indevinable.
//
// Surtout : le fichier n'a aucun besoin d'être exposé pour être téléchargé. Il est
// désormais écrit HORS de la racine web, envoyé dans la réponse HTTP avec un
// Content-Disposition, puis effacé. Ces bordereaux et ces lettres portent des noms
// de personnes ; ils ne laissent plus de trace dans un répertoire public.
if (!function_exists('inrap_0103_slug_document')) {
	/**
	 * Référence de fiche réduite à un fragment de nom de fichier sûr (ASCII).
	 */
	function inrap_0103_slug_document($pm_val) {
		$vs = trim(strip_tags(html_entity_decode((string)$pm_val, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
		if ($vs === '') { return ''; }
		$vs_tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $vs);
		if (($vs_tr !== false) && ($vs_tr !== '')) { $vs = $vs_tr; }
		$vs = preg_replace('/[^A-Za-z0-9]+/', '-', $vs);
		$vs = trim(preg_replace('/-+/', '-', $vs), '-');
		return substr($vs, 0, 60);
	}
}

if (!function_exists('inrap_0103_repertoire_prive')) {
	/**
	 * Répertoire de travail SITUÉ HORS DE LA RACINE WEB (DocumentRoot =
	 * …/collectiveaccess/providence). Le suffixe d'UID évite qu'un répertoire créé
	 * par un autre compte (exécution en ligne de commande) ne bloque le serveur web.
	 * Retourne null si aucun emplacement privé n'est utilisable : l'appelant se
	 * rabat alors sur le répertoire historique.
	 */
	function inrap_0103_repertoire_prive() {
		$vs_uid = function_exists('posix_geteuid') ? (string)posix_geteuid() : 'na';
		foreach ([sys_get_temp_dir(), '/tmp'] as $vs_base) {
			if (!$vs_base || !is_dir($vs_base) || !is_writable($vs_base)) { continue; }
			$vs_dir = rtrim($vs_base, '/').'/etatsInrap_documents_'.$vs_uid;
			if (!is_dir($vs_dir)) { @mkdir($vs_dir, 0700, true); }
			if (is_dir($vs_dir) && is_writable($vs_dir)) { return $vs_dir; }
		}
		return null;
	}
}

if (!function_exists('inrap_0103_prepare_document')) {
	/**
	 * Prépare le couple (chemin de travail, nom de téléchargement) d'un document.
	 *
	 * @param string $ps_prefixe          « bordereau-versement », « lettre_vierge »…
	 * @param mixed  $pm_reference        idno de la fiche éditée
	 * @param string $ps_repertoire_repli répertoire historique, utilisé seulement si
	 *                                    aucun emplacement privé n'est disponible
	 * @return array chemin / nom / fichier / prive
	 */
	function inrap_0103_prepare_document($ps_prefixe, $pm_reference, $ps_repertoire_repli) {
		$vs_ref = inrap_0103_slug_document($pm_reference);
		if ($vs_ref === '') { $vs_ref = 'sans-reference'; }
		$vs_horo = date('Ymd-His');
		try {
			$vs_jeton = bin2hex(random_bytes(8));
		} catch (\Exception $e) {
			$vs_jeton = substr(md5(uniqid('', true).'|'.getmypid()), 0, 16);
		}
		$vs_dir = inrap_0103_repertoire_prive();
		$vb_prive = ($vs_dir !== null);
		if (!$vb_prive) { $vs_dir = rtrim($ps_repertoire_repli, '/'); }
		$vs_fichier = $ps_prefixe.'_'.$vs_ref.'_'.$vs_horo.'_'.$vs_jeton.'.docx';
		return [
			'chemin'  => $vs_dir.'/'.$vs_fichier,   // fichier de travail, jamais montré
			'fichier' => $vs_fichier,
			'nom'     => $ps_prefixe.'_'.$vs_ref.'_'.$vs_horo.'.docx',  // nom vu par l'agent
			'prive'   => $vb_prive,
		];
	}
}

if (!function_exists('inrap_0103_servir_document')) {
	/**
	 * Envoie le document dans la réponse HTTP puis efface le fichier de travail.
	 * L'appelant doit terminer l'exécution (exit) immédiatement après un retour vrai.
	 *
	 * @param array  $pa_doc     tableau rendu par inrap_0103_prepare_document()
	 * @param string $ps_url_repli URL du répertoire historique, pour le seul cas où
	 *                             la réponse aurait déjà commencé à être émise.
	 * @return bool
	 */
	function inrap_0103_servir_document(array $pa_doc, $ps_url_repli = null) {
		$vs_chemin = $pa_doc['chemin'];
		if (!is_file($vs_chemin)) { return false; }

		if (headers_sent()) {
			// Cas résiduel : impossible de poser des en-têtes. On ne peut que rediriger,
			// et seulement si le fichier est dans le répertoire servi en statique.
			if (($ps_url_repli !== null) && !$pa_doc['prive']) {
				print '<script>document.location="'.rtrim($ps_url_repli, '/').'/'.rawurlencode($pa_doc['fichier']).'";</script>';
				return true;
			}
			return false;
		}

		// La vue est rendue dans un tampon de sortie : on l'abandonne pour n'émettre
		// que le document, sans le moindre octet parasite devant l'en-tête ZIP « PK ».
		while (ob_get_level() > 0) { @ob_end_clean(); }

		header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
		header('Content-Disposition: attachment; filename="'.$pa_doc['nom'].'"; filename*=UTF-8\'\''.rawurlencode($pa_doc['nom']));
		header('Content-Length: '.filesize($vs_chemin));
		// Ni le navigateur ni Cloudflare ne doivent conserver une pièce nominative.
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
		header('Pragma: no-cache');
		header('Expires: 0');
		header('X-Content-Type-Options: nosniff');
		// Repère de contrôle : permet de vérifier en recette quelle fiche a été servie.
		header('X-Inrap-Document: '.$pa_doc['nom']);

		readfile($vs_chemin);
		@unlink($vs_chemin);
		return true;
	}
}
// === D2026-0103 §4 : fin ===
