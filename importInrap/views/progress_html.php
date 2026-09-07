<?php
    $keys = $this->getVar("keys");
    $type = $this->getVar("type");
    $length = $this->getVar("length");
    $start = $this->getVar("start");
    $uploadedFile = $this->getVar("uploadedFile");
    $removed = $this->getVar("removed");
    $jsonPath = $this->getVar("jsonPath");
    $json = file_get_contents($jsonPath);
    $json = json_decode($json, true);
    $allRows = $this->getVar("allRows");
    $idnoPrefix = $this->getVar("idnoPrefix");
    $idnoPrefixScope = $this->getVar("idnoPrefixScope");
    $total = sizeof($json);
?>
<style>
:root {
  --success: #00b894;
  --progress: #54b1c5;
}

.progressbar-wrapper {
  background-color: #dfe6e9;
  color: white;
  width: 100%;
}

.progressbar {
  background-color: var(--progress);
  color: white;
  padding: 0.5rem;
  text-align: right;
  font-size: 14px;
}

.progressbar[title="downloading"] {
   background-color: var(--progress);
}

.progressbar[title="downloaded"] {
   background-color: var(--success);
}

</style>
<div class="container">
     <p>Import en cours...</p>
     <div class="progressbar-wrapper">
      <div title="downloading" class="progressbar" style="width:<?= round($start/$total*100) ?>%"><?= round($start/$total*100) ?>%</div>
     </div>
</div>
<!-- Formulaire pour transmettre les données -->
<form method="post" id="continue" action="/index.php/importInrap/Import/Import">
    <input type="hidden" name="keys" value="<?php echo htmlspecialchars(json_encode($keys)); ?>">
    <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
    <input type="hidden" name="length" value="<?php echo htmlspecialchars($length); ?>">
    <input type="hidden" name="start" value="<?php echo htmlspecialchars($start); ?>">
    <input type="hidden" name="uploadedFile" value="<?php echo htmlspecialchars($uploadedFile); ?>">
    <input type="hidden" name="json" value="<?php echo htmlspecialchars($jsonPath); ?>">
    <input type="hidden" name="allRows" value="<?php echo htmlspecialchars($allRows); ?>">
    <input type="hidden" name="idno_prefix" value="<?php echo htmlspecialchars($idnoPrefix); ?>">
    <input type="hidden" name="idno_prefix_scope" value="<?php echo htmlspecialchars($idnoPrefixScope); ?>">

    <!-- Bouton pour soumettre le formulaire -->
    <button type="submit" style="margin-top: 20px;background:white;color:white;border:none;">Continuer</button>
</form>

<script>
	function submitForm() {
		document.getElementById('continue').submit();
	}
	// Soumettre le formulaire après 500 ms
	setTimeout(submitForm, 500);
</script>