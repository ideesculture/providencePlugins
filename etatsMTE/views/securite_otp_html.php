<?php
/* ----------------------------------------------------------------------
 * app/plugins/etatsMTE/views/securite_otp_html.php
 * Écran d'enrôlement en double authentification.
 * ----------------------------------------------------------------------
 */
$enrole          = (bool)$this->getVar('enrole');
$secret_attente  = $this->getVar('secret_en_attente');
$secret_lisible  = $this->getVar('secret_lisible');
$qr              = $this->getVar('qr_code');
$uri             = $this->getVar('uri_enrolement');
$message         = $this->getVar('message');
$type            = $this->getVar('message_type');
$nom             = $this->getVar('nom_utilisateur');

$couleurs = ['succes' => '#1e7a34', 'erreur' => '#b0312e', 'info' => '#002060'];
?>
<div class="sectionBox" style="max-width:720px;">
	<h1><?= _t("Two-factor authentication"); ?></h1>

	<p style="color:#555;">
		<?= _t("Account"); ?> : <strong><?= htmlspecialchars((string)$nom); ?></strong>
	</p>

<?php if ($message) { ?>
	<div style="margin:14px 0; padding:10px 12px; border-left:4px solid <?= $couleurs[$type] ?? '#888'; ?>; background:#f6f6f6;">
		<?= htmlspecialchars((string)$message); ?>
	</div>
<?php } ?>

<?php if ($secret_attente) { ?>
	<!-- Activation en cours : le secret n'est PAS encore posé sur le compte. -->
	<h2><?= _t("Step 1 — add the account to your app"); ?></h2>
	<p><?= _t("Open Google Authenticator on your phone and scan this QR code."); ?></p>
<?php   if ($qr) { ?>
	<p><img src="<?= $qr; ?>" alt="<?= _t("QR code"); ?>" style="width:220px; height:220px; border:1px solid #ddd; padding:8px; background:#fff;"/></p>
<?php   } else { ?>
	<p style="color:#b0312e;"><?= _t("The QR code could not be generated. Use the key below instead."); ?></p>
<?php   } ?>
	<p style="color:#555;">
		<?= _t("If you cannot scan it, choose « Enter a setup key » and type:"); ?><br/>
		<code style="font-size:15px; letter-spacing:1px;"><?= htmlspecialchars((string)$secret_lisible); ?></code><br/>
		<span style="font-size:12px;"><?= _t("Key type: time-based."); ?></span>
	</p>

	<h2><?= _t("Step 2 — confirm"); ?></h2>
	<p><?= _t("Enter the six-digit code shown by the app. Activation only takes effect once this code is accepted."); ?></p>
	<?= caFormTag($this->request, 'Confirmer', 'caOtpConfirmForm', 'etatsMTE/Securite', 'post', 'multipart/form-data', '_top', ['noCSRFToken' => false]); ?>
		<input type="text" name="otp_code" size="10" maxlength="6" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" style="font-size:16px; letter-spacing:3px;"/>
		<button type="submit" class="form-button"><?= _t("Confirm"); ?></button>
	</form>

<?php } elseif ($enrole) { ?>
	<p style="padding:10px 12px; background:#eef6ee; border-left:4px solid #1e7a34;">
		<?= _t("Two-factor authentication is active on your account. A one-time code is required each time you sign in."); ?>
	</p>

	<h2><?= _t("Switch it off"); ?></h2>
	<p><?= _t("Enter a current code to confirm. If you have lost your phone, contact your administrator."); ?></p>
	<?= caFormTag($this->request, 'Desactiver', 'caOtpOffForm', 'etatsMTE/Securite', 'post', 'multipart/form-data', '_top', ['noCSRFToken' => false]); ?>
		<input type="text" name="otp_code" size="10" maxlength="6" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" style="font-size:16px; letter-spacing:3px;"/>
		<button type="submit" class="form-button"><?= _t("Switch off"); ?></button>
	</form>

<?php } else { ?>
	<p>
		<?= _t("Two-factor authentication adds a second check when you sign in: besides your password, you enter a six-digit code from an app on your phone."); ?>
	</p>
	<p style="color:#555;">
		<?= _t("It is not active on your account. You can sign in with your password alone."); ?>
	</p>
	<?= caFormTag($this->request, 'Activer', 'caOtpOnForm', 'etatsMTE/Securite', 'post', 'multipart/form-data', '_top', ['noCSRFToken' => false]); ?>
		<button type="submit" class="form-button"><?= _t("Switch on"); ?></button>
	</form>
<?php } ?>
</div>
