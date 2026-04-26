<?php
$va_files = $this->getVar('files');
//var_dump($va_files);die();
$previews = $this->getVar('previews');
$raws = $this->getVar('raws');
$titles = $this->getVar('titles');
$nb_results = $this->getVar('nb_results');

print "<h1>".$nb_results." résultat".($nb_results>1 ? "s" : "")."</h1>";
?>

<form action="<?php print __CA_URL_ROOT__."/index.php/SimpleZ3950/SimpleZ3950/Import"; ?>" method="post">
	<h2>Liste des résultats</h2>
	<input type="hidden" name="nb_results" value="<?php print $nb_results;?>" /><br/>
	<?php foreach($va_files as $key=>$file): ?>
	<div style="clear:both;">
		<input type="checkbox" name="file_<?php print $key; ?>" value="<?php print $file; ?>"> <?php print $titles[$key]; ?><br/><small><?php print basename($file); ?></small><br/>
		<a onClick="jQuery('#preview_<?php print $key; ?>').slideToggle();" style="color:gray;font-size:9px;cursor:pointer;">Afficher un aperçu</a>
	</div>
	<pre id='preview_<?php print $key; ?>' style="display:none;font-size:9px;border:1px solid gray;background:darkgray;color:white;padding:12px;"><?php print $previews[$key];?>
	</pre>
	<?php endforeach; ?>
	<div style="clear:both;margin-top:20px;">
	<button type="submit">Importer</button>
	</div>
</form>

<div style="height:120px;"></div>
