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
$book_id      = (int)$this->getVar('book');
$notification = $this->getVar('notification');
$error        = $this->getVar('error');
?>

<h1><?php print $error ? _t('Section not deleted') : _t('Section deleted'); ?></h1>

<?php if ($error) { ?>
	<div class="alert alert-danger"><?php print htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php } ?>

<?php if ($notification) { ?>
	<div class="alert alert-success"><?php print htmlspecialchars((string)$notification, ENT_QUOTES, 'UTF-8'); ?></div>
<?php } ?>

<?php
print caFormControlBox(
	caNavButton($this->request, __CA_NAV_ICON_GO__, _t("Back to the sections"), '', 'bookCreator', 'Editor', 'BookSections', array('book' => $book_id)),
	'',
	''
);
?>
