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
?>
<h1><?php print htmlspecialchars((string)$book_title, ENT_QUOTES, "UTF-8"); ?></h1>
<p><?php print _t('Drag and drop the sections to reorder them.'); ?></p>
<?php
print caFormTag($this->request, "BookSections/book/$book_id", "sortBookSections", "bookCreator/Editor");
?>
<ul id="sectionsList">
<?php 
	$count = 0;
	foreach ($sections as $section): 
		$count = $count+$section["pages"];
?>
	<li class="booksection">
		<span class="pull-right"><small style="display:inline-block;color:lightgray;padding-top:14px;"><?php print _t('%1 pages', $section["pages"]); ?> </small><br/><?php print $count; ?>/<?php print $nb_pages; ?></span>
		<h3>
			<?php print caNavButton($this->request, __CA_NAV_ICON_EDIT__, _t("Edit"), '', '*', '*', "editSection", array('book' => $book_id, "section"=> $section["booksection_id"]), array(), array("dont_show_content"=>true)); ?>
			<?php print htmlspecialchars((string)$section["title"], ENT_QUOTES, "UTF-8"); ?> 
		</h3>
		<input class='currentposition' type='hidden' name='sort[<?php print (int)$section['booksection_id']; ?>][currposition]' value="<?php print (int)$section["sort"]; ?>">
	</li>
<?php
$i++;
endforeach;
?>
</ul>
<?php print caNavButton($this->request, __CA_NAV_ICON_GO__, _t("All books"), "", "bookCreator", "Books", "Index"); ?>
<?php print caNavButton($this->request, __CA_NAV_ICON_ADD__, _t("Add a section"), '', '*', '*', "addSection", array_merge(array('book' => $book_id), BookCsrf::param()), array(), array()); ?>
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
<a href="<?php print $preview_url; ?>" class="form-button" target="_blank"><span class="form-button "><?php print _t('Preview the book'); ?></span></a>
<a href="<?php print $generate_url; ?>" class="form-button"><span class="form-button "><?php print _t('Generate the PDF'); ?></span></a>
<a href="<?php print $download_url; ?>" class="form-button" target="_blank"><span class="form-button "><?php print _t('Display the latest generated version'); ?></span></a>
<a href="<?php print $summary_url; ?>" class="form-button" target="_blank"><span class="form-button "><?php print _t('Table of contents'); ?></span></a>

<br/><br/><br/><br/>
<?php
print $vs_control_box = caFormControlBox(
	caFormSubmitButton($this->request, __CA_NAV_ICON_SAVE__, _t("Save"), 'sortBookSections').' '.
	caNavButton($this->request, __CA_NAV_ICON_CANCEL__, _t("Cancel"), '', "*", "*", "BookSections/book/$book_id", array()),
	'',
	caNavButton($this->request, __CA_NAV_ICON_DELETE__, _t("Delete"), '', "*", "*", "deleteSection/book/$book_id", BookCsrf::param())

);?>
<div style="margin-bottom:100px;"></div>

<script>
jQuery(document).ready(function() {
	jQuery("#sectionsList").sortable({
		stop: function () {
            $('input.currentposition').each(function(idx, value) {
                console.log( idx + ": " + $(this).val() );
                $(this).val(idx);
                //console.log($(this));
                //console.log($(idx));
                //$(this).val(nbElems - idx);
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