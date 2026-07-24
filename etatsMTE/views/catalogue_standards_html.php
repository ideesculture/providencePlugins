<?php
$deposants = $this->getVar('deposants');
$sites     = $this->getVar('sites');
$mte_deposant_id = (int)$this->getVar('mte_deposant_id');

$generate_url  = __CA_URL_ROOT__."/index.php/etatsMTE/Catalogue/Generate";
$telechargements_url = caNavUrl($this->request, "etatsMTE", "Catalogue", "Telechargements");
?>
<div style="position:absolute;margin-left:-234px;background-color:white;border:1px solid #DDDDDD;padding:20px 30px 60px 30px;margin-top:-10px;min-height:100%;width:calc(100% + 234px);">

<h1>Catalogue standard</h1>

<!-- Notification masquée -->
<div id="notif-generation" style="display:none;" class="notif-box">
	Génération en cours. Le fichier sera disponible dans
	<a href="<?= $telechargements_url ?>">Téléchargements</a>.
</div>

<!-- Filtres communs -->
<div class="filtres-communs">
	<div class="filtre">
		<label>Déposant :</label>
		<select id="filtre-deposant">
			<option value="">-- Tous --</option>
			<?php foreach($deposants as $id => $name): ?>
			<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<div class="filtre">
		<label>Site :</label>
		<select id="filtre-site">
			<option value="">-- Tous --</option>
			<?php foreach($sites as $id => $name): ?>
			<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
			<?php endforeach; ?>
		</select>
	</div>
</div>

<!-- Catalogues -->
<!-- Par défaut : 2 boutons (par déposant par site + biens disparus).
     Si le déposant MTE est sélectionné : 4 boutons (les 2 précédents + les 2 catalogues MTE). -->
<div class="catalogue-list">

	<div class="catalogue-item">
		<div class="catalogue-name">Catalogue par déposant par site</div>
		<button type="button" class="btn-generer" onclick="lancerCatalogue(this, 'deposant_par_site')">Générer le catalogue</button>
	</div>

	<div class="catalogue-item">
		<div class="catalogue-name">Catalogue des biens disparus</div>
		<button type="button" class="btn-generer" onclick="lancerCatalogue(this, 'biens_disparus')">Générer le catalogue</button>
	</div>

	<div class="catalogue-item cat-mte-only" style="display:none;">
		<div class="catalogue-name">Catalogue MTE des objets par site</div>
		<button type="button" class="btn-generer" onclick="lancerCatalogue(this, 'mte_objets_par_site')">Générer le catalogue</button>
	</div>

	<div class="catalogue-item cat-mte-only" style="display:none;">
		<div class="catalogue-name">Catalogue MTE des mobiliers par site</div>
		<button type="button" class="btn-generer" onclick="lancerCatalogue(this, 'mte_mobiliers_par_site')">Générer le catalogue</button>
	</div>

</div>

</div>

<style>
.notif-box {
	background: #fff3cd;
	border: 1px solid #ffc107;
	border-radius: 6px;
	padding: 14px 20px;
	margin-bottom: 20px;
	font-size: 14px;
	color: #856404;
	font-family: 'Marianne', 'Marianne-Light', sans-serif;
}
.notif-box a {
	color: #1c4792;
	font-weight: bold;
	text-decoration: underline;
}
.filtres-communs {
	background: #f8f9fa;
	border: 1px solid #dee2e6;
	border-radius: 6px;
	padding: 15px 20px;
	margin-bottom: 25px;
	display: flex;
	gap: 30px;
	align-items: center;
}
.filtres-communs .filtre {
	display: flex;
	align-items: center;
	gap: 8px;
}
.filtres-communs label {
	font-size: 13px;
	font-weight: bold;
	color: #555;
	white-space: nowrap;
}
.filtres-communs select {
	padding: 6px 10px;
	border: 1px solid #ccc;
	border-radius: 4px;
	font-size: 13px;
	min-width: 250px;
	font-family: 'Marianne', 'Marianne-Light', sans-serif;
}
.catalogue-list {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
}
.catalogue-item {
	width: 40%;
	padding: 12px 16px;
	box-sizing: border-box;
}
.catalogue-name {
	font-size: 14px;
	font-weight: bold;
	color: #2c3e50;
	margin-bottom: 6px;
}
.btn-generer {
	background-color: #1c4792;
	color: white;
	padding: 8px 18px;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: 13px;
	font-weight: bold;
	white-space: nowrap;
}
.btn-generer:hover {
	background-color: #143670;
}
.btn-generer:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}
</style>

