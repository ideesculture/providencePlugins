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
$vs_error       = $this->getVar('error');
$vs_notification = $this->getVar('notification');
?>
<h2><?php print _t('Book Creator — database setup'); ?></h2>

<?php if ($vs_error) { ?>
	<div class="alert alert-danger"><?php print $vs_error; ?></div>
<?php } ?>

<?php if ($vs_notification) { ?>
	<div class="alert alert-success"><?php print $vs_notification; ?></div>
<?php } ?>

<?php if (is_array($va_applied) && sizeof($va_applied)) { ?>
	<p><?php print _t('The following statements were applied:'); ?></p>
	<ul>
		<?php foreach ($va_applied as $vs_sql) { ?>
			<li><code><?php print htmlspecialchars($vs_sql, ENT_QUOTES, 'UTF-8'); ?></code></li>
		<?php } ?>
	</ul>
<?php } ?>

<?php if ($vb_is_usable) { ?>
	<p>
		<?php print _t('The plugin is ready to use.'); ?>
		<a href="<?php print caNavUrl($this->request, 'bookCreator', 'Editor', 'Index'); ?>"><?php print _t('Open the book editor'); ?></a>
	</p>
<?php } else { ?>
	<p><?php print _t('The plugin needs the following changes before it can run. Nothing is deleted or renamed: tables, columns and indexes are only added.'); ?></p>
	<ul>
		<?php foreach ($va_changes as $vs_change) { ?>
			<li><?php print $vs_change; ?></li>
		<?php } ?>
	</ul>
	<form action="<?php print caNavUrl($this->request, 'bookCreator', 'Install', 'Run'); ?>" method="post">
		<button type="submit" class="btn btn-primary"><?php print _t('Apply these changes'); ?></button>
	</form>
<?php } ?>
