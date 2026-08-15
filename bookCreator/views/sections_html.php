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
$nb_pages = reset($this->getVar('nb_pages'));
?>
<h1>Catalogue</h1>
<p>Glisser déposer les sections pour réorganiser l'ordre des sections.</p>
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
		<span class="pull-right"><small style="display:inline-block;color:lightgray;padding-top:14px;"><?php print $section["pages"]; ?> pages </small><br/><?php print $count; ?>/<?php print $nb_pages; ?></span>
		<h3>
			<?php print caNavButton($this->request, __CA_NAV_ICON_EDIT__, _t("Edit"), '', '*', '*', "editSection", array('book' => $book_id, "section"=> $section["booksection_id"]), array(), array("dont_show_content"=>true)); ?>
			<?php print $section["title"]; ?> 
		</h3>
		<input class='currentposition' type='hidden' name='sort[<?php print $section['booksection_id']; ?>][currposition]' value='<?php print $section["sort"]; ?>'>
	</li>
<?php
$i++;
endforeach;
?>
</ul>
<?php print caNavButton($this->request, __CA_NAV_ICON_ADD__, "Ajouter une section", '', '*', '*', "addSection", array('book' => $book_id), array(), array()); ?>
<a href="<?php print __CA_URL_ROOT__; ?>/index.php/bookCreator/Editor/renderHTML/book/<?php print $book_id; ?>" class="form-button" target="_blank"><span class="form-button "><i class="fa fa-book fa-2x" aria-hidden="true"></i> Aperçu 1ères pages</span></a>
<a href="<?php print __CA_URL_ROOT__; ?>/index.php/bookCreator/Editor/renderSectionsPDF/book/<?php print $book_id; ?>" class="form-button" target="_blank"><span class="form-button "><i class="fa fa-book fa-2x" aria-hidden="true"></i> Générer le PDF global (section par section)</span></a>
<?php 
		print "<a href='".__CA_URL_ROOT__."/app/plugins/bookCreator/tmp/book_".$book_id."_with_cover.pdf' class='form-button' target='_blank'><span class='form-button'><i class=\"fa fa-book fa-2x\" aria-hidden=\"true\"></i> Afficher la dernière version générée</span></a>";
?>
<a href="<?php print __CA_URL_ROOT__; ?>/index.php/bookCreator/Editor/Summary/book/<?php print $book_id; ?>" class="form-button" target="_blank"><span class="form-button "><i class="fa fa-book fa-2x" aria-hidden="true"></i> Générer le sommaire</span></a>

<br/><br/><br/><br/>
<?php
print $vs_control_box = caFormControlBox(
	caFormSubmitButton($this->request, __CA_NAV_ICON_SAVE__, _t("Save"), 'sortBookSections').' '.
	//caFormSubmitButton($this->request, __CA_NAV_BUTTON_SAVE__, _t("Save and redirect"), 'editSectiontext').' '.
	caNavButton($this->request, __CA_NAV_ICON_CANCEL__, _t("Cancel"), '', "*", "*", "BookSections/book/$book_id", array()),
	'',
	caNavButton($this->request, __CA_NAV_ICON_DELETE__, _t("Delete"), '', "*", "*", "deleteSection/book/$book_id", array())

);?>
<div style="margin-bottom:100px;"></div>
<!-- Font awesome -->
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/latest/css/font-awesome.min.css">

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