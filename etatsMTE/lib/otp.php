<?php
/* ----------------------------------------------------------------------
 * app/plugins/etatsMTE/lib/otp.php
 * ----------------------------------------------------------------------
 * Authentification à double facteur par code à usage unique (TOTP, RFC 6238),
 * compatible Google Authenticator.
 *
 * . CollectiveAccess 2.0.8 n'offre aucun mécanisme de
 * second facteur : ni dans le code, ni en base, ni dans les bibliothèques
 * livrées, ni dans la version amont. Tout est donc ici.
 *
 * PRINCIPE DE SÛRETÉ, à ne pas perdre de vue en modifiant ce fichier :
 * un compte qui ne porte PAS de secret se connecte exactement comme avant.
 * Le second facteur est facultatif, compte par compte. Il n'existe aucun
 * réglage global capable de l'imposer à tout le monde d'un coup : c'est
 * délibéré, pour qu'une erreur ne puisse pas enfermer dehors l'ensemble des
 * utilisateurs, production comprise.
 *
 * Le secret est rangé dans ca_users.vars (setVar/getVar), ce qui évite toute
 * modification de schéma et donc tout conflit à la montée de version.
 * ----------------------------------------------------------------------
 */

require_once(__CA_MODELS_DIR__ . '/ca_users.php');

define('__MTE_OTP_VAR__', 'mte_otp_secret');
define('__MTE_OTP_PAS__', 30);        // pas de temps, en secondes (RFC 6238)
define('__MTE_OTP_CHIFFRES__', 6);    // longueur du code
define('__MTE_OTP_FENETRE__', 1);     // tolérance : ±1 pas, soit ±30 s

# ----------------------------------------------------------------------
/**
 * Encode une chaîne binaire en base32 (RFC 4648), sans remplissage.
 * C'est le format attendu par Google Authenticator.
 */
function caMTEOtpBase32Encode(string $donnees) : string {
	$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$bits = '';
	foreach (str_split($donnees) as $octet) {
		$bits .= str_pad(decbin(ord($octet)), 8, '0', STR_PAD_LEFT);
	}
	$sortie = '';
	foreach (str_split($bits, 5) as $morceau) {
		$sortie .= $alphabet[bindec(str_pad($morceau, 5, '0', STR_PAD_RIGHT))];
	}
	return $sortie;
}

# ----------------------------------------------------------------------
/**
 * Décode une chaîne base32 vers sa forme binaire.
 */
function caMTEOtpBase32Decode(string $base32) : string {
	$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$base32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $base32));
	$bits = '';
	foreach (str_split($base32) as $caractere) {
		$position = strpos($alphabet, $caractere);
		if ($position === false) { continue; }
		$bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
	}
	$sortie = '';
	foreach (str_split($bits, 8) as $morceau) {
		if (strlen($morceau) === 8) { $sortie .= chr(bindec($morceau)); }
	}
	return $sortie;
}

# ----------------------------------------------------------------------
/**
 * Fabrique un secret aléatoire, rendu en base32.
 * 20 octets, soit 160 bits : la taille recommandée par la RFC 4226.
 */
function caMTEOtpNouveauSecret() : string {
	return caMTEOtpBase32Encode(random_bytes(20));
}

# ----------------------------------------------------------------------
/**
 * Calcule le code TOTP d'un secret pour un pas de temps donné.
 */
function caMTEOtpCode(string $secret_base32, int $compteur) : string {
	$cle = caMTEOtpBase32Decode($secret_base32);
	if ($cle === '') { return ''; }

	// Le compteur est transmis sur 8 octets, gros-boutiste.
	$compteur_binaire = pack('N*', 0, $compteur);
	$empreinte = hash_hmac('sha1', $compteur_binaire, $cle, true);

	// Troncature dynamique (RFC 4226, §5.3).
	$decalage = ord($empreinte[strlen($empreinte) - 1]) & 0x0F;
	$valeur = ((ord($empreinte[$decalage])     & 0x7F) << 24)
	        | ((ord($empreinte[$decalage + 1]) & 0xFF) << 16)
	        | ((ord($empreinte[$decalage + 2]) & 0xFF) << 8)
	        |  (ord($empreinte[$decalage + 3]) & 0xFF);

	$code = $valeur % pow(10, __MTE_OTP_CHIFFRES__);
	return str_pad((string)$code, __MTE_OTP_CHIFFRES__, '0', STR_PAD_LEFT);
}

