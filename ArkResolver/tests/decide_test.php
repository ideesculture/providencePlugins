<?php
/* ----------------------------------------------------------------------
 * Tests unitaires de ResolveController::decide() et ::normalizeName().
 * Sans base de données ni serveur web : php tests/decide_test.php
 * Code de sortie 0 si tous les tests passent, 1 sinon.
 * ----------------------------------------------------------------------
 */
define('__CA_LIB_DIR__', __DIR__.'/stubs');
define('__CA_APP_DIR__', __DIR__.'/stubs');
define('__CA_MODELS_DIR__', __DIR__.'/stubs/models');
require dirname(__DIR__).'/controllers/ResolveController.php';

$ok = 0; $ko = 0;
function check($label, $got, $exp) {
	global $ok, $ko;
	$pass = true;
	foreach ($exp as $k => $v) { if (!array_key_exists($k, $got) || $got[$k] !== $v) { $pass = false; } }
	print ($pass ? "OK     " : "ÉCHEC  ")."$label -> ".json_encode($got, JSON_UNESCAPED_UNICODE)."\n";
	$pass ? $ok++ : $ko++;
}
function row($id, $deleted, $access) { return array('id' => $id, 'deleted' => $deleted, 'access' => $access); }

$public = array(1);		// valeurs d'accès publiques (exemple)

// decide()
check('aucune correspondance',            ResolveController::decide(array(), $public), array('status' => 404));
check('une fiche publique',               ResolveController::decide(array(row(5, 0, 1)), $public), array('status' => 302, 'id' => 5, 'warning' => null));
check('une fiche non publique',           ResolveController::decide(array(row(5, 0, 0)), $public), array('status' => 404));
check('une fiche supprimée',              ResolveController::decide(array(row(5, 1, 1)), $public), array('status' => 410));
check('deux fiches supprimées',           ResolveController::decide(array(row(5, 1, 1), row(6, 1, 0)), $public), array('status' => 410));
check('non publique + supprimée',         ResolveController::decide(array(row(5, 0, 0), row(6, 1, 1)), $public), array('status' => 404));
check('publique + supprimée',             ResolveController::decide(array(row(5, 0, 1), row(6, 1, 1)), $public), array('status' => 302, 'id' => 5));
check('publique + non publique',          ResolveController::decide(array(row(5, 0, 1), row(7, 0, 0)), $public), array('status' => 302, 'id' => 5));
check('deux fiches publiques',            ResolveController::decide(array(row(5, 0, 1), row(7, 0, 1)), $public), array('status' => 409, 'ids' => array(5, 7)));
check('accès non contrôlé (liste vide)',  ResolveController::decide(array(row(5, 0, 0)), array()), array('status' => 302, 'id' => 5));

// normalizeName() : retire les tirets, rejette ce qui n'est pas un nom ARK
$cases = array(
	array('x6-np1-wh8k',      'x6np1wh8k'),
	array('x6np1wh8k',        'x6np1wh8k'),
	array('x6-np1-wh8k/',     'x6np1wh8k'),
	array('b2c3/d4.e5',       'b2c3/d4.e5'),
	array("' OR 1=1",         null),
	array('',                 null),
	array('---',              null),
	array(str_repeat('a', 129), null),
	array("a\0b",             null),
	array('a b',              null),
	array('a<b>',             null),
);
foreach ($cases as $c) {
	list($in, $exp) = $c;
	$got = ResolveController::normalizeName($in);
	$pass = ($got === $exp);
	$shown = strlen($in) > 20 ? substr($in, 0, 8).'…' : $in;
	print ($pass ? "OK     " : "ÉCHEC  ")."normalizeName(".json_encode($shown, JSON_UNESCAPED_UNICODE).") -> ".json_encode($got)."\n";
	$pass ? $ok++ : $ko++;
}

print "\n$ok OK, $ko ÉCHEC\n";
exit($ko ? 1 : 0);
