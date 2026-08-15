<?php
$book_id = $this->getVar("book");
$file = $this->getVar("file");
$filename = $this->getVar("filename");

header('Content-type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header("Content-Length: " . filesize($file));
readfile($file);
die();
?>