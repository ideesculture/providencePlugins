<?php

	$vs_etat = $this->getVar("etat");
	$va_results = $this->getVar("results");
	$vs_titre = $this->getVar("titre");
    $va_result = $va_results[1];
	$va_results_aggreges = [];
    $nb_col=sizeof($va_results[1]);
    foreach($va_results as $no_row=>$row) {
	    // Skip headers
	    if($no_row == 0) continue;
	    
		foreach($row as $no_col=>$coll) {
			// No data, continue
			//if(!$coll) continue;

			if($va_results_aggreges[$no_col]) {
				// Existing data, add - as a separator
				$va_results_aggreges[$no_col].= " - ";
			} else {
				// No data, create it with empty string
				if(!$va_results_aggreges[$no_col]) $va_results_aggreges[$no_col] = "";
			}			$va_results_aggreges[$no_col].=$coll;
		}    
    }
    //var_dump($va_result );die();
	//require_once(__CA_BASE_DIR__."/vendor/phpoffice/phpword/bootstrap.php");
	$time = time();
	error_reporting(E_ALL);
	ini_set("sys_temp_dir","/var/www/providence/app/tmp");

	
//var_dump(tempnam());
//die();

//var_dump(__CA_APP_DIR__.'/tmp/bordereau-mouvement'.$time.'.docx');
	//var_dump($va_results_aggreges);die();
    // === D2026-0103 §4 — échappement XML et retours à la ligne : début ===
    // Même défaut de rendu que celui corrigé sur le bordereau de versement (modele4_docx.php) :
    // injecté tel quel, « <w:br/> » atterrit à l'intérieur du <w:t> qui portait le placeholder,
    // position où ni Word ni LibreOffice ne le prennent en compte — observations et liste des
    // opérations liées s'impriment en un pavé continu. Le saut doit fermer l'élément de texte
    // courant puis en rouvrir un. Procédé identique à GenererController::enSuspensClean().
    //
    // Correction KO-1 : le traitement ne visait que deux réserves (OBS et OPERATION_LINKED).
    // Les 24 autres étaient injectées brutes — dont ${LIEUDIT}, la réserve même qui casse le
    // bordereau de versement, et ${DESCRIPTION}, qui contient du HTML sur 352 mouvements.
    // Le nettoyage est donc appliqué à toutes les valeurs, par la fonction commune
    // inrap_0103_valeur_word() (lib/inrap0103_documents.php).
    require_once(__CA_APP_DIR__.'/plugins/etatsInrap/lib/inrap0103_documents.php');

    // Référence de la fiche, relevée AVANT nettoyage : elle nomme le fichier remis (KO-3).
    $vs_ref_mvt_0103 = isset($va_results_aggreges[17]) ? $va_results_aggreges[17] : '';

    // Exception unique : l'indice 16 (${LISTE_OBJETS}) est assemblé par le contrôleur et
    // porte DÉJÀ le balisage OOXML de ses sauts de ligne. L'échapper ici afficherait ce
    // balisage en clair ; ses valeurs sont donc échappées à la source, dans
    // GenererController::Modele4b().
    foreach ($va_results_aggreges as $vn_k_0103 => $vs_v_0103) {
        if ((int)$vn_k_0103 === 16) { continue; }
        $va_results_aggreges[$vn_k_0103] = inrap_0103_valeur_word($vs_v_0103);
    }
    // === D2026-0103 §4 : fin ===

	$templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor(__CA_APP_DIR__."/plugins/etatsInrap/templates/bordereau-mouvement.docx");
    $templateProcessor->setValue('AAAA', date("Y"));
    $templateProcessor->setValue('INRAP', $va_results_aggreges[0]);
    $templateProcessor->setValue('OA', $va_results_aggreges[1]);
    $templateProcessor->setValue('REGION', ($va_results_aggreges[2] ? $va_results_aggreges[2] : ""));
    $templateProcessor->setValue('COMMUNE', $va_results_aggreges[3]);
    $templateProcessor->setValue('LIEUDIT', $va_results_aggreges[4]);
    $templateProcessor->setValue('RO', $va_results_aggreges[5]);
    $templateProcessor->setValue('MOTIF', $va_results_aggreges[6]);
    $templateProcessor->setValue('PRECISIONS', $va_results_aggreges[7]);
    $templateProcessor->setValue('DEPART', $va_results_aggreges[8]);
    $templateProcessor->setValue('RETOUR_PREVU', $va_results_aggreges[9]);
    $templateProcessor->setValue('RESP_MOUV', $va_results_aggreges[10]);
    $templateProcessor->setValue('TRANSPORT', $va_results_aggreges[11]);
    $templateProcessor->setValue('DESTINATAIRE', $va_results_aggreges[12]);
    $templateProcessor->setValue('ORGANISME', $va_results_aggreges[13]);
    // === D2026-0103 §4 — retours à la ligne des listes de contenants : début ===
    // Valeur déjà traitée par la boucle ci-dessus (sauts compris) : la reprendre ici
    // l'échapperait une seconde fois — c'est ce qui imprimait « -&gt; » en toutes lettres
    // dans les observations du mouvement 3055.
	$templateProcessor->setValue('OBS', $va_results_aggreges[14]);
    // === D2026-0103 §4 : fin ===
    $templateProcessor->setValue('DESCRIPTION', $va_results_aggreges[15]);   
  /*  $va_results_aggreges[16] = explode("<br/>", $va_results_aggreges[16]);
    $va_results_aggreges[16] = array_map(function($e){
        return strip_tags($e);
    }, $va_results_aggreges[16]);
    $va_results_aggreges[16] = implode("sautdeligne", $va_results_aggreges[16]);
    $va_results_aggreges[16] = str_replace("sautdeligne","<w:br/>\n - ","<w:br/>\n - ".$va_results_aggreges[16]);*/
    $templateProcessor->setValue('LISTE_OBJETS', $va_results_aggreges[16]);    
    $templateProcessor->setValue('NOW', date("d/m/Y"));
    $templateProcessor->setValue('MVMT_ID', $va_results_aggreges[17]);
    $templateProcessor->setValue('NUM_DEVIS', $va_results_aggreges[18]);
    $templateProcessor->setValue('DATE_DEVIS', $va_results_aggreges[19]);
    $templateProcessor->setValue('DEPART_PLACE', $va_results_aggreges[20]);
    $templateProcessor->setValue('TEL', $va_results_aggreges[21]);
    $templateProcessor->setValue('ADRESSE_DEST', $va_results_aggreges[22]);
    $templateProcessor->setValue('ADRESSE_RES', $va_results_aggreges[23]);
    // === D2026-0103 §4 — retours à la ligne des listes de contenants : début ===
    $templateProcessor->setValue('OPERATION_LINKED', $va_results_aggreges[24]);
    // === D2026-0103 §4 : fin ===
    $templateProcessor->setValue('DEMANDE_ORDRE',  $va_results_aggreges[25]);

    //$templateProcessor->setValue('NOTES', "-");

    // === D2026-0103 §4 — nom non ambigu et diffusion du document produit : début ===
    // KO-3, même schéma que le bordereau de versement : nom horodaté à la seconde dans un
    // répertoire public. Le nom porte désormais l'idno du mouvement, le fichier de travail
    // est écrit hors de la racine web, remis dans la réponse puis effacé.
    $va_doc_0103 = inrap_0103_prepare_document('bordereau-mouvement', $vs_ref_mvt_0103,
                                               __CA_APP_DIR__.'/plugins/etatsInrap/tmp');
    $templateProcessor->saveAs($va_doc_0103['chemin']);
    if (inrap_0103_servir_document($va_doc_0103, '/app/plugins/etatsInrap/tmp')) { exit; }
    print "Le bordereau de mouvement n'a pas pu être remis : document introuvable après génération.";
    // === D2026-0103 §4 : fin ===
//die();
// Your browser will name the file "myFile.docx"
// regardless of what it's named on the server
/*header('Content-Type: application/octet-stream');
header("Content-Disposition: attachment; filename=\"bordereau-mouvement.docx\"");
readfile(__CA_APP_DIR__.'/tmp/bordereau-mouvement'.$time.'.docx'); // or echo file_get_contents($temp_file);
unlink(__CA_APP_DIR__.'/tmp/bordereau-mouvement'.$time.'.docx');  // remove temp file*/

    return;
?>
