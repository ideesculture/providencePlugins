<?php
	$lists = $this->getVar("lists");
	$facet = $this->getVar("facet");
	$items = $this->getVar("items");
	$label = $this->getVar("label");
	?>
<h1><?= $label ?></h1>

<div style="width:280px;height:810px;overflow-x:hidden;overflow-y:scroll;">
<?php

foreach($lists as $list) {
	//var_dump($list);die();
	if($list["list_code"]):
	?>
		<p>
			<i class="caIcon fa fa-plus-circle editIcon" onClick="fillChildren('<?= $list["list_code"] ?>', null, '<?= $list["facet"] ?>')"></i>
			<a href="/gestion/index.php/administrate/setup/list_editor/ListEditor/Edit/list_id/<?= $list["list_id"]; ?>" class="list-button"><i class="caIcon fa fa-file editIcon fa-2x"></i></a>
			<?= $list["name"]?> <br/>
			<div id="<?= $list["list_code"] ?>_content" style="display:none;">
				
			</div>
		</p>
	<?php else: 
		print "<p style='padding-left:32px'>".$list["name"]."<br/>\n";
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
</div>
<div style="position:absolute;top:50px;right:50px;
                height: 810px;
                overflow-x: hidden;
                overflow-y: auto;" id="editZone">
	<div style="text-align:right">
		<b style="font-weight: bold;font-family: sans-serif;padding-right:14px;cursor:pointer;" onClick="$('#addForm').slideToggle();">
		<i class="caIcon fa fa-plus-circle editIcon" ></i>
		Ajouter un élément de liste</b></div>
		<form id="addForm" method="post" action="/gestion/index.php/simpleList/Editor/AddItems/page/references" style="display:none;">
			<input id="addFormListCode" type="hidden" name="list_code" value="test"></input>
			<textarea name="items" style="width:540px;height:16px;"></textarea>
			<i class="caIcon fa fa-bars editIcon" onClick="$(this).hide();$('#addForm textarea').css('height','440px').css('width','560px');"></i>
			<br/>
			<button type="submit">Ajouter</button>
			<div style="height:40px"></div>
		</form>
		<div style="width:500px;" id="displayZone">
		</div>

</div>
<div style="height:40px;clear:both"></div>
<script>
	function fillChildren(list_code, parent, facet_code) {
		if((parent !== undefined) && (parent !== null)) {
			if(!$(".plusIcon"+parent).hasClass("fa-minus-circle")) {
				$.get("/gestion/index.php/simpleList/Editor/Fetch/list_code/"+list_code+"/parent/"+parent+"/facet/"+facet_code, function( data ) {
					//console.log(data);
					//if(data != "") {
						$("#displayZone"+parent).html( data );
						$(".plusIcon"+parent).removeClass("fa-plus-circle").addClass("fa-minus-circle");
					//} else {
					//	$(".plusIcon"+parent).removeClass("fa-plus-circle").addClass("fa-circle");
					//}
					
				});
			} else {
				$("#displayZone"+parent).html("");
				$(".plusIcon"+parent).addClass("fa-plus-circle").removeClass("fa-minus-circle");
			}
			
		} else {
			$.get("/gestion/index.php/simpleList/Editor/Fetch/list_code/"+list_code+"/facet/"+facet_code, function( data ) {
				$("#editZone").show();
				$("#displayZone").html( data );
				$("#addFormListCode").attr("value", list_code);
			});
		}
	}
	function jumpToObjectBrowse(facet_code, facet_id) {
		$.get("/gestion/index.php/find/BrowseObjects/clearCriteria", function( data ) {
				window.location = "/gestion/index.php/find/BrowseObjects/modifyCriteria/facet/"+facet_code+"/id/"+facet_id+"/mod_id/0";
				
		});
	}
</script>
<style>
	#leftNav {
		display:none;
	}
	#mainContent {
		margin-left:0 !important;
		width:957px;
		border-left:2px solid #DDDDDD;
	}
</style>