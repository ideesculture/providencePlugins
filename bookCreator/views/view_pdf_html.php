<?php
$file = __CA_APP_DIR__.'/plugins/bookCreator/tmp/book.pdf';
//var_dump($file );die();
$filename = 'book.pdf';
header('Content-type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header("Content-Length: " . filesize($file));
readfile($file);
die();
?>