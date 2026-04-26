<?php
/** @var View $this */
$max_results = (int)$this->getVar('max_results');
?>
<h1>Import Gallica (BnF)</h1>

<style>
#gallica-mode-tabs { margin-bottom: 16px; }
#gallica-mode-tabs label { margin-right: 20px; font-weight: bold; cursor: pointer; }
#gallica-search-help { color: #666; font-size: 13px; margin: 6px 0 12px; }
#gallica-search-input { width: 100%; box-sizing: border-box; padding: 6px; font-size: 14px; }
#gallica-submit { margin-top: 10px; }
</style>

<form action="<?php print __CA_URL_ROOT__ . '/index.php/SimpleGallica/SimpleGallica/Search'; ?>" method="post">

	<div id="gallica-mode-tabs">
		<label>
			<input type="radio" name="mode" value="ark" id="mode_ark" checked="checked" />
			Par ARK
		</label>
		<label>
			<input type="radio" name="mode" value="text" id="mode_text" />
			Recherche textuelle (SRU)
		</label>
	</div>

	<div id="gallica-search-help">
		Saisissez un ou plusieurs ARK Gallica (séparés par des virgules, espaces ou sauts de ligne).<br/>
		Format accepté : <code>ark:/12148/bpt6kxxxxxx</code> ou URL <code>https://gallica.bnf.fr/ark:/12148/bpt6kxxxxxx</code>
	</div>

	<textarea id="gallica-search-input" name="search" rows="3"
		placeholder="ARK Gallica…"></textarea>

	<br/>
	<button id="gallica-submit" type="submit">Rechercher</button>
	<span style="color:#888;font-size:12px;margin-left:8px;">
		(SRU : max <?php echo (int)$max_results; ?> résultats)
	</span>
</form>

<script>
jQuery(function($) {
	var helps = {
		ark:  'Saisissez un ou plusieurs ARK Gallica (séparés par des virgules, espaces ou sauts de ligne).<br/>Format accepté&nbsp;: <code>ark:/12148/bpt6kxxxxxx</code> ou URL <code>https://gallica.bnf.fr/ark:/12148/bpt6kxxxxxx</code>',
		text: 'Saisissez des mots-clés, un titre, un auteur ou un lieu (recherche plein-texte SRU sur Gallica).<br/>Exemple&nbsp;: <code>fortifications Antibes Vauban</code>'
	};
	$('input[name="mode"]').on('change', function() {
		$('#gallica-search-help').html(helps[this.value]);
		$('#gallica-search-input').attr('placeholder',
			this.value === 'ark' ? 'ARK Gallica…' : 'Termes de recherche…'
		);
	});
});
</script>
