<?php
$deposants = $this->getVar('deposants');
$sites     = $this->getVar('sites');

$generate_url = __CA_URL_ROOT__."/index.php/etatsMTE/Catalogue/Generate";
?>
<div style="position:absolute;margin-left:-234px;background-color:white;border:1px solid #DDDDDD;padding:20px 30px 60px 30px;margin-top:-10px;min-height:100%;width:calc(100% + 234px);">

<h1>Catalogue standard</h1>

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
<div class="catalogue-list">

	<div class="catalogue-item" id="cat-deposant-tous-sites">
		<div class="catalogue-name">Catalogue par déposant – tous sites</div>
		<form method="get" action="<?= $generate_url ?>">
			<input type="hidden" name="catalogue_type" value="deposant_tous_sites" />
			<input type="hidden" name="output" value="pdf" />
			<input type="hidden" name="deposant" class="inject-deposant" />
			<button type="submit" class="btn-generer">Générer le catalogue</button>
		</form>
	</div>

	<div class="catalogue-item">
		<div class="catalogue-name">Catalogue par déposant par site</div>
		<form method="get" action="<?= $generate_url ?>">
			<input type="hidden" name="catalogue_type" value="deposant_par_site" />
			<input type="hidden" name="output" value="pdf" />
			<input type="hidden" name="deposant" class="inject-deposant" />
			<input type="hidden" name="site" class="inject-site" />
			<button type="submit" class="btn-generer">Générer le catalogue</button>
		</form>
	</div>

	<div class="catalogue-item">
		<div class="catalogue-name">Catalogue des biens disparus</div>
		<form method="get" action="<?= $generate_url ?>">
			<input type="hidden" name="catalogue_type" value="biens_disparus" />
			<input type="hidden" name="output" value="pdf" />
			<input type="hidden" name="deposant" class="inject-deposant" />
			<button type="submit" class="btn-generer">Générer le catalogue</button>
		</form>
	</div>

	<div class="catalogue-item">
		<div class="catalogue-name">Catalogue MTE des objets par site</div>
		<form method="get" action="<?= $generate_url ?>">
			<input type="hidden" name="catalogue_type" value="mte_objets_par_site" />
			<input type="hidden" name="output" value="pdf" />
			<input type="hidden" name="site" class="inject-site" />
			<button type="submit" class="btn-generer">Générer le catalogue</button>
		</form>
	</div>

	<div class="catalogue-item">
		<div class="catalogue-name">Catalogue MTE des mobiliers par site</div>
		<form method="get" action="<?= $generate_url ?>">
			<input type="hidden" name="catalogue_type" value="mte_mobiliers_par_site" />
			<input type="hidden" name="output" value="pdf" />
			<input type="hidden" name="site" class="inject-site" />
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
</style>

<script>
jQuery(document).ready(function() {
	function syncFilters() {
		var deposant = jQuery('#filtre-deposant').val();
		var site = jQuery('#filtre-site').val();
		jQuery('.inject-deposant').val(deposant);
		jQuery('.inject-site').val(site);

		if (site) {
			jQuery('#cat-deposant-tous-sites').hide();
		} else {
			jQuery('#cat-deposant-tous-sites').show();
		}
	}
	jQuery('#filtre-deposant, #filtre-site').on('change', syncFilters);
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
