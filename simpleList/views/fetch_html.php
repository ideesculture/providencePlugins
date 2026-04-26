<?php
$items = $this->getVar('items');
$list_code = $this->getVar('list_code');
$parent = $this->getVar('parent');

$vt_list = new ca_lists();
$vt_list->load(['list_code' => $list_code, 'deleted' => 0]);

$url_item_editor_base = caNavUrl($this->request, 'administrate/setup/list_item_editor', 'ListItemEditor', 'Edit');

if (!$parent) {
	print "<h2 style='color:black'>"
		.htmlspecialchars($vt_list->get('ca_list_labels.name'))
		." <small style='color:gray'>".(int)$vt_list->numItemsInList($list_code)." "._t('items')."</small></h2>\n";
}

foreach ($items as $item) {
	$id = (int)$item['item_id'];
	$has_children = (int)$item['children'] > 0;
	$icon_class = $has_children ? 'fa-plus-circle' : 'fa-circle';
	$onclick = $has_children
		? " onclick='simpleListFillChildren(\"".htmlspecialchars($list_code, ENT_QUOTES)."\",".$id.")'"
		: '';
	?>
	<i class='caIcon fa <?= $icon_class ?> editIcon plusIcon<?= $id ?>'<?= $onclick ?>></i>
	<a href='<?= $url_item_editor_base ?>/item_id/<?= $id ?>'><i class='caIcon fa fa-file editIcon'></i></a>
	<?= htmlspecialchars($item['name_singular']) ?>
	<div id='displayZone<?= $id ?>' style='padding-left:30px'></div>
	<?php
}