# ----------------------------------------------------------------------
/**
 * Vérifie un code saisi, avec une tolérance de ±1 pas pour absorber les
 * décalages d'horloge entre le téléphone et le serveur.
 * La comparaison est à temps constant.
 */
function caMTEOtpVerifier(string $secret_base32, string $code_saisi) : bool {
	$code_saisi = preg_replace('/[^0-9]/', '', $code_saisi);
	if (strlen($code_saisi) !== __MTE_OTP_CHIFFRES__) { return false; }

	$pas_courant = (int)floor(time() / __MTE_OTP_PAS__);
	for ($decalage = -__MTE_OTP_FENETRE__; $decalage <= __MTE_OTP_FENETRE__; $decalage++) {
		$attendu = caMTEOtpCode($secret_base32, $pas_courant + $decalage);
		if ($attendu !== '' && hash_equals($attendu, $code_saisi)) { return true; }
	}
	return false;
}

# ----------------------------------------------------------------------
/**
 * Secret d'un compte, ou chaîne vide s'il n'est pas enrôlé.
 * Accepte un nom d'utilisateur ou un identifiant numérique.
 */
function caMTEOtpSecretUtilisateur($utilisateur) : string {
	$u = new ca_users();
	$charge = is_numeric($utilisateur)
		? $u->load((int)$utilisateur)
		: $u->load(['user_name' => (string)$utilisateur]);
	if (!$charge) { return ''; }
	$secret = $u->getVar(__MTE_OTP_VAR__);
	return is_string($secret) ? trim($secret) : '';
}

# ----------------------------------------------------------------------
/**
 * Le compte est-il enrôlé ? C'est la seule question posée au moment de la
 * connexion : si la réponse est non, rien ne change pour lui.
 */
function caMTEOtpEstEnrole($utilisateur) : bool {
	return caMTEOtpSecretUtilisateur($utilisateur) !== '';
}

# ----------------------------------------------------------------------
/**
 * Enrôle un compte et renvoie son secret. Sans secret fourni, en fabrique un.
 */
function caMTEOtpEnroler($utilisateur, ?string $secret = null) : ?string {
	$u = new ca_users();
	$charge = is_numeric($utilisateur)
		? $u->load((int)$utilisateur)
		: $u->load(['user_name' => (string)$utilisateur]);
	if (!$charge) { return null; }

	$secret = $secret ?: caMTEOtpNouveauSecret();
	$u->setVar(__MTE_OTP_VAR__, $secret);
	$u->update();
	return $u->numErrors() ? null : $secret;
}

# ----------------------------------------------------------------------
/**
 * Retire le second facteur d'un compte. C'est la procédure de secours en cas
 * de perte du téléphone : le compte redevient un compte à mot de passe simple.
 */
function caMTEOtpDesenroler($utilisateur) : bool {
	$u = new ca_users();
	$charge = is_numeric($utilisateur)
		? $u->load((int)$utilisateur)
		: $u->load(['user_name' => (string)$utilisateur]);
	if (!$charge) { return false; }
	$u->setVar(__MTE_OTP_VAR__, '');
	$u->update();
	return !$u->numErrors();
}

# ----------------------------------------------------------------------
/**
 * Adresse otpauth:// à encoder en QR code pour l'enrôlement.
 * L'étiquette sert uniquement à ce que l'agent reconnaisse la bonne ligne
 * dans son application ; elle n'entre pas dans le calcul du code.
 */
function caMTEOtpUriEnrolement(string $nom_utilisateur, string $secret_base32, string $emetteur = 'Mobiliers classés') : string {
	$etiquette = rawurlencode($emetteur) . ':' . rawurlencode($nom_utilisateur);
	return 'otpauth://totp/' . $etiquette
		. '?secret=' . $secret_base32
		. '&issuer=' . rawurlencode($emetteur)
		. '&algorithm=SHA1'
		. '&digits=' . __MTE_OTP_CHIFFRES__
		. '&period=' . __MTE_OTP_PAS__;
}
