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

<h1><?php print _t('Section deleted'); ?></h1>
<input name="book" type="hidden" value="<?php print $book_id; ?>"/>
<input name="section" type="hidden" value="<?php print $section_id; ?>"/>

<?php
print $vs_control_box = caFormControlBox(
	caNavButton($this->request, __CA_NAV_ICON_SAVE__, _t("OK"), '', "*", "*", "BookSections/book/$book_id", array()),
	'',
	''
);?>
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


<link rel="stylesheet" href="<?php print __CA_URL_ROOT__; ?>/app/plugins/bookCreator/assets/css/vendor/easymde.min.css">
<script src="<?php print __CA_URL_ROOT__; ?>/app/plugins/bookCreator/assets/js/vendor/easymde.min.js"></script>

<script>
	new EasyMDE({
		element: document.getElementById("section-contenu-textarea"),
		spellChecker: false,
	});
</script>
