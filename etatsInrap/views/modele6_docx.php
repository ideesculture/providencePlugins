<?php
    // 04/09/2026 GM : le chemin était figé sur /var/www/comodo2024, répertoire disparu à la
    // migration du 26/08 — d'où la fatale « Failed opening required PhpWord/Settings.php » sur
    // les conditions de prêt et le dossier d'exposition (signalée le 31/08, expo 2023-613003).
    //
    // 07/09/2026 GM : ne pas le déduire de __DIR__. Depuis le passage du plugin en dépôt
    // providencePlugins déployé par lien symbolique (cf. .claude/CLAUDE.md §3), __DIR__ pointe
    // vers le dépôt et non vers l'instance : dirname(__DIR__, 3) rendait la racine du dépôt.
    // En rendu normal la constante est déjà posée par setup.php et ce repli ne sert jamais ;
    // on le garde correct pour une exécution isolée, en remontant vers la racine de Providence
    // (modèle de MeilisearchAppPlugin/tools/, CA_RACINE force le chemin).
    if (!defined("__CA_APP_DIR__")) {
        $racine = getenv('CA_RACINE') ?: null;
        if (!$racine) {
            $candidat = getcwd();
            for ($i = 0; $i < 6 && $candidat && $candidat !== '/'; $i++) {
                if (file_exists($candidat . '/setup.php') && is_dir($candidat . '/app/lib')) { $racine = $candidat; break; }
                $candidat = dirname($candidat);
            }
        }
        if (!$racine || !file_exists($racine . '/setup.php')) {
            throw new Exception('Racine de Providence introuvable ; se placer dedans ou poser CA_RACINE.');
        }
        require_once($racine . '/setup.php');
    }
    //require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/bootstrap.php";
    
    require_once __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Settings.php";
    require_once __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Media.php";
    require_once __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Style.php";
    require_once __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/TemplateProcessor.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Shared/ZipArchive.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Shared/Text.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Element/AbstractElement.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Element/Table.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Shared/AbstractEnum.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/SimpleType/TblWidth.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Style/AbstractStyle.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Style/Border.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Style/Font.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Style/Paragraph.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Element/Text.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Element/TextBreak.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Element/AbstractContainer.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Style/Cell.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Element/Cell.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Style/Row.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Element/Row.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Style/Table.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Shared/XMLWriter.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Element/Image.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Style/Frame.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Style/Image.php";

    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Exception/Exception.php";



    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Writer/Word2007/Style/AbstractStyle.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Writer/Word2007/Element/AbstractElement.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Writer/Word2007/Style/TablePosition.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Writer/Word2007/Style/MarginBorder.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Writer/Word2007/Style/Row.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Writer/Word2007/Style/Font.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Writer/Word2007/Style/Cell.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Writer/Word2007/Element/Container.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Writer/Word2007/Style/Table.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Writer/Word2007/Element/ParagraphAlignment.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Writer/Word2007/Style/Paragraph.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Writer/Word2007/Element/Table.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Writer/Word2007/Element/Text.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/Writer/Word2007/Element/TextBreak.php";

    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/SimpleType/Jc.php";
    require __CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/SimpleType/VerticalJc.php";


    // foreach (glob(__CA_APP_DIR__."/plugins/etatsInrap/lib/PHPWord/src/PhpWord/*.php") as $filename)
    // {
    //     echo $filename.'<br/>';$i++;require $filename;
    // }
   
    use \PhpOffice\PhpWord\TemplateProcessor;
    use \PhpOffice\PhpWord\Element\Table;
    use \PhpOffice\PhpWord\SimpleType\TblWidth;

    $timestamp = filter_var($_GET["date"], FILTER_SANITIZE_STRING);
    error_reporting(E_ALL);

    $va_results = json_decode(file_get_contents("../tmp/".$timestamp.".json"));
    
    unlink("../tmp/".$timestamp.".json");
    $va_result = $va_results[1];
    


	$templateProcessor = new TemplateProcessor(__CA_APP_DIR__."/plugins/etatsInrap/templates/conditions_generales_de_pret.docx");
	
    $templateProcessor->setValue('AAAA', date("Y"));
	$templateProcessor->setValue('TITRE', ($va_result[0] ? $va_result[0] : " "));
    $templateProcessor->setValue('LIEU', ($va_result[1] ? $va_result[1] : " "));
    $templateProcessor->setValue('DU', ($va_result[2] ? $va_result[2] : " "));
    $templateProcessor->setValue('AU', ($va_result[3] ? $va_result[3] : " "));
    $templateProcessor->setValue('EMPRUNTEUR', ($va_result[4] ? $va_result[4] : " "));
    $templateProcessor->setValue('EMPRUNTEUR', ($va_result[4] ? $va_result[4] : " "));

    $templateProcessor->setValue("SIGNA_EMPRUNTEUR", ($va_result[5] ? $va_result[5] : " "));
    $templateProcessor->setValue('COMMISSAIRE', ($va_result[7] ? $va_result[7] : " "));
    $templateProcessor->setValue('SIGNA_INRAP',($va_result[6] ? $va_result[6] : " "));
    $templateProcessor->setValue('QUALITE_INRAP',($va_result[8] ? $va_result[8] : " "));
    $templateProcessor->setValue('QUALITE_EMP',($va_result[9] ? $va_result[9] : " "));

    //$templateProcessor->setValue('pageBreakHere', '<w:p><w:r><w:br w:type="page"/></w:r></w:p>');

   /* $cellRowSpan = array('vMerge' => 'restart', 'valign' => 'center');
    $cellRowContinue = array('vMerge' => 'continue');
    $cellColSpan = array('gridSpan' => 2, 'valign' => 'center');
    $cellHCentered = array('align' => 'left');
    $cellHCentered2 = array('align' => 'center');
    $cellVCentered = array('valign' => 'center');
    $fs = array("size" => 10);
    $fsi = array("size" => 10, "italic" => true);
    $table = new Table(array('width' => 60000, 'unit' => TblWidth::TWIP));
    //Début
    $i=0;
    foreach ($va_result[10] as $table_val){
	    if (!empty($table_val[13]))
	    {
		    $text = '${image_identifier'.$i.'}';
		    $i++;
		   
	    }else{$text = "";}
        $table->addRow();
        $table->addCell(10000, $cellRowSpan)->addText($text, null ,array("align" => "center", "valign" =>"center"));
        $table->addCell(10000)->addText('Identification : '.$table_val[0], $fs, $cellHCentered);
        $table->addRow();
        $table->addCell(null, $cellRowContinue);
        $table->addCell(10000)->addText('Période chronologique : '.$table_val[1], $fs, $cellHCentered);
        $table->addRow();
        $table->addCell(null, $cellRowContinue);
        $table->addCell(10000)->addText('Matière(s) : '.$table_val[2], $fs, $cellHCentered);
        $table->addRow();
        $table->addCell(null, $cellRowContinue);
        $table->addCell(10000)->addText('Dimension(s) : '.$table_val[3], $fs, $cellHCentered);
        $table->addRow();
        $table->addCell(null, $cellRowContinue);
        $table->addCell(10000)->addText('Quantification : '.$table_val[4], $fs, $cellHCentered);
        $table->addRow();
        $table->addCell(null, $cellRowContinue);
        $table->addCell(10000)->addText('Nb de reste : '.$table_val[5], $fs, $cellHCentered);
        $table->addRow();
        $table->addCell(null, $cellRowContinue);
        $table->addCell(10000)->addText('Description : '.strip_tags($table_val[6]), $fs, $cellHCentered);
        $table->addRow();
        $table->addCell(null, $cellRowContinue);
        $table->addCell(10000)->addText("Valeur d'assurance : ".$table_val[7], $fs, $cellHCentered);
        $table->addRow();
        $table->addCell(null, $cellRowContinue);
        $table->addCell(10000)->addText('Provenance : '.$table_val[8], $fs, $cellHCentered);
        $table->addRow();
        $table->addCell(null, $cellRowContinue);
        $table->addCell(10000)->addText('Code SGA Inrap : '.$table_val[9], $fs, $cellHCentered);
        $table->addRow();
        $table->addCell(null, $cellRowContinue);
        $table->addCell(10000)->addText('Code interne : '.$table_val[10], $fs, $cellHCentered);
        $table->addRow();
        $table->addCell(null, $cellRowContinue);
        $table->addCell(10000)->addText("N° d'inventaire : ".$table_val[11], $fs, $cellHCentered);
        $table->addRow();
        $table->addCell(null, $cellRowContinue);
        $table->addCell(10000)->addText("N° d'isolation : ".$table_val[12], $fs, $cellHCentered);
    }
    
    
    $templateProcessor->setComplexBlock('TABLE', $table);    

    $j = 0;
    foreach ($va_result[10] as $table_val){

	    if (empty($table_val[13])){continue;}
        $templateProcessor->setImageValue('image_identifier'.$j, array("path"=>$table_val[13], "width" => 250, "height"=>500));
        $j++;
    }*/
    $now = time();
    $templateProcessor->saveAs(__CA_APP_DIR__.'/tmp/conditions_generales_de_pret_'.$now.'.docx');
    //die();
    // Your browser will name the file "myFile.docx"
    // regardless of what it's named on the server

    header('Location: /app/tmp/conditions_generales_de_pret_'.$now.'.docx');
  //  unlink(__CA_APP_DIR__.'/tmp/conditions_generales_de_pret1'.$now.'.docx');  // remove temp file

   /* header('Content-Type: application/octet-stream');
    header("Content-Disposition: attachment; filename=\"conditions_generales_de_pret.docx\"");
    readfile(__CA_APP_DIR__.'/tmp/conditions_generales_de_pret1.docx'); // or echo file_get_contents($temp_file);
*/
    return;
