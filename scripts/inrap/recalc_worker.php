#!/usr/bin/env php
<?php
/**
 * recalc_worker.php — Consomme _inrap_recalc_queue et exécute les recalculs.
 *
 * À lancer périodiquement par cron (toutes les 1-3 min).
 *
 * Usage :
 *   sudo -u www-data php support/bin/recalc_worker.php           # 1 batch, exit
 *   sudo -u www-data php support/bin/recalc_worker.php --max=N   # limiter à N items
 *   sudo -u www-data php support/bin/recalc_worker.php --verbose # log chaque item
 *
 * Garde anti-concurrence : un seul worker à la fois grâce au lockfile.
 */

// 07/09/2026 GM : ne pas déduire la racine de l'instance de __FILE__. Ce script est déployé
// par lien symbolique depuis providencePlugins/scripts/inrap (cf. .claude/CLAUDE.md §3) :
// __FILE__ est résolu par PHP et pointe vers le dépôt, où il n'y a pas de setup.php.
//
// On part du chemin d'appel — $_SERVER['SCRIPT_FILENAME'], que PHP ne résout pas : c'est le
// chemin du lien dans l'instance, celui que pose le cron. Et non de getcwd() : sous cron le
// répertoire courant est hors de l'instance, une remontée n'y trouverait rien. Celle-ci ne
// sert que de dernier recours, pour un lancement à la main depuis l'instance. CA_RACINE force.
$_racine = getenv('CA_RACINE') ?: null;
if (!$_racine) {
    $_appel = $_SERVER['SCRIPT_FILENAME'] ?? ($argv[0] ?? '');
    if (($_appel !== '') && ($_appel[0] !== '/')) { $_appel = getcwd() . '/' . $_appel; }
    $_cand = ($_appel !== '') ? dirname($_appel) . '/../..' : '';
    if (($_cand !== '') && file_exists($_cand . '/setup.php') && is_dir($_cand . '/app/lib')) { $_racine = $_cand; }
}
if (!$_racine) {
    $_cand = getcwd();
    for ($_i = 0; $_i < 6 && $_cand && $_cand !== '/'; $_i++) {
        if (file_exists($_cand . '/setup.php') && is_dir($_cand . '/app/lib')) { $_racine = $_cand; break; }
        $_cand = dirname($_cand);
    }
}
if (!$_racine || !file_exists($_racine . '/setup.php')) {
    fwrite(STDERR, "recalc_worker : racine de Providence introuvable ; poser CA_RACINE.\n");
    exit(2);
}
require_once($_racine . "/setup.php");
require_once(__CA_LIB_DIR__ . "/ApplicationPluginManager.php");
require_once(__CA_MODELS_DIR__ . "/ca_movements.php");
require_once(__CA_MODELS_DIR__ . "/ca_collections.php");
require_once(__CA_MODELS_DIR__ . "/ca_objects.php");
// === D2026-0103 §5 — source unique du titre automatique de versement, partagée avec la
// reprise de masse (0103_reprise_titres_versements.php) ===
require_once(__DIR__ . "/inrap_0103_label_versement.inc.php");

// 27/08/2026 GM : marque le contexte worker — le plugin prepopulateInrap exécute alors
// ses cascades en entier au lieu de les remettre en file.
putenv('INRAP_RECALC_WORKER=1');

// === D2026-0103 — délai de grâce avant la bascule « versé » : début ===
// 29/08/2026, demande L. Pelletier : la bascule des contenants en « versé » ne doit pas être
// instantanée. Elle intervient un délai de grâce APRÈS LA DATE DE VERSEMENT — et non après la
// validation du bordereau : le versement a lieu physiquement à cette date, et les gestionnaires
// doivent pouvoir corriger la sélection dans les jours qui suivent, quand l'état est confronté
// au réel. Un bordereau édité le lundi pour un versement du vendredi laisse donc la fenêtre
// ouverte jusqu'au lundi suivant, et non jusqu'au jeudi.
// Pendant la fenêtre, RIEN n'est basculé : retirer un contenant ne coûte rien, il n'y a pas de
// retour arrière à faire.
// Engagement d'origine : courriel du 12/08/2026 au réseau des collections, « un délai de grâce
// de 3 jours est posé avant ce passage, pour d'éventuelles corrections ».
// Valeur en secondes, volontairement isolée ici pour être ajustable sans toucher au reste.
define('INRAP_0103_DELAI_BASCULE_VERSE', 72 * 3600);   // 72 heures
// === D2026-0103 — délai de grâce : fin ===

