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

$path = __CA_URL_ROOT__."/app/plugins/bookCreator/";
$dir = __CA_BASE_DIR__."/app/plugins/bookCreator/";

// Fetching all styles from style thumbnails
$styles= array_slice(scandir($dir."assets/styles"), 2);
foreach ($styles as &$style_pic) {
	$style_pic = str_replace('.png', '', $style_pic);
}
?>
<div class="navBreadCrumbContainer">
	<div class="navBreadCrumbs">
<div class="crumb"><div class="crumbtext navBreadCrumbLabel"><?php print _t('Current location'); ?></div><img src="/gestion/themes/default/graphics/arrows/breadcrumbloc.png" width="16" height="19" border="0"></div><div class="crumb"><nobr><div class="crumbtext"><a href="/gestion/index.php/bookCreator/Editor/BookSections/book/1"><?php print _t('Book editor'); ?></a></div><img src="/gestion/themes/default/graphics/arrows/breadcrumb.png" width="16" height="19" border="0"></nobr></div><div class="crumb"><nobr><div class="crumbtext"><?php print _t('Edit section'); ?></div><img src="/gestion/themes/default/graphics/arrows/breadcrumb.png" width="16" height="19" border="0"></nobr></div>
	</div><!-- end navBreadCrumbs-->
</div>


<h1><?php print _t('Section'); ?></h1>
<?php
print caFormTag($this->request, "SaveSection/book/$book_id/section/$section_id", "editSectiontext", "bookCreator/Editor");
print $vs_control_box = caFormControlBox(
	//left
	caFormSubmitButton($this->request, __CA_NAV_ICON_SAVE__, _t("Save"), 'editSectiontext').' '.
	//caFormSubmitButton($this->request, __CA_NAV_BUTTON_SAVE__, _t("Save and redirect"), 'editSectiontext').' '.
	caNavButton($this->request, __CA_NAV_ICON_CANCEL__, _t("Cancel"), '', "*", "*", "BookSections/book/$book_id/section/$section_id", array()).' ',
	//middle
	'',
	//right
	caNavButton($this->request, __CA_NAV_ICON_DELETE__, _t("Delete"), '', "*", "*", "deleteSection/book/$book_id/section/$section_id", array())

);?>

<input name="book" type="hidden" value="<?php print $book_id; ?>"/>
<input name="section" type="hidden" value="<?php print $section_id; ?>"/>

<h3><?php print _t('Title'); ?></h3>
<input name="title" class="section-titre-input" type="text" value="<?php print $section["title"]; ?>" />

<label style="font-weight: normal;" class="pull-right">
  <input type="checkbox" name="is_in_summary" id="is_in_summary" value="1" <?php print ($section["is_in_summary"]*1 ? "checked=\"checked\"" : ""); ?>>
  <?php print _t('This section appears in the table of contents'); ?>
</label>

<h3 type="button" data-toggle="collapse" data-target="#collapseIntro" aria-expanded="false" aria-controls="collapseExample">
	<i class="fa fa-plus-square-o" aria-hidden="true"></i> <?php print _t('Introduction/summary'); ?>
</h3>
<div class="collapse" id="collapseIntro">
	<input name="intro" class="section-intro-input" type="text" value="<?php print $section["intro"]; ?>" />
</div>

<h3 type="button" data-toggle="collapse" data-target="#collapseStyle" aria-expanded="false" aria-controls="collapseExample">
	<i class="fa fa-plus-square-o" aria-hidden="true"></i> <?php print _t('Style'); ?>
</h3>
<div class="collapse" id="collapseStyle">
	<div class="row">
	<?php foreach($styles as $style) : ?>
	<div class="col-md-6" style="margin-top:6px;">
	<input type="radio" name="style" value="<?php print $style; ?>" <?php print ($section["style"] == $style ? "checked=\"checked\"" :""); ?>/>
		<img class="style-pic" src="<?php print $path; ?>assets/styles/<?php print $style; ?>.png"/> <?php print $style; ?>
	</div>
	<?php endforeach; ?>
	</div>
</div>

<h3><?php print _t('Content'); ?></h3>
<textarea name="content" class="section-contenu-textarea" id="section-contenu-textarea"><?php print $section["content"]; ?></textarea>

<h3 type="button" data-toggle="collapse" data-target="#collapseRepresentation" aria-expanded="false" aria-controls="collapseRepresentation">
	<i class="fa fa-plus-square-o" aria-hidden="true"></i> <?php print _t('Representation'); ?>
</h3>
<div class="collapse" id="collapseRepresentation">
	<input name="representation_id" class="section-representation_id-input" type="text" value="<?php print $section["representation_id"]; ?>" />
</div>

<h3 type="button" data-toggle="collapse" data-target="#collapseSet" aria-expanded="false" aria-controls="collapseSet">
	<i class="fa fa-plus-square-o" aria-hidden="true"></i> <?php print _t('Set'); ?>
</h3>
<div class="collapse" id="collapseSet">
	<input name="set_id" class="section-set_id-input" type="text" value="<?php print $section["set_id"]; ?>" />
</div>
<br/><br/>
<?php
print $vs_control_box = caFormControlBox(
	caFormSubmitButton($this->request, __CA_NAV_ICON_SAVE__, _t("Save"), 'editSectiontext').' '.
	//caFormSubmitButton($this->request, __CA_NAV_BUTTON_SAVE__, _t("Save and redirect"), 'editSectiontext').' '.
	caNavButton($this->request, __CA_NAV_ICON_CANCEL__, _t("Cancel"), '', "*", "*", "BookSections/book/$book_id/section/$section_id", array()).' '.
	caNavButton($this->request, __CA_NAV_ICON_PDF__, _t("PDF"), '', "*", "*", "renderSectionPDF/book/$book_id/section/$section_id", array()).' '.
	caNavButton($this->request, __CA_NAV_ICON_PDF__, _t("HTML preview"), '', "*", "*", "renderSectionPDF/book/$book_id/section/$section_id/debug/1", array()),
	'',
	caNavButton($this->request, __CA_NAV_ICON_DELETE__, _t("Delete"), '', "*", "*", "deleteSection/book/$book_id/section/$section_id", array())

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
<div style="margin-bottom:100px"></div>
<!-- Bootstrap -->
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js" integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa" crossorigin="anonymous"></script>

<!-- SimpleMDE -->
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/latest/css/font-awesome.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/simplemde/latest/simplemde.min.css">
<script src="https://cdn.jsdelivr.net/simplemde/latest/simplemde.min.js"></script>


<!-- BookEditor editor CSS -->
<link rel="stylesheet" href="<?php print $path."assets/css/editor.css"; ?>" />

<script>
	new SimpleMDE({
		element: document.getElementById("section-contenu-textarea"),
		spellChecker: false,
	});
</script>
