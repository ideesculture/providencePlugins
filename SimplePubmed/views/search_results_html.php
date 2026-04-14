<?php
/** @var View $this */
$articles       = $this->getVar('articles');
$existing_pmids = $this->getVar('existing_pmids');

$nb = count($articles);
print '<h1>' . $nb . ' résultat' . ($nb > 1 ? 's' : '') . '</h1>';
?>

<style>
.pubmed-article { border: 1px solid #ccc; border-radius: 4px; margin-bottom: 14px; padding: 10px 14px; background: #fafafa; }
.pubmed-article.already-imported { background: #f5f5dc; border-color: #bbb; }
.pubmed-article h3 { margin: 0 0 4px; font-size: 15px; }
.pubmed-article .meta { color: #555; font-size: 12px; margin-bottom: 6px; }
.pubmed-article .badge-exists { background: #e0a000; color: white; border-radius: 3px; padding: 1px 6px; font-size: 11px; margin-left: 8px; }
.pubmed-abstract-toggle { font-size: 11px; color: #777; cursor: pointer; }
.pubmed-abstract { display: none; font-size: 12px; margin-top: 6px; background: #eee; padding: 8px; border-radius: 3px; white-space: pre-wrap; }
.pubmed-mesh { font-size: 11px; color: #444; margin-top: 4px; }
#pubmed-import-btn { margin-top: 16px; font-size: 14px; padding: 6px 20px; }
#pubmed-select-all, #pubmed-deselect-all { font-size: 12px; cursor: pointer; color: #0055a5; margin-right: 10px; }
</style>

<form action="<?php print __CA_URL_ROOT__ . '/index.php/SimplePubmed/SimplePubmed/Import'; ?>" method="post" id="pubmed-import-form">
	<input type="hidden" name="articles_json" id="articles_json_input" value="" />

	<div style="margin-bottom:10px;">
		<a id="pubmed-select-all">Tout sélectionner</a>
		<a id="pubmed-deselect-all">Tout désélectionner</a>
	</div>

	<?php foreach ($articles as $i => $art): ?>
	<?php $already = in_array((string)$art['pmid'], $existing_pmids); ?>
	<div class="pubmed-article<?php echo $already ? ' already-imported' : ''; ?>">
		<label style="display:flex;align-items:flex-start;gap:10px;">
			<input type="checkbox" class="pubmed-check" name="select_<?php echo $i; ?>"
				value="<?php echo $i; ?>"
				data-article="<?php echo htmlspecialchars(json_encode($art), ENT_QUOTES); ?>"
				<?php echo $already ? 'disabled' : ''; ?> />
			<div style="flex:1;">
				<h3>
					<?php echo htmlspecialchars($art['title']); ?>
					<?php if ($already): ?>
						<span class="badge-exists">Déjà importé</span>
					<?php endif; ?>
				</h3>
				<div class="meta">
					<?php if ($art['authors']): ?><strong><?php echo htmlspecialchars($art['authors']); ?></strong> — <?php endif; ?>
					<?php echo htmlspecialchars($art['journal']); ?>
					<?php if ($art['year']): ?>(<?php echo htmlspecialchars($art['year']); ?>)<?php endif; ?>
					<?php if ($art['volume'] || $art['issue'] || $art['pages']): ?>
						;
						<?php echo $art['volume'] ? 'vol. ' . htmlspecialchars($art['volume']) : ''; ?>
						<?php echo $art['issue']  ? '(' . htmlspecialchars($art['issue'])  . ')' : ''; ?>
						<?php echo $art['pages']  ? ':' . htmlspecialchars($art['pages'])  : ''; ?>
					<?php endif; ?>
					<?php if ($art['doi']): ?>
						— <a href="https://doi.org/<?php echo htmlspecialchars($art['doi']); ?>" target="_blank">DOI</a>
					<?php endif; ?>
					— <a href="<?php echo htmlspecialchars($art['pubmed_url']); ?>" target="_blank">PubMed (<?php echo htmlspecialchars($art['pmid']); ?>)</a>
				</div>
				<?php if ($art['pub_types']): ?>
					<div class="meta" style="font-style:italic;"><?php echo htmlspecialchars(implode(', ', $art['pub_types'])); ?></div>
				<?php endif; ?>
				<?php if ($art['mesh']): ?>
					<div class="pubmed-mesh"><strong>MeSH :</strong> <?php echo htmlspecialchars($art['mesh']); ?></div>
				<?php endif; ?>
				<?php if ($art['abstract']): ?>
					<a class="pubmed-abstract-toggle" onclick="jQuery(this).next('.pubmed-abstract').slideToggle();">Afficher le résumé</a>
					<div class="pubmed-abstract"><?php echo htmlspecialchars($art['abstract']); ?></div>
				<?php endif; ?>
			</div>
		</label>
	</div>
	<?php endforeach; ?>

	<button id="pubmed-import-btn" type="submit">Importer les notices sélectionnées</button>
</form>

<script>
jQuery(function($) {
	// Pré-sélectionner les notices non encore importées
	$('.pubmed-check:not(:disabled)').prop('checked', true);

	$('#pubmed-select-all').on('click', function(e) {
		e.preventDefault();
		$('.pubmed-check:not(:disabled)').prop('checked', true);
	});
	$('#pubmed-deselect-all').on('click', function(e) {
		e.preventDefault();
		$('.pubmed-check:not(:disabled)').prop('checked', false);
	});

	$('#pubmed-import-form').on('submit', function() {
		var selected = [];
		$('.pubmed-check:checked').each(function() {
			selected.push($(this).data('article'));
		});
		if (selected.length === 0) {
			alert('Veuillez sélectionner au moins une notice à importer.');
			return false;
		}
		$('#articles_json_input').val(JSON.stringify(selected));
		return true;
	});
});
</script>
