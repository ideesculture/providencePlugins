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
$sections = $this->getVar('sections');
$book_id = $this->getVar('book_id');
$nb_pages = $this->getVar('nb_pages');
$book_title = $this->getVar('book_title');
$notification = $this->getVar('notification');
$error = $this->getVar('error');
?>
<h1><?php print htmlspecialchars((string)$book_title, ENT_QUOTES, "UTF-8"); ?></h1>

<?php if ($error) { ?>
	<div class="alert alert-danger"><?php print htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php } ?>

<?php if ($notification) { ?>
	<div class="alert alert-success"><?php print htmlspecialchars((string)$notification, ENT_QUOTES, 'UTF-8'); ?></div>
<?php } ?>

<p><?php print IdC::_t('Drag and drop the sections to reorder them.'); ?></p>
<?php
print caFormTag($this->request, "BookSections/book/$book_id", "sortBookSections", "bookCreator/Editor");
print BookCsrf::field();
?>
<ul id="sectionsList">
<?php 
	$count = 0;
	foreach ($sections as $section): 
		$count = $count+$section["pages"];
?>
	<li class="booksection">
		<span class="pull-right"><small style="display:inline-block;color:lightgray;padding-top:14px;"><?php print IdC::_t('%1 pages', $section["pages"]); ?> </small><br/><?php print $count; ?>/<?php print $nb_pages; ?></span>
		<h3>
			<?php print caNavButton($this->request, __CA_NAV_ICON_EDIT__, IdC::_t("Edit"), '', '*', '*', "editSection", array('book' => $book_id, "section"=> $section["booksection_id"]), array(), array("dont_show_content"=>true)); ?>
			<?php
			// Deletion belongs to the section it deletes. The previous version
			// carried a single Delete button under the list, pointing at
			// deleteSection without a section parameter: it could never delete
			// anything, whichever section the editor had in mind.
			print caNavButton($this->request, __CA_NAV_ICON_DELETE__, IdC::_t("Delete"), '', '*', '*', "deleteSection", array('book' => $book_id, "section" => $section["booksection_id"]), array(), array("dont_show_content"=>true));
			?>
			<?php print htmlspecialchars((string)$section["title"], ENT_QUOTES, "UTF-8"); ?>
		</h3>
		<input class='currentposition' type='hidden' name='sort[<?php print (int)$section['booksection_id']; ?>][currposition]' value="<?php print (int)$section["sort"]; ?>">
	</li>
<?php
endforeach;
?>
</ul>
<?php print caNavButton($this->request, __CA_NAV_ICON_GO__, IdC::_t("All books"), "", "bookCreator", "Books", "Index"); ?>
<?php print caNavButton($this->request, __CA_NAV_ICON_ADD__, IdC::_t("Add a section"), '', '*', '*', "addSection", array_merge(array('book' => $book_id), BookCsrf::param()), array(), array()); ?>
<?php
// Actions built with caNavUrl rather than assembled by hand: the previous
// version wrote /index.php/... itself, and pointed the download straight at a
// file under tmp/, which both exposed the layout of the plugin directory and
// offered a link to a PDF that might be half written.
$preview_url  = caNavUrl($this->request, 'bookCreator', 'Preview', 'Book', ['book' => $book_id]);
$generate_url = caNavUrl($this->request, 'bookCreator', 'Generation', 'Submit', array_merge(['book' => $book_id], BookCsrf::param()));
$download_url = caNavUrl($this->request, 'bookCreator', 'Generation', 'Download', ['book' => $book_id]);
$summary_url  = caNavUrl($this->request, 'bookCreator', 'Editor', 'Summary', ['book' => $book_id]);
?>
<a href="<?php print $preview_url; ?>" class="form-button" target="_blank"><span class="form-button "><?php print IdC::_t('Preview the book'); ?></span></a>
<a href="<?php print $generate_url; ?>" class="form-button"><span class="form-button "><?php print IdC::_t('Generate the PDF'); ?></span></a>
<a href="<?php print $download_url; ?>" class="form-button" target="_blank"><span class="form-button "><?php print IdC::_t('Display the latest generated version'); ?></span></a>
<a href="<?php print $summary_url; ?>" class="form-button" target="_blank"><span class="form-button "><?php print IdC::_t('Table of contents'); ?></span></a>

<br/><br/><br/><br/>
<?php
print caFormControlBox(
	caFormSubmitButton($this->request, __CA_NAV_ICON_SAVE__, IdC::_t("Save"), 'sortBookSections').' '.
	caNavButton($this->request, __CA_NAV_ICON_CANCEL__, IdC::_t("Cancel"), '', "*", "*", "BookSections", array('book' => $book_id)),
	'',
	''
);?>
<div style="margin-bottom:100px;"></div>

<script>
jQuery(document).ready(function() {
	jQuery("#sectionsList").sortable({
		stop: function () {
			// The hidden inputs carry the new order to the form: after a drop,
			// each one takes the index of its row.
			jQuery('input.currentposition').each(function (idx) {
				jQuery(this).val(idx);
			});
		}
	});
	jQuery("#sectionsList").disableSelection();
});

</script>
<style type="text/css">
	#sectionsList {
		margin:0 0 20px 0;
		list-style: none;
		-webkit-padding-start: 0px;
	}
	.booksection {
		list-style: none;
		border:1px dotted lightblue;
		padding:0 10px;
		margin:0px;
	}
</style>