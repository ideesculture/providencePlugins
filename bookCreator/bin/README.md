# bookworker.php — worker de génération

Le worker rend les PDF des livres en tâche de fond. L'interface web ne génère plus rien elle-même : le bouton « Générer » dépose un job dans la table `plugin_book_jobs`, le worker le réclame, rend le livre section par section et met à jour la progression que la page interroge en AJAX.

Un seul rendu à la fois par worker. C'est cette règle qui borne le pic mémoire à ~65 Mo avec WeasyPrint, quelle que soit la taille du livre. On monte en charge en lançant plusieurs workers, jamais en élargissant celui-ci.

## Options

```
php bin/bookworker.php [options]

  --providence=PATH   Racine Providence, le dossier qui contient setup.php.
                      À défaut : $BOOKCREATOR_PROVIDENCE_HOME, puis
                      $COLLECTIVEACCESS_HOME, puis le premier dossier parent
                      du plugin contenant setup.php.
  --max-runtime=N     Arrête de réclamer de nouveaux jobs après N secondes et sort.
                      0 (défaut) = tourne jusqu'à l'arrêt du processus.
  --once              Traite au plus un job puis sort. Sort immédiatement si la file est vide.
  --job=N             Traite le job N et rien d'autre. Le job doit être encore `pending`.
  --sleep=N           Attente entre deux sondages quand la file est vide (défaut 5 s).
  --reap-after=N      Remet en file les jobs `running` depuis plus de N secondes,
                      abandonnés par un worker mort (défaut 3600 s). 0 désactive.
  --verbose           Journalise chaque étape sur stdout. Les erreurs vont toujours sur stderr.
  --help              Cette aide.
```

Codes de retour : `0` exécution normale, `1` erreur d'option, `2` bootstrap CollectiveAccess impossible, `3` au moins un job en échec.

Sans `--verbose`, le worker est silencieux tant que tout va bien : c'est ce qui permet de le mettre en cron sans recevoir un courriel par minute.

## Installation en cron (Providence classique)

Une entrée par minute, chaque exécution s'arrêtant avant la suivante :

```cron
* * * * * cd /var/www/providence && /usr/bin/php app/plugins/bookCreator/bin/bookworker.php --max-runtime=55 >> /var/log/bookcreator/worker.log 2>&1
```

Points d'attention :

- l'utilisateur du cron doit être **le même que celui du serveur web** (`www-data` en général), sinon les PDF produits ne seront pas lisibles par Apache/nginx et les fichiers temporaires appartiendront à deux utilisateurs différents ;
- `--max-runtime=55` garantit qu'un worker a rendu la main avant que le suivant démarre. Deux workers simultanés ne sont pas un problème de correction — la réclamation des jobs est atomique — mais deux rendus simultanés doublent le pic mémoire ;
- si le PHP CLI n'est pas le même binaire que celui du serveur web, donner le chemin complet (`/usr/bin/php8.4`) ;
- le `cd` n'est pas indispensable (le worker se replace lui-même dans la racine Providence) mais rend le diagnostic plus simple.

Installation multi-bases : ajouter `CA_HOSTNAME=client.example.org` devant la commande, la valeur sert de `HTTP_HOST` au bootstrap et sélectionne la bonne configuration.

## Installation en Kubernetes

Le worker devient un `Deployment` d'une réplique, sans `--max-runtime` : il tourne en continu et sonde la file.

```yaml
command: ["php", "/var/www/html/app/plugins/bookCreator/bin/bookworker.php"]
args:    ["--sleep=5"]
env:
  - name: BOOKCREATOR_PROVIDENCE_HOME
    value: /var/www/html
resources:
  requests: { memory: 128Mi, cpu: 100m }
  limits:   { memory: 512Mi, cpu: "1" }
terminationGracePeriodSeconds: 120
```

