<?php
$items = $this->getVar("items");
$list_code = $this->getVar("list_code");
$sort = $this->getVar("sort");
$parent = $this->getVar("parent");
//$facet_code = 
$facet = $this->getVar("facet");
$vt_list = new ca_lists();
$vt_list->load(["list_code"=>$list_code,"deleted"=>0]);
if(!$parent) {
	print "<h2 style='color:black'>".$vt_list->get("ca_list_labels.name")." <small style='color:gray'>".$vt_list->numItemsInList($list_code)." éléments</small></h2>\n";
	if($sort == 1) {
		print "<p>Trié par rang</p>";
	} else {
		print "<p>Trié par libellé</p>";
	}
}

foreach($items as $key=>$item) {
	if($item["children"]) {
		print "<i class='caIcon fa fa-plus-circle editIcon plusIcon".$item["item_id"]."' onClick='fillChildren(\"".$list_code."\",".$item["item_id"].",\"".$facet."\")'></i> <a href='/gestion/index.php/administrate/setup/list_item_editor/ListItemEditor/Edit/item_id/".$item["item_id"]."'><i class='caIcon fa fa-file editIcon'></i></a> ".$item["name_singular"].( $facet ? " <i class='caIcon fa fa-search editIcon' onClick=\"jumpToObjectBrowse('".$facet."', ".$item["item_id"].")\"></i>" : "")."
	<div id='displayZone".$item["item_id"]."' style='padding-left:30px'></div>
	\n";
	} else {
		print "<i class='caIcon fa fa-circle editIcon plusIcon".$item["item_id"]."'></i> <a href='/gestion/index.php/administrate/setup/list_item_editor/ListItemEditor/Edit/item_id/".$item["item_id"]."'><i class='caIcon fa fa-file editIcon'></i></a> ".$item["name_singular"].( $facet ? " <i class='caIcon fa fa-search editIcon' onClick=\"jumpToObjectBrowse('".$facet."', ".$item["item_id"].")\"></i>" : "")."
	<div id='displayZone".$item["item_id"]."' style='padding-left:30px'></div>
	\n";
	}
	
}
?>
