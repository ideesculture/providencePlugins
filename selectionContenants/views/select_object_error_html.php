<?php
/* === D2026-0103 — écran de sélection des contenants : début ===
 * Fragment d'erreur du popup (accès refusé, versement introuvable, etc.).
 * === D2026-0103 — écran de sélection des contenants : fin === */
?>
<div class="selcont-error" style="margin:20px;">
	<?= htmlspecialchars((string)$this->getVar('error'), ENT_QUOTES, 'UTF-8') ?>
</div>
