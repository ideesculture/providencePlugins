<?php
	$title = $this->getVar('ark_title');
	$message = $this->getVar('ark_message');
	$ark = $this->getVar('ark_requested');
?>
<div class="row">
	<div class="col-sm-12">
		<h1><?php print htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
		<p><?php print htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
<?php if (strlen($ark)) { ?>
		<p><small><?php print htmlspecialchars(_t('Identifiant demandé'), ENT_QUOTES, 'UTF-8'); ?> : <code><?php print htmlspecialchars($ark, ENT_QUOTES, 'UTF-8'); ?></code></small></p>
<?php } ?>
		<p><?php print caNavLink($this->request, _t("Retour à l'accueil"), 'btn btn-default', '', '', ''); ?></p>
	</div>
</div>
