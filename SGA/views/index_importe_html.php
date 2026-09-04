<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.10.25/css/jquery.dataTables.css">
<script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.10.25/js/jquery.dataTables.js"></script>

<h1>Listes des opérations importées du SGA</h1>
<?php include "table_importe.html";?>

<script>
$(document).ready( function () {
    $('#sga').DataTable();
} );
</script>