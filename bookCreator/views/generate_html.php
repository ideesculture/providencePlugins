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
$book_id = (int)$this->getVar('book_id');
$job     = $this->getVar('job');
$error   = $this->getVar('error');
$notification = $this->getVar('notification');

$status   = is_array($job) ? $job['status'] : 'none';
$progress = is_array($job) ? (int)$job['progress'] : 0;
$message  = is_array($job) ? $job['message'] : null;

$status_url = caNavUrl($this->request, 'bookCreator', 'Generation', 'Status', ['book' => $book_id]);
?>
<h1><?php print _t('Generating the book'); ?></h1>

<?php if ($error) { ?>
	<div class="alert alert-danger"><?php print htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php } ?>

<?php if ($notification) { ?>
	<div class="alert alert-success"><?php print htmlspecialchars($notification, ENT_QUOTES, 'UTF-8'); ?></div>
<?php } ?>

<?php
// Only pending and running are in progress. A done or cancelled job is history:
// offering to cancel it answered nonsense, and leaving out the generate button
// turned a cancellation into a dead end with no way back.
$is_running = in_array($status, ['pending', 'running'], true);
?>
<div id="bookGenerationState">
<?php if (!$is_running) { ?>
	<?php if ($status === 'none') { ?>
		<p><?php print _t('No generation is running for this book.'); ?></p>
	<?php } else { ?>
		<p class="bookGenerationStatus"><?php print htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8'); ?></p>
	<?php } ?>
	<?php print caNavButton($this->request, __CA_NAV_ICON_PDF__, _t('Generate the PDF'), '', '*', 'Generation', 'Submit', array_merge(['book' => $book_id], BookCsrf::param())); ?>
<?php } else { ?>
	<p class="bookGenerationStatus"><?php print htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8'); ?></p>
	<?php print caNavButton($this->request, __CA_NAV_ICON_CANCEL__, _t('Cancel this generation'), '', '*', 'Generation', 'Cancel', array_merge(['book' => $book_id], BookCsrf::param())); ?>
	<progress id="bookGenerationProgress" value="<?php print $progress; ?>" max="100"></progress>
	<span id="bookGenerationPercent"><?php print $progress; ?>%</span>
<?php } ?>
</div>

<div id="bookGenerationDone" style="display:none;">
	<a id="bookGenerationLink" href="#" class="form-button"><span class="form-button"><?php print _t('Download the PDF'); ?></span></a>
</div>

<script>
/* Poll the job while it runs. The interval is deliberately slow: a catalogue
   takes minutes, and a tight loop would only add load to the server that is
   busy rendering it. Polling stops on a terminal state, so an abandoned tab
   does not keep asking forever. */
(function () {
	var status = <?php print json_encode($status); ?>;
	if (status !== 'pending' && status !== 'running') { return; }

	var url = <?php print json_encode($status_url); ?>;
	var timer = setInterval(function () {
		jQuery.getJSON(url, function (data) {
			var bar = document.getElementById('bookGenerationProgress');
			var pct = document.getElementById('bookGenerationPercent');
			if (bar) { bar.value = data.progress; }
			if (pct) { pct.textContent = data.progress + '%'; }

			var line = document.querySelector('.bookGenerationStatus');
			if (line && data.message) { line.textContent = data.message; }

			if (data.status === 'done' || data.status === 'error') {
				clearInterval(timer);
			}
			if (data.status === 'done' && data.url) {
				var link = document.getElementById('bookGenerationLink');
				link.href = data.url;
				document.getElementById('bookGenerationDone').style.display = '';
			}
		});
	}, 3000);
})();
</script>
