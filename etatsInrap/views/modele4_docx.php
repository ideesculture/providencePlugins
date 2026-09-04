<?php
/**
 * Bordereau de versement — édition Word (marché 036SE2025, D2026-0103 M0103-B)
 * Template : templates/bordereau-versement.docx (maquette INRAP variabilisée)
 * Alimenté par GenererController::Modele4() → $va_results[0]=en-têtes, [1..n]=opérations liées.
 * Page 1 = niveau versement (une fois) ; pages 2..n = une page par opération (bloc ${OP}, saut de page inclus).
 */
    $va_results = $this->getVar("results");
    $vs_titre   = $this->getVar("titre");

    // === D2026-0103 §4 — échappement XML de toutes les valeurs injectées : début ===
    // KO-1 (vérification consolidée du 09/08/2026). Les trois listes de contenants et
    // le bloc direction/SRA étaient échappés ; les 25 autres setValue() ne passaient
    // que par strip_tags(), qui ne neutralise ni « & » ni « < ». Un lieu-dit tel que
    // « PA de la Gaultière Tr 1&2 » (versements 12912, 10016 et 14804) produisait un
    // word/document.xml mal formé, que Word comme LibreOffice refusent d'ouvrir.
    //
    // Le correctif ne vise pas ces trois fiches : l'échappement est remonté DANS
    // $clean(), point de passage unique de toutes les valeurs de ligne. Aucun champ
    // ne peut plus y échapper, pas même ceux qui seront ajoutés plus tard.
    // La chaîne appliquée est celle qui existait déjà dans le plugin (celle des listes
    // et de GenererController::enSuspensClean() du D2026-0102), factorisée dans
    // lib/inrap0103_documents.php et partagée avec modele4b / modele10 / modele11 :
    // saut repéré → HTML nettoyé → entités décodées → échappement XML → saut OOXML.
    require_once(__CA_APP_DIR__.'/plugins/etatsInrap/lib/inrap0103_documents.php');

    // nettoyage d'une ligne : espace pour les valeurs vides. $clean() pose désormais
    // aussi les sauts de ligne OOXML : les trois listes de contenants n'ont donc plus
    // besoin d'un traitement séparé (voir plus bas).
    $clean = function($r){
        foreach($r as $k=>$v){
            // === D2026-0103 §4 — évolution 12/08/2026, listes de contenants en tableau : début ===
            // Les colonnes [51..53] portent les données STRUCTURÉES des tableaux de
            // contenants : elles ne passent pas par l'échappement global — chaque cellule
            // est échappée individuellement à la composition du tableau, le balisage OOXML
            // qui l'entoure ne devant PAS l'être.
            if (is_array($v)) { continue; }
            // === D2026-0103 §4 — évolution 12/08/2026, listes de contenants en tableau : fin ===
            $v = inrap_0103_valeur_word($v); $r[$k] = ($v==="") ? " " : $v;
        }
        return $r;
    };
    // === D2026-0103 §4 : fin ===

    // === D2026-0103 §4 — retours à la ligne des listes de contenants : début ===
    // Le contrôleur assemble les trois listes (mobilier, documentation, numérique) en séparant
    // les entrées par le littéral « sautdeligne ». Le remplacer par « <w:br/> » ne suffit pas :
    // le saut est alors injecté À L'INTÉRIEUR du <w:t> qui portait le placeholder, position où
    // ni Word ni LibreOffice ne le prennent en compte — un versement à 300 contenants s'imprime
    // en un pavé continu illisible. En OOXML, un saut de ligne doit FERMER l'élément de texte
    // courant, porter le <w:br/> au niveau du run, puis rouvrir un élément de texte.
    // Procédé identique à GenererController::enSuspensClean(), écrit pour les lettres
    // « En suspens » : on le réutilise ici plutôt que d'en inventer un autre.
    // Nota : la règle « ,sautdeligne » d'enSuspensClean est propre aux adresses (suppression de
    // la virgule de fin de ligne) et n'est PAS reprise ici, pour ne pas manger une virgule
    // légitime d'un libellé de contenant.
    //
    // Correction KO-1 : ce traitement n'est plus une exception réservée aux trois listes.
    // Il est appliqué à toutes les valeurs par $clean() ci-dessus, via la fonction commune
    // inrap_0103_valeur_word(). La fermeture $sautDeLigneWord est donc supprimée : la
    // conserver reviendrait à échapper deux fois les listes (« & » imprimé « &amp; »).
    // === D2026-0103 §4 : fin ===

    $n = max(0, count($va_results)-1);
    if ($n < 1) { print "Aucune opération liée à ce versement."; return; }

    // === D2026-0103 §4 — évolution 12/08/2026, listes de contenants en tableau : début ===
    // Demande client du 12/08/2026 : les trois listes de contenants deviennent des TABLEAUX
    // Word. Le contrôleur fournit en [51..53] les données par liste (lignes déjà nettoyées
    // par $vs_pourImpression_0103, drapeaux de présence des colonnes facultatives) ; la vue
    // compose ici le XML OOXML en CHAÎNES CONCATÉNÉES — aucune bibliothèque — et le fait
    // passer par le MÊME chemin que toutes les autres valeurs : le cloneBlock porteur de
    // valeurs (correctif de performance (e), replaceClonedVariables = simple str_replace).
    //
    // INSERTION XML BRUT — pourquoi ce détour. Un <w:tbl> ne peut ni être échappé (le
    // htmlspecialchars du chemin normal l'imprimerait en toutes lettres), ni vivre dans un
    // <w:p> (schéma OOXML). Le gabarit a donc été restructuré le 12/08/2026 (sauvegarde
    // bordereau-versement.docx.bak_0103tbl_*) : chaque variable ${LISTE_*} est désormais
    // SEULE dans son paragraphe. La valeur injectée FERME ce paragraphe, pose le tableau au
    // niveau du corps, puis ROUVRE un paragraphe que le balisage du gabarit referme — même
    // équilibre d'ouvertures/fermetures que $adresseDirWord ci-dessous. Le XML reste valide
    // dans tous les cas :
    //   · liste vide → « — » en texte simple, pas de tableau : la page reste honnête
    //     vis-à-vis du compteur zéro affiché au-dessus (réserve R1, versement 13140) ;
    //   · valeurs à caractères spéciaux → chaque cellule est échappée par inrap_0103_xml()
    //     (décodage puis ENT_XML1 — le KO-1 du 0103 était précisément une esperluette nue) ;
    //   · les données arrivent déjà nettoyées (idno à espaces insécables compris) par
    //     $vs_pourImpression_0103, appliqué dans le contrôleur à chaque valeur de cellule.
    //
    // En-têtes RÉPÉTÉS en haut de chaque page traversée : <w:tblHeader/> sur la ligne 1
    // (un versement à plusieurs centaines de contenants traverse plusieurs pages).
    // Largeurs en dxa sur 9 072 twips utiles (A4 portrait, marges 1 417), tableau en
    // disposition fixe ; Arial 8 pt, libellés d'en-tête courts calés sur les champs
    // normalisés de l'inventaire des contenants du référentiel national (oct. 2025).
    $vs_celluleTbl_0103 = function($ps_val, $pn_w, $pb_entete) {
        $vs_rpr = '<w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>'
                . ($pb_entete ? '<w:b/>' : '')
                . '<w:sz w:val="16"/><w:szCs w:val="16"/></w:rPr>';
        $vs_c = '<w:tc><w:tcPr><w:tcW w:w="'.$pn_w.'" w:type="dxa"/>'
              . ($pb_entete ? '<w:shd w:val="clear" w:color="auto" w:fill="D9D9D9"/>' : '')
              . '<w:vAlign w:val="top"/></w:tcPr>'
              . '<w:p><w:pPr><w:spacing w:before="20" w:after="20" w:line="240" w:lineRule="auto"/>'.$vs_rpr.'</w:pPr>';
        if ((string)$ps_val !== '') {
            $vs_c .= '<w:r>'.$vs_rpr.'<w:t xml:space="preserve">'.inrap_0103_xml($ps_val, true).'</w:t></w:r>';
        }
        return $vs_c.'</w:p></w:tc>';
    };
    $vs_tableauContenants_0103 = function($pm_liste) use ($vs_celluleTbl_0103) {
        // Repli défensif : sans données structurées (contrôleur plus ancien, appel hors
        // docx), l'appelant retombe sur la colonne texte historique.
        if (!is_array($pm_liste) || empty($pm_liste['contenants_0103'])) { return null; }
        if (!sizeof($pm_liste['rows'])) { return '—'; }
        $vb_mob = ($pm_liste['fam'] === 'mob');
        // Colonnes : 1-5 fixes pour le mobilier ; « Référence » (CDC d'origine) pour les
        // familles documentation/numérique, où taille et poids ne font colonne que s'ils
        // sont portés par au moins un contenant de la liste. « Nature prélèvement » et
        // « Catégorie de documentation » n'apparaissent que si l'élément existe en base ET
        // porte une valeur dans la liste (aujourd'hui jamais — emplacement prévu, cf.
        // contrôleur). Poids relatifs → dxa, la dernière colonne absorbe l'arrondi.
        $va_cols = [];
        $va_cols[] = ['k' => '__oa',  'l' => 'Code OA',               'w' => 120];
        $va_cols[] = ['k' => 'idno',  'l' => 'Identifiant contenant', 'w' => 190];
        if ($vb_mob) {
            $va_cols[] = ['k' => 'taille', 'l' => 'Type-taille',    'w' => 200];
            $va_cols[] = ['k' => 'poids',  'l' => 'Poids (kg)',     'w' => 95];
            $va_cols[] = ['k' => 'mat',    'l' => 'Matière/classe', 'w' => 300];
        } else {
            $va_cols[] = ['k' => 'ref', 'l' => 'Référence', 'w' => 460];
            if (!empty($pm_liste['has']['taille'])) { $va_cols[] = ['k' => 'taille', 'l' => 'Type-taille', 'w' => 200]; }
            if (!empty($pm_liste['has']['poids']))  { $va_cols[] = ['k' => 'poids',  'l' => 'Poids (kg)',  'w' => 95]; }
        }
        if (!empty($pm_liste['has']['nature'])) { $va_cols[] = ['k' => 'nature', 'l' => 'Nature prélèvement',          'w' => 170]; }
        if (!empty($pm_liste['has']['categ']))  { $va_cols[] = ['k' => 'categ',  'l' => 'Catégorie de documentation', 'w' => 200]; }
        $vn_tot = 0; foreach ($va_cols as $va_c) { $vn_tot += $va_c['w']; }
        $vn_reste = 9072; $vn_nc = sizeof($va_cols);
        foreach ($va_cols as $vn_i => $va_c) {
            $vn_w = ($vn_i == $vn_nc - 1) ? $vn_reste : (int)floor(9072 * $va_c['w'] / $vn_tot);
            $va_cols[$vn_i]['dxa'] = $vn_w; $vn_reste -= $vn_w;
        }
        $vs_tbl = '<w:tbl><w:tblPr><w:tblW w:w="9072" w:type="dxa"/><w:tblBorders>'
                . '<w:top w:val="single" w:sz="4" w:space="0" w:color="808080"/>'
                . '<w:left w:val="single" w:sz="4" w:space="0" w:color="808080"/>'
                . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="808080"/>'
                . '<w:right w:val="single" w:sz="4" w:space="0" w:color="808080"/>'
                . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="808080"/>'
                . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="808080"/>'
                . '</w:tblBorders><w:tblLayout w:type="fixed"/>'
                . '<w:tblCellMar><w:left w:w="57" w:type="dxa"/><w:right w:w="57" w:type="dxa"/></w:tblCellMar>'
                . '</w:tblPr><w:tblGrid>';
        foreach ($va_cols as $va_c) { $vs_tbl .= '<w:gridCol w:w="'.$va_c['dxa'].'"/>'; }
        $vs_tbl .= '</w:tblGrid><w:tr><w:trPr><w:cantSplit/><w:tblHeader/></w:trPr>';
        foreach ($va_cols as $va_c) { $vs_tbl .= $vs_celluleTbl_0103($va_c['l'], $va_c['dxa'], true); }
        $vs_tbl .= '</w:tr>';
        foreach ($pm_liste['rows'] as $va_r) {
            $vs_tbl .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';
            foreach ($va_cols as $va_c) {
                $vs_v = ($va_c['k'] === '__oa') ? $pm_liste['oa'] : (isset($va_r[$va_c['k']]) ? $va_r[$va_c['k']] : '');
                $vs_tbl .= $vs_celluleTbl_0103($vs_v, $va_c['dxa'], false);
            }
            $vs_tbl .= '</w:tr>';
        }
        $vs_tbl .= '</w:tbl>';
        return '</w:t></w:r></w:p>'.$vs_tbl
             . '<w:p><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr><w:r><w:t xml:space="preserve">';
    };
    // === D2026-0103 §4 — évolution 12/08/2026, listes de contenants en tableau : fin ===

    $first = $clean($va_results[1]);   // niveau MOUVEMENT (constant pour toutes les opérations)

    $templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor(__CA_APP_DIR__."/plugins/etatsInrap/templates/bordereau-versement.docx");

    // 1) Cloner le bloc opération AVANT tout setValue (sinon ${IDNO}/${DATE}, présents page 1 ET page 2,
    //    seraient consommés avant l'indexation). indexVariables=true => ${VAR#1..#n} dans le bloc.
    // === D2026-0103 §4 — édition des versements à nombreuses opérations : début ===
    // (e) Exigence #43. Les 28 réserves de la page opération étaient posées APRÈS le
    //     clonage, par setValue() indexés : 28 × n appels, chacun balayant le document
    //     ENTIER — lequel grandit lui-même avec n. Coût quadratique : mesuré 7 s pour
    //     60 opérations et 140 s pour 312, soit 60 % du temps d'édition du versement 3518.
    //
    //     PhpWord prévoit exactement ce cas : cloneBlock() accepte en 5e argument un
    //     tableau de remplacements PAR CLONE, appliqués sur le BLOC seul au moment du
    //     clonage (TemplateProcessor::replaceClonedVariables). Le coût redevient linéaire.
    //
    //     Résultat identique par construction : replaceClonedVariables() fait le même
    //     str_replace('${CLE}', valeur) que setValue(), dans le même ordre ; les réserves
    //     du bloc n'existent que dans la partie principale (ni en-tête ni pied de page),
    //     et l'échappement de sortie de PhpWord est désactivé sur cette instance.
    //     Contrôlé : documents octet pour octet identiques avant/après.
    //
    //     Les valeurs sont préparées AVANT le clonage, sans toucher au gabarit : l'ordre
    //     d'origine est préservé (cloner avant tout setValue, sinon ${IDNO} et ${DATE},
    //     présents page 1 ET page 2, seraient consommés par la page 1).
    //     Les 27 réserves du bloc « OP » du masque sont toutes couvertes ; ANNEE_OP est
    //     posée en prévision, le masque ne la porte pas encore.
    $va_repl_0103 = [];
    for ($i=1; $i<=$n; $i++){
        $r = $clean($va_results[$i]);
        // === D2026-0103 §4 — évolution 12/08/2026, listes de contenants en tableau : début ===
        // Tableaux composés depuis les données structurées [51..53] ; à défaut (null),
        // repli sur les colonnes texte historiques [42..44] déjà échappées par $clean().
        $vs_tblMob_0103 = $vs_tableauContenants_0103(isset($r[51]) ? $r[51] : null);
        $vs_tblDoc_0103 = $vs_tableauContenants_0103(isset($r[52]) ? $r[52] : null);
        $vs_tblNum_0103 = $vs_tableauContenants_0103(isset($r[53]) ? $r[53] : null);
        // === D2026-0103 §4 — évolution 12/08/2026, listes de contenants en tableau : fin ===
        $va_repl_0103[] = [
            'IDNO'              => $r[22],
            'DATE'              => $r[23],
            'OA'                => $r[1],
            'INRAP'             => $r[0],
            'REGION'            => $r[2],
            'DEP'               => $r[3],
            'COMMUNE'           => $r[4],
            'LIEUDIT'           => $r[5],
            'TYPE_OP'           => $r[31],
            'DATE_DEB_TERRAIN'  => $r[32],
            'DATE_FIN_TERRAIN'  => $r[33],
            'NUM_PRESCRI'       => $r[34],
            'DATE_PRESCRI'      => $r[35],
            'RO'                => $r[6],
            'NUM_DESI'          => $r[36],
            'DATE_DESI'         => $r[37],
            'LIEU_VERS'         => $r[40],
            'ANNEE_OP'          => $r[41],
            'BA_CONT'           => $r[7],
            'BA_HCONT'          => $r[8],
            'BA_VOL'            => $r[9],
            'POIDS_BAM'         => $r[38],
            'DOC_CONT'          => $r[11],
            'NUM_CONT'          => $r[14],
            'VOL_MO'            => $r[45],
            // === D2026-0103 §4 — évolution 12/08/2026, listes de contenants en tableau : début ===
            'LISTE_MOB'         => ($vs_tblMob_0103 !== null) ? $vs_tblMob_0103 : $r[42],
            'LISTE_DOC'         => ($vs_tblDoc_0103 !== null) ? $vs_tblDoc_0103 : $r[43],
            'LISTE_NUM'         => ($vs_tblNum_0103 !== null) ? $vs_tblNum_0103 : $r[44],
            // === D2026-0103 §4 — évolution 12/08/2026, listes de contenants en tableau : fin ===
        ];
    }
    $templateProcessor->cloneBlock('OP', $n, true, false, $va_repl_0103);
    // === D2026-0103 §4 : fin ===

    // 2) Page 1 — niveau versement (une seule fois ; ces placeholders sont hors bloc)
    $templateProcessor->setValue('AAAA', date("Y"));
    $templateProcessor->setValue('IDNO', $first[22]);
    $templateProcessor->setValue('DATE', $first[23]);
    // === D2026-0103 §4 — mapping des métadonnées : début ===
    // §4, page 1 : « Inrap – Nom, prénom » et « Acceptation et décharge – Nom, Prénom » =
    // « Nom pour l'affichage (displayname) ». Le contrôleur expose ce displayname en [49]
    // (agent Inrap, relation « mover ») et [50] (agent SRA, relation « destinataire »).
    //
    // Le masque porte DEUX réserves accolées, « ${RESP_VERS_NOM} ${RESP_VERS_PRENOM} », héritées
    // du découpage nom / prénom. Le displayname est un libellé unique : il alimente la première,
    // la seconde est vidée. Le gabarit Word n'est PAS modifié — y toucher obligerait à revalider
    // les 37 réserves et la mise en page auprès du client pour un gain nul.
    // On passe une chaîne VIDE, et non $clean() qui substitue une espace insécable aux valeurs
    // absentes : la réserve disparaît sans laisser de blanc parasite. Reste la seule espace
    // littérale du masque entre les deux réserves, invisible en fin de cellule.
    // Un versement sans agent renseigné (aucune relation mover / destinataire) sort donc une
    // cellule vide, sans « ${...} » résiduel — vérifié sur les versements 1443 et 1453.
    // KO-1 : displayname du répertoire, injecté sans échappement (« CCE Sartène & Aléria »).
    $templateProcessor->setValue('RESP_VERS_NOM', inrap_0103_xml($va_results[1][49], true));
    $templateProcessor->setValue('RESP_VERS_PRENOM', '');
    $templateProcessor->setValue('RESP_QUAL', $first[28]);
    $templateProcessor->setValue('SRA_NOM', inrap_0103_xml($va_results[1][50], true));
    $templateProcessor->setValue('SRA_PRENOM', '');
    $templateProcessor->setValue('RESP_SRA', $first[29]);
    // === D2026-0103 §4 : fin ===
    // === D2026-0103 §4 — signataires et adresse de la page 1 : début ===
    // Ces trois réserves de la page 1 étaient blanchies en dur (setValue ' ') : le bordereau
    // sortait avec la ligne « Direction » vide, « Service Régional de l'Archéologie : » vide, et
    // l'annotation « [Adresse DIR] » seule en pied de page 1.
    //
    // Source réelle (établie en base, cf. compte rendu) : le VERSEMENT ne porte aucune de ces
    // trois informations — ca_movements_x_entities ne connaît que mover / destinataire /
    // authorizer / conservation, il n'existe aucune relation DIR sur un mouvement. Le §4 les
    // annonce « A venir (ca_storage_locations) » et autorise « sinon répertoire en attendant » :
    // c'est ce repli qui est appliqué, via l'OPÉRATION LIÉE (ca_entities_x_collections).
    // Le contrôleur expose [46] Direction Inrap, [47] SRA, [48] adresse de la direction.
    // Page 1 = niveau versement ; la sélection de l'opération portante est expliquée plus bas.

    // Échappement XML : les libellés du répertoire contiennent des apostrophes
    // (« pôle [sra] d'Amiens ») et peuvent contenir des « & », qui casseraient word/document.xml.
    // KO-1 : le décodage préalable des entités HTML (assuré par inrap_0103_xml) évite
    // le double échappement sur les valeurs déjà encodées en base — « Garoutier 1 &amp; 2 »
    // pour le versement 3673, qui s'imprimerait sinon en toutes lettres. Cette variante ne
    // pose PAS de saut de ligne : $adresseDirWord() a besoin de découper lui-même l'adresse
    // sur « sautdeligne » pour changer de couleur à chaque ligne.
    $xmlText = function($ps_val){
        return inrap_0103_xml($ps_val, true);
    };

    // §4 « En petite calligraphie … Nom en rouge … finir adresse par inrap.fr en rouge
    // (présentation type courrier) » : trois habillages de run, en Arial 8 pt (sz 16).
    $vs_rpr_rouge_0103 = '<w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/><w:color w:val="EE0000"/><w:sz w:val="16"/><w:szCs w:val="16"/></w:rPr>';
    $vs_rpr_noir_0103  = '<w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/><w:sz w:val="16"/><w:szCs w:val="16"/></w:rPr>';

    // Bloc adresse de la direction, en plusieurs lignes et trois couleurs.
    // Même règle OOXML que $sautDeLigneWord ci-dessus (et que enSuspensClean du D2026-0102) :
    // la valeur est nettoyée puis ÉCHAPPÉE AVANT toute insertion de balise, et un saut de ligne
    // FERME l'élément de texte courant au lieu d'être glissé dedans. Ici chaque ligne change en
    // plus de couleur : on ferme donc le run du gabarit, on émet un run complet par ligne, puis
    // on rouvre un run vide que le </w:t></w:r> du gabarit refermera — l'XML reste équilibré.
    $adresseDirWord = function($ps_nom, $ps_adresse) use ($xmlText, $vs_rpr_rouge_0103, $vs_rpr_noir_0103) {
        $vs_nom = $xmlText($ps_nom);
        // le gabarit d'adresse du 0102 sépare ses lignes par « ,sautdeligne » ; on ôte la virgule
        // de fin de ligne exactement comme le fait enSuspensClean, puis on découpe.
        $vs_adr = $xmlText(str_replace(',sautdeligne', 'sautdeligne', (string)$ps_adresse));
        $va_lignes = array_values(array_filter(array_map('trim', explode('sautdeligne', $vs_adr)), function($v){ return $v !== ''; }));

        // Absence en base = absence dans le document : ni réserve ${...} résiduelle, ni « inrap.fr »
        // orphelin, ni bloc vide. La réserve disparaît purement et simplement.
        if ($vs_nom === '' && !sizeof($va_lignes)) { return ''; }

        $vs_out = '</w:t></w:r>';
        $vb_premier = true;
        if ($vs_nom !== '') {
            $vs_out .= '<w:r>'.$vs_rpr_rouge_0103.'<w:t xml:space="preserve">'.$vs_nom.'</w:t></w:r>';
            $vb_premier = false;
        }
        foreach ($va_lignes as $vs_l) {
            $vs_out .= '<w:r>'.$vs_rpr_noir_0103.($vb_premier ? '' : '<w:br/>').'<w:t xml:space="preserve">'.$vs_l.'</w:t></w:r>';
            $vb_premier = false;
        }
        $vs_out .= '<w:r>'.$vs_rpr_rouge_0103.($vb_premier ? '' : '<w:br/>').'<w:t xml:space="preserve">inrap.fr</w:t></w:r>';
        return $vs_out.'<w:r>'.$vs_rpr_noir_0103.'<w:t xml:space="preserve">';
    };

    // Un versement multi-opérations peut avoir sa PREMIÈRE opération non renseignée alors que
    // les suivantes le sont (constaté sur les versements 8899 et 11580 : le SRA n'apparaît qu'à
    // partir de la 5e / 2e opération). Se limiter à $first laissait la page 1 vide alors que la
    // donnée existait. On retient donc la première valeur NON VIDE dans l'ordre des opérations :
    // un versement s'adresse à une direction et à un SRA uniques.
    // Le nom et l'adresse de la direction sont pris sur la MÊME opération, pour ne pas composer
    // un bloc à partir de deux directions différentes.
    $vs_dir_nom_0103 = $vs_dir_adr_0103 = $vs_sra_svc_0103 = '';
    for ($k = 1; $k <= $n; $k++) {
        // KO-1 : valeurs BRUTES ici. $clean() échappe désormais, or $xmlText() et
        // $adresseDirWord() échappent à leur tour un peu plus bas : passer par $clean()
        // produirait un double échappement du bloc adresse de la page 1.
        $r = $va_results[$k];
        if ($vs_dir_nom_0103 === '' && trim(strip_tags((string)$r[46])) !== '') {
            $vs_dir_nom_0103 = (string)$r[46];
            $vs_dir_adr_0103 = (string)$r[48];
        }
        if ($vs_sra_svc_0103 === '' && trim(strip_tags((string)$r[47])) !== '') {
            $vs_sra_svc_0103 = (string)$r[47];
        }
        if ($vs_dir_nom_0103 !== '' && $vs_sra_svc_0103 !== '') { break; }
    }

    $templateProcessor->setValue('DIR_INRAP',   $xmlText($vs_dir_nom_0103));
    $templateProcessor->setValue('SRA_SERVICE', $xmlText($vs_sra_svc_0103));
    $templateProcessor->setValue('ADRESSE_DIR', $adresseDirWord($vs_dir_nom_0103, $vs_dir_adr_0103));
    // === D2026-0103 §4 : fin ===

    // === D2026-0103 §4 — édition des versements à nombreuses opérations : début ===
    // (e) Les réserves des pages 2..n sont désormais posées AU CLONAGE (voir plus haut).
    //     La boucle de setValue() indexés qui se trouvait ici est supprimée : c'était
    //     elle qui rendait l'édition quadratique. Le mapping réserve → colonne est
    //     inchangé, il a simplement été déplacé.
    // === D2026-0103 §4 : fin ===

    // === D2026-0103 §4 — nom non ambigu et diffusion du document produit : début ===
    // KO-3 : le nom était « bordereau-versement1<time()>.docx », horodaté à la seconde,
    // écrit dans un répertoire servi en statique, et l'agent y était renvoyé par une
    // redirection 302. Deux éditions dans la même seconde partageaient nom et URL ;
    // reproduit le 10/08/2026, les versements 13140 et 16804 demandés simultanément ont
    // reçu la même URL et le même fichier (md5 70af2d96…).
    //
    // Ce qui identifie ce document, c'est le VERSEMENT dont il est l'édition : son idno
    // entre donc dans le nom, avec l'horodatage, et le fichier de travail y ajoute un
    // jeton aléatoire. Le fichier est écrit hors de la racine web, remis dans la réponse
    // HTTP puis effacé : un bordereau nominatif n'a pas à rester dans un répertoire public.
    $va_doc_0103 = inrap_0103_prepare_document('bordereau-versement', $va_results[1][22],
                                               __CA_APP_DIR__.'/plugins/etatsInrap/tmp');
    $templateProcessor->saveAs($va_doc_0103['chemin']);
    if (inrap_0103_servir_document($va_doc_0103, '/app/plugins/etatsInrap/tmp')) { exit; }
    print "Le bordereau de versement n'a pas pu être remis : document introuvable après génération.";
    // === D2026-0103 §4 : fin ===
    return;
?>
