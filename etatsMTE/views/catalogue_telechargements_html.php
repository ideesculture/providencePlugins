<?php
$catalogues = $this->getVar('catalogues');
?>
<div style="position:absolute;margin-left:-234px;background-color:white;border:1px solid #DDDDDD;padding:20px 30px 60px 30px;margin-top:-10px;min-height:100%;width:calc(100% + 234px);">

<h1>Téléchargements</h1>

<div style="margin-bottom:15px;">
	<button type="button" onclick="location.reload();" style="background-color:#1c4792;color:white;padding:8px 18px;border:none;border-radius:4px;cursor:pointer;font-size:13px;font-weight:bold;font-family:'Marianne','Marianne-Light',sans-serif;">Actualiser</button>
</div>

<?php if (empty($catalogues)): ?>
<div class="dl-empty">
	Aucun catalogue généré pour le moment.
</div>
<?php else: ?>

<table class="dl-table">
	<thead>
		<tr>
			<th>Catalogue</th>
			<th>Statut</th>
			<th>Objets</th>
			<th>Taille</th>
			<th>Date</th>
			<th>Actions</th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ($catalogues as $cat): ?>
		<tr id="row-<?= $cat['job_id'] ?>">
			<td class="dl-titre"><?= htmlspecialchars($cat['titre']) ?></td>
			<td>
				<?php if ($cat['status'] === 'done'): ?>
					<span class="dl-badge dl-badge-done">Terminé</span>
				<?php elseif ($cat['status'] === 'running' || $cat['status'] === 'rendering'): ?>
					<span class="dl-badge dl-badge-running">En cours<?php
						if ($cat['total']) echo ' (' . ($cat['processed'] ?? 0) . '/' . $cat['total'] . ')';
					?></span>
				<?php elseif ($cat['status'] === 'error'): ?>
					<span class="dl-badge dl-badge-error" title="<?= htmlspecialchars($cat['message'] ?? '') ?>">Erreur</span>
				<?php else: ?>
					<span class="dl-badge"><?= htmlspecialchars($cat['status']) ?></span>
				<?php endif; ?>
			</td>
			<td><?= $cat['total'] ? $cat['total'] : '-' ?></td>
			<td><?= $cat['pdf_size'] > 0 ? round($cat['pdf_size'] / 1024) . ' Ko' : '-' ?></td>
			<td>
				<?php
				if ($cat['finished']) {
					$dt = new DateTime($cat['finished']);
					echo $dt->format('d/m/Y H:i');
				} elseif ($cat['started']) {
					$dt = new DateTime($cat['started']);
					echo $dt->format('d/m/Y H:i') . ' (démarré)';
				} else {
					echo '-';
				}
				?>
			</td>
			<td class="dl-actions">
				<?php if ($cat['download_url']): ?>
					<a href="<?= $cat['download_url'] ?>" class="dl-btn dl-btn-download">Télécharger</a>
				<?php endif; ?>
				<?php if ($cat['status'] === 'error' && $cat['message']): ?>
					<span class="dl-error-msg" title="<?= htmlspecialchars($cat['message']) ?>">Détails</span>
				<?php endif; ?>
				<button class="dl-btn dl-btn-delete" onclick="deleteCatalogue('<?= $cat['job_id'] ?>', '<?= $cat['delete_url'] ?>')">Supprimer</button>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<?php endif; ?>

</div>

