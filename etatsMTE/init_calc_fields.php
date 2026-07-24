#!/usr/bin/env php
<?php
/* ----------------------------------------------------------------------
 * init_calc_fields.php — (re)calcule les 4 champs Oui/Non calculés sur ca_objects
 *   calc_inventorie / calc_recole / calc_restaure / calc_restitue
 * Réplique la logique du hook etatsMTEPlugin::updateCalcFields pour l'EXISTANT
 * (le hook ne se déclenche que lors des enregistrements applicatifs).
 *
 * Usage : php init_calc_fields.php [ca_base_dir] [limit] [offset]
 * ---------------------------------------------------------------------- */

if (php_sapi_name() !== 'cli') { die("CLI seulement.\n"); }
$ca_base = $argv[1] ?? '/var/www/mobiliersclasses/collectiveaccess/providence';
$limit   = isset($argv[2]) ? (int)$argv[2] : 0;   // 0 = tous
$offset  = isset($argv[3]) ? (int)$argv[3] : 0;

require_once($ca_base . '/setup.php');
require_once(__CA_MODELS_DIR__ . '/ca_objects.php');
error_reporting(E_ERROR);
ini_set('memory_limit', '2G');
set_time_limit(0);

// champ calculé => element_id de la DATE d'intervention. Un bien est "inventorié / récolé /
// restauré / restitué" seulement s'il porte une DATE RÉELLE (parseable) sur le champ
// correspondant — pas un conteneur créé vide à la migration ni un texte "sansdate"
// (value_decimal1 renseignée = vraie date). Recalé sur les comptages MTE (10/07/2026) :
// inventorié 1262, récolé 276, restauré 0, restitué 71.
$map = [
	'calc_inventorie' => 775, // inventaire_cont > inv_date
	'calc_recole'     => 659, // recolement_inv > der_date_reco
	'calc_restaure'   => 757, // restauration_cont2 > date_restauration_date
	'calc_restitue'   => 738, // restitution_cont2 > restitution_date
];

$o_db = new Db();
$sql = "SELECT object_id FROM ca_objects WHERE deleted = 0 ORDER BY object_id";
if ($limit > 0) { $sql .= " LIMIT {$limit} OFFSET {$offset}"; }
$qr = $o_db->query($sql);

$total = $qr->numRows();
$n = 0; $changed = 0;
while ($qr->nextRow()) {
	$oid = $qr->get('object_id');
	$t = new ca_objects($oid);
	if (!$t->getPrimaryKey()) { continue; }
	$t->setMode(ACCESS_WRITE);
	$dirty = false;
	foreach ($map as $code => $date_eid) {
		$pq = $o_db->query("SELECT 1 FROM ca_attribute_values v JOIN ca_attributes a ON a.attribute_id = v.attribute_id WHERE a.table_num = 57 AND a.row_id = ? AND v.element_id = ? AND v.value_decimal1 IS NOT NULL LIMIT 1", [(int)$oid, (int)$date_eid]);
		$target = $pq->nextRow() ? 3655 : 3656;   // oui_calc / non_calc (item_id)
		$cur = $t->get('ca_objects.' . $code, ['returnAsArray' => true]);   // valeur stockée réelle (pas le défaut)
		$current = (is_array($cur) && count($cur)) ? (int)$cur[0] : 0;
		if ($current !== $target) {
			$t->replaceAttribute([$code => $target], $code);
			$dirty = true;
		}
	}
	if ($dirty) {
		$t->update();
		if (!$t->numErrors()) { $changed++; }
		else { fwrite(STDERR, "obj {$oid}: " . join('; ', $t->getErrors()) . "\n"); }
	}
	$n++;
	if ($n % 100 === 0) { fwrite(STDOUT, "  {$n}/{$total} traités, {$changed} modifiés\n"); }
}
fwrite(STDOUT, "TERMINÉ : {$n} objets parcourus, {$changed} modifiés.\n");
