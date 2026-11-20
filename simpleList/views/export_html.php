<header>
	<meta charset="UTF-8">
</header>
<body>
<!-- <body onload='window.print()'> -->
<?php
error_reporting(E_ERROR);
	$id = $this->getVar("object_id");
	$item = $this->getVar("item");
	$template = $this->getVar("template");
	$db = new Db();
	error_reporting(E_ERROR);

	function printItemWithTemplate ($item, $template) {
	    $type = $item->get("ca_objects.type_id");
	    if(isset($template[$type]) && trim($template[$type])) {
		    $return = $item->getWithTemplate($template[$type]);
        } else {
            $return = $item->getWithTemplate($template["_default"]);
        }
        print $return;
	}


	function printHierarchyFromItemWithTemplate($db, $id, $template, $level) {
		$item = new ca_objects($id);
		print "<blockquote>";
        printItemWithTemplate($item, $template);
		$num_children = reset($item->getHierarchyChildCountsForIDs([$id]));
		if($num_children) {
			$children = $item->getHierarchyChildren($id);
			foreach($children as $child) {
				printHierarchyFromItemWithTemplate($db, $child["object_id"], $template, $level+1);
			}
		}
		print "</blockquote>";
	}

	//printItemWithTemplate($item, $templateH1);
	printHierarchyFromItemWithTemplate($db, $id, $template, 1);
?>
<style>
body {
	font-size:10px;
	font-family: "DIN-Regular", Arial, Helvetica, sans-serif;
}
h1, h2, h3, h4, h5, h6 {
	font-weight: bold;
	font-family: "DIN-Regular", Arial, Helvetica, sans-serif;

}
	blockquote blockquote {
		border-left:1px solid lightgray;
		padding-left:10px;
	}
</style>
</body>
