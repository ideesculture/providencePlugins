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
$section_id = (int)$this->getVar('section');
$book_id    = (int)$this->getVar('book');
$section    = $this->getVar("section_details");
$error      = $this->getVar('error');

// The title, because "section 47" tells the editor nothing about what is
// about to be deleted. Falls back to the id when the section has no title.
$label = (is_array($section) && strlen(trim((string)$section['title'])))
	? $section['title']
	: _t('section %1', $section_id);
?>

<h1><?php print _t('Delete section'); ?></h1>

<?php if ($error) { ?>
	<div class="alert alert-danger"><?php print htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php } ?>

<p><?php print _t('Delete “%1”? This cannot be undone.', htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8')); ?></p>
<?php
print caFormTag($this->request, "DeleteSection/book/$book_id/section/$section_id", "editSectiontext", "bookCreator/Editor");
print BookCsrf::field();
?>
<input name="book" type="hidden" value="<?php print $book_id; ?>"/>
<input name="section" type="hidden" value="<?php print $section_id; ?>"/>

<?php
print caFormControlBox(
	caFormSubmitButton($this->request, __CA_NAV_ICON_DELETE__, _t("Delete"), 'editSectiontext').' '.
	caNavButton($this->request, __CA_NAV_ICON_CANCEL__, _t("Cancel"), '', "*", "*", "editSection", array('book' => $book_id, 'section' => $section_id)),
	'',
	''
);?>
</form>