$lockfile = '/var/www/comodo/backup/recalc_worker.lock';
$max_items = 100;
$verbose = in_array('--verbose', $argv);
$boucle_s = 0;
foreach ($argv as $a) {
    if (preg_match('/^--max=(\d+)$/', $a, $m)) { $max_items = (int)$m[1]; }
    // 27/08/2026 GM : mode boucle de garde — le worker reste vivant N secondes et scrute la
    // file toutes les 3 s, au lieu de sortir après son lot. Lancé chaque minute par cron avec
    // --boucle=55, la latence de prise en charge tombe de ≤60 s à ≤5 s ; le verrou garantit
    // toujours l'instance unique (les ticks suivants abandonnent tant qu'il vit).
    if (preg_match('/^--boucle=(\d+)$/', $a, $m)) { $boucle_s = (int)$m[1]; }
}

$fp = fopen($lockfile, 'c');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[recalc_worker] Un autre worker tourne déjà, abandon.\n");
    exit(0);
}

function log_line($msg) {
    echo "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
}

$db = new Db();
$processed = 0;
$ok = 0;
$err = 0;
$t_start = microtime(true);

log_line("worker démarré (max_items=$max_items)");

while ($processed < $max_items) {
    $q = $db->query("SELECT queue_id, table_num, row_id, enqueued_at FROM _inrap_recalc_queue WHERE status='pending' AND enqueued_at <= UNIX_TIMESTAMP() ORDER BY enqueued_at ASC LIMIT 1");
    if (!$q->nextRow()) {
        // 27/08/2026 GM : en mode boucle de garde, une file vide n'est pas une fin — on
        // souffle 3 s et on rescrute, tant que la fenêtre n'est pas écoulée.
        if ($boucle_s > 0 && (microtime(true) - $t_start) < $boucle_s) { sleep(3); continue; }
        break;
    }

    $qid = (int)$q->get('queue_id');
    $tnum = (int)$q->get('table_num');
    $rid = (int)$q->get('row_id');

    $db->query("UPDATE _inrap_recalc_queue SET status='processing', started_at=? WHERE queue_id=? AND status='pending'", time(), $qid);
    if ($db->affectedRows() === 0) {
        continue;
    }

    if ($verbose) log_line("  → item #$qid : table=$tnum row=$rid");

    try {
        switch ($tnum) {
            case 137:
                recalc_movement($rid, $verbose);
                break;
            case 13:
                // 26/08/2026 GM : rafraîchissement des caches d'affichage d'une opération
                // (poussé par prepopulateInrap quand un lieu, une entité ou un emplacement lié change)
                recalc_collection_caches($rid, $verbose);
                break;
            case 57:
                // 27/08/2026 GM : cascade prepopulate complète d'un objet, différée depuis le web
                recalc_cascade_complete('ca_objects', $rid, $verbose);
                break;
            case 113:
                // 27/08/2026 GM : cascade prepopulate complète d'une opération (type 125)
                recalc_cascade_complete('ca_collections', $rid, $verbose);
                break;
            case 2137:
                // 29/08/2026 — D2026-0103 : bascule « versé » arrivée à échéance.
                // Ce cas DOIT rester avant le `default`, que la branche « > 1000 »
                // capterait sinon en croyant à une réindexation de la table 1137.
                $mov_dif = new ca_movements($rid);
                if (!$mov_dif->getPrimaryKey() || (int)$mov_dif->get('deleted')) {
                    if ($verbose) log_line("    versement #$rid absent ou supprimé, bascule abandonnée");
                    break;
                }
                if ((int)$mov_dif->get('ca_movements.type_id') !== 1796) {
                    if ($verbose) log_line("    #$rid n'est plus un versement, bascule abandonnée");
                    break;
                }
                $mov_dif->setMode(ACCESS_WRITE);
                inrap_0103_bascule_types_verses($mov_dif, $verbose);
                break;
            default:
                // 27/08/2026 GM : codes 1000+table_num = réindexation Meilisearch SEULE du
                // document (compensation d'enregistrement différée, toutes tables). Si une
                // cascade complète est aussi en attente pour la même fiche (57/113), elle
                // réindexera de toute façon : on saute, la file se dédouble d'elle-même.
                if ($tnum > 1000) {
                    $vrai_tnum = $tnum - 1000;
                    $table = Datamodel::getTableName($vrai_tnum);
                    if (!$table) { throw new RuntimeException("table_num=$tnum : table " . $vrai_tnum . " inconnue"); }
                    $cascade_code = ($table === 'ca_objects') ? 57 : (($table === 'ca_collections') ? 113 : null);
                    if ($cascade_code) {
                        $qd = $db->query("SELECT 1 FROM _inrap_recalc_queue WHERE table_num=? AND row_id=? AND status IN ('pending','processing')", $cascade_code, $rid);
                        if ($qd->nextRow()) {
                            if ($verbose) log_line("    réindexation $table #$rid sautée : cascade $cascade_code en attente");
                            break;
                        }
                    }
                    reindex_document($table, $rid, $verbose);
                    break;
                }
                throw new RuntimeException("table_num=$tnum non géré par le worker pour l'instant");
        }
        $db->query("UPDATE _inrap_recalc_queue SET status='done', finished_at=?, error_msg=NULL WHERE queue_id=?", time(), $qid);
        $ok++;
    } catch (\Throwable $e) {
        // 27/08/2026 GM : un échec SQL au milieu d'une transaction de modèle laisse la
        // transaction OUVERTE sur cette connexion — l'écriture du statut « error » partait
        // dedans et se faisait rollbacker à la sortie, laissant l'item « processing » pour
        // l'éternité (constaté sur le mouvement 16775, bloqué depuis juillet). On rollbacke
        // explicitement avant d'écrire le statut ; sans transaction en cours, c'est un no-op.
        try { $db->query("ROLLBACK"); } catch (\Throwable $ignore) {}
        $db->query("UPDATE _inrap_recalc_queue SET status='error', finished_at=?, error_msg=? WHERE queue_id=?", time(), $e->getMessage(), $qid);
        $err++;
        log_line("  ✗ item #$qid ERREUR : " . $e->getMessage());
    }
    $processed++;
}

