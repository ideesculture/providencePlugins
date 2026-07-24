<?php
$search_url = $this->getVar('search_url');
$query      = $this->getVar('query');
?>
<div class="ra-loading">
	<div class="ra-spinner"></div>
	<div class="ra-loading-text">Recherche en cours…</div>
	<?php if ($query === '') { ?>
	<div class="ra-loading-sub">Aucun critère saisi — affichage de tous les objets.</div>
	<?php } ?>
	<noscript>
		<p><a href="<?= htmlspecialchars($search_url) ?>">Cliquez ici pour afficher les résultats</a></p>
	</noscript>
</div>

<style>
.ra-loading { display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:340px; text-align:center; font-family:'Marianne','Marianne-Light',sans-serif; }
.ra-spinner { width:54px; height:54px; border:6px solid #e0e0ec; border-top-color:#313178; border-radius:50%; animation:ra-spin 0.9s linear infinite; }
.ra-loading-text { margin-top:20px; font-size:16px; font-weight:bold; color:#313178; }
.ra-loading-sub { margin-top:8px; font-size:13px; color:#777; }
@keyframes ra-spin { to { transform:rotate(360deg); } }
</style>

<script type="text/javascript">
	// C5/RA — redirection automatique vers la recherche simple avec la requête construite
	(function() {
		var url = <?= json_encode($search_url) ?>;
		setTimeout(function() { window.location.href = url; }, 150);
	})();
</script>
</content>
