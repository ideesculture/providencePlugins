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

<h1>Catalogues MTE</h1>

<!-- ===================== A. CATALOGUES STANDARDS ===================== -->
<h2 style="margin-top:30px;color:#2c3e50;border-bottom:2px solid #1ab3c8;padding-bottom:5px;">A. Catalogues standards</h2>

<div style="display:flex;flex-wrap:wrap;gap:20px;margin-top:15px;">

	<!-- Catalogue par déposant – tous sites -->
	<div class="catalogue-card">
		<h3>Catalogue par déposant – tous sites</h3>
		<form method="get" action="<?= $generate_url ?>">
			<input type="hidden" name="catalogue_type" value="deposant_tous_sites" />
			<label>Déposant :</label>
			<select name="deposant" required>
				<option value="">-- Choisir --</option>
				<?php foreach($deposants as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
			<div class="catalogue-buttons">
				<button type="submit" class="btn-catalogue">Afficher</button>
				<button type="submit" name="output" value="pdf" class="btn-catalogue btn-pdf">PDF</button>
			</div>
		</form>
	</div>

	<!-- Catalogue par déposant par site -->
	<div class="catalogue-card">
		<h3>Catalogue par déposant par site</h3>
		<form method="get" action="<?= $generate_url ?>">
			<input type="hidden" name="catalogue_type" value="deposant_par_site" />
			<label>Déposant :</label>
			<select name="deposant" required>
				<option value="">-- Choisir --</option>
				<?php foreach($deposants as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
			<label>Site :</label>
			<select name="site" required>
				<option value="">-- Choisir --</option>
				<?php foreach($sites as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
			<div class="catalogue-buttons">
				<button type="submit" class="btn-catalogue">Afficher</button>
				<button type="submit" name="output" value="pdf" class="btn-catalogue btn-pdf">PDF</button>
			</div>
		</form>
	</div>

	<!-- Catalogue des biens disparus -->
	<div class="catalogue-card">
		<h3>Catalogue des biens disparus</h3>
		<form method="get" action="<?= $generate_url ?>">
			<input type="hidden" name="catalogue_type" value="biens_disparus" />
			<label>Déposant :</label>
			<select name="deposant">
				<option value="">-- Tous --</option>
				<?php foreach($deposants as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
			<div class="catalogue-buttons">
				<button type="submit" class="btn-catalogue">Afficher</button>
				<button type="submit" name="output" value="pdf" class="btn-catalogue btn-pdf">PDF</button>
			</div>
		</form>
	</div>

	<!-- Catalogue MTE des objets par site -->
	<div class="catalogue-card">
		<h3>Catalogue MTE des objets par site</h3>
		<form method="get" action="<?= $generate_url ?>">
			<input type="hidden" name="catalogue_type" value="mte_objets_par_site" />
			<label>Site :</label>
			<select name="site" required>
				<option value="">-- Choisir --</option>
				<?php foreach($sites as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
			<div class="catalogue-buttons">
				<button type="submit" class="btn-catalogue">Afficher</button>
				<button type="submit" name="output" value="pdf" class="btn-catalogue btn-pdf">PDF</button>
			</div>
		</form>
	</div>

	<!-- Catalogue MTE des mobiliers par site -->
	<div class="catalogue-card">
		<h3>Catalogue MTE des mobiliers par site</h3>
		<form method="get" action="<?= $generate_url ?>">
			<input type="hidden" name="catalogue_type" value="mte_mobiliers_par_site" />
			<label>Site :</label>
			<select name="site" required>
				<option value="">-- Choisir --</option>
				<?php foreach($sites as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
			<div class="catalogue-buttons">
				<button type="submit" class="btn-catalogue">Afficher</button>
				<button type="submit" name="output" value="pdf" class="btn-catalogue btn-pdf">PDF</button>
			</div>
		</form>
	</div>
</div>

<!-- ===================== B. CATALOGUES SPÉCIFIQUES ===================== -->
<h2 style="margin-top:40px;color:#2c3e50;border-bottom:2px solid #e67e22;padding-bottom:5px;">B. Catalogues spécifiques</h2>

<div style="display:flex;flex-wrap:wrap;gap:20px;margin-top:15px;">

	<!-- Par déposant + site + bâtiment + étage -->
	<div class="catalogue-card catalogue-specific">
		<h3>Par déposant + site + bâtiment + étage</h3>
		<form method="get" action="<?= $generate_url ?>">
			<input type="hidden" name="catalogue_type" value="specifique_deposant_site_batiment_etage" />
			<label>Déposant :</label>
			<select name="deposant">
				<option value="">-- Tous --</option>
				<?php foreach($deposants as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
			<label>Site :</label>
			<select name="site">
				<option value="">-- Tous --</option>
				<?php foreach($sites as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
			<label>Bâtiment :</label>
			<select name="batiment">
				<option value="">-- Tous --</option>
				<?php foreach($batiments as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
			<label>Étage :</label>
			<select name="etage">
				<option value="">-- Tous --</option>
				<?php foreach($etages as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
			<div class="catalogue-buttons">
				<button type="submit" class="btn-catalogue">Afficher</button>
				<button type="submit" name="output" value="pdf" class="btn-catalogue btn-pdf">PDF</button>
			</div>
		</form>
	</div>

	<!-- Par type (Objet ou Mobilier) -->
	<div class="catalogue-card catalogue-specific">
		<h3>Par type (Objet ou Mobilier)</h3>
		<p class="catalogue-info">Résultat regroupé par site et déposant</p>
		<form method="get" action="<?= $generate_url ?>">
			<input type="hidden" name="catalogue_type" value="specifique_par_type" />
			<label>Type :</label>
			<select name="type_domaine" required>
				<option value="">-- Choisir --</option>
				<?php foreach($types as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
			<div class="catalogue-buttons">
				<button type="submit" class="btn-catalogue">Afficher</button>
				<button type="submit" name="output" value="pdf" class="btn-catalogue btn-pdf">PDF</button>
			</div>
		</form>
	</div>

	<!-- VU / NON VU -->
	<div class="catalogue-card catalogue-specific">
		<h3>Biens VU / NON VU (Constat présence)</h3>
		<p class="catalogue-info">Résultat regroupé par site et déposant</p>
		<form method="get" action="<?= $generate_url ?>">
			<input type="hidden" name="catalogue_type" value="specifique_vu_non_vu" />
			<label>Constat présence :</label>
			<select name="constat" required>
				<option value="">-- Choisir --</option>
				<?php foreach($constats as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
			<div class="catalogue-buttons">
				<button type="submit" class="btn-catalogue">Afficher</button>
				<button type="submit" name="output" value="pdf" class="btn-catalogue btn-pdf">PDF</button>
			</div>
		</form>
	</div>

	<!-- Biens récolés sur une période -->
	<div class="catalogue-card catalogue-specific">
		<h3>Biens récolés sur une période</h3>
		<p class="catalogue-info">Tous déposants (regroupé par site + déposant) ou sélection d'un déposant (regroupé par site)</p>
		<form method="get" action="<?= $generate_url ?>">
			<input type="hidden" name="catalogue_type" value="specifique_recoles_periode" />
			<label>Déposant :</label>
			<select name="deposant">
				<option value="">-- Tous les déposants --</option>
				<?php foreach($deposants as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
			<label>Date début :</label>
			<input type="date" name="date_debut" required />
			<label>Date fin :</label>
			<input type="date" name="date_fin" required />
			<div class="catalogue-buttons">
				<button type="submit" class="btn-catalogue">Afficher</button>
				<button type="submit" name="output" value="pdf" class="btn-catalogue btn-pdf">PDF</button>
			</div>
		</form>
	</div>

	<!-- Biens inventoriés sur une période -->
	<div class="catalogue-card catalogue-specific">
		<h3>Biens inventoriés sur une période</h3>
		<p class="catalogue-info">Tous déposants (regroupé par site + déposant) ou sélection d'un déposant (regroupé par site)</p>
		<form method="get" action="<?= $generate_url ?>">
			<input type="hidden" name="catalogue_type" value="specifique_inventories_periode" />
			<label>Déposant :</label>
			<select name="deposant">
				<option value="">-- Tous les déposants --</option>
				<?php foreach($deposants as $id => $name): ?>
				<option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
				<?php endforeach; ?>
			</select>
			<label>Date début :</label>
			<input type="date" name="date_debut" required />
			<label>Date fin :</label>
			<input type="date" name="date_fin" required />
			<div class="catalogue-buttons">
				<button type="submit" class="btn-catalogue">Afficher</button>
				<button type="submit" name="output" value="pdf" class="btn-catalogue btn-pdf">PDF</button>
			</div>
		</form>
	</div>
</div>

</div>

<style>
.catalogue-card {
	background: #f8f9fa;
	border: 1px solid #dee2e6;
	border-radius: 8px;
	padding: 20px;
	width: 320px;
	box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.catalogue-card.catalogue-specific {
	border-left: 4px solid #e67e22;
}
.catalogue-card:not(.catalogue-specific) {
	border-left: 4px solid #1ab3c8;
}
.catalogue-card h3 {
	margin: 0 0 12px 0;
	font-size: 14px;
	color: #2c3e50;
}
.catalogue-card label {
	display: block;
	margin: 8px 0 3px 0;
	font-size: 12px;
	font-weight: bold;
	color: #555;
}
.catalogue-card select,
.catalogue-card input[type="date"] {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid #ccc;
	border-radius: 4px;
	font-size: 13px;
	box-sizing: border-box;
}
.catalogue-info {
	font-size: 11px;
	color: #888;
	margin: 0 0 8px 0;
	font-style: italic;
}
.catalogue-buttons {
	margin-top: 12px;
	display: flex;
	gap: 8px;
}
.btn-catalogue {
	background-color: #1ab3c8;
	color: white;
	padding: 8px 16px;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: 13px;
	font-weight: bold;
}
.btn-catalogue:hover {
	background-color: #159aad;
}
.btn-catalogue.btn-pdf {
	background-color: #e67e22;
}
.btn-catalogue.btn-pdf:hover {
	background-color: #d35400;
}
</style>
