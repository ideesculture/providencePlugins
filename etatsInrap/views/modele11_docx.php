<?php
	$vs_etat = $this->getVar("etat");
	$va_result = $this->getVar("results");
	$vs_titre = $this->getVar("titre");
    
    //var_dump($va_result );die();
    // === D2026-0103 §4 — corps de la lettre : retours à la ligne : début ===
    // La boucle d'origine écrasait sa propre conversion : la seconde ligne repartait de
    // $value (la valeur brute) au lieu du résultat de la première, et strip_tags effaçait
    // alors les <br>. Le corps de la lettre s'imprimait donc en un seul pavé.
    // Chaîne retenue, identique à GenererController::enSuspensClean() (D2026-0102) :
    // repérage des sauts → suppression du HTML résiduel → échappement XML → insertion du
    // saut OOXML, qui doit fermer l'élément de texte courant puis en rouvrir un.
    //
    // Cette chaîne est désormais portée par la fonction commune inrap_0103_valeur_word()
    // (lib/inrap0103_documents.php), partagée avec modele4 / modele4b / modele10, et
    // complétée d'un décodage des entités HTML avant l'échappement : sans lui, une valeur
    // déjà encodée en base (« -&gt; », « &amp; ») serait échappée deux fois et s'imprimerait
    // en toutes lettres.
    require_once(__CA_APP_DIR__.'/plugins/etatsInrap/lib/inrap0103_documents.php');

    // Référence de la fiche, relevée AVANT nettoyage : elle nomme le fichier remis (KO-3).
    $vs_ref_courrier_0103 = isset($va_result[12]) ? $va_result[12] : '';
    foreach ($va_result as $key => $value) {
        $va_result[$key] = inrap_0103_valeur_word($value, true);
    }
    // === D2026-0103 §4 : fin ===



    // === D2026-0103 §4 — retours à la ligne des adresses : début ===
    // Cette fermeture était appliquée APRÈS la boucle de nettoyage ci-dessus, laquelle avait
    // déjà converti « sautdeligne » en balisage OOXML : elle ne trouvait donc plus rien à
    // convertir et n'était qu'un décor. Elle est supprimée pour ne pas masquer l'origine
    // réelle du KO-2, décrite au point ADRESSE_INTERLOCUTEUR ci-dessous.
    // === D2026-0103 §4 : fin ===

