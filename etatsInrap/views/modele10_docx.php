<?php
	$vs_etat = $this->getVar("etat");
	$va_result = $this->getVar("results");
	$vs_titre = $this->getVar("titre");
    
    //var_dump($va_result );die();
	//require_once(__CA_BASE_DIR__."/vendor/phpoffice/phpword/bootstrap.php");

    // === D2026-0103 §4 — échappement XML et retours à la ligne : début ===
    // Même défaut de rendu que celui corrigé sur le bordereau de versement (modele4_docx.php) :
    // injecté tel quel, « <w:br/> » atterrit à l'intérieur du <w:t> qui portait le placeholder,
    // position où ni Word ni LibreOffice ne le prennent en compte — l'adresse s'imprime sur une
    // seule ligne. Le saut doit fermer l'élément de texte courant puis en rouvrir un.
    // Procédé identique à GenererController::enSuspensClean() ; comme il s'agit ici d'adresses,
    // la règle « ,sautdeligne » (pas de virgule en fin de ligne d'adresse) est reprise elle aussi.
    //
    // Correction KO-1 : cette lettre n'échappait AUCUNE de ses 25 valeurs. Le défaut est le
    // même que celui constaté sur le bordereau de versement ; il est latent ici seulement
    // parce qu'aucune des données concernées ne porte aujourd'hui d'esperluette nue. La
    // protection ne doit pas reposer sur l'état des données : le nettoyage est appliqué à
    // toutes les valeurs, par la fonction commune inrap_0103_valeur_word().
    require_once(__CA_APP_DIR__.'/plugins/etatsInrap/lib/inrap0103_documents.php');

    // Référence de la fiche, relevée AVANT nettoyage : elle nomme le fichier remis (KO-3).
    $vs_ref_courrier_0103 = '';
    foreach ([24, 13] as $vn_i_0103) {
        if (isset($va_result[$vn_i_0103]) && (trim(strip_tags((string)$va_result[$vn_i_0103])) !== '')) {
            $vs_ref_courrier_0103 = $va_result[$vn_i_0103];
            break;
        }
    }
    foreach ($va_result as $vs_k_0103 => $vs_v_0103) {
        $va_result[$vs_k_0103] = inrap_0103_valeur_word($vs_v_0103, true);
    }
    // === D2026-0103 §4 : fin ===

//error_reporting(E_ALL);
	$templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor(__CA_APP_DIR__."/plugins/etatsInrap/templates/lettre_garde_inrap.docx");
    $templateProcessor->setValue('IDNO_LETTRE', $va_result[24]);
    $templateProcessor->setValue('CODE_IDNO', $va_result[0]);
    $templateProcessor->setValue('NOM_PRESCRI', $va_result[1]);
    $templateProcessor->setValue('NOM_OP', $va_result[2]);
    $templateProcessor->setValue('NUM_OA', $va_result[3]);
    $templateProcessor->setValue('OP_TYPE', $va_result[4]);
    $templateProcessor->setValue('NUM_PRESCRI', $va_result[5]);
    $templateProcessor->setValue('DATE_PRESCRI', $va_result[6]);
    $templateProcessor->setValue('DATE_RAPPORT', $va_result[7]);
    $templateProcessor->setValue('NOM_RO', $va_result[8]);
    $templateProcessor->setValue('NUM_AUTOFOUILLE', $va_result[9]);
    $templateProcessor->setValue('DATE_AUTOFOUILLE', $va_result[10]);
    $templateProcessor->setValue('DATE_FINGARDE', $va_result[11]);
    $templateProcessor->setValue('DAST_NOM', $va_result[12]);
    $templateProcessor->setValue('IDNO_LETTRE', $va_result[13]);
    $templateProcessor->setValue('LIEU_LETTRE', $va_result[14]);
    $templateProcessor->setValue('DATE_LETTRE', $va_result[15]);
    $templateProcessor->setValue('LIEU_ENTREE', $va_result[16]);
    $templateProcessor->setValue('NOM_GESTIONNAIRE', $va_result[17]);
    $templateProcessor->setValue('NUM_GESTIONNAIRE', $va_result[18]);
    $templateProcessor->setValue('EMAIL_GESTIONNAIRE', $va_result[19]);
    $templateProcessor->setValue('DIR_NOM', $va_result[20]);
    // === D2026-0103 §4 — retours à la ligne des listes de contenants : début ===
    // Sauts déjà posés par la boucle de nettoyage ci-dessus.
    $templateProcessor->setValue('DIR_ADRESSE', $va_result[21]);
    // === D2026-0103 §4 : fin ===
    $templateProcessor->setValue('NOM_SRA', str_replace("_", " ", $va_result[22]));
    // === D2026-0103 §4 — retours à la ligne des listes de contenants : début ===
    $templateProcessor->setValue('ADRESSE_SRA', $va_result[23]);
    // === D2026-0103 §4 : fin ===

    
    // === D2026-0103 §4 — nom non ambigu et diffusion du document produit : début ===
    // KO-3 : nom horodaté à la seconde dans app/tmp, servi en statique. Le nom porte
    // désormais la référence du courrier ; le fichier est écrit hors de la racine web,
    // remis dans la réponse puis effacé.
    $va_doc_0103 = inrap_0103_prepare_document('lettre_garde_inrap', $vs_ref_courrier_0103,
                                               __CA_APP_DIR__.'/tmp');
    $templateProcessor->saveAs($va_doc_0103['chemin']);
    if (inrap_0103_servir_document($va_doc_0103, '/app/tmp')) { exit; }
    print "La lettre de garde n'a pas pu être remise : document introuvable après génération.";
    // === D2026-0103 §4 : fin ===

//die();
// Your browser will name the file "myFile.docx"
// regardless of what it's named on the server
/*header('Content-Type: application/octet-stream');
header("Content-Disposition: attachment; filename=\"lettre_garde_inrap.docx\"");
readfile(__CA_APP_DIR__.'/tmp/lettre_garde_inrap1.docx'); // or echo file_get_contents($temp_file);
unlink(__CA_APP_DIR__.'/tmp/lettre_garde_inrap1.docx');  // remove temp file

    return;*/
?>
