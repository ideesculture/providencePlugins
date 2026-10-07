<?php
/* ----------------------------------------------------------------------
 * app/plugins/etatsMTE/controllers/SecuriteController.php
 * ----------------------------------------------------------------------
 * Écran d'enrôlement en libre-service pour la double authentification (TOTP).
 *
 * Trois garde-fous, à conserver en cas de modification :
 *
 *  1. Un utilisateur n'agit QUE sur son propre compte. L'identifiant vient de
 *     la session, jamais d'un paramètre de la requête.
 *  2. L'activation n'est effective qu'APRÈS saisie d'un code valide. Le secret
 *     reste en attente dans la session tant que l'utilisateur n'a pas prouvé
 *     que son téléphone le reconnaît. Sans cela, on pourrait s'enfermer dehors
 *     en quittant la page sans avoir scanné le QR code.
 *  3. La désactivation exige elle aussi un code valide, pour qu'une session
 *     volée ne puisse pas retirer la protection. En cas de perte du téléphone,
 *     c'est l'outil d'administration en ligne de commande qui débloque.
 * ----------------------------------------------------------------------
 */

require_once(__CA_APP_DIR__ . '/plugins/etatsMTE/lib/otp.php');

class SecuriteController extends ActionController {

	# -------------------------------------------------------
	protected function utilisateur() : ?ca_users {
		if (!$this->request->isLoggedIn()) { return null; }
		$u = new ca_users();
		return $u->load((int)$this->request->getUserID()) ? $u : null;
	}

	# -------------------------------------------------------
	/**
	 * Image PNG du QR code, en data: URI, ou null si la bibliothèque manque.
	 * La saisie manuelle de la clé reste alors possible.
	 */
	protected function qrCode(string $uri) : ?string {
		if (!class_exists('\\Com\\Tecnick\\Barcode\\Barcode')) { return null; }
		try {
			$b = new \Com\Tecnick\Barcode\Barcode();
			$png = $b->getBarcodeObj('QRCODE,M', $uri, -7, -7, 'black', [0, 0, 0, 0])->getPngData();
			return 'data:image/png;base64,' . base64_encode($png);
		} catch (\Throwable $e) {
			return null;
		}
	}

	# -------------------------------------------------------
	protected function afficher(?string $message = null, ?string $type = null) {
		$u = $this->utilisateur();
		if (!$u) { $this->response->setRedirect(caNavUrl($this->request, '', 'system/auth', 'Login')); return; }

		$this->view->setVar('nom_utilisateur', $u->get('user_name'));
		$this->view->setVar('enrole', caMTEOtpEstEnrole((int)$u->getPrimaryKey()));
		$this->view->setVar('message', $message);
		$this->view->setVar('message_type', $type);

		// Un secret en attente de confirmation ? On réaffiche son QR code.
		$en_attente = Session::getVar('mte_otp_secret_en_attente');
		if (is_string($en_attente) && strlen($en_attente)) {
			$uri = caMTEOtpUriEnrolement($u->get('user_name'), $en_attente);
			$this->view->setVar('secret_en_attente', $en_attente);
			$this->view->setVar('secret_lisible', trim(chunk_split($en_attente, 4, ' ')));
			$this->view->setVar('qr_code', $this->qrCode($uri));
			$this->view->setVar('uri_enrolement', $uri);
		}
		$this->render('securite_otp_html.php');
	}

	# -------------------------------------------------------
	public function Index() {
		$this->afficher();
	}

	# -------------------------------------------------------
	/**
	 * Prépare un secret et affiche le QR code. Rien n'est encore enregistré sur
	 * le compte : le secret attend sa confirmation.
	 */
	public function Activer() {
		if (!$this->utilisateur()) { $this->afficher(); return; }
		Session::setVar('mte_otp_secret_en_attente', caMTEOtpNouveauSecret());
		$this->afficher(_t("Scan the QR code with your authenticator app, then enter the code it shows to confirm."), 'info');
	}

	# -------------------------------------------------------
	/**
	 * Confirme l'activation. C'est ici, et seulement ici, que le secret est
	 * réellement posé sur le compte.
	 */
	public function Confirmer() {
		$u = $this->utilisateur();
		if (!$u) { $this->afficher(); return; }

		$secret = Session::getVar('mte_otp_secret_en_attente');
		if (!is_string($secret) || !strlen($secret)) {
			$this->afficher(_t("No activation in progress. Start again."), 'erreur');
			return;
		}
		$code = (string)$this->request->getParameter('otp_code', pString);
		if (!caMTEOtpVerifier($secret, $code)) {
			$this->afficher(_t("That code is not valid. Check the time on your phone and try again."), 'erreur');
			return;
		}
		caMTEOtpEnroler((int)$u->getPrimaryKey(), $secret);
		Session::setVar('mte_otp_secret_en_attente', '');
		$this->afficher(_t("Two-factor authentication is now active on your account."), 'succes');
	}

	# -------------------------------------------------------
	/**
	 * Désactive, sur présentation d'un code valide.
	 */
	public function Desactiver() {
		$u = $this->utilisateur();
		if (!$u) { $this->afficher(); return; }

		$secret = caMTEOtpSecretUtilisateur((int)$u->getPrimaryKey());
		if ($secret === '') { $this->afficher(); return; }

		$code = (string)$this->request->getParameter('otp_code', pString);
		if (!caMTEOtpVerifier($secret, $code)) {
			$this->afficher(_t("That code is not valid. Two-factor authentication remains active."), 'erreur');
			return;
		}
		caMTEOtpDesenroler((int)$u->getPrimaryKey());
		Session::setVar('mte_otp_secret_en_attente', '');
		$this->afficher(_t("Two-factor authentication has been switched off for your account."), 'succes');
	}
	# -------------------------------------------------------
}
