<?php
	$va_results = $this->getVar("results");

	$time = time();
	error_reporting(E_ALL);
	ini_set("sys_temp_dir", __CA_APP_DIR__."/tmp");

	$vs_template_path = __CA_APP_DIR__."/plugins/etatsMTE/templates/constat-detat.docx";
	if (!file_exists($vs_template_path)) {
		print "Erreur : le modèle DOCX constat-detat.docx est introuvable.";
		return;
	}

	$templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor($vs_template_path);
	$templateProcessor->setValue('IDNO', $va_results[0]);
	$templateProcessor->setValue('TITRE', $va_results[1]);
	$templateProcessor->setValue('AUTEUR', $va_results[2]);
	$templateProcessor->setValue('DATE', $va_results[3]);
	$templateProcessor->setValue('MATIERE', $va_results[4]);
	$templateProcessor->setValue('DIM', $va_results[5]);
	$templateProcessor->setValue('POIDS', $va_results[6]);
	$templateProcessor->setValue('LOCALISATION', $va_results[7]);
	$templateProcessor->setValue('DEPOSANT', $va_results[8]);

	$vs_output_path = __CA_APP_DIR__.'/tmp/constat_detat_'.$time.'.docx';
	$templateProcessor->saveAs($vs_output_path);

	header('Content-Type: application/octet-stream');
	header("Content-Disposition: attachment; filename=\"constat_detat.docx\"");
	readfile($vs_output_path);
	unlink($vs_output_path);

	return;
?>
