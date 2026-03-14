<?php
define("__PLUGIN_URL__", __CA_URL_ROOT__."/index.php/etatsMTE");
error_reporting(E_ERROR);
?>

<h1>Etats MTE - Mobiliers Classés</h1>

<div class="etatsMTE container" style="padding:20px;">
	<div style="display:flex;gap:20px;flex-wrap:wrap;">
		<a href="<?= __PLUGIN_URL__ ?>/Catalogue/Index" style="display:block;background-color:#1ab3c8;color:white;padding:20px 30px;border-radius:8px;text-decoration:none;font-weight:bold;font-size:16px;text-align:center;min-width:200px;">
			Catalogues MTE
			<div style="font-size:12px;font-weight:normal;margin-top:5px;">Standards et spécifiques</div>
		</a>
	</div>
	<p style="margin-top:20px;color:#666;">Les constats d'état sont également accessibles depuis la fiche de chaque objet, via le bouton "Constat d'état" dans la barre latérale.</p>
</div>