//error_reporting(E_ALL);
	$templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor(__CA_APP_DIR__."/plugins/etatsInrap/templates/lettre_vierge.docx");

    $pdfTempFile = __CA_APP_DIR__.'/tmp/'.time()."pdf";
    $templateProcessor->setValue('AAAA', date("Y"));
    $templateProcessor->setValue('CODE_IDNO', $va_result[0]);
    $templateProcessor->setValue('NOM_OP', $va_result[1]);
    $templateProcessor->setValue('NUM_OA', $va_result[2]);
    $templateProcessor->setValue('OP_TYPE', $va_result[3]);
    $templateProcessor->setValue('OBJET_LETTRE', $va_result[4]);
    $templateProcessor->setValue('DEBUT_LETTRE', $va_result[5]);
    // === D2026-0103 §4 — corps de la lettre : début ===
    // La conversion « <br> → <w:br/> » qui figurait ici est celle que la boucle de nettoyage
    // a remplacée : elle glissait le saut À L'INTÉRIEUR du <w:t>, où il est ignoré. À ce
    // stade il n'y a plus de <br> dans la valeur, et le saut OOXML est déjà correctement
    // posé : le remplacement ne faisait plus rien et cachait le traitement réel.
    $templateProcessor->setValue('TEXTE', $va_result[6]);
    // === D2026-0103 §4 : fin ===
    $templateProcessor->setValue('FIN_LETTRE', $va_result[7]);
    $templateProcessor->setValue('NOM_SIGNATAIRE', $va_result[8]);
    $templateProcessor->setValue('QUALITE_SIGNATAIRE', $va_result[9]);
    $templateProcessor->setValue('NOM_INTERLOCUTEUR', $va_result[10]);
    // === D2026-0103 §4 — adresse du destinataire (KO-2) : début ===
    // Défaut corrigé : ce strip_tags() s'appliquait APRÈS la boucle de nettoyage du haut de
    // fichier, laquelle avait déjà posé le saut « </w:t><w:br/><w:t …> ». Il effaçait donc le
    // balisage tout juste inséré, et l'adresse du destinataire s'imprimait sans le moindre
    // séparateur : « Hôtel de Grave5, rue de la Salle l'Evêque CS 4902034967 MONTPELLIER
    // cedex 2 » (occurrence 550), « 1 rue Blessig67000 Strasbourg France » (occurrence 856).
    // L'ordre correct est celui de la boucle : repérer les sauts, nettoyer le HTML, décoder,
    // échapper, PUIS poser le saut OOXML — jamais l'inverse. La valeur arrive ici prête à
    // l'emploi ; elle est posée telle quelle, exactement comme DIR_ADRESSE plus bas, qui
    // n'avait pas de strip_tags et fonctionnait déjà.
    $templateProcessor->setValue('ADRESSE_INTERLOCUTEUR', $va_result[11]);
    // === D2026-0103 §4 : fin ===
    $templateProcessor->setValue('IDNO_LETTRE', $va_result[12]);
    $templateProcessor->setValue('LIEU_LETTRE', $va_result[13]);
    $templateProcessor->setValue('DATE_LETTRE', $va_result[14]);
    $templateProcessor->setValue('NOM_GESTIONNAIRE', $va_result[15]);
    $templateProcessor->setValue('NUM_GESTIONNAIRE', $va_result[16]);
    $templateProcessor->setValue('EMAIL_GESTIONNAIRE', $va_result[17]);
    $templateProcessor->setValue('DIR_NOM', $va_result[18]);
    // === D2026-0103 §4 — retours à la ligne des adresses : début ===
    // Sauts déjà posés par la boucle de nettoyage du haut de fichier.
    $templateProcessor->setValue('DIR_ADRESSE', $va_result[19]);
    // === D2026-0103 §4 : fin ===

    // === D2026-0103 §4 — nom non ambigu et diffusion du document produit : début ===
    // KO-3 : « lettre_vierge_<time()>.docx » dans app/tmp, servi en statique. Deux courriers
    // édités dans la même seconde recevaient le même nom et la même URL (constaté le
    // 09/08/2026 sur les occurrences 1096 et 957). Le nom porte désormais la référence du
    // courrier ; le fichier est écrit hors de la racine web, remis dans la réponse puis
    // effacé — ces lettres portent des noms de personnes.
    $va_doc_0103 = inrap_0103_prepare_document('lettre_vierge', $vs_ref_courrier_0103,
                                               __CA_APP_DIR__.'/tmp');
    $templateProcessor->saveAs($va_doc_0103['chemin']);
    if (inrap_0103_servir_document($va_doc_0103, '/app/tmp')) { exit; }
    print "La lettre n'a pas pu être remise : document introuvable après génération.";
    // === D2026-0103 §4 : fin ===

    /*$phpWord = IOFactory::load(__CA_APP_DIR__.'/tmp/lettre_vierge.docx');
    $phpWord->save($pdfTempFile,'PDF');

    $vt_occ = new ca_occurrences($this->getVar("occurrence_id"));
    $vt_occ->addRepresentation($pdfTempFile, 143, 2, 1, 1, true, ["name" => "lettre_vierge.pdf"], ["original_filename" => "lettre_vierge.pdf"]);
    $vt_occ->update();*/

    

//die();
// Your browser will name the file "myFile.docx"
// regardless of what it's named on the server
/*header('Content-Type: application/octet-stream');
header("Content-Disposition: attachment; filename=\"lettre_vierge.docx\"");
readfile(__CA_APP_DIR__.'/tmp/lettre_vierge.docx'); // or echo file_get_contents($temp_file);*/
//unlink(__CA_APP_DIR__.'/tmp/lettre_vierge.docx');  // remove temp file

    return;
?>
