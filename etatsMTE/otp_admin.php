<?php
/* ----------------------------------------------------------------------
 * app/plugins/etatsMTE/otp_admin.php
 * ----------------------------------------------------------------------
 * Administration du second facteur (TOTP), en ligne de commande.
 *
 *   sudo -u www-data php otp_admin.php etat
 *   sudo -u www-data php otp_admin.php enroler <utilisateur>
 *   sudo -u www-data php otp_admin.php desenroler <utilisateur>
 *   sudo -u www-data php otp_admin.php verifier <utilisateur> <code>
 *
 * « desenroler » est la procédure de secours en cas de perte du téléphone :
 * le compte redevient un compte à mot de passe simple, sans intervention en
 * base de données.
 * ----------------------------------------------------------------------
 */

if (php_sapi_name() !== 'cli') { die("À lancer en ligne de commande.\n"); }

// Le chemin de l'instance est EXIGÉ en premier argument, et ne se déduit pas de
// __DIR__ : en production, app/plugins/etatsMTE est un lien symbolique vers le
// dépôt partagé providencePlugins, si bien que __DIR__ ne désigne pas l'instance.
// Le même répertoire de plugin pouvant servir plusieurs instances, il faut de
// toute façon dire explicitement sur laquelle on agit.
$instance = $argv[1] ?? '';
if ($instance === '' || !is_file($instance . '/setup.php')) {
	fwrite(STDERR,
		"Usage : php otp_admin.php <chemin_instance> <commande> [arguments]\n\n" .
		"  commandes : etat | enroler <utilisateur> | desenroler <utilisateur> | verifier <utilisateur> <code>\n\n" .
		"  exemple :\n" .
		"    sudo -u www-data php otp_admin.php /var/www/mobiliersclasses/collectiveaccess/providence etat\n\n");
	exit(1);
}

define("__CA_APP_TYPE__", "PROVIDENCE");
require_once($instance . '/setup.php');
require_once(__CA_APP_DIR__ . '/plugins/etatsMTE/lib/otp.php');

$commande = $argv[2] ?? 'etat';
$cible    = $argv[3] ?? null;

function mte_otp_sortie_qr(string $uri, string $fichier) : ?string {
	// tc-lib-barcode est livré avec CollectiveAccess ; s'il manque, on se
	// contente de la saisie manuelle du secret, qui suffit à Google Authenticator.
	if (!class_exists('\\Com\\Tecnick\\Barcode\\Barcode')) { return null; }
	try {
		$b = new \Com\Tecnick\Barcode\Barcode();
		$code = $b->getBarcodeObj('QRCODE,M', $uri, -6, -6, 'black', [0, 0, 0, 0]);
		file_put_contents($fichier, $code->getPngData());
		return file_exists($fichier) ? $fichier : null;
	} catch (\Throwable $e) {
		return null;
	}
}

switch ($commande) {

	case 'enroler':
		if (!$cible) { die("Usage : enroler <utilisateur>\n"); }
		$secret = caMTEOtpEnroler($cible);
		if (!$secret) { die("Compte introuvable, ou enregistrement refusé : $cible\n"); }
		$uri = caMTEOtpUriEnrolement($cible, $secret);
		$png = sys_get_temp_dir() . '/otp_' . preg_replace('/[^A-Za-z0-9_-]/', '', $cible) . '.png';
		$qr  = mte_otp_sortie_qr($uri, $png);

		echo "\n  Compte enrôlé : $cible\n\n";
		echo "  Clé à saisir dans Google Authenticator (« Saisir une clé de configuration ») :\n";
		echo "      " . chunk_split($secret, 4, ' ') . "\n\n";
		echo "  Type de clé : « Par code temporel ».\n\n";
		echo "  Adresse otpauth, si vous préférez fabriquer le QR code vous-même :\n      $uri\n\n";
		if ($qr) { echo "  QR code écrit dans : $qr\n\n"; }
		echo "  Code valable à cet instant : " . caMTEOtpCode($secret, (int)floor(time() / __MTE_OTP_PAS__)) . "\n";
		echo "  (il change toutes les " . __MTE_OTP_PAS__ . " secondes)\n\n";
		break;

	case 'desenroler':
		if (!$cible) { die("Usage : desenroler <utilisateur>\n"); }
		echo caMTEOtpDesenroler($cible)
			? "  Second facteur retiré de : $cible\n  Ce compte se connecte de nouveau avec son seul mot de passe.\n"
			: "  Échec : compte introuvable ou enregistrement refusé ($cible)\n";
		break;

	case 'verifier':
		$code = $argv[4] ?? '';
		if (!$cible || $code === '') { die("Usage : verifier <utilisateur> <code>\n"); }
		$secret = caMTEOtpSecretUtilisateur($cible);
		if ($secret === '') { die("  Ce compte n'est pas enrôlé : $cible\n"); }
		echo caMTEOtpVerifier($secret, $code) ? "  Code ACCEPTÉ\n" : "  Code REFUSÉ\n";
		break;

	case 'etat':
	default:
		$m = new mysqli(__CA_DB_HOST__, __CA_DB_USER__, __CA_DB_PASSWORD__, __CA_DB_DATABASE__);
		$m->set_charset('utf8mb4');
		$r = $m->query("SELECT user_name FROM ca_users WHERE active = 1 AND userclass <> 255 ORDER BY user_id");
		echo "\n  Comptes actifs et état du second facteur :\n\n";
		$n = 0;
		while ($x = $r->fetch_assoc()) {
			$enrole = caMTEOtpEstEnrole($x['user_name']);
			$n += $enrole ? 1 : 0;
			printf("      %-22s %s\n", $x['user_name'], $enrole ? 'ENRÔLÉ' : '—');
		}
		echo "\n  $n compte(s) enrôlé(s). Les autres se connectent avec leur seul mot de passe.\n\n";
		break;
}