<style>
.dl-empty {
	color: #888;
	font-size: 14px;
	padding: 30px 0;
	font-style: italic;
}
.dl-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
}
.dl-table th {
	background: #f8f9fa;
	border-bottom: 2px solid #dee2e6;
	padding: 10px 12px;
	text-align: left;
	font-weight: bold;
	color: #2c3e50;
	font-size: 12px;
	text-transform: uppercase;
}
.dl-table td {
	padding: 10px 12px;
	border-bottom: 1px solid #eee;
	vertical-align: middle;
}
.dl-table tr:hover {
	background: #f8f9fa;
}
.dl-titre {
	font-weight: bold;
	color: #2c3e50;
}
.dl-badge {
	display: inline-block;
	padding: 3px 10px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: bold;
}
.dl-badge-done {
	background: #d4edda;
	color: #155724;
}
.dl-badge-running {
	background: #fff3cd;
	color: #856404;
}
.dl-badge-error {
	background: #f8d7da;
	color: #721c24;
	cursor: help;
}
.dl-actions {
	display: flex;
	gap: 8px;
	align-items: center;
}
.dl-btn {
	display: inline-block;
	padding: 6px 14px;
	border-radius: 4px;
	font-size: 12px;
	font-weight: bold;
	text-decoration: none;
	border: none;
	cursor: pointer;
	font-family: 'Marianne', 'Marianne-Light', sans-serif;
}
.dl-btn-download {
	background-color: #28a745;
	color: white;
}
.dl-btn-download:hover {
	background-color: #218838;
}
.dl-btn-delete {
	background-color: #dc3545;
	color: white;
}
.dl-btn-delete:hover {
	background-color: #c82333;
}
.dl-error-msg {
	font-size: 11px;
	color: #721c24;
	cursor: help;
	text-decoration: underline dotted;
}
</style>

<script>
var listUrl = '<?= caNavUrl($this->request, "etatsMTE", "Catalogue", "TelechargementsList") ?>';

function deleteCatalogue(jobId, deleteUrl) {
	if (!confirm('Supprimer ce catalogue ?')) return;

	jQuery.getJSON(deleteUrl + (deleteUrl.indexOf('?') > -1 ? '&' : '?') + 'ajax=1', function(resp) {
		if (resp && resp.status === 'deleted') {
			jQuery('#row-' + jobId).fadeOut(300, function() { jQuery(this).remove(); });
		}
	});
}

function refreshTelechargements() {
	jQuery.getJSON(listUrl, function(catalogues) {
		if (!catalogues || !catalogues.length) return;
		var hasRunning = false;
		jQuery.each(catalogues, function(i, cat) {
			var $row = jQuery('#row-' + cat.job_id);
			if (!$row.length) return;

			// Update status badge
			var badgeHtml = '';
			if (cat.status === 'done') {
				badgeHtml = '<span class="dl-badge dl-badge-done">Terminé</span>';
			} else if (cat.status === 'running' || cat.status === 'rendering') {
				hasRunning = true;
				var progress = '';
				if (cat.total) progress = ' (' + (cat.processed || 0) + '/' + cat.total + ')';
				badgeHtml = '<span class="dl-badge dl-badge-running">En cours' + progress + '</span>';
			} else if (cat.status === 'error') {
				badgeHtml = '<span class="dl-badge dl-badge-error" title="' + (cat.message || '') + '">Erreur</span>';
			}
			$row.find('td:eq(1)').html(badgeHtml);

			// Update objects count
			$row.find('td:eq(2)').text(cat.total ? cat.total : '-');

			// Update size
			$row.find('td:eq(3)').text(cat.pdf_size > 0 ? Math.round(cat.pdf_size / 1024) + ' Ko' : '-');

			// Update date
			if (cat.finished) {
				var d = new Date(cat.finished);
				$row.find('td:eq(4)').text(d.toLocaleDateString('fr-FR') + ' ' + d.toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit'}));
			}

			// Update actions
			var actionsHtml = '';
			if (cat.download_url) {
				actionsHtml += '<a href="' + cat.download_url + '" class="dl-btn dl-btn-download">Télécharger</a>';
			}
			if (cat.status === 'error' && cat.message) {
				actionsHtml += '<span class="dl-error-msg" title="' + cat.message + '">Détails</span>';
			}
			actionsHtml += '<button class="dl-btn dl-btn-delete" onclick="deleteCatalogue(\'' + cat.job_id + '\', \'' + cat.delete_url + '\')">Supprimer</button>';
			$row.find('td:eq(5)').html(actionsHtml);
		});

		if (hasRunning) {
			setTimeout(refreshTelechargements, 10000);
		}
	});
}

// Auto-poll if there are running jobs
jQuery(document).ready(function() {
	if (jQuery('.dl-badge-running').length > 0) {
		setTimeout(refreshTelechargements, 10000);
	}
});
</script>
