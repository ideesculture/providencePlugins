<?php
$results  = $this->getVar('results');
$outputs  = $this->getVar('outputs');
$commands = $this->getVar('commands');
?>

<h1>Résultat de l'import Z39.50</h1>

<style>
.z3950-result { padding: 8px 12px; margin-bottom: 8px; border-radius: 4px; font-size: 14px; }
.z3950-result.ok    { background: #dff0d8; border-left: 4px solid #3c763d; }
.z3950-result.skip  { background: #fcf8e3; border-left: 4px solid #8a6d3b; }
.z3950-result.error { background: #f2dede; border-left: 4px solid #a94442; }
.z3950-result .idno { font-family: monospace; font-size: 12px; color: #333; margin-right: 8px; }
.z3950-result .msg  { color: #555; font-size: 12px; }
.z3950-legacy { font-size: 11px; background: #eee; color: #333; padding: 8px; border-radius: 3px; white-space: pre-wrap; }
</style>

<?php if (is_array($results)): ?>
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
		<div class="z3950-result <?php echo htmlspecialchars($r['status']); ?>">
			<?php if (!empty($r['idno'])): ?>
				<span class="idno"><?php echo htmlspecialchars($r['idno']); ?></span>
			<?php endif; ?>
			<?php echo htmlspecialchars($r['title'] ?? ''); ?>
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

<?php elseif (is_array($outputs)): ?>
	<p>Import via <code>caUtils import-data</code> (mode legacy — mapping XLSX externe).</p>
	<?php foreach ($outputs as $i => $output): ?>
		<h3>Notice <?php echo $i + 1; ?></h3>
		<?php if (!empty($commands[$i])): ?>
			<div class="z3950-legacy"><?php echo htmlspecialchars($commands[$i]); ?></div>
		<?php endif; ?>
		<?php if (is_array($output) && !empty($output)): ?>
			<div class="z3950-legacy"><?php echo htmlspecialchars(implode("\n", $output)); ?></div>
		<?php endif; ?>
	<?php endforeach; ?>

<?php else: ?>
	<p>Aucun résultat.</p>
<?php endif; ?>

<p style="margin-top:20px;">
	<a href="<?php print __CA_URL_ROOT__ . '/index.php/SimpleZ3950/SimpleZ3950/Index'; ?>">
		← Retour à la recherche Z39.50
	</a>
</p>