- **arrêt propre** : à la suppression du pod, Kubernetes envoie `SIGTERM`. Le worker termine la section en cours, remet le job en `pending` et sort en 0. Le `terminationGracePeriodSeconds` doit dépasser la durée de rendu d'une section (quelques secondes), pas celle du livre entier ; au-delà du délai le `SIGKILL` laisse le job en `running`, et c'est le reaper qui le rattrape.
- **montée en charge** : augmenter `replicas`. Chaque réplique rend un livre à la fois et aucune ne peut réclamer le job d'une autre.
- **volumes** : le dossier de travail et le dossier de sortie doivent être accessibles au pod Providence si c'est lui qui sert les PDF au téléchargement — un `PersistentVolumeClaim` en `ReadWriteMany`, ou un stockage objet, selon l'installation.
- `pcntl` doit être compilé dans l'image PHP pour l'arrêt propre. Sans lui le worker fonctionne quand même, le job abandonné est simplement récupéré par le reaper.

## Droits nécessaires

- lecture de `setup.php` et de tout l'arbre Providence, comme le serveur web ;
- accès à la base de données via la configuration Providence ordinaire (aucun compte SQL dédié) ;
- écriture dans le dossier de travail et le dossier de sortie (`conf/bookCreator.conf`, clés `job_work_dir` et `job_output_dir` ; par défaut le dossier `tmp/` du plugin) ;
- exécution des binaires de la chaîne PDF (`weasyprint`, `qpdf`) présents dans le `PATH` du worker — celui du cron n'est pas celui d'un shell de connexion, donner un chemin absolu en configuration en cas de doute.

## Diagnostiquer un job bloqué

L'état complet tient dans une table. Les requêtes ci-dessous se passent en console MySQL sur la base Providence.

```sql
-- file d'attente et jobs en cours
SELECT job_id, book_id, status, progress, worker_id,
       FROM_UNIXTIME(created_on) AS created, FROM_UNIXTIME(started_on) AS started, message
FROM plugin_book_jobs
WHERE status IN ('pending','running')
ORDER BY created_on;

-- dernières erreurs
SELECT job_id, book_id, FROM_UNIXTIME(finished_on) AS finished, message
FROM plugin_book_jobs WHERE status = 'error' ORDER BY finished_on DESC LIMIT 20;
```

Lecture des cas courants :

- **le job reste `pending`, la progression ne bouge pas** — aucun worker ne tourne. Vérifier le cron (`grep CRON /var/log/syslog`) ou le pod (`kubectl get pods`), puis lancer une fois à la main : `php bin/bookworker.php --once --verbose`.
- **le job reste `running`, `started_on` remonte à longtemps** — le worker est mort en cours de rendu. Le reaper le remet en file au bout de `--reap-after` secondes (une heure par défaut) ; pour ne pas attendre, `UPDATE plugin_book_jobs SET status='pending', worker_id=NULL, started_on=NULL WHERE job_id=…`. La colonne `worker_id` porte le nom d'hôte et le pid du worker qui l'avait réclamé (`hote:1234#<jeton>`), de quoi retrouver ses traces.
- **le job passe en `error`** — le message affiché dans l'interface est celui de la colonne `message`, tronqué ; la trace complète est sur la sortie d'erreur du worker (journal du cron ou `kubectl logs`).
- **rejouer un job** — le remettre en `pending` puis `php bin/bookworker.php --job=<id> --verbose`, qui traite ce seul job et sort. La commande refuse un job déjà `running` : elle ne peut donc pas entrer en conflit avec le worker de production.
- **un livre refuse une nouvelle génération** — c'est volontaire : tant qu'un job `pending` ou `running` existe pour ce livre, la soumission renvoie le job existant au lieu d'en créer un second. Traiter ou nettoyer le job en cours d'abord.

Note d'exploitation : la réclamation d'un job utilise un `UPDATE … ORDER BY … LIMIT 1`, marqué « unsafe » par MySQL en réplication basée sur les requêtes. Sur une installation répliquée, utiliser le binlog en mode `ROW` (le défaut depuis MySQL 5.7 et MariaDB 10.2).
