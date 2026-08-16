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
$vn_book_id      = (int)$this->getVar('book_id');
$va_values       = $this->getVar('values');
$va_themes       = $this->getVar('themes');
$va_formats      = $this->getVar('formats');
$va_font_pairs   = $this->getVar('font_pairs');
$vs_notification = $this->getVar('notification');
$vs_error        = $this->getVar('error');

if (!is_array($va_values))     { $va_values = array(); }
if (!is_array($va_themes))     { $va_themes = array(); }
if (!is_array($va_formats))    { $va_formats = array(); }
if (!is_array($va_font_pairs)) { $va_font_pairs = array(); }

/** Same rule as the list: nothing reaches the page unescaped. */
$esc = function($vm_value) {
	return htmlspecialchars((string)$vm_value, ENT_QUOTES, 'UTF-8');
};

/** One field of the form, with an empty string for anything not set. */
$val = function($vs_field) use ($va_values, $esc) {
	return $esc(isset($va_values[$vs_field]) ? $va_values[$vs_field] : '');
};

/**
 * caHTMLSelect prints option labels verbatim, so they are escaped here; the
 * option values it escapes itself. A code no longer offered by the theme (a
 * format renamed, a pair removed) is added to the list so the form does not
 * silently reassign the book to another one on the next save.
 */
$select_options = function($va_entries, $vs_current) use ($esc) {
	$va_out = array();
	foreach ($va_entries as $vs_code => $vs_label) {
		$va_out[(string)$vs_code] = $esc($vs_label);
	}
	if (strlen((string)$vs_current) && !isset($va_out[(string)$vs_current])) {
		$va_out[(string)$vs_current] = $esc($vs_current).' '._t('(not declared by this theme)');
	}
	return $va_out;
};
?>
<h1><?php print $vn_book_id ? _t('Book settings') : _t('New book'); ?></h1>

<?php if ($vs_error) { ?>
	<div class="alert alert-danger"><?php print $esc($vs_error); ?></div>
<?php } ?>

<?php if ($vs_notification) { ?>
	<div class="alert alert-success"><?php print $esc($vs_notification); ?></div>
<?php } ?>

<?php
print caFormTag($this->request, 'Save/book/'.$vn_book_id, 'bookEditor', 'bookCreator/Books');
print BookCsrf::field();

print caFormControlBox(
	caFormSubmitButton($this->request, __CA_NAV_ICON_SAVE__, _t('Save'), 'bookEditor').' '.
	caNavButton($this->request, __CA_NAV_ICON_CANCEL__, _t('Cancel'), '', '*', '*', 'Index', array()).' '.
	($vn_book_id ? caNavButton($this->request, __CA_NAV_ICON_HIER__, _t('Sections'), '', 'bookCreator', 'Editor', 'BookSections', array('book' => $vn_book_id)) : ''),
	'',
	$vn_book_id ? caNavButton($this->request, __CA_NAV_ICON_DELETE__, _t('Delete'), '', '*', '*', 'Delete', array('book' => $vn_book_id)) : ''
);
?>
<input name="book" type="hidden" value="<?php print $vn_book_id; ?>"/>

<h3><?php print _t('Title'); ?></h3>
<input name="title" class="book-input" type="text" value="<?php print $val('title'); ?>"/>

<h3><?php print _t('Subtitle'); ?></h3>
<input name="subtitle" class="book-input" type="text" value="<?php print $val('subtitle'); ?>"/>

<h3><?php print _t('Identifier'); ?></h3>
<input name="idno" class="book-input" type="text" value="<?php print $val('idno'); ?>"/>

<h3><?php print _t('Description'); ?></h3>
<textarea name="description" class="book-textarea"><?php print $val('description'); ?></textarea>

<h3><?php print _t('Theme'); ?></h3>
<?php
print caHTMLSelect(
	'theme',
	$select_options($va_themes, isset($va_values['theme']) ? $va_values['theme'] : ''),
	array('id' => 'book_theme'),
	array('contentArrayUsesKeysForValues' => true, 'value' => isset($va_values['theme']) ? $va_values['theme'] : '')
);
?>
<p class="book-hint"><?php print _t('Page formats and typographic pairs are declared by the theme: save the book after changing it to see the lists below refreshed.'); ?></p>

<h3><?php print _t('Page format'); ?></h3>
<?php
print caHTMLSelect(
	'page_format',
	$select_options($va_formats, isset($va_values['page_format']) ? $va_values['page_format'] : ''),
	array('id' => 'book_page_format'),
	array('contentArrayUsesKeysForValues' => true, 'value' => isset($va_values['page_format']) ? $va_values['page_format'] : '')
);
?>

<h3><?php print _t('Typographic pair'); ?></h3>
<?php
print caHTMLSelect(
	'font_pair',
	$select_options($va_font_pairs, isset($va_values['font_pair']) ? $va_values['font_pair'] : ''),
	array('id' => 'book_font_pair'),
	array('contentArrayUsesKeysForValues' => true, 'value' => isset($va_values['font_pair']) ? $va_values['font_pair'] : '')
);
?>

<h3><?php print _t('Cover PDF'); ?></h3>
<input name="cover_pdf" class="book-input" type="text" value="<?php print $val('cover_pdf'); ?>"/>
<p class="book-hint"><?php print _t('File name of a PDF held in the covers directory, bound before the content. A name, not a path. Leave empty for a book without a separate cover.'); ?></p>

<h3><?php print _t('Back cover PDF'); ?></h3>
<input name="backcover_pdf" class="book-input" type="text" value="<?php print $val('backcover_pdf'); ?>"/>

<br/><br/>
<?php
print caFormControlBox(
	caFormSubmitButton($this->request, __CA_NAV_ICON_SAVE__, _t('Save'), 'bookEditor').' '.
	caNavButton($this->request, __CA_NAV_ICON_CANCEL__, _t('Cancel'), '', '*', '*', 'Index', array()),
	'',
	$vn_book_id ? caNavButton($this->request, __CA_NAV_ICON_DELETE__, _t('Delete'), '', '*', '*', 'Delete', array('book' => $vn_book_id)) : ''
);
?>
</form>

<div style="margin-bottom:100px;"></div>

<style type="text/css">
	.book-input {
		width: 100%;
	}
	.book-textarea {
		width: 100%;
		height: 140px;
	}
	.book-hint {
		color: #808080;
		margin: 4px 0 0 0;
	}
</style>
