<?php
	$lists = $this->getVar("lists");
	$items = $this->getVar("items");
	//var_dump($items);
	//var_dump($lists);
	//die();
	?>
<h1>Références</h1>

<?php

foreach($lists as $list) {
	//var_dump($list);die();
	if($list["list_code"]):
	?>
		<p><a href="/gestion/index.php/administrate/setup/list_editor/ListEditor/Edit/list_id/<?= $list["list_id"]; ?>" class="list-button"><i class="caIcon fa fa-file editIcon fa-2x"></i></a>
	<?php
	print $list["name"]."<br/>\n";
	?>
	</p>
	<?php else: 
		print "<p style='padding-left:32px;'>".$list["name"]."<br/>\n";
	endif; ?>
	<?php
	//var_dump($items[$list["list_id"]]);die();
		foreach($items[$list["list_id"]] as $item) {
			//print "<span style='padding-left:20px'>".$item["name_singular"]."</span><br/>";
		}
	?>
	<?php
}

?>
<div style="height:120px"></div>