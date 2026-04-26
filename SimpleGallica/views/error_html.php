<?php
/** @var View $this */
$message = $this->getVar('error');
?>
<h1>Import Gallica</h1>

<div style="background:#f2dede;border-left:4px solid #a94442;padding:12px 16px;border-radius:4px;margin-bottom:20px;">
	<strong>Erreur :</strong> <?php echo htmlspecialchars($message); ?>
</div>

<p>
	<a href="<?php print __CA_URL_ROOT__ . '/index.php/SimpleGallica/SimpleGallica/Index'; ?>">
		← Retour à la recherche Gallica
	</a>
</p>
