<?php
$message = $this->getVar("message");
?>
<h1><?php print _t('searchIdno'); ?></h1>
<p><b><?php print _t('Error'); ?> :</b> <?php print htmlspecialchars((string) $message); ?></p>
