<?php
$deposants  = $this->getVar('deposants');
$categories = $this->getVar('categories');
$types      = $this->getVar('types');
$sites      = $this->getVar('sites');
$batiments  = $this->getVar('batiments');
$etages     = $this->getVar('etages');
$constats   = $this->getVar('constats');
$natures_retrait = $this->getVar('natures_retrait');
$erreur     = $this->getVar('erreur');
$traitement_url = $this->getVar('traitement_url');

function raOptions($items) {
	$h = '<option value="">-- Tous --</option>';
	if (is_array($items)) {
		foreach ($items as $id => $name) {
			$h .= '<option value="'.(int)$id.'">'.htmlspecialchars($name).'</option>';
		}
	}
	return $h;
}
?>
<div class="ra-wrap">
	<h1>Recherche avancée</h1>
<?php if (!empty($erreur)) { ?>
	<div class="ra-erreur" style="color:#a8322a;font-weight:bold;margin-bottom:14px;"><?= htmlspecialchars($erreur) ?></div>
<?php } ?>

	<form id="raForm" method="post" action="<?= $traitement_url ?>">
		<div class="ra-grid">
			<div class="ra-field">
				<label>Numéro d'inventaire</label>
				<input type="text" name="numinv" />
			</div>
			<div class="ra-field">
				<label>Nom du déposant</label>
				<select name="deposant"><?= raOptions($deposants) ?></select>
			</div>
			<div class="ra-field">
				<label>Titre</label>
				<input type="text" name="titre" />
			</div>

			<div class="ra-field">
				<label>Catégorie</label>
				<select name="categorie"><?= raOptions($categories) ?></select>
			</div>
			<div class="ra-field">
				<label>Type</label>
				<select name="denomination"><?= raOptions($types) ?></select>
			</div>
			<div class="ra-field">
				<label>Constat présence</label>
				<select name="constat"><?= raOptions($constats) ?></select>
			</div>

			<div class="ra-field">
				<label>Site</label>
				<select name="site"><?= raOptions($sites) ?></select>
			</div>
			<div class="ra-field">
				<label>Bâtiment</label>
				<select name="batiment"><?= raOptions($batiments) ?></select>
			</div>
			<div class="ra-field">
				<label>Étage</label>
				<select name="etage"><?= raOptions($etages) ?></select>
			</div>
			<div class="ra-field">
				<label>Nature du retrait</label>
				<select name="nature_retrait"><?= raOptions($natures_retrait) ?></select>
			</div>
		</div>

		<div class="ra-checks">
			<label><input type="checkbox" name="f_inventorie" value="1" /> Bien inventorié</label>
			<label><input type="checkbox" name="f_recole" value="1" /> Bien récolé</label>
			<label><input type="checkbox" name="f_restaure" value="1" /> Bien restauré</label>
			<label><input type="checkbox" name="f_restitue" value="1" /> Bien restitué</label>
			<label><input type="checkbox" name="f_retire" value="1" /> Bien retiré</label>
			<label><input type="checkbox" name="f_disparu" value="1" /> Bien disparu</label>
		</div>

		<div class="ra-dates">
			<div class="ra-field">
				<label>Date début</label>
				<input type="date" name="date_debut" />
			</div>
			<div class="ra-field">
				<label>Date fin</label>
				<input type="date" name="date_fin" />
			</div>
		</div>

		<div class="ra-actions">
			<button type="submit" class="ra-btn">Recherche</button>
		</div>
	</form>
</div>

<script>
/* la date de fin doit être postérieure ou égale à la date de début. */
(function () {
	var form = document.getElementById('raForm');
	if (!form) { return; }
	var debut = form.querySelector('input[name="date_debut"]');
	var fin   = form.querySelector('input[name="date_fin"]');
	if (!debut || !fin) { return; }

	function message(texte) {
		if (fin.setCustomValidity) { fin.setCustomValidity(texte); }
		var zone = document.getElementById('raErreurDates');
		if (!zone) {
			zone = document.createElement('div');
			zone.id = 'raErreurDates';
			zone.style.cssText = 'color:#a8322a;font-size:13px;font-weight:bold;margin-top:6px;';
			fin.parentNode.appendChild(zone);
		}
		zone.textContent = texte;
	}

	function controler() {
		if (debut.value) { fin.setAttribute('min', debut.value); } else { fin.removeAttribute('min'); }
		if (debut.value && fin.value && fin.value < debut.value) {
			message('La date de fin doit être postérieure ou égale à la date de début.');
			return false;
		}
		message('');
		return true;
	}

	debut.addEventListener('change', controler);
	fin.addEventListener('change', controler);
	form.addEventListener('submit', function (e) {
		if (!controler()) { e.preventDefault(); fin.focus(); }
	});
})();
</script>

<style>
.ra-wrap { background:#fff; border:1px solid #ddd; padding:22px 28px 40px; margin-top:-10px; }
.ra-wrap h1 { margin-bottom:20px; }
.ra-grid { display:grid; grid-template-columns:repeat(3, 1fr); gap:14px 22px; }
.ra-field { display:flex; flex-direction:column; gap:4px; }
.ra-field label { font-size:13px; font-weight:bold; color:#313178; }
.ra-field input[type="text"], .ra-field select, .ra-field input[type="date"] {
	padding:7px 9px; border:1px solid #ccc; border-radius:4px; font-size:13px; width:100%; box-sizing:border-box;
	font-family:'Marianne','Marianne-Light',sans-serif;
}
.ra-checks { display:flex; flex-wrap:wrap; gap:24px; margin:22px 0 6px; padding:14px 0; border-top:1px solid #eee; border-bottom:1px solid #eee; }
.ra-checks label { font-size:14px; font-weight:bold; color:#333; display:flex; align-items:center; gap:8px; cursor:pointer; }
.ra-dates { display:flex; align-items:flex-end; gap:22px; margin:18px 0; }
.ra-dates .ra-field { max-width:200px; }
.ra-note { font-size:12px; color:#777; font-style:italic; padding-bottom:6px; }
.ra-actions { margin-top:18px; }
.ra-btn { background:#313178; color:#fff; border:none; border-radius:4px; padding:10px 26px; font-size:14px; font-weight:bold; cursor:pointer; }
.ra-btn:hover { background:#1f1f52; }
</style>
</content>
