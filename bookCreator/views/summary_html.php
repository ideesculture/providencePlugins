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
	$count = 0;
	foreach ($sections as $section): 
		$count = $count+$section["pages"];
		if($section["is_in_summary"]):
?>
	<tr>
		<td style="width: auto;"><?php print htmlspecialchars((string)$section["title"], ENT_QUOTES, "UTF-8"); ?> </td>
		<td style="text-align: right;width: 20%;"><?php print $count; ?></td>
	</tr>
<?php
		endif;
	$i++;
	endforeach;
?>
</table>

