<?php
/** @var View $this */
$max_results = (int)$this->getVar('max_results');
?>
<h1>Import SUDOC (ABES)</h1>

<style>
#sudoc-mode-tabs { margin-bottom: 16px; }
#sudoc-mode-tabs label { margin-right: 20px; font-weight: bold; cursor: pointer; }
#sudoc-search-help { color: #666; font-size: 13px; margin: 6px 0 12px; }
#sudoc-search-input { width: 100%; box-sizing: border-box; padding: 6px; font-size: 14px; }
#sudoc-submit { margin-top: 10px; }
</style>

<form action="<?php print __CA_URL_ROOT__ . '/index.php/SimpleSudoc/SimpleSudoc/Search'; ?>" method="post">

	<div id="sudoc-mode-tabs">
		<label>
			<input type="radio" name="mode" value="id" id="mode_id" checked="checked" />
			Par identifiant (PPN, ISBN, ISSN)
		</label>
		<label>
			<input type="radio" name="mode" value="text" id="mode_text" />
			Recherche textuelle (titre / auteur)
		</label>
	</div>

	<div id="sudoc-search-help">
		Saisissez un ou plusieurs identifiants séparés par des virgules, espaces ou sauts de ligne.<br/>
		Le type est détecté automatiquement : 8 caractères = ISSN, 10/13 = ISBN, 5-9 = PPN.<br/>
		Exemples : <code>008637253</code>, <code>0294-1767</code>, <code>978-2-13-051234-5</code>
	</div>

	<textarea id="sudoc-search-input" name="search" rows="3"
		placeholder="PPN, ISBN ou ISSN…"></textarea>

	<br/>
	<button id="sudoc-submit" type="submit">Rechercher</button>
	<span style="color:#888;font-size:12px;margin-left:8px;">
		(SRU : max <?php echo (int)$max_results; ?> résultats en mode texte)
	</span>
</form>

<script>
jQuery(function($) {
	var helps = {
		id:   'Saisissez un ou plusieurs identifiants séparés par des virgules, espaces ou sauts de ligne.<br/>Le type est détecté automatiquement&nbsp;: 8 caractères = ISSN, 10/13 = ISBN, 5-9 = PPN.<br/>Exemples&nbsp;: <code>008637253</code>, <code>0294-1767</code>, <code>978-2-13-051234-5</code>',
		text: 'Saisissez un titre, un auteur ou des mots-clés. Recherche dans les index <code>mti</code> (titre) et <code>aut</code> (auteur).<br/>Exemple&nbsp;: <code>Vauban fortifications</code>'
	};
	$('input[name="mode"]').on('change', function() {
		$('#sudoc-search-help').html(helps[this.value]);
		$('#sudoc-search-input').attr('placeholder',
			this.value === 'id' ? 'PPN, ISBN ou ISSN…' : 'Termes de recherche…'
		);
	});
});
</script>
