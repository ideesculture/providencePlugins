<?php
/** @var View $this */
?>
<h1>Import PubMed</h1>

<style>
#pubmed-mode-tabs { margin-bottom: 16px; }
#pubmed-mode-tabs label { margin-right: 20px; font-weight: bold; cursor: pointer; }
#pubmed-search-help { color: #666; font-size: 13px; margin: 6px 0 12px; }
#pubmed-search-input { width: 100%; box-sizing: border-box; padding: 6px; font-size: 14px; }
#pubmed-submit { margin-top: 10px; }
</style>

<form action="<?php print __CA_URL_ROOT__ . '/index.php/SimplePubmed/SimplePubmed/Search'; ?>" method="post">

	<div id="pubmed-mode-tabs">
		<label>
			<input type="radio" name="mode" value="pmid" id="mode_pmid" checked="checked" />
			Par PMID
		</label>
		<label>
			<input type="radio" name="mode" value="text" id="mode_text" />
			Recherche textuelle
		</label>
	</div>

	<div id="pubmed-search-help" id="help_pmid">
		Saisissez un ou plusieurs PMIDs séparés par des virgules ou des espaces.<br/>
		Exemple : <code>12345678, 23456789</code>
	</div>

	<textarea id="pubmed-search-input" name="search" rows="3"
		placeholder="PMID(s) ou termes de recherche…"></textarea>

	<br/>
	<button id="pubmed-submit" type="submit">Rechercher</button>
</form>

<script>
jQuery(function($) {
	var helps = {
		pmid: 'Saisissez un ou plusieurs PMIDs séparés par des virgules ou des espaces.<br/>Exemple&nbsp;: <code>12345678, 23456789</code>',
		text: 'Saisissez des mots-clés, un titre partiel ou un auteur.<br/>Exemple&nbsp;: <code>paracetamol poisoning children[Title]</code>'
	};
	$('input[name="mode"]').on('change', function() {
		$('#pubmed-search-help').html(helps[this.value]);
		$('#pubmed-search-input').attr('placeholder',
			this.value === 'pmid' ? 'PMID(s)…' : 'Termes de recherche…'
		);
	});
});
</script>