<script>
var catGenerateUrl = '<?= $generate_url ?>';
var catCheckUrl = '<?= __CA_URL_ROOT__ ?>/index.php/etatsMTE/Catalogue/CheckPDF';

function lancerCatalogue(btn, catalogueType) {
	if (!confirm('La génération d\'un catalogue peut prendre plusieurs minutes. Continuer ?')) return;

	var notif = document.getElementById('notif-generation');
	notif.innerHTML = 'Génération en cours… le téléchargement démarrera automatiquement dès que le catalogue est prêt.';
	notif.style.display = 'block';
	btn.disabled = true;

	var params = 'catalogue_type=' + encodeURIComponent(catalogueType)
		+ '&output=pdf'
		+ '&deposant=' + encodeURIComponent(jQuery('#filtre-deposant').val() || '')
		+ '&site=' + encodeURIComponent(jQuery('#filtre-site').val() || '')
		+ '&ajax=1';

	fetch(catGenerateUrl + '?' + params, {
		method: 'GET',
		headers: {'X-Requested-With': 'XMLHttpRequest'}
	})
	.then(function(r){ return r.json(); })
	.then(function(data){
		if (!data || !data.job_id) { notif.innerHTML = 'Erreur au lancement de la génération.'; btn.disabled = false; return; }
		pollCatalogueJob(data.job_id, notif, btn);
	})
	.catch(function(){ notif.innerHTML = 'Erreur au lancement de la génération.'; btn.disabled = false; });
}

// C2 (recette 22/04) : poll le statut et déclenche le téléchargement automatiquement
function pollCatalogueJob(jobId, notif, btn) {
	var iv = setInterval(function() {
		fetch(catCheckUrl + '?job_id=' + encodeURIComponent(jobId), {
			headers: {'X-Requested-With': 'XMLHttpRequest'}
		})
		.then(function(r){ return r.json(); })
		.then(function(st){
			if (!st) { return; }
			if (st.status === 'done' && st.download_url) {
				clearInterval(iv);
				var nb = (st.total != null) ? st.total : '?';
				var s = (st.total > 1) ? 's' : '';
				notif.innerHTML = 'Catalogue prêt — <strong>' + nb + ' fiche' + s + '</strong>. Le téléchargement démarre…';
				window.location = st.download_url;
				btn.disabled = false;
				setTimeout(function(){ notif.style.display = 'none'; }, 15000);
			} else if (st.status === 'error') {
				clearInterval(iv);
				notif.innerHTML = 'Erreur : ' + (st.message || 'la génération a échoué.');
				btn.disabled = false;
			} else if (st.total) {
				// running / rendering : afficher le nombre total de fiches concernées
				notif.innerHTML = 'Génération en cours… <strong>' + st.total + ' fiche' + ((st.total > 1) ? 's' : '') + '</strong> concernée' + ((st.total > 1) ? 's' : '') + ' (' + (st.processed || 0) + ' / ' + st.total + ' traitées).';
			}
		})
		.catch(function(){ /* transitoire, on retente */ });
	}, 3000);
}

// Affichage des catalogues spécifiques MTE : uniquement si le déposant MTE est sélectionné (4 boutons au lieu de 2)
var mteDeposantId = '<?= $mte_deposant_id ?>';
function majCataloguesMTE() {
	if (jQuery('#filtre-deposant').val() === mteDeposantId) {
		jQuery('.cat-mte-only').show();
	} else {
		jQuery('.cat-mte-only').hide();
	}
}
jQuery(document).ready(function() {
	jQuery('#filtre-deposant').on('change', majCataloguesMTE);
	majCataloguesMTE();
});
</script>
