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
$section_id = $this->getVar('section');
$book_id = $this->getVar('book');
$section = $this->getVar("section_details");
?>

<h1><?php print _t('Delete section'); ?></h1>
<p><?php print _t('Confirm deletion of section'); ?> <?php $section_id; ?> ?</p>
<?php
print caFormTag($this->request, "DeleteSection/book/$book_id/section/$section_id", "editSectiontext", "bookCreator/Editor");
?>
<input name="book" type="hidden" value="<?php print $book_id; ?>"/>
<input name="section" type="hidden" value="<?php print $section_id; ?>"/>

<?php
print $vs_control_box = caFormControlBox(
	caFormSubmitButton($this->request, __CA_NAV_BUTTON_DELETE__, _t("Delete"), 'editSectiontext').' '.
	caNavButton($this->request, __CA_NAV_BUTTON_CANCEL__, _t("Cancel"), '', "*", "*", "editSection/book/$book_id/section/$section_id", array()),
	'',
	''
);?>
</form>
<style type="text/css">
	.section-titre-input,
	.section-intro-input {
		width:100%;
	}
	.section-contenu-textarea {
		width: 100%;
		height:220px;
	}
</style>


<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/latest/css/font-awesome.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/simplemde/latest/simplemde.min.css">
<script src="https://cdn.jsdelivr.net/simplemde/latest/simplemde.min.js"></script>

<script>
	new SimpleMDE({
		element: document.getElementById("section-contenu-textarea"),
		spellChecker: false,
	});
</script>