$dt = microtime(true) - $t_start;
log_line("worker fini : $processed traités ($ok OK, $err erreurs) en " . round($dt, 2) . "s");

fclose($fp);
@unlink($lockfile);


// 29/08/2026 — MAINTENANCE CORRECTIVE (incident file du 28/08, cf. RAPPORT_DEPLOIEMENT_PROD_20260828.md §8.2)
// Lit en SQL DIRECT la ou les valeurs actuellement stockées pour un élément d'une opération.
// SQL direct et non lecture par le modèle : le cache statique ca_attributes::$s_get_attributes_cache
// rend les lectures non fiables juste après une écriture (piège constaté le 28/08).
// Rend ['n' => nombre de valeurs trouvées, 'v' => la valeur si elle est unique].
function inrap_valeur_stockee($db, $collection_id, $code) {
    $qr = $db->query("SELECT v.value_longtext1 AS val
        FROM ca_attributes a
        JOIN ca_attribute_values v ON v.attribute_id = a.attribute_id
        JOIN ca_metadata_elements e ON e.element_id = v.element_id
        WHERE a.table_num = 13 AND a.row_id = ? AND e.element_code = ?", [$collection_id, $code]);
    $vals = [];
    while ($qr->nextRow()) { $vals[] = (string)$qr->get('val'); }
    return ['n' => sizeof($vals), 'v' => (sizeof($vals) === 1) ? $vals[0] : null];
}

// 29/08/2026 — l'élément existe-t-il dans le schéma ? Sans ce garde-fou, un élément absent
// est réécrit à chaque passage sans jamais se poser : la fonction ne converge pas et
// update() est appelé pour rien. Sans effet en production (les 9 éléments y existent) ;
// indispensable partout où le socle est plus ancien — en préproduction, 6 des 9 manquent.
function inrap_element_existe($db, $code) {
    static $connus = [];
    if (!array_key_exists($code, $connus)) {
        $qr = $db->query("SELECT element_id FROM ca_metadata_elements WHERE element_code = ?", [$code]);
        $connus[$code] = (bool)$qr->nextRow();
    }
    return $connus[$code];
}

// 26/08/2026 GM : recalcule compteurs + caches d'affichage d'une opération (éléments 742-747
// et 503/518/522). Même logique que support/bin/precalc_caches_collections.php — sans
// indexation ni journal des modifications (caches système, non indexés).
//
// 29/08/2026 — MAINTENANCE CORRECTIVE : on ne réécrit QUE ce qui change réellement, et on
// n'appelle update() que si au moins un élément a bougé. Motif : removeAttributes() se termine
// par un update() sans 'dontDoSearchIndexing' (BaseModelWithAttributes.php:671, cœur
// CollectiveAccess qu'on ne touche pas) ; appelée 9 fois par opération, elle provoquait 9
// réindexations Meilisearch complètes, chacune attendue de façon bloquante — ~13 s par élément.
// Or l'immense majorité de ces recalculs ne change rien (mesuré le 28/08 : 0 écart sur 2 178
// opérations enfilées d'un coup par un simple enregistrement de fiche entité). Ils coûtent
// désormais 9 SELECT et zéro écriture.
function recalc_collection_caches($collection_id, $verbose = false) {
    $coll = new ca_collections($collection_id);
    if (!$coll->getPrimaryKey() || $coll->get('deleted')) {
        if ($verbose) log_line("    collection $collection_id absente ou supprimée, ignorée");
        return;
    }
    if (!in_array((int)$coll->get('type_id'), [125, 39983], true)) { return; }
    $db = new Db();
    $coll->setMode(ACCESS_WRITE);
    $modifie = false;   // 29/08/2026 : passe à vrai dès qu'un élément diffère réellement
    $compteurs = [
        'inrap_nb_total_contenants' => '(o.type_id=30 or o.type_id=28)',
        'op_nb_contenants'          => '(o.type_id=28 or o.type_id=39988)',
        'op_doc_sci_contenants'     => '(o.type_id=30 or o.type_id=39987)',
        'op_nb_contenants_num'      => '(o.type_id=1886 or o.type_id=39989)',
    ];
    foreach ($compteurs as $code => $cond) {
        if (!inrap_element_existe($db, $code)) { continue; }   // 29/08/2026 : élément absent du schéma
        $qr = $db->query("select count(o.object_id) as nombre from ca_objects_x_collections oc
            join ca_objects o on o.object_id=oc.object_id
            where {$cond} and o.deleted=0 and oc.collection_id=?", [$collection_id]);
        $qr->nextRow();
        $nouveau = (string)(int)$qr->get("nombre");
        $actuel  = inrap_valeur_stockee($db, $collection_id, $code);
        // 29/08/2026 : inchangé (une seule valeur, identique) → aucune écriture, aucune réindexation.
        if ($actuel['n'] === 1 && $actuel['v'] === $nouveau) { continue; }
        $coll->removeAttributes($code, ['force' => true]);
        $coll->addAttribute([$code => (int)$nouveau], $code);
        $modifie = true;
    }
    $caches = [
        "inrap_cache_commune" => "<unit relativeTo='ca_places' restrictToRelationshipTypes='operation'>^ca_places.hierarchy.preferred_labels.name%delimiter=_➔_</unit>",
        "inrap_cache_centre" => "<unit relativeTo='ca_storage_locations' restrictToRelationshipTypes='entree_collection'>^ca_storage_locations.hierarchy.preferred_labels.name%delimiter=_>_ (^relationship_typename)</unit>",
        "inrap_cache_lieu_versement" => "<unit relativeTo='ca_storage_locations' restrictToRelationshipTypes='conservation'>^ca_storage_locations.hierarchy.preferred_labels.name%delimiter=_>_ (^relationship_typename)</unit>",
        "inrap_cache_responsable" => "<unit relativeTo='ca_entities' restrictToRelationshipTypes='responsable'>^ca_entities.preferred_labels.displayname</unit>",
        "inrap_cache_dir" => "<unit relativeTo='ca_entities' restrictToTypes='DIR'>^ca_entities.preferred_labels.displayname</unit>",
    ];
    foreach ($caches as $code => $gabarit) {
        if (!inrap_element_existe($db, $code)) { continue; }   // 29/08/2026 : élément absent du schéma
        $v = $coll->getWithTemplate($gabarit);
        $vt = trim($v);
        $actuel = inrap_valeur_stockee($db, $collection_id, $code);
        // 29/08/2026 : état visé = absent si le gabarit rend du vide, sinon la valeur calculée.
        // S'il correspond déjà à l'état stocké, on ne touche à rien.
        if ($vt === '') {
            if ($actuel['n'] === 0) { continue; }
        } elseif ($actuel['n'] === 1 && $actuel['v'] === $vt) {
            continue;
        }
        $coll->removeAttributes($code, ['force' => true]);
        if (strlen($vt)) { $coll->addAttribute([$code => $v], $code); }
        $modifie = true;
    }
    // 29/08/2026 : plus rien à écrire si aucun des 9 éléments n'a bougé — c'est le cas de
    // l'immense majorité des recalculs enfilés en masse.
    if (!$modifie) {
        if ($verbose) log_line("    caches collection $collection_id : rien à changer");
        return;
    }
    $coll->update(['dontLogChange' => true, 'dontDoSearchIndexing' => true]);
    if ($coll->numErrors()) {
        throw new RuntimeException("maj caches collection $collection_id : " . join('; ', $coll->getErrors()));
    }
    SearchResult::clearCaches();
    if ($verbose) log_line("    caches collection $collection_id rafraîchis");
}

function recalc_movement($movement_id, $verbose = false) {
    $mov = new ca_movements($movement_id);
    if (!$mov->getPrimaryKey()) {
        throw new RuntimeException("Mouvement #$movement_id introuvable");
    }
    $type = $mov->get('ca_movements.type_id');
    $mov->setMode(ACCESS_WRITE);

    switch ($type) {
        case 1796:
            recalc_movement_versement($mov, $verbose);
            break;
        case 81:
            recalc_movement_simple($mov, $verbose);
            break;
        default:
            return;
    }
}

function recalc_movement_versement($mov, $verbose) {
    // Auto-heal : si l'attribut date est vide mais le label actuel contient
    // encore une date DD/MM/YYYY, on récupère cette date avant de recalculer
    // le label (sinon on écraserait une donnée encore présente côté texte).
    $vs_date = $mov->getWithTemplate("^ca_movements.inrap_date_versement.inrap_date_versement_date");
    if (empty(trim($vs_date))) {
        $current_label = $mov->getLabelForDisplay();
        if (preg_match('#([0-9]{2}/[0-9]{2}/[0-9]{4})#', (string)$current_label, $m_date)) {
            if (set_movement_versement_date($mov, $m_date[1])) {
                $mov = new ca_movements($mov->getPrimaryKey());
                $mov->setMode(ACCESS_WRITE);
                if ($verbose) log_line("    auto-heal : date {$m_date[1]} récupérée depuis le label");
            }
        }
    }

    $vs_date = $mov->getWithTemplate("^ca_movements.inrap_date_versement.inrap_date_versement_date");
    $vs_type = $mov->getWithTemplate("^ca_movements.inrap_date_versement.inrap_date_versement_type");

    $coll_ids = array_filter(explode(";", $mov->get("ca_collections.collection_id")));
    $empl_id = $mov->getWithTemplate("<unit relativeTo='ca_storage_locations' restrictToRelationshipTypes='arrivee'>^ca_storage_locations.location_id</unit>");

    if ($verbose) log_line("    versement date=$vs_date type=$vs_type, " . count($coll_ids) . " collections, empl_arrivee=$empl_id");

    foreach ($coll_ids as $cid) {
        $coll = new ca_collections($cid);
        if (!$coll->getPrimaryKey()) continue;
        $coll->setMode(ACCESS_WRITE);

        $coll_date = $coll->getWithTemplate("<unit relativeTo='ca_collections.inrap_date_versement'>^ca_collections.inrap_date_versement.inrap_date_versement_date|^ca_collections.inrap_date_versement.inrap_date_versement_type</unit>");

        if ($coll_date == "|Dates prévisionnelles") {
            $coll->removeAttributes("inrap_date_versement", ['force' => true]);
            $coll->update();
        }
        if ($vs_date && stripos($coll_date, $vs_date . "|" . $vs_type) === false) {
            $coll->addAttribute(['inrap_date_versement_date' => $vs_date, 'inrap_date_versement_type' => $vs_type], 'inrap_date_versement');
            $coll->update();
        }
        if ($empl_id) {
            // 27/08/2026 GM : le gabarit peut rendre PLUSIEURS emplacements d'arrivée joints
            // par « ; » (constaté sur le mouvement 16775 : « 5602; 18040 » passé tel quel à
            // l'insertion → contrainte de clé étrangère, l'item restait bloqué depuis
            // juillet). On éclate, et on vérifie l'existence de chaque emplacement.
            foreach (preg_split('/[;,]/', (string)$empl_id) as $un_empl) {
                $un_empl = (int)trim($un_empl);
                if (!$un_empl) { continue; }
                $qr_loc = (new Db())->query("SELECT 1 FROM ca_storage_locations WHERE location_id = ? AND deleted = 0", [$un_empl]);
                if ($qr_loc->nextRow()) {
                    $coll->addRelationship('ca_storage_locations', $un_empl, 'conservation');
                    $coll->update();
                } else {
                    log_line("    mouvement : emplacement #{$un_empl} inexistant ou supprimé, relation non recréée (donnée à corriger sur la fiche)");
                }
            }
        }
    }

    // === D2026-0103 — écran de sélection des contenants : début ===
    // 29/08/2026 — RÉGRESSION CORRIGÉE. Bascule « versé » des contenants rattachés au
    // versement. L'écran de sélection (app/plugins/selectionContenants), déployé le 28/08,
    // ne pose QUE les relations mouvement→objet puis enfile le versement dans la file : il
    // délègue explicitement le changement de type au worker. Or la fusion du worker du 28/08
    // était partie du worker de PRODUCTION en n'y greffant que le titre — cette bascule,
    // qui appartenait à la lignée du worker de préproduction, n'avait pas été reprise. Les
    // contenants étaient rattachés mais ne passaient jamais en « versé ».
    // Elle se fait ici, hors requête web, sans réentrance de hooks.
    // 29/08/2026 : la bascule n'est plus faite ici mais REPORTÉE, à
    // « date de versement + délai de grâce ». On enfile un élément daté dans le futur
    // (code 2137) ; le worker ne le verra qu'à échéance. La clé unique (table_num, row_id)
    // fait que toute nouvelle validation recalcule l'échéance — corriger la date de versement
    // déplace donc la bascule avec elle.
    $echeance = inrap_0103_echeance_bascule($mov);
    if ($echeance === null) {
        // Pas de date de versement exploitable : on ne programme RIEN plutôt que de deviner.
        // Le gestionnaire renseignera la date ; le prochain enregistrement programmera la
        // bascule. Règle posée par Gautier le 29/08 : « si autre format ou vide, pas
        // d'automatisation ».
        if ($verbose) log_line("    bascule versé NON programmée : date de versement absente ou imprécise");
    } else {
        $db_dif = new Db();
        $db_dif->query(
            "INSERT INTO _inrap_recalc_queue (table_num, row_id, status, enqueued_at)
             VALUES (2137, ?, 'pending', ?)
             ON DUPLICATE KEY UPDATE status='pending', enqueued_at=VALUES(enqueued_at),
                                     started_at=NULL, finished_at=NULL, error_msg=NULL",
            [(int)$mov->getPrimaryKey(), $echeance]
        );
        if ($verbose) {
            log_line("    bascule versé programmée au " . date('d/m/Y H:i', $echeance)
                     . ($echeance <= time() ? " (échéance déjà atteinte, au prochain tour)" : ""));
        }
    }
    // === D2026-0103 — écran de sélection des contenants : fin ===

    // === D2026-0103 §5 — reprise des titres de versement : début ===
    // Le gabarit (format PV signé : « Versement / DIR / idno / date ») vit dans l'include
    // partagé inrap_0103_label_versement.inc.php, le même code que la reprise de masse.
    // L'ancien gabarit en ligne consommait ^ca_movements.description, champ que le §5.1
    // retire de l'écran de saisie : les deux corrections vont ensemble.
    // Prudence arbitrée par l'INRAP le 07/08/2026 : on n'écrase un titre existant que si
    // le nouveau peut être composé ENTIÈREMENT (dir + idno + date). Un versement incomplet
    // conserve son ancien libellé au lieu de recevoir « Versement /  / V-123 /  ».
    if (!inrap_0103_label_versement_composable($mov)) {
        if ($verbose) log_line("    label : composants incomplets, titre conserve");
    } else {
        $label = inrap_0103_compose_label_versement($mov);
        $mov->removeAllLabels();
        $mov->addLabel(['name' => $label], 2, null, true);
        $mov->update();
        if ($verbose) log_line("    label : " . substr($label, 0, 100));
    }
    // === D2026-0103 §5 : fin ===
}

/**
 * Pose la date de versement sur un mouvement, en gérant les 2 cas :
 * - pas de container `inrap_date_versement` → addAttribute
 * - container existant avec date vide → editAttribute (préserve les autres sous-champs)
 * Retourne true si l'écriture a réussi, false sinon.
 */
function set_movement_versement_date($mov, $date) {
    $attrs = $mov->getAttributesByElement('inrap_date_versement');
    if (empty($attrs)) {
        $mov->addAttribute(['inrap_date_versement_date' => $date], 'inrap_date_versement');
    } else {
        $att = $attrs[0];
        $existing = [];
        foreach ($att->getValues() as $v) {
            $existing[$v->getElementCode()] = $v->getDisplayValue();
        }
        $existing['inrap_date_versement_date'] = $date;
        $mov->editAttribute($att->getAttributeID(), 'inrap_date_versement', $existing);
    }
    $mov->update();
    return empty($mov->getErrors());
}

function recalc_movement_simple($mov, $verbose) {
    $label = $mov->getWithTemplate(
        "<unit relativeTo='ca_entities' restrictToRelationshipTypes='authorizer'>^ca_entities.preferred_labels.displayname</unit>" .
        " / ^ca_movements.dates_mouvement.mvt_date_debut" .
        " / <unit relativeTo='ca_entities' restrictToRelationshipTypes='destinataire'>^ca_entities.preferred_labels.displayname</unit>" .
        " / ^ca_movements.movement_reason"
    );
    $mov->removeAllLabels();
    $mov->addLabel(['name' => $label], 2, null, true);
    $mov->update();
}

// 27/08/2026 GM : exécute la cascade prepopulateInrap complète pour un objet ou une opération
// différé(e) depuis le web. Appelé par le worker pour les codes 57 (ca_objects) et 113 (ca_collections).
// Après la cascade, réindexation complète dans Meilisearch (les update() internes ne produisent
// que des mises à jour partielles — le document doit être réindexé en entier pour être cohérent).
function recalc_cascade_complete(string $table, int $row_id, bool $verbose) {
    // 04/09/2026 GM : **instance NEUVE, jamais l'instance partagée.** Le second argument à `true`
    // rend celle que Datamodel garde en cache : une seule et même instance ca_objects servait
    // alors tous les items de la file. Or le plugin calcule le libellé avec getWithTemplate(),
    // qui fige l'identifiant au moment de l'appel, puis l'écrit avec removeAllLabels() et
    // addLabel(), qui relisent getPrimaryKey() au moment d'écrire. Entre les deux, un chemin
    // d'indexation repointe l'instance partagée : le libellé calculé pour la fiche N se pose
    // alors sur la fiche précédente.
    //
    // Le défaut n'existait pas avant le 28/08 parce que la cascade s'exécutait dans le processus
    // web, un enregistrement par processus — l'état partagé ne franchissait pas la frontière.
    // cascadeEstDifferee() l'a déportée ici, où un processus enchaîne des dizaines de fiches.
    // Signature : dans chaque processus worker, le premier item est juste et tous les suivants
    // sont décalés. 113 fiches touchées entre le 28/08 20:23 et le 03/09.
    $instance = Datamodel::getInstanceByTableName($table, false);
    $instance->load($row_id);
    if (!$instance->getPrimaryKey() || $instance->get('deleted')) {
        if ($verbose) log_line("    $table #$row_id absent ou supprimé, ignoré");
        return;
    }

    // Instanciation unique du plugin (statique pour éviter les rechargements multiples).
    static $plugin = null;
    if ($plugin === null) {
        require_once(__CA_APP_DIR__ . '/plugins/prepopulateInrap/prepopulateInrapPlugin.php');
        $plugin = new prepopulateInrapPlugin();
    }

    // Exécution de la cascade complète. hookSaveItem() prend son argument PAR RÉFÉRENCE :
    // il faut une variable, pas un littéral.
    $params = [
        'id'         => $row_id,
        'table_num'  => $instance->tableNum(),
        'table_name' => $table,
        'instance'   => $instance,
    ];
    $plugin->hookSaveItem($params);

    if ($verbose) log_line("    cascade prepopulate $table #$row_id terminée");

    // Réindexation complète du document dans Meilisearch.
    reindex_document($table, $row_id, $verbose);
}

// 27/08/2026 GM : réindexation Meilisearch complète d'un document — mêmes chemins que le
// réindexeur en lot, sans purge. Sert à la fin des cascades (57/113) et aux compensations
// d'enregistrement différées (codes 1000+table_num), toutes tables.
function reindex_document(string $table, int $row_id, bool $verbose = false) {
    // 04/09/2026 GM : même raison qu'en amont — et cette instance-là est précisément l'un des
    // chemins qui repointaient l'instance partagée au milieu d'une cascade.
    $instance = Datamodel::getInstanceByTableName($table, false);
    $instance->load($row_id);
    if (!$instance->getPrimaryKey() || $instance->get('deleted')) {
        if ($verbose) log_line("    réindexation $table #$row_id : fiche absente ou supprimée, ignorée");
        return;
    }
    if (!defined('__CollectiveAccess_IS_REINDEXING__')) { define('__CollectiveAccess_IS_REINDEXING__', 1); }
    require_once(__CA_LIB_DIR__ . '/Search/SearchIndexer.php');
    require_once(__CA_LIB_DIR__ . '/Search/SearchBase.php');
    require_once('/var/www/comodo/collectiveaccess/meilisearch/MeilisearchAppPlugin/tools/_socle.php');
    $db       = new Db();
    $indexeur = new SearchIndexer($db, 'Meilisearch');
    $moteur   = SearchBase::newSearchEngine('Meilisearch');
    $tn       = $instance->tableNum();
    $lot      = [$row_id];
    $element_ids = method_exists($instance, 'getApplicableElementCodes')
        ? array_keys($instance->getApplicableElementCodes(null, false, false)) : null;
    if ($element_ids) { ca_attributes::prefetchAttributes($db, $tn, $lot, $element_ids); }
    $fd = donnees_de_champs($indexeur, $table, $lot, $db);
    SearchResult::clearCaches();
    $indexeur->indexRow($tn, $row_id, $fd[$row_id] ?? [], true);
    vider_tampon_moteur($moteur);

    if ($verbose) log_line("    réindexation Meilisearch $table #$row_id terminée");
}
// === D2026-0103 — écran de sélection des contenants : début ===
require_once(__CA_MODELS_DIR__ . "/ca_objects.php");
/**
 * Bascule en type « versé » les contenants (ca_objects) rattachés à un versement
 * (ca_movements_x_objects). Correspondances résolues PAR CODE dans la liste
 * object_types — jamais d'item_id en dur, ils diffèrent d'une base à l'autre :
 *   contenant                → contenant_mob_verse   (mobilier)
 *   contenant_intermediaire  → contenant_doc_verse   (documentation)
 *   numerique                → contenant_num_verse   (numérique)
 * Les contenants déjà « versés » sont laissés tels quels (idempotent).
 * NB : le volume par opération (inrap_volume_total / inrap_volume_doc_total,
 * recalculé par prepopulateInrap) compte les deux formes (ex. 28 ET 39988) :
 * la bascule ne change aucun volume — aucun double travail ici.
 */
function inrap_0103_bascule_types_verses($mov, $verbose = false) {
    $db = new Db();
    $map_codes = array(
        'contenant'               => 'contenant_mob_verse',
        'contenant_intermediaire' => 'contenant_doc_verse',
        'numerique'               => 'contenant_num_verse',
    );
    $codes = array_merge(array_keys($map_codes), array_values($map_codes));
    $ph = implode(',', array_fill(0, count($codes), '?'));
    $qr = $db->query("
        SELECT li.item_id, li.idno
        FROM ca_list_items li
        INNER JOIN ca_lists l ON l.list_id = li.list_id
        WHERE l.list_code = 'object_types' AND li.deleted = 0 AND li.idno IN ({$ph})
    ", $codes);
    $ids = array();
    while ($qr->nextRow()) { $ids[$qr->get('idno')] = (int)$qr->get('item_id'); }
    $verse_par_type_id = array();   // type_id source => code cible
    foreach ($map_codes as $src => $dst) {
        if (isset($ids[$src]) && isset($ids[$dst])) { $verse_par_type_id[$ids[$src]] = $dst; }
    }
    if (!count($verse_par_type_id)) {
        log_line("    bascule versé : types introuvables dans object_types — aucune bascule");
        return;
    }
    $qr = $db->query("
        SELECT mxo.object_id, o.type_id
        FROM ca_movements_x_objects mxo
        INNER JOIN ca_objects o ON o.object_id = mxo.object_id AND o.deleted = 0
        WHERE mxo.movement_id = ?
    ", array((int)$mov->getPrimaryKey()));
    while ($qr->nextRow()) {
        $tid = (int)$qr->get('type_id');
        if (!isset($verse_par_type_id[$tid])) { continue; }   // déjà versé, ou hors périmètre
        $t_obj = new ca_objects((int)$qr->get('object_id'));
        if (!$t_obj->getPrimaryKey()) { continue; }
        $t_obj->setMode(ACCESS_WRITE);
        $t_obj->changeType($verse_par_type_id[$tid]);
        $t_obj->update();
        if ($t_obj->numErrors()) {
            log_line("    bascule versé ÉCHEC objet #" . (int)$qr->get('object_id') . " : " . join('; ', $t_obj->getErrors()));
        } elseif ($verbose) {
            log_line("    bascule versé : objet #" . (int)$qr->get('object_id') . " → " . $verse_par_type_id[$tid]);
        }
    }
}
// === D2026-0103 — écran de sélection des contenants : fin ===


// === D2026-0103 — échéance de la bascule « versé » : début ===
/**
 * 29/08/2026 — À quel moment un versement doit-il basculer ses contenants en « versé » ?
 *
 * Réponse métier (Gautier, 29/08) : « il faut 72 h APRÈS la date de versement définie ».
 * Le point de départ est donc la DATE DE VERSEMENT, pas la validation du bordereau : le
 * versement a lieu physiquement à cette date, et c'est à partir de là que court le délai de
 * grâce pendant lequel l'état peut écarter un contenant.
 *
 * La date est un vrai champ date (datatype 2). Sa valeur exploitable ne vit PAS dans le texte
 * saisi — qui existe en formats mêlés (JJ/MM/AAAA, JJ-MM-AAAA, « janvier 2019 »…) — mais dans
 * les bornes analysées `value_decimal1` / `value_decimal2`, au format « historique »
 * YYYY.MMDDHHMMSS de CollectiveAccess.
 *
 * On n'accepte QUE les dates précises au jour : si les deux bornes tombent le même jour, la
 * date désigne un jour et sert de point de départ. Sinon (« 2019 », « janvier 2019 », période),
 * ou en l'absence de date, on rend null et rien n'est programmé — mieux vaut ne pas basculer
 * que basculer au mauvais moment.
 *
 * @return int|null timestamp Unix de l'échéance, ou null si la date n'est pas exploitable
 */
function inrap_0103_echeance_bascule($mov) {
    $db = new Db();
    $qr = $db->query("SELECT v.value_decimal1 AS d1, v.value_decimal2 AS d2
                      FROM ca_attributes a
                      JOIN ca_attribute_values v ON v.attribute_id = a.attribute_id
                      JOIN ca_metadata_elements e ON e.element_id = v.element_id
                      WHERE a.table_num = 137 AND a.row_id = ?
                        AND e.element_code = 'inrap_date_versement_date'
                      LIMIT 1", [(int)$mov->getPrimaryKey()]);
    if (!$qr->nextRow()) { return null; }

    $d1 = $qr->get('d1');
    $d2 = $qr->get('d2');
    if ($d1 === null || $d1 === '' || (float)$d1 <= 0) { return null; }

    // Précision : les deux bornes doivent désigner le même jour (YYYY.MMDD).
    $jour1 = floor((float)$d1 * 10000) / 10000;
    $jour2 = ($d2 === null || $d2 === '') ? $jour1 : floor((float)$d2 * 10000) / 10000;
    if (abs($jour1 - $jour2) > 0.00001) { return null; }   // mois, année ou période : on s'abstient

    $ts = caHistoricTimestampToUnixTimestamp((float)$d1);
    if (!$ts) { return null; }
    return (int)$ts + INRAP_0103_DELAI_BASCULE_VERSE;
}
// === D2026-0103 — échéance de la bascule « versé » : fin ===
