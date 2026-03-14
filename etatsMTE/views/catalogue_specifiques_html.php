<?php
$deposants = $this->getVar('deposants');
$sites     = $this->getVar('sites');
$batiments = $this->getVar('batiments');
$etages    = $this->getVar('etages');
$types     = $this->getVar('types');
$constats  = $this->getVar('constats');

$generate_url = __CA_URL_ROOT__."/index.php/etatsMTE/Catalogue/Generate";
?>
<div style="position:absolute;margin-left:-234px;background-color:white;border:1px solid #DDDDDD;padding:20px 30px 60px 30px;margin-top:-10px;min-height:100%;width:calc(100% + 234px);">

<h1>Catalogue spécifique</h1>

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
			<label>Type :</label>
			<select id="filtre-type">
				<option value="">-- Tous --</option>
				<?php foreach($types as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
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
</div>

<!-- Catalogues -->
<div class="catalogue-list">

	<div class="catalogue-item cat-no-periode">
		<div class="catalogue-name">Par déposant + site + bâtiment + étage</div>
		<form method="get" action="<?= $generate_url ?>">
			<input type="hidden" name="catalogue_type" value="specifique_deposant_site_batiment_etage" />
			<input type="hidden" name="output" value="pdf" />
			<input type="hidden" name="deposant" class="inject-deposant" />
			<input type="hidden" name="site" class="inject-site" />
			<input type="hidden" name="batiment" class="inject-batiment" />
			<input type="hidden" name="etage" class="inject-etage" />
			<button type="submit" class="btn-generer">Générer le catalogue</button>
		</form>
	</div>

	<div class="catalogue-item cat-no-periode">
		<div class="catalogue-name">Par type (Objet ou Mobilier)</div>
		<p class="catalogue-info">Résultat regroupé par site et déposant</p>
		<form method="get" action="<?= $generate_url ?>">
			<input type="hidden" name="catalogue_type" value="specifique_par_type" />
			<input type="hidden" name="output" value="pdf" />
			<input type="hidden" name="deposant" class="inject-deposant" />
			<input type="hidden" name="site" class="inject-site" />
			<input type="hidden" name="type_domaine" class="inject-type" />
			<button type="submit" class="btn-generer">Générer le catalogue</button>
		</form>
	</div>

	<div class="catalogue-item cat-no-periode">
		<div class="catalogue-name">Biens VU / NON VU (Constat présence)</div>
		<p class="catalogue-info">Résultat regroupé par site et déposant</p>
		<form method="get" action="<?= $generate_url ?>">
			<input type="hidden" name="catalogue_type" value="specifique_vu_non_vu" />
			<input type="hidden" name="output" value="pdf" />
			<input type="hidden" name="deposant" class="inject-deposant" />
			<input type="hidden" name="site" class="inject-site" />
			<input type="hidden" name="constat" class="inject-constat" />
			<button type="submit" class="btn-generer">Générer le catalogue</button>
		</form>
	</div>

	<div class="catalogue-item">
		<div class="catalogue-name">Biens récolés sur une période</div>
		<p class="catalogue-info">Tous déposants (regroupé par site + déposant) ou sélection d'un déposant (regroupé par site)</p>
		<form method="get" action="<?= $generate_url ?>">
			<input type="hidden" name="catalogue_type" value="specifique_recoles_periode" />
			<input type="hidden" name="output" value="pdf" />
			<input type="hidden" name="deposant" class="inject-deposant" />
			<input type="hidden" name="site" class="inject-site" />
			<input type="hidden" name="date_debut" class="inject-date-debut" />
			<input type="hidden" name="date_fin" class="inject-date-fin" />
			<button type="submit" class="btn-generer">Générer le catalogue</button>
		</form>
	</div>

	<div class="catalogue-item">
		<div class="catalogue-name">Biens inventoriés sur une période</div>
		<p class="catalogue-info">Tous déposants (regroupé par site + déposant) ou sélection d'un déposant (regroupé par site)</p>
		<form method="get" action="<?= $generate_url ?>">
			<input type="hidden" name="catalogue_type" value="specifique_inventories_periode" />
			<input type="hidden" name="output" value="pdf" />
			<input type="hidden" name="deposant" class="inject-deposant" />
			<input type="hidden" name="site" class="inject-site" />
			<input type="hidden" name="date_debut" class="inject-date-debut" />
			<input type="hidden" name="date_fin" class="inject-date-fin" />
			<button type="submit" class="btn-generer">Générer le catalogue</button>
		</form>
	</div>

</div>

</div>

<style>
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
</style>

<script>
jQuery(document).ready(function() {
	function syncFilters() {
		var deposant = jQuery('#filtre-deposant').val();
		var site = jQuery('#filtre-site').val();
		var batiment = jQuery('#filtre-batiment').val();
		var etage = jQuery('#filtre-etage').val();
		var type = jQuery('#filtre-type').val();
		var constat = jQuery('#filtre-constat').val();
		var dateDebut = jQuery('#filtre-date-debut').val();
		var dateFin = jQuery('#filtre-date-fin').val();

		jQuery('.inject-deposant').val(deposant);
		jQuery('.inject-site').val(site);
		jQuery('.inject-batiment').val(batiment);
		jQuery('.inject-etage').val(etage);
		jQuery('.inject-type').val(type);
		jQuery('.inject-constat').val(constat);
		jQuery('.inject-date-debut').val(dateDebut);
		jQuery('.inject-date-fin').val(dateFin);

		if (dateDebut || dateFin) {
			jQuery('.cat-no-periode').hide();
		} else {
			jQuery('.cat-no-periode').show();
		}
	}
	jQuery('#filtre-deposant, #filtre-site, #filtre-batiment, #filtre-etage, #filtre-type, #filtre-constat, #filtre-date-debut, #filtre-date-fin').on('change', syncFilters);
	syncFilters();

	jQuery('.catalogue-list').on('submit', 'form', function(e) {
		if (!confirm('La génération d\'un catalogue peut prendre plusieurs minutes. Êtes-vous sûr de vouloir continuer ?')) {
			e.preventDefault();
			return false;
		}
		jQuery('.btn-generer').prop('disabled', true).css('opacity', '0.5').css('cursor', 'not-allowed');
	});
});
</script>
