<?php
$va_files        = $this->getVar('files');
$previews        = $this->getVar('previews');
$raws            = $this->getVar('raws');
$titles          = $this->getVar('titles');
$nb_results      = $this->getVar('nb_results');
$import_disabled = (bool)$this->getVar('import_disabled');

print "<h1>".$nb_results." résultat".($nb_results>1 ? "s" : "")."</h1>";
?>

<?php if ($import_disabled): ?>
<div style="background:#fcf8e3;border-left:4px solid #8a6d3b;padding:10px 14px;border-radius:4px;margin-bottom:16px;font-size:13px;">
	<strong>Mode recherche seule.</strong> L'import Z39.50 est désactivé sur cette instance (mapping <code>z3950_import_marc</code> non chargé). Les résultats sont affichés à titre informatif ; pour importer une notice, utiliser temporairement les plugins <strong>Gallica</strong> ou <strong>SUDOC</strong>.
</div>
<?php endif; ?>

<form action="<?php print __CA_URL_ROOT__."/index.php/SimpleZ3950/SimpleZ3950/Import"; ?>" method="post">
	<h2>Liste des résultats</h2>
	<input type="hidden" name="nb_results" value="<?php print $nb_results;?>" /><br/>
	<?php foreach($va_files as $key=>$file): ?>
	<div style="clear:both;">
		<input type="checkbox" name="file_<?php print $key; ?>" value="<?php print $file; ?>" <?php if ($import_disabled): ?>disabled<?php endif; ?>> <?php print $titles[$key]; ?><br/><small><?php print basename($file); ?></small><br/>
		<a onClick="jQuery('#preview_<?php print $key; ?>').slideToggle();" style="color:gray;font-size:9px;cursor:pointer;">Afficher un aperçu</a>
	</div>
	<pre id='preview_<?php print $key; ?>' style="display:none;font-size:9px;border:1px solid gray;background:darkgray;color:white;padding:12px;"><?php print $previews[$key];?>
	</pre>
	<?php endforeach; ?>
	<?php if (!$import_disabled): ?>
	<div style="clear:both;margin-top:20px;">
	<button type="submit">Importer</button>
	</div>
	<?php endif; ?>
</form>

<div style="height:120px;"></div>
