<?php
/**
 * LOT 7 — Lettre 1 « Impossibilité de remise de rapport » (CDC INRAP 202606116 §9.1)
 * Marché 036SE2025 · D2026-0102 · statut « En suspens »
 *
 * Gabarit : templates/lettre1_en_suspens.docx (dérivé de lettre_garde_inrap.docx)
 * Alimenté par GenererController::Modele13() → $this->getVar('lettre') + $this->getVar('motifs')
 *
 * ATTENTION (piège PhpWord) : cloneBlock() DOIT être appelé AVANT tout setValue portant
 * sur une variable située dans le bloc, sinon la variable est consommée avant clonage.
 */
$L  = $this->getVar('lettre');
$MT = $this->getVar('motifs');          // tableau ordonné des phrases des motifs cochés (« oui »)
$n  = is_array($MT) ? count($MT) : 0;

$tp = new \PhpOffice\PhpWord\TemplateProcessor(
    __CA_APP_DIR__ . "/plugins/etatsInrap/templates/lettre1_en_suspens.docx"
);

// 1) Bloc conditionnel des motifs — AVANT tout setValue.
//    n = nombre de motifs à « oui ». n = 0 supprime proprement le bloc et ses deux balises.
$tp->cloneBlock('MOTIFS', $n, true, true);
for ($i = 1; $i <= $n; $i++) {
    $tp->setValue('MOTIF_TEXTE#' . $i, $MT[$i - 1]);
}

// 2) Variables de fusion (§6.4)
foreach ($L as $vs_var => $vs_val) {
    $tp->setValue($vs_var, $vs_val);
}

$vs_dir  = __CA_APP_DIR__ . '/plugins/etatsInrap/tmp';
$vs_file = 'lettre1_en_suspens_' . preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$this->getVar('idnoCourrier')) . '_' . time() . '.docx';
$tp->saveAs($vs_dir . '/' . $vs_file);

// LOT 12 — preuve de production du courrier, relue par
// GenererController::enSuspensCompteurEnregistrer() APRÈS le rendu. Le compteur de tirages
// n'est incrémenté que si ce fichier existe et n'est pas vide : une génération interrompue
// avant ce point (gabarit illisible, écriture impossible) ne consomme aucun numéro.
$this->setVar('lettreFichierGenere', $vs_dir . '/' . $vs_file);

header("Location: /app/plugins/etatsInrap/tmp/" . $vs_file);

return;
