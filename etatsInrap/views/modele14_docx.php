<?php
/**
 * LOT 7 — Lettre 2 « Responsable absent, sollicitation du contrôle scientifique et technique »
 * (CDC INRAP 202606116 §9.2) · Marché 036SE2025 · D2026-0102 · statut « En suspens »
 *
 * Gabarit : templates/lettre2_en_suspens.docx (dérivé de lettre_garde_inrap.docx)
 * §6.4 : « pour la lettre 2 les deux cas sont d'office donnés » → les deux puces sont EN DUR
 * dans le gabarit, aucun bloc conditionnel.
 */
$L = $this->getVar('lettre');

$tp = new \PhpOffice\PhpWord\TemplateProcessor(
    __CA_APP_DIR__ . "/plugins/etatsInrap/templates/lettre2_en_suspens.docx"
);

foreach ($L as $vs_var => $vs_val) {
    $tp->setValue($vs_var, $vs_val);
}

$vs_dir  = __CA_APP_DIR__ . '/plugins/etatsInrap/tmp';
$vs_file = 'lettre2_en_suspens_' . preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$this->getVar('idnoCourrier')) . '_' . time() . '.docx';
$tp->saveAs($vs_dir . '/' . $vs_file);

// LOT 12 — preuve de production du courrier, relue par
// GenererController::enSuspensCompteurEnregistrer() APRÈS le rendu. Le compteur de tirages
// n'est incrémenté que si ce fichier existe et n'est pas vide : une génération interrompue
// avant ce point (gabarit illisible, écriture impossible) ne consomme aucun numéro.
$this->setVar('lettreFichierGenere', $vs_dir . '/' . $vs_file);

header("Location: /app/plugins/etatsInrap/tmp/" . $vs_file);

return;
