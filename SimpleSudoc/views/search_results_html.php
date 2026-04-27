<?php
/** @var View $this */
$records       = $this->getVar('records');
$existing_ppns = $this->getVar('existing_ppns') ?: [];
$mode          = $this->getVar('mode');
$search        = $this->getVar('search');

$nb = count($records);
?>
<h1><?php echo $nb . ' résultat' . ($nb > 1 ? 's' : ''); ?> SUDOC</h1>
<p style="color:#666;font-size:12px;">
	Mode : <strong><?php echo htmlspecialchars($mode === 'id' ? 'Identifiant' : 'Recherche texte'); ?></strong>
	— Requête : <code><?php echo htmlspecialchars($search); ?></code>
</p>

<style>
.sudoc-record { border: 1px solid #ccc; border-radius: 4px; margin-bottom: 14px; padding: 10px 14px; background: #fafafa; }
.sudoc-record.already-imported { background: #f5f5dc; border-color: #bbb; }
.sudoc-record h3 { margin: 0 0 4px; font-size: 15px; }
.sudoc-record .meta { color: #555; font-size: 12px; margin-bottom: 6px; }
.sudoc-record .badge-exists { background: #e0a000; color: white; border-radius: 3px; padding: 1px 6px; font-size: 11px; margin-left: 8px; }
.sudoc-desc-toggle { font-size: 11px; color: #777; cursor: pointer; }
.sudoc-desc { display: none; font-size: 12px; margin-top: 6px; background: #eee; padding: 8px; border-radius: 3px; white-space: pre-wrap; }
.sudoc-subjects { font-size: 11px; color: #444; margin-top: 4px; }
#sudoc-import-btn { margin-top: 16px; font-size: 14px; padding: 6px 20px; }
#sudoc-select-all, #sudoc-deselect-all { font-size: 12px; cursor: pointer; color: #0055a5; margin-right: 10px; }
</style>

<form action="<?php print __CA_URL_ROOT__ . '/index.php/SimpleSudoc/SimpleSudoc/Import'; ?>" method="post" id="sudoc-import-form">
	<input type="hidden" name="records_json" id="records_json_input" value="" />

	<div style="margin-bottom:10px;">
		<a id="sudoc-select-all">Tout sélectionner</a>
		<a id="sudoc-deselect-all">Tout désélectionner</a>
	</div>

	<?php foreach ($records as $i => $rec): ?>
	<?php $already = in_array((string)$rec['ppn'], $existing_ppns, true); ?>
	<div class="sudoc-record<?php echo $already ? ' already-imported' : ''; ?>">
		<label style="display:flex;align-items:flex-start;gap:10px;">
			<input type="checkbox" class="sudoc-check" name="select_<?php echo $i; ?>"
				value="<?php echo $i; ?>"
				data-record="<?php echo htmlspecialchars(json_encode($rec), ENT_QUOTES); ?>"
				<?php echo $already ? 'disabled' : ''; ?> />
			<div style="flex:1;">
				<h3>
					<?php echo htmlspecialchars($rec['title']); ?>
					<?php if ($already): ?>
						<span class="badge-exists">Déjà importé</span>
					<?php endif; ?>
				</h3>
				<div class="meta">
					<?php if ($rec['creator']): ?><strong><?php echo htmlspecialchars($rec['creator']); ?></strong> — <?php endif; ?>
					<?php if ($rec['publisher']): ?><?php echo htmlspecialchars($rec['publisher']); ?> — <?php endif; ?>
					<?php if ($rec['date']): ?>(<?php echo htmlspecialchars($rec['date']); ?>)<?php endif; ?>
					<?php if ($rec['ppn']): ?>
						— <a href="<?php echo htmlspecialchars($rec['url'] ?: ('https://www.sudoc.fr/' . $rec['ppn'])); ?>" target="_blank">SUDOC <?php echo htmlspecialchars($rec['ppn']); ?></a>
					<?php endif; ?>
				</div>
				<?php if ($rec['subject']): ?>
					<div class="sudoc-subjects"><strong>Sujets :</strong> <?php echo htmlspecialchars($rec['subject']); ?></div>
				<?php endif; ?>
				<?php if ($rec['description']): ?>
					<a class="sudoc-desc-toggle" onclick="jQuery(this).next('.sudoc-desc').slideToggle();">Afficher la description</a>
					<div class="sudoc-desc"><?php echo htmlspecialchars($rec['description']); ?></div>
				<?php endif; ?>
			</div>
		</label>
	</div>
	<?php endforeach; ?>

	<button id="sudoc-import-btn" type="submit">Importer les notices sélectionnées</button>
</form>

<script>
jQuery(function($) {
	$('.sudoc-check:not(:disabled)').prop('checked', true);

	$('#sudoc-select-all').on('click', function(e) {
		e.preventDefault();
		$('.sudoc-check:not(:disabled)').prop('checked', true);
	});
	$('#sudoc-deselect-all').on('click', function(e) {
		e.preventDefault();
		$('.sudoc-check:not(:disabled)').prop('checked', false);
	});

	$('#sudoc-import-form').on('submit', function() {
		var selected = [];
		$('.sudoc-check:checked').each(function() {
			selected.push($(this).data('record'));
		});
		if (selected.length === 0) {
			alert('Veuillez sélectionner au moins une notice à importer.');
			return false;
		}
		$('#records_json_input').val(JSON.stringify(selected));
		return true;
	});
});
</script>
