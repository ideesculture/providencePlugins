<?php
$va_var 	= $this->getVar('var');
?>

<h1>Import par lot Z3950</h1>
<form action="#" method="post">
	<h2>Serveur</h2>
	<input type="radio" id="serveur" name="serveur" checked="checked"/> BnF
	<input type="radio" id="serveur" name="serveur" /> SUDOC
	<input type="radio" id="serveur" name="serveur" /> KBR
	<h2>Action si déjà présent</h2>
	<input type="radio" id="serveur" name="action" checked="checked" value="skip_on_idno"/> Ignorer
	<input type="radio" id="serveur" name="action" value="merge_on_idno"/> Fusionner (ajouter les valeurs)
	<input type="radio" id="serveur" name="action" value="merge_on_idno_with_rewrite"/> Fusionner (remplacer les valeurs)
	<input type="radio" id="serveur" name="action" value="overwrite_on_idno"> Ecraser
	<h2>Recherche</h2>
	<textarea style="width:100%;height:300px;" name="search"></textarea>
	<button type="submit">Chercher</button>
</form>
