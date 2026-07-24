<?php
$deposants = $this->getVar('deposants');
$sites     = $this->getVar('sites');
$batiments = $this->getVar('batiments');
$etages    = $this->getVar('etages');
$types     = $this->getVar('types');          // Catégorie (domaine_logement)
$denominations = $this->getVar('denominations'); // Type (denomination)
$constats  = $this->getVar('constats');

$generate_url  = __CA_URL_ROOT__."/index.php/etatsMTE/Catalogue/Generate";
$telechargements_url = caNavUrl($this->request, "etatsMTE", "Catalogue", "Telechargements");
?>
<div style="position:absolute;margin-left:-234px;background-color:white;border:1px solid #DDDDDD;padding:20px 30px 60px 30px;margin-top:-10px;min-height:100%;width:calc(100% + 234px);">

<h1>Catalogue spécifique</h1>

<!-- Notification masquée -->
<div id="notif-generation" style="display:none;" class="notif-box">
	Génération en cours. Le fichier sera disponible dans
	<a href="<?= $telechargements_url ?>">Téléchargements</a>.
</div>

<!-- Filtres communs -->
<div class="filtres-communs">
	<div class="filtre-ligne">
		<div class="filtre">
			<label>Déposant :</label>
			<select id="filtre-deposant">
				<option value="">-- Tous --</option>
				<?php foreach($deposants as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	</div>
	<div class="filtre-ligne">
		<div class="filtre">
			<label>Site :</label>
			<select id="filtre-site">
				<option value="">-- Tous --</option>
				<?php foreach($sites as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="filtre">
			<label>Bâtiment :</label>
			<select id="filtre-batiment">
				<option value="">-- Tous --</option>
				<?php foreach($batiments as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="filtre">
			<label>Étage :</label>
			<select id="filtre-etage">
				<option value="">-- Tous --</option>
				<?php foreach($etages as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	</div>
	<div class="filtre-ligne">
		<div class="filtre">
			<label>Catégorie :</label>
			<select id="filtre-type">
				<option value="">-- Toutes --</option>
				<?php foreach($types as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="filtre">
			<label>Type :</label>
			<select id="filtre-denomination">
				<option value="">-- Tous --</option>
				<?php foreach($denominations as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="filtre">
			<label>Objet / Mobilier :</label>
			<select id="filtre-objet-mobilier">
				<option value="">-- Tous --</option>
				<option value="objet">Objet</option>
				<option value="mobilier">Mobilier</option>
			</select>
		</div>
		<div class="filtre">
			<label>Constat présence :</label>
			<select id="filtre-constat">
				<option value="">-- Tous --</option>
				<?php foreach($constats as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	</div>
	<div class="filtre-ligne">
		<div class="filtre">
			<label>Date début :</label>
			<input type="date" id="filtre-date-debut" />
		</div>
		<div class="filtre">
			<label>Date fin :</label>
			<input type="date" id="filtre-date-fin" />
		</div>
	</div>
	<div class="filtre-ligne filtre-ligne-etats">
		<label class="filtre-etat"><input type="checkbox" id="filtre-recole" value="1" /> Bien récolé</label>
		<label class="filtre-etat"><input type="checkbox" id="filtre-inventorie" value="1" /> Bien inventorié</label>
		<label class="filtre-etat"><input type="checkbox" id="filtre-restitue" value="1" /> Bien restitué</label>
		<label class="filtre-etat"><input type="checkbox" id="filtre-restaure" value="1" /> Bien restauré</label>
	</div>
</div>

<!-- Un seul catalogue, piloté par les filtres ci-dessus -->
<p class="catalogue-hint">Le catalogue est généré selon les filtres sélectionnés ci-dessus. Résultat regroupé par site, et par déposant si aucun déposant n'est sélectionné.</p>

<div class="catalogue-generate-bar">
	<button type="button" class="btn-generer" onclick="lancerCatalogueSpecifique()">Générer le catalogue</button>
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
	flex-direction: column;
	gap: 10px;
}
.filtre-ligne {
	display: flex;
	gap: 20px;
	align-items: center;
	flex-wrap: wrap;
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
.filtres-communs select,
.filtres-communs input[type="date"] {
	padding: 6px 10px;
	border: 1px solid #ccc;
	border-radius: 4px;
	font-size: 13px;
	min-width: 180px;
	font-family: 'Marianne', 'Marianne-Light', sans-serif;
}
.filtre-ligne-etats {
	gap: 24px;
	padding-top: 4px;
}
.filtre-etat {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
	font-weight: bold;
	color: #555;
	white-space: nowrap;
	cursor: pointer;
}
.filtre-etat input[type="checkbox"] {
	width: 16px;
	height: 16px;
	cursor: pointer;
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
.catalogue-info {
	font-size: 11px;
	color: #888;
	margin: 0 0 8px 0;
	font-style: italic;
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
/* C5 : choix par radio + un seul bouton Générer */
.catalogue-list .catalogue-item {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	cursor: pointer;
}
.catalogue-list .catalogue-item input[type="radio"] {
	margin-top: 4px;
	flex: 0 0 auto;
}
.catalogue-generate-bar {
	margin-top: 22px;
}
.catalogue-hint {
	font-size: 13px;
	color: #555;
	font-style: italic;
	margin: 4px 0 0 0;
}
</style>

<script>
var catGenerateUrl = '<?= $generate_url ?>';
var catCheckUrl = '<?= __CA_URL_ROOT__ ?>/index.php/etatsMTE/Catalogue/CheckPDF';

// Catalogue spécifique unifié : un seul type, piloté par les filtres
function lancerCatalogueSpecifique() {
	lancerCatalogue('specifique');
}

function lancerCatalogue(catalogueType) {
	if (!confirm('La génération d\'un catalogue peut prendre plusieurs minutes. Continuer ?')) return;

	var notif = document.getElementById('notif-generation');
	notif.innerHTML = 'Génération en cours… le téléchargement démarrera automatiquement dès que le catalogue est prêt.';
	notif.style.display = 'block';

	// Gather all filter values
	var params = 'catalogue_type=' + encodeURIComponent(catalogueType)
		+ '&output=pdf'
		+ '&deposant=' + encodeURIComponent(jQuery('#filtre-deposant').val() || '')
		+ '&site=' + encodeURIComponent(jQuery('#filtre-site').val() || '')
		+ '&batiment=' + encodeURIComponent(jQuery('#filtre-batiment').val() || '')
		+ '&etage=' + encodeURIComponent(jQuery('#filtre-etage').val() || '')
		+ '&type_domaine=' + encodeURIComponent(jQuery('#filtre-type').val() || '')
		+ '&denomination=' + encodeURIComponent(jQuery('#filtre-denomination').val() || '')
		+ '&objet_mobilier=' + encodeURIComponent(jQuery('#filtre-objet-mobilier').val() || '')
		+ '&constat=' + encodeURIComponent(jQuery('#filtre-constat').val() || '')
		+ '&date_debut=' + encodeURIComponent(jQuery('#filtre-date-debut').val() || '')
		+ '&date_fin=' + encodeURIComponent(jQuery('#filtre-date-fin').val() || '')
		+ '&recole=' + (jQuery('#filtre-recole').is(':checked') ? '1' : '0')
		+ '&inventorie=' + (jQuery('#filtre-inventorie').is(':checked') ? '1' : '0')
		+ '&restitue=' + (jQuery('#filtre-restitue').is(':checked') ? '1' : '0')
		+ '&restaure=' + (jQuery('#filtre-restaure').is(':checked') ? '1' : '0')
		+ '&ajax=1';

	fetch(catGenerateUrl + '?' + params, {
		method: 'GET',
		headers: {'X-Requested-With': 'XMLHttpRequest'}
	})
	.then(function(r){ return r.json(); })
	.then(function(data){
		if (!data || !data.job_id) { notif.innerHTML = 'Erreur au lancement de la génération.'; return; }
		pollCatalogueJob(data.job_id, notif);
	})
	.catch(function(){ notif.innerHTML = 'Erreur au lancement de la génération.'; });
}

// C2 (recette 22/04) : poll le statut et déclenche le téléchargement automatiquement
function pollCatalogueJob(jobId, notif) {
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
				setTimeout(function(){ notif.style.display = 'none'; }, 15000);
			} else if (st.status === 'error') {
				clearInterval(iv);
				notif.innerHTML = 'Erreur : ' + (st.message || 'la génération a échoué.');
			} else if (st.total) {
				// running / rendering : afficher le nombre total de fiches concernées
				notif.innerHTML = 'Génération en cours… <strong>' + st.total + ' fiche' + ((st.total > 1) ? 's' : '') + '</strong> concernée' + ((st.total > 1) ? 's' : '') + ' (' + (st.processed || 0) + ' / ' + st.total + ' traitées).';
			}
		})
		.catch(function(){ /* transitoire */ });
	}, 3000);
}

</script>
