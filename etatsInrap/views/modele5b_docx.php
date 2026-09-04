<?php
	$vs_etat = $this->getVar("etat");
	$va_results = $this->getVar("results");
	$vs_titre = $this->getVar("titre");
    $va_result = $va_results[1];
    //var_dump($va_result );die();
	//require_once(__CA_BASE_DIR__."/vendor/phpoffice/phpword/bootstrap.php");

//error_reporting(E_ALL);
	$templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor(__CA_APP_DIR__."/plugins/etatsInrap/templates/constat_etat_base.docx");
    $templateProcessor->setValue('AAAA', date("Y"));
    $templateProcessor->setValue('INV', $va_result[0]);
    $templateProcessor->setValue('UE', $va_result[1]);
    $templateProcessor->setValue('CATEG', $va_result[2]);
    $templateProcessor->setValue('MATERIAUX', $va_result[3]);
    $templateProcessor->setValue('DESCRIPTION', $va_result[4]);
    $templateProcessor->setValue('DATE', $va_result[5]);
    $templateProcessor->setValue('ASS', $va_result[6]);
    $templateProcessor->setValue('ETAT', $va_result[7]);
    $templateProcessor->setValue('TRAITEMENT', $va_result[8]);
    $templateProcessor->setValue('FRAGMENTS', $va_result[9]);
    $templateProcessor->setValue('DIMENSIONS', $va_result[10]);
    $templateProcessor->setValue('EXPO', $va_result[11]);
    $templateProcessor->setValue('DEBUT_EXPO', $va_result[12]);
    $templateProcessor->setValue('FIN_EXPO', $va_result[13]);
    $templateProcessor->setValue('CONTACT', $va_result[14]);
    $templateProcessor->setValue('NUM_EXPO', $va_result[15]);
    $templateProcessor->setValue('OA', $va_result[16]);
    $templateProcessor->setValue('INRAP', $va_result[17]);
    $templateProcessor->setValue('REGION', $va_result[18]);
    $templateProcessor->setValue('DEP', $va_result[19]);
    $templateProcessor->setValue('COMMUNE', $va_result[20]);
    $templateProcessor->setValue('LIEUDIT', $va_result[21]);
    $templateProcessor->setValue('ANNEE_INTER', $va_result[22]);
    $templateProcessor->setValue('RO', $va_result[23]);
    $now = time();

    $templateProcessor->saveAs(__CA_APP_DIR__.'/tmp/constat_etat_base1_'.$now.'.docx');
//die();
// Your browser will name the file "myFile.docx"
// regardless of what it's named on the server

header("Location: /app/plugins/etatsInrap/tmp/constat_etat_base1_".$now.".docx");

/*header('Content-Type: application/octet-stream');
header("Content-Disposition: attachment; filename=\"constat_etat_base1.docx\"");
readfile(__CA_APP_DIR__.'/tmp/constat_etat_base1.docx'); // or echo file_get_contents($temp_file);
unlink(__CA_APP_DIR__.'/tmp/constat_etat_base1.docx');  // remove temp file*/

    return;
?>
