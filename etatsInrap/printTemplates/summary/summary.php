<?php
/* ----------------------------------------------------------------------
 * app/templates/summary/summary.php
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2014 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of 
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  
 *
 * This source code is free and modifiable under the terms of 
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * -=-=-=-=-=- CUT HERE -=-=-=-=-=-
 * Template configuration:
 *
 * @name Generic summary
 * @type page
 * @pageSize letter
 * @pageOrientation portrait
 * @tables *
 *
 * @marginTop 0.75in
 * @marginLeft 0.25in
 * @marginBottom 0.5in
 * @marginRight 0.25in
 *
 * ----------------------------------------------------------------------
 */
 
 	$t_item 				= $this->getVar('t_subject');
	$va_bundle_displays 	= $this->getVar('bundle_displays');
	$t_display 				= $this->getVar('t_display');
	$va_placements 			= $this->getVar("placements");
	$vn_number				= $this->getVar("vn_number");
	$vn_labels_per_line 	= $this->getVar("vn_labels_per_line");
	$vn_labels_per_page 	= $this->getVar("vn_labels_per_page");

	$vs_label 	= $this->getVar("label");
	$vs_code = $this->getVar("code");
	$vr_long 	= $this->getVar("long")-10;// removing margins
	$vr_larg 	= $this->getVar("larg")-15;


?><!DOCTYPE html>
<html>
	<head>
		<title><?php print _t('Summary for %1 (%2)', $t_item->getLabelForDisplay(), $t_item->get($t_item->getProperty('ID_NUMBERING_ID_FIELD'))); ?></title>

<?php if(file_exists($this->getVar('base_path')."/local/pdf.css")): ?>
		<link type="text/css" href="<?php print $this->getVar('base_path'); ?>/local/pdf.css" rel="stylesheet" />
<?php else: ?>
		<link type="text/css" href="<?php print $this->getVar('base_path'); ?>/pdf.css" rel="stylesheet" />
<?php endif;
	if(file_exists($this->getVar('base_path')."/".$code.".css")): ?>
		<link type="text/css" href="<?php print $this->getVar('base_path'); ?>/".$code.".css" rel="stylesheet" />
<?php endif; ?>

		<style type="text/css">
			@page { margin: {{{marginTop}}} {{{marginRight}}} {{{marginBottom}}} {{{marginLeft}}}; }
		</style>
	</head>
	<body>
<?php
	print "<table style='page-break-inside: avoid !important;margin:0;padding:0;'>";
	for($i=0;$i<$vn_number;$i++) {
		if($i % $vn_labels_per_line == 0) print "<tr style='overflow: hidden;height:<?php print $vr_larg; ?>mm;margin:0;padding:0;'>";
		?>
		<td style="width:<?php print $vr_long; ?>mm;height:<?php print $vr_larg; ?>mm;overflow: hidden;margin:0;padding:4mm 5mm;">
			<table  style="width:100%;">
			<?php
		foreach($va_placements as $vn_placement_id => $va_bundle_info){
			if (!is_array($va_bundle_info)) break;

			if (!strlen($vs_display_value = $t_display->getDisplayValue($t_item, $vn_placement_id, array('purify' => true)))) {
				if (!(bool)$t_display->getSetting('show_empty_values')) { continue; }
				$vs_display_value = "&lt;"._t('not defined')."&gt;";
			}

			print '<tr class="data"><td class="label">'.$va_bundle_info['display']."</td><td class=\"meta\">".strip_tags($vs_display_value)."</td></tr>\n";
		}
		?>
		</table>
		</td>
		<?php
		if($i % $vn_labels_per_line == $vn_labels_per_line-1) print "</tr>";
	}
	print "</table>";
