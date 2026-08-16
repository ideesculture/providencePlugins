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
?>
<h1><?php print _t('Table of contents'); ?></h1>
<table style="border:none;width: 80%;margin:auto;">
<?php 
	// Show the page the section STARTS on, which is what the printed table of
	// contents carries. Adding the page count before displaying showed the last
	// page of the section instead — an off-by-one-section discrepancy between
	// this screen and the book, invisible while the counters were all null.
	foreach ($sections as $section):
		if($section["is_in_summary"]):
			$first_page = is_null($section["first_page"]) ? null : (int)$section["first_page"];
?>
	<tr>
		<td style="width: auto;"><?php print htmlspecialchars((string)$section["title"], ENT_QUOTES, "UTF-8"); ?> </td>
		<td style="text-align: right;width: 20%;"><?php print is_null($first_page) ? '&mdash;' : $first_page; ?></td>
	</tr>
<?php
		endif;
	endforeach;
?>
</table>

