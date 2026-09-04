<?php
	$vs_etat = $this->getVar("etat");
	$va_infos = $this->getVar("InfosCourrier");
    $infoCols = $this->getVar("infoCols");
	$vs_titre = $this->getVar("titre");

    // === D2026-0103 §4 — retours à la ligne des listes de contenants : début ===
    // Même défaut de rendu que celui corrigé sur le bordereau de versement (modele4_docx.php) :
    // injecté tel quel, « <w:br/> » atterrit à l'intérieur du <w:t> qui portait le placeholder,
    // position où ni Word ni LibreOffice ne le prennent en compte — adresses et listes de
    // contenants s'impriment sur une seule ligne. Le saut doit fermer l'élément de texte courant
    // puis en rouvrir un. Procédé identique à GenererController::enSuspensClean().
    // Ici l'échappement XML est déjà fait en amont (boucles ci-dessous) : la fonction ne pose
    // que le saut de ligne, et la règle « ,sautdeligne » propre aux adresses.
    $sautDeLigneWord = function($ps_val){
        $vs = str_replace(',sautdeligne', 'sautdeligne', (string)$ps_val);
        return str_replace('sautdeligne', '</w:t><w:br/><w:t xml:space="preserve">', $vs);
    };
    // === D2026-0103 §4 : fin ===

    foreach ($va_infos as $key => $value) {
        $va_infos[$key] = strip_tags($value);
        // 25/03/26 : échapper les caractères XML spéciaux
        $va_infos[$key] = htmlspecialchars($va_infos[$key], ENT_XML1, 'UTF-8');
    }

    foreach ($infoCols as $key => $value) {
        $infoCols[$key] = strip_tags($value);
        // 25/03/26 : échapper les caractères XML spéciaux
        $infoCols[$key] = htmlspecialchars($infoCols[$key], ENT_XML1, 'UTF-8');
        // === D2026-0103 §4 — retours à la ligne des listes de contenants : début ===
        $infoCols[$key] = $sautDeLigneWord($infoCols[$key]);
        // === D2026-0103 §4 : fin ===
    }

    //var_dump($va_result );die();

    use PhpOffice\PhpWord\IOFactory;

	//require_once(__CA_BASE_DIR__."/vendor/phpoffice/phpword/bootstrap.php");

//error_reporting(E_ALL);
	$templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor(__CA_APP_DIR__."/plugins/etatsInrap/templates/lettre_versement.docx");

    $templateProcessor->setValue('IDNO_LETTRE',$va_infos['idno']);
    $templateProcessor->setValue('NOM_GESTIONNAIRE',$va_infos["nom_gestionnaire"]);
    $templateProcessor->setValue('NUM_GESTIONNAIRE',$va_infos["tel_gestionnaire"]);
    $templateProcessor->setValue('EMAIL_GESTIONNAIRE',$va_infos["mail_gestionnaire"]);
    $templateProcessor->setValue('NOM_SRA', str_replace("_", " ",$va_infos["sra_nom"]));
    // === D2026-0103 §4 — retours à la ligne des listes de contenants : début ===
    $templateProcessor->setValue('ADRESSE_SRA', $sautDeLigneWord($va_infos["sra_adresse"]));
    // === D2026-0103 §4 : fin ===
    $templateProcessor->setValue('DATE_LETTRE',$va_infos["date"]);
    $templateProcessor->setValue('LIEU_LETTRE',$va_infos["lieu"]);
    $templateProcessor->setValue('DAST_NOM',$va_infos["dast_nom"]);
    $templateProcessor->setValue('LIEU_ENTREE',$va_infos["lieu_entree"]);
    $templateProcessor->setValue('DIR_NOM',$va_infos["dir_nom"]);
    // === D2026-0103 §4 — retours à la ligne des listes de contenants : début ===
    $templateProcessor->setValue('DIR_ADRESSE', $sautDeLigneWord($va_infos["dir_adresse"]));
    // === D2026-0103 §4 : fin ===
    $templateProcessor->setValue('SIGNATAIRE_NOM',$va_infos["signataire"]);
    $templateProcessor->setValue('QUALITE_SIGNATAIRE',$va_infos["qualite_signataire"]);

    if (!is_dir(__CA_APP_DIR__.'p/lugins/etatsInrap/tmp/'.$va_infos['idno'])) {
        mkdir(__CA_APP_DIR__.'/plugins/etatsInrap/tmp/'.$va_infos['idno'], 0777, true);
    }
    
    $templateProcessor->saveAs(__CA_APP_DIR__.'/plugins/etatsInrap/tmp/'.$va_infos['idno'].'/lettre_versement_base.docx');
    //header("Location: /app/tmp/lettre_versement_".$now.".docx");

    /*$phpWord = IOFactory::load(__CA_APP_DIR__.'/tmp/lettre_vierge.docx');
    $phpWord->save($pdfTempFile,'PDF');

    $vt_occ = new ca_occurrences($this->getVar("occurrence_id"));
    $vt_occ->addRepresentation($pdfTempFile, 143, 2, 1, 1, true, ["name" => "lettre_vierge.pdf"], ["original_filename" => "lettre_vierge.pdf"]);
    $vt_occ->update();*/

    

//die();
// Your browser will name the file "myFile.docx"
// regardless of what it's named on the server
/*header('Content-Type: application/octet-stream');
header("Content-Disposition: attachment; filename=\"lettre_versement.docx\"");
readfile(__CA_APP_DIR__.'/tmp/lettre_versement.docx'); // or echo file_get_contents($temp_file);
unlink(__CA_APP_DIR__.'/tmp/lettre_versement.docx');  // remove temp file*/

    return;
?>
