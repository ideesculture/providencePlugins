<?php
$dir = __CA_APP_DIR__.'/plugins/exportInrap/tmp/';
//var_dump($file );die();
$file = $this->getVar("file");
$filename = $this->getVar("filename");

header('Content-type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header("Content-Length: " . filesize($file));
readfile($file);
die();
?>