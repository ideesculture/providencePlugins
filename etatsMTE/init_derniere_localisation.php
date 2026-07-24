#!/usr/bin/env php
<?php
/* ----------------------------------------------------------------------
 * init_derniere_localisation.php — (re)calcule la métadonnée calc_derniere_localisation
 * pour l'EXISTANT (le hook etatsMTEPlugin::updateDerniereLocalisation ne se déclenche
 * qu'aux enregistrements applicatifs).
 *
 * Règle : localisation rattachée à l'événement DATÉ le plus récent parmi ceux qui
 * portent un lieu — dépôt (date_depot -> ca_objects.site), inventaire
 * (inventaire_cont.inv_date -> inv_site/…), restitution (restitution_cont2.restitution_date
 * -> der_loc_cont.*). Restauration et récolement ignorés (pas de lieu).
 *
 * Usage : php init_derniere_localisation.php [ca_base_dir] [limit] [offset]
 * Puis  : caUtils process-indexing-queue
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

$DD = ['returnAsArray' => true, 'getDirectDate' => true];
$DT = ['returnAsArray' => true, 'convertCodesToDisplayText' => true];

function mkLoc($parts, $i) {
	$b = [];
	foreach ($parts as $a) { $v = (is_array($a) && isset($a[$i])) ? trim((string)$a[$i]) : ''; if ($v !== '') { $b[] = $v; } }
	return implode(' › ', $b);
}
function computeLoc($t, $DD, $DT) {
	$best_d = null; $best = '';
	$cons = function($dates, $parts) use (&$best_d, &$best) {
		if (!is_array($dates)) { return; }
		foreach ($dates as $i => $d) { $d = (float)$d; if ($d <= 0) { continue; }
			$loc = mkLoc($parts, $i); if ($loc === '') { continue; }
			if ($best_d === null || $d > $best_d) { $best_d = $d; $best = $loc; } }
	};
	$site = mkLoc([$t->get('ca_objects.site.site_nom1',$DT),$t->get('ca_objects.site.site_batiment1',$DT),$t->get('ca_objects.site.site_etage',$DT),$t->get('ca_objects.site.site_piece',$DT)], 0);
	if ($site !== '') { $dep = $t->get('ca_objects.date_depot',$DD); if (is_array($dep)) { foreach ($dep as $d) { $cons([$d],[[$site]]); } } }
	$cons($t->get('ca_objects.inventaire_cont.inv_date',$DD), [$t->get('ca_objects.inventaire_cont.inv_site',$DT),$t->get('ca_objects.inventaire_cont.inv_site_bat',$DT),$t->get('ca_objects.inventaire_cont.inv_etage',$DT),$t->get('ca_objects.inventaire_cont.inv_piece',$DT)]);
	$cons($t->get('ca_objects.restitution_cont2.restitution_date',$DD), [$t->get('ca_objects.restitution_cont2.der_loc_cont.restauration_site',$DT),$t->get('ca_objects.restitution_cont2.der_loc_cont.rest_batiment',$DT),$t->get('ca_objects.restitution_cont2.der_loc_cont.restauration_etage',$DT),$t->get('ca_objects.restitution_cont2.der_loc_cont.restauration_piece',$DT)]);
	return $best;
}

$o_db = new Db();
$sql = "SELECT object_id FROM ca_objects WHERE deleted = 0 ORDER BY object_id";
if ($limit > 0) { $sql .= " LIMIT {$limit} OFFSET {$offset}"; }
$qr = $o_db->query($sql);
$total = $qr->numRows(); $n = 0; $changed = 0;
while ($qr->nextRow()) {
	$oid = $qr->get('object_id');
	$t = new ca_objects($oid);
	if (!$t->getPrimaryKey()) { continue; }
	$new = computeLoc($t, $DD, $DT);
	$cur = trim((string)$t->get('ca_objects.calc_derniere_localisation'));
	if ($cur !== $new) {
		$t->setMode(ACCESS_WRITE);
		$t->replaceAttribute(['calc_derniere_localisation' => $new], 'calc_derniere_localisation');
		$t->update();
		if (!$t->numErrors()) { $changed++; }
		else { fwrite(STDERR, "obj {$oid}: " . join('; ', $t->getErrors()) . "\n"); }
	}
	$n++;
	if ($n % 200 === 0) { fwrite(STDOUT, "  {$n}/{$total} traités, {$changed} modifiés\n"); }
}
fwrite(STDOUT, "TERMINÉ : {$n} objets parcourus, {$changed} modifiés.\n");
