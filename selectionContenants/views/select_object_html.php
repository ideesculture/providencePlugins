<?php
/* ----------------------------------------------------------------------
 * plugins/selectionContenants/views/select_object_html.php
 * === D2026-0103 — écran de sélection des contenants : début ===
 *
 * Fragment HTML du popup (chargé en AJAX dans #caSelContPanelContent).
 * Ergonomie (spécification Gautier) :
 *  - contenants sur plusieurs colonnes (grille), regroupés par opération
 *    (repliable) ;
 *  - grande zone de recherche + filtres type / contenu appliqués à
 *    l'AFFICHAGE uniquement, sans rechargement ;
 *  - la sélection vit en mémoire navigateur (objet selcontSel) : elle
 *    SURVIT aux filtrages et aux recherches ; validation unique ;
 *  - colonnes affichables/masquables, volume cumulé recalculé à la volée,
 *    lien « Voir la fiche », pré-cochage des contenants déjà rattachés
 *    (repris de l'ancienne vue oldPlugins/BordereauVersement).
 * Aucune bibliothèque externe : jQuery de l'instance uniquement (pas de
 * CDN — la production INRAP est derrière un pare-feu).
 * === D2026-0103 — écran de sélection des contenants : fin ===
 * ----------------------------------------------------------------------
 */

$movement_id    = (int)$this->getVar('movement_id');
$movement_idno  = $this->getVar('movement_idno');
$movement_label = $this->getVar('movement_label');
$ops            = $this->getVar('ops');
$contenants     = $this->getVar('contenants');
$linked_ids     = $this->getVar('linked_ids');
$hors_ops       = $this->getVar('hors_ops');
$type_map       = $this->getVar('type_map');      // code => item_id
$item_labels    = $this->getVar('item_labels');   // item_id => libellé
$has_content    = $this->getVar('has_content');   // object_id => true
$validate_url   = $this->getVar('validate_url');
$editor_url_base = $this->getVar('editor_url_base');
$screen132_url  = $this->getVar('screen132_url');

$type_code_by_id = array_flip($type_map);
$e = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

// Types effectivement présents à l'écran (pour le filtre)
$types_present = array();
foreach ($contenants as $c) { $types_present[(int)$c['type_id']] = true; }

