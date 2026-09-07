<?php 
    // 07/09/2026 GM : le chemin relatif partait de app/plugins/SGA/lib et remontait de quatre
    // niveaux jusqu'à providence/setup.php. Depuis que le plugin est déployé par lien
    // symbolique depuis providencePlugins (cf. .claude/CLAUDE.md §3), PHP résout le lien : la
    // remontée sort du dépôt et ne trouve plus rien. Ce script étant lancé hors du socle (cron
    // quotidien), aucune constante n'est posée pour l'aider. On cherche donc la racine parmi
    // des candidats, en ne retenant que celui qui porte setup.php ET app/lib. CA_RACINE force.
    $_racine = null;
    foreach ([getenv('CA_RACINE') ?: null,
              isset($_SERVER['SCRIPT_FILENAME']) ? dirname($_SERVER['SCRIPT_FILENAME'], 5) : null,
              getcwd() ?: null,
              '/var/www/comodo/collectiveaccess/providence'] as $_c) {
        if ($_c && file_exists($_c . '/setup.php') && is_dir($_c . '/app/lib')) { $_racine = $_c; break; }
    }
    if (!$_racine) { fwrite(STDERR, "update_table : racine de Providence introuvable ; poser CA_RACINE.\n"); exit(2); }
    require_once $_racine . "/setup.php";
    require_once __CA_MODELS_DIR__."/ca_collections.php";
    $req = "select id, idno, dir_inrap, centre_op, commune, lieudit from _sga_comodo;";
    $o_data = new Db();
    $qr_result = $o_data->query($req);

    $sga_table_import = "
	<head>
  		<meta charset='UTF-8'>
		<META HTTP-EQUIV='Pragma' CONTENT='no-cache'>
		<META HTTP-EQUIV='Expires' CONTENT='-1'>
	</head>
	<body>
	<table id='sga'>
        <thead>
            <tr>
                <th>Code Inrap</th>
                <th>Direction</th>
                <th>Centre opérationnel</th>
                <th>Commune</th>
                <th>Lieu-dit</th>
                <th></th>
            </tr>
        </thead><tbody>";
    $sga_table_non_import = $sga_table_import;
    while($qr_result->nextRow()) {
        $vt_col = new ca_collections();
        $vt_col->load(["idno" => $qr_result->get("idno"), "deleted" => 0]);
        if ($vt_col->getPrimaryKey()){
            $sga_table_import.="<tr>";
            $sga_table_import .="<td>".$qr_result->get("idno")."</td>\n";
            $sga_table_import .="<td>".$qr_result->get("dir_inrap")."</td>\n";
            $sga_table_import .="<td>".$qr_result->get("centre_op")."</td>\n";
            $sga_table_import .="<td>".$qr_result->get("commune")."</td>\n";
            $sga_table_import .="<td>".$qr_result->get("lieudit")."</td>\n";
            $sga_table_import .="<td><a target=_top href='/index.php/SGA/SGA/Compare/id/".$qr_result->get("id")."'>Mettre à jour</a></td>";
            $sga_table_import .="</tr>\n";
        }else{                    
            $sga_table_non_import.="<tr>\n";
            $sga_table_non_import .="<td>".$qr_result->get("idno")."</td>\n";
            $sga_table_non_import .="<td>".$qr_result->get("dir_inrap")."</td>\n";
            $sga_table_non_import .="<td>".$qr_result->get("centre_op")."</td>\n";
            $sga_table_non_import .="<td>".$qr_result->get("commune")."</td>\n";
            $sga_table_non_import .="<td>".$qr_result->get("lieudit")."</td>\n";
            $sga_table_non_import .="<td><a target=_top href='/index.php/SGA/SGA/Compare/id/".$qr_result->get("id")."'>Importer</a></td>";
            $sga_table_non_import .="</tr>\n";
        }
    }
    $sga_table_non_import.= "</tbody></table></body>\n";
    $sga_table_import.= "</tbody></table></body>\n";
    unlink(__CA_APP_DIR__."/plugins/SGA/views/table_importe.html");
    unlink(__CA_APP_DIR__."/plugins/SGA/views/table_non_importe.html");

    file_put_contents(__CA_APP_DIR__."/plugins/SGA/views/table_importe.html", $sga_table_import);

    // 07/09/2026 GM (ticket 7913) : la table des non importées était précédée d'un bloc chargeant
    // jQuery et DataTables, puis armant $("#sga").DataTable(). Ces deux pages sont affichées dans
    // une iframe : le DataTable s'exécutait donc bel et bien, sur 60 604 lignes, et figeait le
    // navigateur — c'est le « le script ne va pas jusqu'au bout » signalé par le client. La table
    // des importées n'a jamais eu ce bloc, d'où le déséquilibre entre les deux pages.
    //
    // On écrit désormais les deux tables de la même façon : un document HTML simple, sans script,
    // que le navigateur affiche au fil de l'eau. L'identifiant « sga » est conservé.
    //
    // Ce n'est pas la solution de fond : à cette volumétrie il faudra une pagination côté serveur
    // sur _sga_comodo, avec un tri et une recherche qui s'exécutent en base et non chez le client.
    file_put_contents(__CA_APP_DIR__."/plugins/SGA/views/table_non_importe.html", $sga_table_non_import);

        ?>