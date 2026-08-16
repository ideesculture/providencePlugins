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
$va_books          = $this->getVar('books');
$va_themes         = $this->getVar('themes');
$va_formats        = $this->getVar('formats_by_theme');
$vn_confirm_book   = $this->getVar('confirm_book_id');
$vs_notification   = $this->getVar('notification');
$vs_error          = $this->getVar('error');

if (!is_array($va_books))   { $va_books = array(); }
if (!is_array($va_themes))  { $va_themes = array(); }
if (!is_array($va_formats)) { $va_formats = array(); }

/**
 * Everything read from the database goes through here before reaching the
 * page: a title holding an apostrophe or an angle bracket is ordinary book
 * data, not an attack, and it must render as typed.
 */
$esc = function($vm_value) {
	return htmlspecialchars((string)$vm_value, ENT_QUOTES, 'UTF-8');
};

/** Escaped title, with a placeholder so an untitled book stays clickable. */
$title = function($vm_value) use ($esc) {
	return strlen(trim((string)$vm_value)) ? $esc($vm_value) : _t('(untitled)');
};

// The row awaiting deletion confirmation, when there is one.
$va_confirm = null;
if ($vn_confirm_book) {
	foreach ($va_books as $va_book) {
		if ((int)$va_book['book_id'] === (int)$vn_confirm_book) { $va_confirm = $va_book; break; }
	}
}
?>
<h1><?php print _t('Books'); ?></h1>

<?php if ($vs_error) { ?>
	<div class="alert alert-danger"><?php print $esc($vs_error); ?></div>
<?php } ?>

<?php if ($vs_notification) { ?>
	<div class="alert alert-success"><?php print $esc($vs_notification); ?></div>
<?php } ?>

<?php if (is_array($va_confirm)) { ?>
	<div class="bookCreatorConfirm">
		<p>
			<?php print _t('Delete the book &laquo; %1 &raquo; and its %2 sections? This cannot be undone.',
				$title($va_confirm['title']), (int)$va_confirm['nb_sections']); ?>
		</p>
		<?php print caFormTag($this->request, 'Delete/book/'.(int)$va_confirm['book_id'], 'deleteBook', 'bookCreator/Books'); ?>
		<?php print caFormControlBox(
			caFormSubmitButton($this->request, __CA_NAV_ICON_DELETE__, _t('Delete'), 'deleteBook').' '.
			caNavButton($this->request, __CA_NAV_ICON_CANCEL__, _t('Cancel'), '', '*', '*', 'Index', array()),
			'',
			''
		); ?>
		</form>
	</div>
<?php } ?>

<?php if (!sizeof($va_books)) { ?>
	<p><?php print _t('No book yet. Create the first one to start composing.'); ?></p>
<?php } else { ?>
	<table class="bookCreatorList">
		<tr>
			<th><?php print _t('Title'); ?></th>
			<th><?php print _t('Format'); ?></th>
			<th><?php print _t('Theme'); ?></th>
			<th class="numeric"><?php print _t('Sections'); ?></th>
			<th class="numeric"><?php print _t('Pages'); ?></th>
			<th><?php print _t('Last modified'); ?></th>
			<th><?php print _t('Actions'); ?></th>
		</tr>
		<?php foreach ($va_books as $va_book) {
			$vn_book_id = (int)$va_book['book_id'];
			$vs_theme   = (string)$va_book['theme'];
			$vs_format  = (string)$va_book['page_format'];

			// Codes are shown as they are stored when the theme declares no
			// display name, which is also how a theme deleted from disk stays
			// readable in the list instead of showing an empty cell.
			$vs_theme_label  = isset($va_themes[$vs_theme]) ? $va_themes[$vs_theme] : $vs_theme;
			$vs_format_label = isset($va_formats[$vs_theme][$vs_format]) ? $va_formats[$vs_theme][$vs_format] : $vs_format;
		?>
		<tr>
			<td>
				<?php print caNavLink($this->request, $title($va_book['title']), '', '*', '*', 'Edit', array('book' => $vn_book_id)); ?>
				<?php if (strlen((string)$va_book['subtitle'])) { ?>
					<br/><small><?php print $esc($va_book['subtitle']); ?></small>
				<?php } ?>
				<?php if (strlen((string)$va_book['idno'])) { ?>
					<br/><small class="idno"><?php print $esc($va_book['idno']); ?></small>
				<?php } ?>
			</td>
			<td><?php print $esc($vs_format_label); ?></td>
			<td><?php print $esc($vs_theme_label); ?></td>
			<td class="numeric"><?php print (int)$va_book['nb_sections']; ?></td>
			<td class="numeric"><?php print (int)$va_book['nb_pages']; ?></td>
			<td>
				<?php
					// Timestamps are Unix seconds; a book never rendered nor
					// touched since an import may carry none.
					print $va_book['modified_on']
						? $esc(caGetLocalizedDate((int)$va_book['modified_on']))
						: '&ndash;';
				?>
			</td>
			<td class="actions">
				<?php print caNavButton($this->request, __CA_NAV_ICON_EDIT__, _t('Edit the book settings'), '', '*', '*', 'Edit', array('book' => $vn_book_id), array(), array('dont_show_content' => true)); ?>
				<?php print caNavButton($this->request, __CA_NAV_ICON_HIER__, _t('Sections'), '', 'bookCreator', 'Editor', 'BookSections', array('book' => $vn_book_id), array(), array('dont_show_content' => true)); ?>
				<?php print caNavButton($this->request, __CA_NAV_ICON_DUPLICATE__, _t('Duplicate'), '', '*', '*', 'Duplicate', array('book' => $vn_book_id), array(), array('dont_show_content' => true)); ?>
				<?php print caNavButton($this->request, __CA_NAV_ICON_DELETE__, _t('Delete'), '', '*', '*', 'Delete', array('book' => $vn_book_id), array(), array('dont_show_content' => true)); ?>
			</td>
		</tr>
		<?php } ?>
	</table>
<?php } ?>

<br/>
<?php print caNavButton($this->request, __CA_NAV_ICON_ADD__, _t('Add a book'), '', '*', '*', 'Edit', array('book' => 0)); ?>

<div style="margin-bottom:100px;"></div>

<style type="text/css">
	.bookCreatorList {
		width: 100%;
		border-collapse: collapse;
		margin: 10px 0 20px 0;
	}
	.bookCreatorList th,
	.bookCreatorList td {
		text-align: left;
		padding: 6px 10px;
		border-bottom: 1px solid #e0e0e0;
		vertical-align: top;
	}
	.bookCreatorList th.numeric,
	.bookCreatorList td.numeric {
		text-align: right;
		white-space: nowrap;
	}
	.bookCreatorList td.actions {
		white-space: nowrap;
	}
	.bookCreatorList small.idno {
		color: #808080;
	}
	.bookCreatorConfirm {
		border: 1px solid #d0d0d0;
		padding: 10px 15px;
		margin: 10px 0 20px 0;
	}
</style>
