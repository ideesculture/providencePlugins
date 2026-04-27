<?php
/** @var View $this */
$results = $this->getVar('results');
?>
<h1>Résultat de l'import SUDOC</h1>

<style>
.sudoc-result { padding: 8px 12px; margin-bottom: 8px; border-radius: 4px; font-size: 14px; }
.sudoc-result.ok    { background: #dff0d8; border-left: 4px solid #3c763d; }
.sudoc-result.skip  { background: #fcf8e3; border-left: 4px solid #8a6d3b; }
.sudoc-result.error { background: #f2dede; border-left: 4px solid #a94442; }
.sudoc-result .ppn  { font-family: monospace; font-size: 12px; color: #333; margin-right: 8px; }
.sudoc-result .msg  { color: #555; font-size: 12px; }
</style>

<?php if (empty($results)): ?>
	<p>Aucune notice traitée.</p>
<?php else: ?>
	<?php
	$nb_ok    = count(array_filter($results, function($r){ return $r['status'] === 'ok'; }));
	$nb_skip  = count(array_filter($results, function($r){ return $r['status'] === 'skip'; }));
	$nb_error = count(array_filter($results, function($r){ return $r['status'] === 'error'; }));
	?>
	<p>
		<strong><?php echo $nb_ok; ?></strong> notice(s) importée(s) —
		<strong><?php echo $nb_skip; ?></strong> déjà présente(s) —
		<strong><?php echo $nb_error; ?></strong> erreur(s)
	</p>

	<?php foreach ($results as $r): ?>
		<div class="sudoc-result <?php echo htmlspecialchars($r['status']); ?>">
			<span class="ppn">PPN <?php echo htmlspecialchars($r['ppn']); ?></span>
			<?php echo htmlspecialchars($r['title']); ?>
			<div class="msg">
				<?php echo htmlspecialchars($r['message']); ?>
				<?php if ($r['status'] === 'ok' && !empty($r['object_id'])): ?>
					— <a href="<?php echo __CA_URL_ROOT__ . '/index.php/editor/objects/ObjectEditor/Edit/object_id/' . (int)$r['object_id']; ?>" target="_blank">
						Ouvrir la fiche →
					</a>
				<?php endif; ?>
			</div>
		</div>
	<?php endforeach; ?>
<?php endif; ?>

<p style="margin-top:20px;">
	<a href="<?php print __CA_URL_ROOT__ . '/index.php/SimpleSudoc/SimpleSudoc/Index'; ?>">
		← Retour à la recherche SUDOC
	</a>
</p>
