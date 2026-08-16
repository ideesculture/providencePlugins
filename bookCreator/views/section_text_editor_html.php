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

// Layouts offered for this book, resolved by the controller from the theme and
// the page format. Reading the thumbnail directory, as this view used to do,
// listed every layout regardless of the format and turned any stray file
// dropped there into a phantom layout.
$templates = $this->getVar('templates');
if (!is_array($templates)) { $templates = []; }
?>
<div class="navBreadCrumbContainer">
	<div class="navBreadCrumbs">
<?php
// Breadcrumb built from the request rather than from literals: the previous
// version pointed at /gestion/, the URL root of one single installation, and at
// book 1 whatever book was being edited.
$arrow_loc = __CA_URL_ROOT__.'/themes/default/graphics/arrows/breadcrumbloc.png';
$arrow = __CA_URL_ROOT__.'/themes/default/graphics/arrows/breadcrumb.png';
$sections_url = caNavUrl($this->request, 'bookCreator', 'Editor', 'BookSections', ['book' => $book_id]);
?>
<div class="crumb"><div class="crumbtext navBreadCrumbLabel"><?php print _t('Current location'); ?></div><img src="<?php print $arrow_loc; ?>" width="16" height="19" border="0" alt=""></div><div class="crumb"><nobr><div class="crumbtext"><a href="<?php print $sections_url; ?>"><?php print _t('Book editor'); ?></a></div><img src="<?php print $arrow; ?>" width="16" height="19" border="0" alt=""></nobr></div><div class="crumb"><nobr><div class="crumbtext"><?php print _t('Edit section'); ?></div><img src="<?php print $arrow; ?>" width="16" height="19" border="0" alt=""></nobr></div>
	</div><!-- end navBreadCrumbs-->
</div>


<h1><?php print _t('Section'); ?></h1>
<?php
print caFormTag($this->request, "SaveSection/book/$book_id/section/$section_id", "editSectiontext", "bookCreator/Editor");
print BookCsrf::field();
print $vs_control_box = caFormControlBox(
	//left
	caFormSubmitButton($this->request, __CA_NAV_ICON_SAVE__, _t("Save"), 'editSectiontext').' '.
	caNavButton($this->request, __CA_NAV_ICON_CANCEL__, _t("Cancel"), '', "*", "*", "BookSections/book/$book_id/section/$section_id", array()).' ',
	//middle
	'',
	//right
	caNavButton($this->request, __CA_NAV_ICON_DELETE__, _t("Delete"), '', "*", "*", "deleteSection/book/$book_id/section/$section_id", array())

);?>

<input name="book" type="hidden" value="<?php print $book_id; ?>"/>
<input name="section" type="hidden" value="<?php print $section_id; ?>"/>

<h3><?php print _t('Title'); ?></h3>
<input name="title" class="section-titre-input" type="text" value="<?php print htmlspecialchars((string)$section["title"], ENT_QUOTES, "UTF-8"); ?>" />

<label style="font-weight: normal;" class="pull-right">
  <input type="checkbox" name="is_in_summary" id="is_in_summary" value="1" <?php print ($section["is_in_summary"]*1 ? "checked=\"checked\"" : ""); ?>>
  <?php print _t('This section appears in the table of contents'); ?>
</label>

<h3 type="button" data-toggle="collapse" data-target="#collapseIntro" aria-expanded="false" aria-controls="collapseExample">
	<i class="fa fa-plus-square-o" aria-hidden="true"></i> <?php print _t('Introduction/summary'); ?>
</h3>
<div class="collapse" id="collapseIntro">
	<input name="intro" class="section-intro-input" type="text" value="<?php print htmlspecialchars((string)$section["intro"], ENT_QUOTES, "UTF-8"); ?>" />
</div>

<h3 type="button" data-toggle="collapse" data-target="#collapseStyle" aria-expanded="false" aria-controls="collapseExample">
	<i class="fa fa-plus-square-o" aria-hidden="true"></i> <?php print _t('Style'); ?>
