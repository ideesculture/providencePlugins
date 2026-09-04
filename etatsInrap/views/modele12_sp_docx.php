<?php
	$idnoCourrier = $this->getVar("idnoCourrier");
    $infoCols = $this->getVar("InfosCols");
    $partie = $this->getVar("partie");

    // === D2026-0103 §4 — retours à la ligne des listes de contenants : début ===
    // Même défaut de rendu que celui corrigé sur le bordereau de versement (modele4_docx.php) :
    // injecté tel quel, « <w:br/> » atterrit à l'intérieur du <w:t> qui portait le placeholder,
    // position où ni Word ni LibreOffice ne le prennent en compte — la liste des contenants
    // (${DETAIL_COL}) s'imprime en un pavé continu. Le saut doit fermer l'élément de texte
    // courant puis en rouvrir un. Procédé identique à GenererController::enSuspensClean().
    // L'échappement XML est déjà fait ci-dessous : la fonction ne pose que le saut de ligne.
    $sautDeLigneWord = function($ps_val){
        $vs = str_replace(',sautdeligne', 'sautdeligne', (string)$ps_val);
        return str_replace('sautdeligne', '</w:t><w:br/><w:t xml:space="preserve">', $vs);
    };
    // === D2026-0103 §4 : fin ===

    foreach ($infoCols as $key => $value) {
        $infoCols[$key] = strip_tags($value);
        // 25/03/26 : échapper les caractères XML spéciaux (&, <, >) pour éviter
        // la corruption du docx et le crash de pagemerger lors de la fusion
        $infoCols[$key] = htmlspecialchars($infoCols[$key], ENT_XML1, 'UTF-8');
        // === D2026-0103 §4 — retours à la ligne des listes de contenants : début ===
        $infoCols[$key] = $sautDeLigneWord($infoCols[$key]);
        // === D2026-0103 §4 : fin ===
    }

    //var_dump($va_result );die();

    use PhpOffice\PhpWord\IOFactory;

	//require_once(__CA_BASE_DIR__."/vendor/phpoffice/phpword/bootstrap.php");

//error_reporting(E_ALL);
	$templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor(__CA_APP_DIR__."/plugins/etatsInrap/templates/lettre_versement_sous_partie.docx");

    $templateProcessor->setValue('IDNO_LETTRE',$idnoCourrier);
    $templateProcessor->setValue('NOM_OP',$infoCols[0]);
    $templateProcessor->setValue('TYPE_OP',$infoCols[1]);
    $templateProcessor->setValue('CODE_INRAP',$infoCols[2]);
    $templateProcessor->setValue('CODE_OA',$infoCols[3]);
    $templateProcessor->setValue('ANNEE_OP', $infoCols[4]);
    $templateProcessor->setValue('AGENT_PRESCRI', $infoCols[5]);
    $templateProcessor->setValue('NUM_PRESCRI',$infoCols[6]);
    $templateProcessor->setValue('DATE_PRESCRI',$infoCols[7]);
    $templateProcessor->setValue('DATE_RAPPORT',$infoCols[8]);
    $templateProcessor->setValue('RO',$infoCols[9]);
    $templateProcessor->setValue('NUM_DESI',$infoCols[10]);
    $templateProcessor->setValue('DATE_DESI', $infoCols[11]);
    $templateProcessor->setValue('PRES_INV_BAM', '');
    $templateProcessor->setValue('PRES_INV_DOC', $infoCols[15]);
    $templateProcessor->setValue('BA_CONT', $infoCols[12]);
    $templateProcessor->setValue('DOC_CONT', $infoCols[16]);
    $templateProcessor->setValue('BA_HCONT', $infoCols[13]);
    $templateProcessor->setValue('DOC_HCONT', $infoCols[17]);
    $templateProcessor->setValue('BA_VOL', $infoCols[14]);
    $templateProcessor->setValue('DOC_VOL', $infoCols[18]);

    $templateProcessor->setValue('DETAIL_COL',$infoCols[19]);

    
    $templateProcessor->saveAs(__CA_APP_DIR__.'/plugins/etatsInrap/tmp/'.$idnoCourrier.'/lettre_versement_'.$partie.'.docx');
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