// Rendu d'une carte contenant (fonction locale, réutilisée par groupe)
$render_card = function($oid) use ($contenants, $has_content, $type_code_by_id, $item_labels, $linked_ids, $editor_url_base, $e) {
	$c = $contenants[$oid];
	$type_id = (int)$c['type_id'];
	$type_code = $type_code_by_id[$type_id] ?? ('type_'.$type_id);
	$type_label = $item_labels[$type_id] ?? $type_code;
	$vol = isset($c['volume']) ? (float)$c['volume'] : 0.0;
	$vol_disp = isset($c['volume']) ? str_replace('.', ',', rtrim(rtrim(number_format($vol, 3, '.', ''), '0'), '.')) : '';
	$refs = array(); foreach (($c['referentiel_items'] ?? array()) as $i) { $refs[] = $item_labels[$i] ?? ''; }
	$mats = array(); foreach (($c['inrap_materiaux_items'] ?? array()) as $i) { $mats[] = $item_labels[$i] ?? ''; }
	$desc = implode(' — ', $c['description'] ?? array());
	$avec = !empty($has_content[$oid]);
	$checked = in_array($oid, $linked_ids) ? ' checked="checked"' : '';
	$search = mb_strtolower(implode(' ', array($c['idno'], $type_label, implode(' ', $refs), $desc, implode(' ', $mats))), 'UTF-8');
	$fiche = $editor_url_base.'/object_id/'.(int)$oid;

	$h  = '<div class="selcont-card'.($avec ? ' selcont-avec' : ' selcont-sans').'" data-oid="'.(int)$oid.'" data-type="'.$e($type_code).'" data-content="'.($avec ? '1' : '0').'" data-search="'.$e($search).'">';
	$h .= '<label class="selcont-main"><input type="checkbox" class="selcont-cb" data-oid="'.(int)$oid.'" data-vol="'.$e($vol).'"'.$checked.'/> <span class="selcont-idno">'.$e($c['idno']).'</span></label>';
	$h .= '<span class="selcont-field selcont-f-type selcont-badge">'.$e($type_label).'</span>';
	$h .= '<span class="selcont-contentflag">'.($avec ? 'Au moins un enregistrement lié' : 'Sans autre enregistrement lié').'</span>';
	if (sizeof($refs)) { $h .= '<div class="selcont-field selcont-f-ref"><b>Référentiels :</b> '.$e(implode(', ', $refs)).'</div>'; }
	$h .= '<div class="selcont-field selcont-f-vol"><b>Volume :</b> '.($vol_disp !== '' ? $e($vol_disp).' m³' : '—').'</div>';
	if ($desc !== '') { $h .= '<div class="selcont-field selcont-f-desc"><b>Description :</b> '.$e(mb_strimwidth($desc, 0, 220, '…', 'UTF-8')).'</div>'; }
	if (sizeof($mats)) { $h .= '<div class="selcont-field selcont-f-mat"><b>Matière :</b> '.$e(implode(', ', $mats)).'</div>'; }
	$h .= '<a class="selcont-fiche" href="'.$e($fiche).'" target="_blank" rel="noopener">Voir la fiche</a>';
	$h .= '</div>';
	return $h;
};
?>
<div id="selcont-root">
	<div class="selcont-head">
		<div class="selcont-title">
			<?= $e($movement_label) ?> <span class="selcont-mvtidno">(n° <?= $e($movement_idno) ?>)</span>
		</div>

		<input type="text" id="selcont-search" placeholder="Rechercher un contenant (n° d'inventaire, description, matière, référentiel…)" autocomplete="off"/>

		<div class="selcont-filters">
			<label>Type de contenant :
				<select id="selcont-filter-type">
					<option value="">Tous les types</option>
					<?php foreach ($type_map as $vs_code => $vn_tid) {
						if (empty($types_present[$vn_tid])) { continue; }
						print '<option value="'.$e($vs_code).'">'.$e($item_labels[$vn_tid] ?? $vs_code).'</option>';
					} ?>
				</select>
			</label>
			<label>Contenu :
				<select id="selcont-filter-content">
					<option value="">Tous les contenants</option>
					<option value="0">Sans autre enregistrement lié</option>
					<option value="1">Avec au moins un enregistrement lié</option>
				</select>
			</label>
			<span class="selcont-filternote">Les filtres n'agissent que sur l'affichage : la sélection est conservée.</span>
		</div>

		<div class="selcont-columns">
			Colonnes :
			<label><input type="checkbox" class="selcont-coltoggle" data-col="type" checked/> Type</label>
			<label><input type="checkbox" class="selcont-coltoggle" data-col="ref" checked/> Référentiels</label>
			<label><input type="checkbox" class="selcont-coltoggle" data-col="vol" checked/> Volume</label>
			<label><input type="checkbox" class="selcont-coltoggle" data-col="desc" checked/> Description</label>
			<label><input type="checkbox" class="selcont-coltoggle" data-col="mat" checked/> Matière</label>
		</div>

		<div class="selcont-bar">
			<span id="selcont-counters"></span>
			<span class="selcont-actions">
				<a href="#" id="selcont-clear">Tout décocher</a>
				<button type="button" id="selcont-validate">Valider la sélection</button>
			</span>
		</div>
		<div id="selcont-message" style="display:none;"></div>
	</div>

	<div class="selcont-body">
	<?php if (!sizeof($contenants)) { ?>
		<div class="selcont-empty">Aucun contenant n'est rattaché aux opérations de ce versement.</div>
	<?php } ?>
	<?php foreach ($ops as $vn_cid => $va_op) { ?>
		<div class="selcont-group" data-cid="<?= (int)$vn_cid ?>">
			<h3 class="selcont-grouphead">
				<span class="selcont-caret">▼</span>
				<?= $e($va_op['idno']) ?> — <?= $e($va_op['label'] ?: 'Opération #'.$vn_cid) ?>
				<span class="selcont-groupcount"></span>
			</h3>
			<div class="selcont-grid">
				<?php foreach ($va_op['contenants'] as $vn_oid) { print $render_card($vn_oid); } ?>
				<?php if (!sizeof($va_op['contenants'])) { print '<div class="selcont-empty">Aucun contenant sur cette opération.</div>'; } ?>
			</div>
		</div>
	<?php } ?>
	<?php if (sizeof($hors_ops)) { ?>
		<div class="selcont-group" data-cid="0">
			<h3 class="selcont-grouphead">
				<span class="selcont-caret">▼</span>
				Contenants déjà rattachés au versement, hors des opérations listées
				<span class="selcont-groupcount"></span>
			</h3>
			<div class="selcont-grid">
				<?php foreach ($hors_ops as $vn_oid) { print $render_card($vn_oid); } ?>
			</div>
		</div>
	<?php } ?>
	</div>
</div>