</h3>
<div class="collapse" id="collapseStyle">
	<div class="row">
	<?php foreach($templates as $code => $template) :
		$code = htmlspecialchars((string)$code, ENT_QUOTES, 'UTF-8');
		$label = htmlspecialchars((string)($template['label'] ?? $code), ENT_QUOTES, 'UTF-8');
	?>
	<div class="col-md-6" style="margin-top:6px;">
	<input type="radio" name="style" value="<?php print $code; ?>" <?php print ($section["style"] == $code ? "checked=\"checked\"" :""); ?>/>
		<img class="style-pic" src="<?php print $path; ?>assets/styles/<?php print $code; ?>.png" alt=""/> <?php print $label; ?>
	</div>
	<?php endforeach; ?>
	<?php if (!sizeof($templates)) { ?>
	<p><?php print _t('No layout is available for the page format of this book.'); ?></p>
	<?php } ?>
	</div>
</div>

<h3><?php print _t('Content'); ?></h3>
<textarea name="content" class="section-contenu-textarea" id="section-contenu-textarea"><?php print htmlspecialchars((string)$section["content"], ENT_QUOTES, "UTF-8"); ?></textarea>

<h3 type="button" data-toggle="collapse" data-target="#collapseRepresentation" aria-expanded="false" aria-controls="collapseRepresentation">
	<i class="fa fa-plus-square-o" aria-hidden="true"></i> <?php print _t('Representation'); ?>
</h3>
<div class="collapse" id="collapseRepresentation">
	<input name="representation_id" class="section-representation_id-input" type="text" value="<?php print htmlspecialchars((string)$section["representation_id"], ENT_QUOTES, "UTF-8"); ?>" />
</div>

<h3 type="button" data-toggle="collapse" data-target="#collapseSet" aria-expanded="false" aria-controls="collapseSet">
	<i class="fa fa-plus-square-o" aria-hidden="true"></i> <?php print _t('Set'); ?>
</h3>
<div class="collapse" id="collapseSet">
	<input name="set_id" class="section-set_id-input" type="text" value="<?php print htmlspecialchars((string)$section["set_id"], ENT_QUOTES, "UTF-8"); ?>" />
</div>
<br/><br/>
<?php
print $vs_control_box = caFormControlBox(
	caFormSubmitButton($this->request, __CA_NAV_ICON_SAVE__, _t("Save"), 'editSectiontext').' '.
	caNavButton($this->request, __CA_NAV_ICON_CANCEL__, _t("Cancel"), '', "*", "*", "BookSections/book/$book_id/section/$section_id", array()).' '.
	caNavButton($this->request, __CA_NAV_ICON_PDF__, _t("Preview this section"), '', "bookCreator", "Preview", "Section", array('book' => $book_id, 'section' => $section_id)),
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
<link rel="stylesheet" href="<?php print __CA_URL_ROOT__; ?>/app/plugins/bookCreator/assets/css/vendor/bootstrap.min.css">
<script src="<?php print __CA_URL_ROOT__; ?>/app/plugins/bookCreator/assets/js/vendor/bootstrap.min.js"></script>

<!-- EasyMDE, the maintained fork of the abandoned SimpleMDE -->
<link rel="stylesheet" href="<?php print __CA_URL_ROOT__; ?>/app/plugins/bookCreator/assets/css/vendor/easymde.min.css">
<script src="<?php print __CA_URL_ROOT__; ?>/app/plugins/bookCreator/assets/js/vendor/easymde.min.js"></script>


<!-- BookEditor editor CSS -->
<link rel="stylesheet" href="<?php print $path."assets/css/editor.css"; ?>" />

<script>
	new EasyMDE({
		element: document.getElementById("section-contenu-textarea"),
		spellChecker: false,
	});
</script>
