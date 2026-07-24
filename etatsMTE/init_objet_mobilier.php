#!/usr/bin/env php
<?php
/* ----------------------------------------------------------------------
 * init_objet_mobilier.php — (re)calcule la métadonnée calc_objet_mobilier
 *   sur ca_objects, à partir de la dénomination (mapping conf/etatsMTE.conf).
 * Réplique la logique de etatsMTEPlugin::updateObjetMobilier pour l'EXISTANT
 * (le hook ne se déclenche que lors des enregistrements applicatifs).
 *
 * Usage : php init_objet_mobilier.php [ca_base_dir] [limit] [offset]
 * ---------------------------------------------------------------------- */

if (php_sapi_name() !== 'cli') { die("CLI seulement.\n"); }
$ca_base = $argv[1] ?? '/var/www/mobiliersclasses/collectiveaccess/providence';
$limit   = isset($argv[2]) ? (int)$argv[2] : 0;
$offset  = isset($argv[3]) ? (int)$argv[3] : 0;

require_once($ca_base . '/setup.php');
require_once(__CA_MODELS_DIR__ . '/ca_objects.php');
error_reporting(E_ERROR);
ini_set('memory_limit', '2G');
set_time_limit(0);

$conf = Configuration::load($ca_base . '/app/plugins/etatsMTE/conf/etatsMTE.conf');
$objet_ids = array_map('intval', (array)$conf->getList('objet_denomination_ids'));
$mob_ids   = array_map('intval', (array)$conf->getList('mobilier_denomination_ids'));
$target_objet = (int)$conf->get('om_item_objet');
$target_mob   = (int)$conf->get('om_item_mobilier');

$o_db = new Db();
$sql = "SELECT object_id FROM ca_objects WHERE deleted = 0 ORDER BY object_id";
if ($limit > 0) { $sql .= " LIMIT {$limit} OFFSET {$offset}"; }
$qr = $o_db->query($sql);

$total = $qr->numRows();
$n = 0; $changed = 0; $unclassified = 0;
while ($qr->nextRow()) {
	$oid = $qr->get('object_id');
	$t = new ca_objects($oid);
	if (!$t->getPrimaryKey()) { continue; }
	$n++;

	$va_denom = $t->get('ca_objects.denomination', ['returnAsArray' => true]);
	$vn_denom = (is_array($va_denom) && count($va_denom)) ? (int)$va_denom[0] : 0;
	if (!$vn_denom) { continue; }

	$target = 0;
	if (in_array($vn_denom, $objet_ids, true))    { $target = $target_objet; }
	elseif (in_array($vn_denom, $mob_ids, true))  { $target = $target_mob; }
	if (!$target) { $unclassified++; continue; }

	$cur = $t->get('ca_objects.calc_objet_mobilier', ['returnAsArray' => true]);
	$current = (is_array($cur) && count($cur)) ? (int)$cur[0] : 0;
	if ($current !== $target) {
		$t->setMode(ACCESS_WRITE);
		$t->replaceAttribute(['calc_objet_mobilier' => $target], 'calc_objet_mobilier');
		$t->update();
		if (!$t->numErrors()) { $changed++; }
		else { fwrite(STDERR, "obj {$oid}: " . join('; ', $t->getErrors()) . "\n"); }
	}
	if ($n % 200 === 0) { fwrite(STDOUT, "  {$n}/{$total} parcourus, {$changed} modifiés, {$unclassified} non classés\n"); }
}
fwrite(STDOUT, "TERMINÉ : {$n} objets, {$changed} modifiés, {$unclassified} dénominations non classées (ou sans dénomination classable).\n");
