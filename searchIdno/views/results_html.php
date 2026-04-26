<?php
$results = $this->getVar("results");
$search2 = $this->getVar("search2");

$o_config_file = __CA_APP_DIR__ . '/plugins/searchIdno/conf/local/searchIdno.conf';
if (!file_exists($o_config_file)) {
	$o_config_file = __CA_APP_DIR__ . '/plugins/searchIdno/conf/searchIdno.conf';
}
$o_config = Configuration::load($o_config_file);

$identifier_template = $o_config->get('result_identifier_template');
if (!$identifier_template) { $identifier_template = '^ca_objects.idno'; }

$identifier_label = $o_config->get('result_identifier_label');
if (!$identifier_label) { $identifier_label = _t('Identifier'); }

$datatables_lang_url = $o_config->get('datatables_lang_url');
?>
<link rel="stylesheet" href="//cdn.datatables.net/1.11.4/css/jquery.dataTables.min.css" />
<script src="//cdn.datatables.net/1.11.4/js/jquery.dataTables.min.js"></script>

<?php if (!is_array($results) || sizeof($results) === 0): ?>
	<p><?php print _t('No result found for "%1".', htmlspecialchars((string) $search2)); ?></p>
<?php else: ?>
<table id="results">
	<thead>
		<tr>
			<th><?php print _t('Edit'); ?></th>
			<th><?php print htmlspecialchars($identifier_label); ?></th>
			<th><?php print _t('Title'); ?></th>
		</tr>
	</thead>
	<tbody>
<?php
foreach ($results as $result) {
	$id = $result["object_id"];
	$vt_object = new ca_objects($id);
	$edit_url = caEditorUrl($this->request, 'ca_objects', $id);
	print "<tr><td>"
		. '<a href="'.htmlspecialchars($edit_url).'"><i class="caIcon fa fa-file editIcon fa-2x"></i></a>'
		. "</td><td>" . $vt_object->getWithTemplate($identifier_template)
		. "</td><td>" . $vt_object->get("preferred_labels")
		. "</td></tr>";
}
?>
	</tbody>
</table>
<script>
$(document).ready(function () {
	$('#results').DataTable({
		<?php if ($datatables_lang_url): ?>
		language: { url: '<?php print $datatables_lang_url; ?>' }
		<?php endif; ?>
	});
});
</script>

<style>tr.odd { background-color: lightgray !important; }</style>
<?php endif; ?>
