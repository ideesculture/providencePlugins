<?php
$va_var 	= $this->getVar('var');
$va_servers = $this->getVar('servers');
?>

<h1>Import Z3950</h1>

<form action="<?php print __CA_URL_ROOT__."/index.php/SimpleZ3950/SimpleZ3950/Search"; ?>" method="post">
	<h2>Serveur</h2>
	<?php 
		$i=1;
		foreach($va_servers as $key=>$server): 
			print "<input type=\"radio\" class=\"serveur\" id=\"serveur$i\" name=\"serveur\" ".($i==1 ? "checked=\"checked\"" : "")." value=\"$key\" data-searchtarget=\"".$server["target"]."\" /> ".$server["label"]." ";
			$i++;
		endforeach; ?>

<!--	<h2>Action si déjà présent</h2>
	<input type="radio" id="serveur" name="action" checked="checked" value="skip_on_idno"/> Ignorer
	<input type="radio" id="serveur" name="action" value="merge_on_idno"/> Fusionner (ajouter les valeurs)
	<input type="radio" id="serveur" name="action" value="merge_on_idno_with_rewrite"/> Fusionner (remplacer les valeurs)
	<input type="radio" id="serveur" name="action" value="overwrite_on_idno"> Ecraser-->
	<h2>Recherche</h2>
	<p>La recherche porte sur <b><span id='searchtarget'>...</span></b></p>
	<input type="text" style="width:100%;" name="search">
	<button type="submit">Chercher</button>
</form>

<script>
	jQuery('#searchtarget').html($('#serveur1').data("searchtarget"));
	jQuery(".serveur").on("click", function() {
		jQuery('#searchtarget').html($(this).data("searchtarget"));		
	});
</script>