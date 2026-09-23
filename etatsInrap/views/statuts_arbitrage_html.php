<?php
/**
 * statuts_arbitrage_html.php — les reculs de statut soumis à arbitrage humain.
 * 14/09/2026 GM (ticket 8025). Écran de consultation, n'écrit rien.
 */
	$lignes      = $this->getVar("lignes");
	$date_liste  = $this->getVar("date_liste");
	$fichier     = $this->getVar("fichier");
	if (!is_array($lignes)) { $lignes = array(); }

	// Date en français, sans dépendre de la locale du serveur.
	$jour = '';
	if ($date_liste && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date_liste, $m)) {
		$jour = $m[3].'/'.$m[2].'/'.$m[1];
	}
	$recent = $date_liste ? ($date_liste >= date('Y-m-d', time() - 86400 * 2)) : false;
?>
<div style="float:right"><a href="/">Retour</a></div>
<h1>Statuts d'opération — cas soumis à arbitrage</h1>

<link href="//cdn.datatables.net/2.1.8/css/dataTables.dataTables.min.css" rel="stylesheet" />
<script src="//cdn.datatables.net/2.1.8/js/dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.2.0/js/dataTables.buttons.js"></script>
<script src="https://cdn.datatables.net/buttons/3.2.0/js/buttons.dataTables.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.2.0/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.2.0/js/buttons.print.min.js"></script>

<div class="dashboardWidgetContentContainer">

<p style="max-width:60em;">
	Le statut d'une opération est recalculé chaque nuit à partir des dates de terrain, de remise
	de rapport, de garde et des versements enregistrés. Le programme n'écrit jamais un statut qui
	ferait <strong>reculer</strong> la collection&nbsp;: une collection versée ne doit pas repasser
	« en attente de versement » parce qu'un calcul de nuit en a décidé ainsi.
</p>
<p style="max-width:60em;">
	Les opérations ci-dessous sont dans ce cas. Leur statut <strong>n'a pas été modifié</strong>.
	Soit le statut actuel est le bon et il n'y a rien à faire&nbsp;; soit c'est la fiche qui est
	incomplète — une date ou un versement manquant — et la corriger fera converger le calcul.
	Cliquez l'identifiant pour ouvrir l'opération.
</p>

<?php if (!sizeof($lignes)) { ?>
	<p style="padding:10px;background:#e8f5e9;border:1px solid #a5d6a7;max-width:60em;">
		<?= $fichier ? "Aucun cas à arbitrer dans la dernière liste" . ($jour ? " du {$jour}" : "") . "."
		             : "Aucune liste d'arbitrage n'a encore été produite." ?>
	</p>
<?php } else { ?>

	<p>
		<strong><?= sizeof($lignes) ?></strong> opération(s)<?= $jour ? ", liste du <strong>".$jour."</strong>" : "" ?>.
		<?php if ($jour && !$recent) { ?>
			<span style="color:#b71c1c;">Cette liste date de plus de deux jours&nbsp;: le recalcul de nuit n'a peut-être pas tourné.</span>
		<?php } ?>
		&nbsp;— <a href="<?= caNavUrl($this->request, 'etatsInrap', 'Statuts', 'Csv') ?>">télécharger en CSV</a>
	</p>

	<table id="tableArbitrage" class="display" style="width:100%">
		<thead>
			<tr>
				<th>Identifiant</th>
				<th>Commune</th>
				<th>Direction</th>
				<th>Statut actuel</th>
				<th>Statut calculé</th>
				<th>Statut posé par</th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ($lignes as $l) {
			$url = caNavUrl($this->request, 'editor/collections', 'CollectionEditor', 'Edit',
			                array('collection_id' => (int)$l['id']));
		?>
			<tr>
				<td><a href="<?= $url ?>"><?= htmlspecialchars($l['idno'], ENT_QUOTES, 'UTF-8') ?></a></td>
				<td><?= htmlspecialchars($l['commune'], ENT_QUOTES, 'UTF-8') ?></td>
				<td><?= htmlspecialchars($l['direction'], ENT_QUOTES, 'UTF-8') ?></td>
				<td><?= htmlspecialchars($l['actuel'], ENT_QUOTES, 'UTF-8') ?></td>
				<td style="color:#8a6200;"><?= htmlspecialchars($l['calcule'], ENT_QUOTES, 'UTF-8') ?></td>
				<td style="color:#555e5b;"><?= $l['auteur'] !== ''
					? htmlspecialchars($l['auteur'], ENT_QUOTES, 'UTF-8')
					: '<span style="color:#9aa;">non tracé</span>' ?></td>
			</tr>
		<?php } ?>
		</tbody>
	</table>

	<script>
		jQuery(document).ready(function() {
			jQuery('#tableArbitrage').DataTable({
				pageLength: 50,
				order: [[2, 'asc'], [1, 'asc']],
				layout: { topStart: { buttons: ['copy', 'csv', 'print'] } },
				language: {
					search: "Filtrer :", lengthMenu: "Afficher _MENU_ lignes",
					info: "_START_ à _END_ sur _TOTAL_", infoEmpty: "aucune ligne",
					infoFiltered: "(filtré sur _MAX_)", zeroRecords: "Aucune correspondance",
					paginate: { first: "Début", previous: "Précédent", next: "Suivant", last: "Fin" }
				}
			});
		});
	</script>
<?php } ?>

</div>
