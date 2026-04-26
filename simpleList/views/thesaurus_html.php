<?php
$lists = $this->getVar('lists');
$label = $this->getVar('label');

$url_fetch = caNavUrl($this->request, 'simpleList', 'Editor', 'Fetch');
$url_add   = caNavUrl($this->request, 'simpleList', 'Editor', 'AddItems', ['page' => 'listes']);
$url_list_editor_base = caNavUrl($this->request, 'administrate/setup/list_editor', 'ListEditor', 'Edit');
?>
<h1><?= htmlspecialchars($label) ?></h1>

<div class="simpleList-tree">
<?php foreach ($lists as $list): ?>
	<?php if ($list['list_code']): ?>
		<p>
			<i class="caIcon fa fa-plus-circle editIcon" onclick="simpleListFillChildren('<?= htmlspecialchars($list['list_code']) ?>', null)"></i>
			<a href="<?= $url_list_editor_base ?>/list_id/<?= (int)$list['list_id'] ?>" class="list-button"><i class="caIcon fa fa-file editIcon fa-2x"></i></a>
			<?= htmlspecialchars($list['name']) ?>
			<div id="<?= htmlspecialchars($list['list_code']) ?>_content" style="display:none;"></div>
		</p>
	<?php else: ?>
		<p style="padding-left:32px"><?= htmlspecialchars($list['name']) ?></p>
	<?php endif; ?>
<?php endforeach; ?>
</div>

<div id="editZone" class="simpleList-edit">
	<div style="text-align:right">
		<b style="cursor:pointer" onclick="$('#addForm').slideToggle();">
			<i class="caIcon fa fa-plus-circle editIcon"></i>
			<?= _t('Add list items') ?>
		</b>
	</div>
	<form id="addForm" method="post" action="<?= $url_add ?>" style="display:none;">
		<input id="addFormListCode" type="hidden" name="list_code" value="">
		<textarea name="items" style="width:540px;height:16px;"></textarea>
		<i class="caIcon fa fa-bars editIcon" onclick="$(this).hide();$('#addForm textarea').css('height','440px').css('width','560px');"></i>
		<br>
		<button type="submit"><?= _t('Add') ?></button>
	</form>
	<div id="displayZone"></div>
</div>

<script>
(function() {
	var FETCH_URL = <?= json_encode($url_fetch) ?>;

	window.simpleListFillChildren = function(list_code, parent) {
		if (parent !== undefined && parent !== null) {
			var $zone = $('#displayZone' + parent);
			$zone.html('<i class="caIcon fa fa-spinner fa-spin"></i>');
			if (!$('.plusIcon' + parent).hasClass('fa-minus-circle')) {
				$.get(FETCH_URL + '/list_code/' + encodeURIComponent(list_code) + '/parent/' + parent, function(data) {
					if (data) {
						$zone.html(data);
						$('.plusIcon' + parent).removeClass('fa-plus-circle').addClass('fa-minus-circle');
					} else {
						$('.plusIcon' + parent).removeClass('fa-plus-circle').addClass('fa-circle');
					}
				});
			} else {
				$zone.html('');
				$('.plusIcon' + parent).addClass('fa-plus-circle').removeClass('fa-minus-circle');
			}
		} else {
			$('#displayZone').html('<i class="caIcon fa fa-spinner fa-spin"></i>');
			$.get(FETCH_URL + '/list_code/' + encodeURIComponent(list_code), function(data) {
				$('#editZone').show();
				$('#displayZone').html(data);
				$('#addFormListCode').attr('value', list_code);
			});
		}
	};
})();
</script>

<style>
	#leftNav { display:none; }
	#mainContent { margin-left:0 !important; width:957px; border-left:2px solid #DDDDDD; }
	.simpleList-tree { width:280px; height:810px; overflow-x:hidden; overflow-y:scroll; float:left; }
	.simpleList-edit { position:absolute; top:50px; right:50px; height:810px; overflow-x:hidden; overflow-y:auto; width:600px; }
</style>
