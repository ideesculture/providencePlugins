<?php
/* === D2026-0103 — écran de sélection des contenants : début ===
 * Réponse JSON de l'action Validate.
 * === D2026-0103 — écran de sélection des contenants : fin === */
header('Content-Type: application/json; charset=utf-8');
print json_encode($this->getVar('json_payload'), JSON_UNESCAPED_UNICODE);
