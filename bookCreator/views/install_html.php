<?php
/* Book Creator plugin for CollectiveAccess
 *
 * Plugin by idéesculture – Gautier MICHELIN
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License v3. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * ----------------------------------------------------------------------
 */
$vb_is_usable   = $this->getVar('is_usable');
$va_changes     = $this->getVar('changes');
$va_applied     = $this->getVar('applied');
$va_failed      = $this->getVar('failed');
$va_renderer    = $this->getVar('renderer');
$vs_error       = $this->getVar('error');
$vs_notification = $this->getVar('notification');
?>
<h2><?php print IdC::_t('Book Creator — database setup'); ?></h2>

<?php if ($vs_error) { ?>
	<div class="alert alert-danger"><?php print $vs_error; ?></div>
<?php } ?>

<?php if ($vs_notification) { ?>
	<div class="alert alert-success"><?php print $vs_notification; ?></div>
<?php } ?>

<?php if (is_array($va_applied) && sizeof($va_applied)) { ?>
	<p><?php print IdC::_t('The following statements were applied:'); ?></p>
	<ul>
		<?php foreach ($va_applied as $vs_sql) { ?>
			<li><code><?php print htmlspecialchars($vs_sql, ENT_QUOTES, 'UTF-8'); ?></code></li>
		<?php } ?>
	</ul>
<?php } ?>

<?php if (is_array($va_failed) && sizeof($va_failed)) { ?>
	<p><?php print IdC::_t('The database refused the following statements:'); ?></p>
	<ul>
		<?php foreach ($va_failed as $vs_failure) { ?>
			<li><code><?php print htmlspecialchars($vs_failure, ENT_QUOTES, 'UTF-8'); ?></code></li>
		<?php } ?>
	</ul>
<?php } ?>

<?php if (is_array($va_renderer) && !$va_renderer['ok']) { ?>
	<div class="alert alert-danger">
		<p><?php print IdC::_t('The database is one half of the setup; the rendering chain is the other, and it is not ready:'); ?></p>
		<ul>
			<?php foreach ($va_renderer['reasons'] as $vs_reason) { ?>
				<li><?php print htmlspecialchars((string)$vs_reason, ENT_QUOTES, 'UTF-8'); ?></li>
			<?php } ?>
		</ul>
		<p><?php print IdC::_t('Books can be composed without it, but no PDF will be produced. See bin/README.md for the installation of the renderer.'); ?></p>
	</div>
<?php } elseif (is_array($va_renderer)) { ?>
	<p><?php print IdC::_t('The rendering chain is available.'); ?></p>
<?php } ?>

<?php if ($vb_is_usable) { ?>
	<p>
		<?php print IdC::_t('The plugin is ready to use.'); ?>
		<a href="<?php print caNavUrl($this->request, 'bookCreator', 'Editor', 'Index'); ?>"><?php print IdC::_t('Open the book editor'); ?></a>
	</p>
<?php } else { ?>
	<p><?php print IdC::_t('The plugin needs the following changes before it can run. Nothing is deleted or renamed: tables, columns and indexes are only added.'); ?></p>
	<ul>
		<?php foreach ($va_changes as $vs_change) { ?>
			<li><?php print $vs_change; ?></li>
		<?php } ?>
	</ul>
	<form action="<?php print caNavUrl($this->request, 'bookCreator', 'Install', 'Run'); ?>" method="post">
		<?php print BookCsrf::field(); ?>
		<button type="submit" class="btn btn-primary"><?php print IdC::_t('Apply these changes'); ?></button>
	</form>
<?php } ?>