<script type="text/javascript">
jQuery(function($) {
	// =====================================================================
	// D2026-0103 — la sélection vit ICI, en mémoire, indépendamment des
	// filtres : cocher, filtrer, cocher encore, valider une seule fois.
	// =====================================================================
	var selcontSel = {};	// object_id -> volume (float)

	// État initial : contenants déjà rattachés (cases pré-cochées côté PHP)
	$('#selcont-root .selcont-cb:checked').each(function() {
		selcontSel[$(this).data('oid')] = parseFloat($(this).data('vol')) || 0;
	});

	function fmtVol(v) {
		return (Math.round(v * 1000) / 1000).toString().replace('.', ',');
	}
	function refreshCounters() {
		var nsel = Object.keys(selcontSel).length, vol = 0;
		for (var k in selcontSel) { vol += selcontSel[k]; }
		var nvis = $('#selcont-root .selcont-card:visible').length;
		var ntot = $('#selcont-root .selcont-card').length;
		$('#selcont-counters').html(
			'<b>' + nsel + '</b> contenant(s) sélectionné(s) — volume cumulé <b>' + fmtVol(vol) + '</b> m³' +
			' · ' + nvis + '/' + ntot + ' affiché(s)');
		$('#selcont-root .selcont-group').each(function() {
			var $g = $(this), vis = $g.find('.selcont-card:visible').length,
				tot = $g.find('.selcont-card').length, sel = 0;
			$g.find('.selcont-card').each(function() { if (selcontSel[$(this).data('oid')] !== undefined) { sel++; } });
			$g.find('.selcont-groupcount').text('(' + vis + '/' + tot + ' affiché(s), ' + sel + ' sélectionné(s))');
		});
	}

	// ------- cases à cocher : mise à jour de la sélection (jamais des filtres)
	$('#selcont-root').on('change', '.selcont-cb', function() {
		var oid = $(this).data('oid'), vol = parseFloat($(this).data('vol')) || 0, on = this.checked;
		if (on) { selcontSel[oid] = vol; } else { delete selcontSel[oid]; }
		// un même contenant peut figurer dans plusieurs opérations : on synchronise ses cases
		$('#selcont-root .selcont-cb[data-oid="' + oid + '"]').prop('checked', on);
		refreshCounters();
	});

	// ------- filtres d'AFFICHAGE (recherche + type + contenu) — ne touchent pas aux cases
	function applyFilters() {
		var q = $.trim($('#selcont-search').val()).toLowerCase(),
			ft = $('#selcont-filter-type').val(),
			fc = $('#selcont-filter-content').val();
		$('#selcont-root .selcont-card').each(function() {
			var $c = $(this), show = true;
			if (q && String($c.data('search')).indexOf(q) === -1) { show = false; }
			if (show && ft && ($c.data('type') !== ft)) { show = false; }
			if (show && (fc !== '') && (String($c.data('content')) !== fc)) { show = false; }
			$c.toggle(show);
		});
		refreshCounters();
	}
	$('#selcont-search').on('input', applyFilters);
	$('#selcont-filter-type,#selcont-filter-content').on('change', applyFilters);

	// ------- colonnes affichables/masquables
	$('.selcont-coltoggle').on('change', function() {
		$('#selcont-root').toggleClass('selcont-hide-' + $(this).data('col'), !this.checked);
	});

	// ------- groupes repliables par opération
	$('#selcont-root').on('click', '.selcont-grouphead', function() {
		var $g = $(this).closest('.selcont-group');
		$g.toggleClass('selcont-collapsed');
		$(this).find('.selcont-caret').text($g.hasClass('selcont-collapsed') ? '▶' : '▼');
	});

	// ------- tout décocher
	$('#selcont-clear').on('click', function() {
		selcontSel = {};
		$('#selcont-root .selcont-cb').prop('checked', false);
		refreshCounters();
		return false;
	});

	// ------- validation unique
	$('#selcont-validate').on('click', function() {
		var $btn = $(this).prop('disabled', true).text('Enregistrement…');
		$.ajax({
			url: <?= json_encode($validate_url) ?>,
			method: 'POST',
			dataType: 'json',
			data: {
				movement_id: <?= (int)$movement_id ?>,
				objects: Object.keys(selcontSel).join(';')
			}
		}).done(function(r) {
			if (r && r.ok) {
				$('#selcont-message').attr('class', 'selcont-ok').html(
					'Sélection enregistrée : <b>' + r.added + '</b> rattachement(s) ajouté(s), <b>' + r.removed +
					'</b> retiré(s), <b>' + r.total + '</b> contenant(s) rattaché(s) au versement.<br/>' +
					'Le recalcul (types « versé », statuts, titre) sera fait par le traitement automatique dans les prochaines minutes.<br/>' +
					'<a href="<?= $e($screen132_url) ?>">Recharger la fiche versement (écran de saisie)</a> — ' +
					'<a href="#" onclick="jQuery(document).trigger(\'selcont:close\');return false;">Fermer cette fenêtre</a>'
				).show();
				jQuery(document).trigger('selcont:validated');
			} else {
				$('#selcont-message').attr('class', 'selcont-error').html(
					'Erreur à l\'enregistrement :<br/>' +
					((r && r.errors && r.errors.length) ? r.errors.join('<br/>') : (r && r.error ? r.error : 'réponse inattendue'))
				).show();
			}
		}).fail(function() {
			$('#selcont-message').attr('class', 'selcont-error')
				.text('Erreur réseau : la sélection n\'a pas été enregistrée.').show();
		}).always(function() {
			$btn.prop('disabled', false).text('Valider la sélection');
		});
		return false;
	});

	refreshCounters();
});
</script>
