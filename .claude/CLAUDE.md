# providencePlugins — conventions

Plugins IdéesCulture pour CollectiveAccess Providence.

---

## 1. Nommage des fichiers de configuration : préfixer par le nom du plugin

**Règle : tout fichier `.conf` livré par un plugin porte le nom du plugin en préfixe.**

```
bookCreator/conf/bookCreator.conf
bookCreator/conf/bookCreator_translations.conf     <- et non translations.conf
searchIdno/conf/searchIdno.conf
```

### Pourquoi

`Configuration::load()` résout **par nom de fichier**, pas par chemin. Et la fusion
avec `app/conf/<même nom>` est faite **inconditionnellement** — le bloc est situé
*hors* de la garde `$dont_load_from_default_path` :

```php
// app/lib/Configuration.php, ~ligne 158
if (!$dont_load_from_default_path) {
    // ... app/conf/local/, themes/<theme>/conf/, variante par app name
}
if (defined('__CA_CONF_DIR__') && ... && file_exists($p)) {   // <-- hors de la garde
    $config_file_list[] = __CA_CONF_DIR__.'/'.$config_filename;
}
```

Conséquence : un plugin qui nomme son catalogue `translations.conf` le voit fusionné
avec `app/conf/translations.conf`, dont le bloc `strings` est vide — et **le fichier
du cœur gagne**.

### Le symptôme est traître

Aucune erreur, aucun avertissement : la configuration est simplement **vide**.
Mesuré sur le catalogue de bookCreator, fichier identique (même md5) :

| Emplacement | Nom | Entrées lues |
|---|---|---|
| `bookCreator/conf/` | `translations.conf` | **0** |
| `/tmp/` | `_cat_test.conf` | 129 |
| `bookCreator/conf/` | `bookCreator_translations.conf` | 129 |

Aucun drapeau de `Configuration::load()` ne protège de ce cas. **Seul le nom de
fichier le fait.**

### Vérification avant d'ajouter un `.conf`

```bash
ls providence/app/conf/ | grep -x "<nom envisagé>.conf"   # doit ne rien renvoyer
```

Ou, pour auditer tout le dépôt d'un coup :

```bash
C=<...>/providence/app/conf
for f in $(find . -name '*.conf' -not -path '*/vendor/*' -not -path '*/.git/*'); do
  b=$(basename "$f"); [ -f "$C/$b" ] && echo "COLLISION : $f <-> app/conf/$b"
done
```

### Risque résiduel connu

`bookCreator/themes/*/theme.conf` et `bookCreator/themes/*/templates/*/manifest.conf`
portent des noms génériques et passent par `Configuration::load()`
(`lib/ThemeRegistry.php`, `lib/TemplateRegistry.php`). Ils ne collisionnent avec rien
aujourd'hui, mais ils collisionneraient le jour où CollectiveAccess livrerait un
`app/conf/theme.conf`. À renommer si l'occasion se présente.

---

## 2. Traductions : `IdC::_t()`, jamais `_t()` pour les chaînes du plugin

CollectiveAccess **ne prévoit aucun emplacement de traduction dans un plugin**. Les
trois mécanismes consultent des listes de chemins codées en dur, dont aucune ne
descend dans `app/plugins` :

| Mécanisme | Où | Chemins |
|---|---|---|
| Catalogues gettext | `app/helpers/initializeLocale.php`, `validateLocale()` | `themes/<theme>/locale`, `themes/default/locale`, `app/locale/user`, `app/locale` |
| Surcharge `translations.conf` | `Configuration::load()` | `app/conf/local`, `themes/<theme>/conf`, `app/conf` |
| Extraction des chaînes | `caUtils extract-strings-for-translation` | thèmes, `app/models`, `app/lib`, `app/helpers`, `app/conf` |

D'où le helper **`lib/IdC.php`**, embarqué par chaque plugin et enregistré par le
premier chargé (`class_exists('IdC', false)` puis `require_once`). Il n'y a pas de
répertoire partagé possible : les plugins sont déployés individuellement dans
`app/plugins`.

```php
// dans le constructeur du plugin
if (!class_exists('IdC', false)) { require_once($ps_plugin_path.'/lib/IdC.php'); }
if (!IdC::registered('monPlugin')) {
    IdC::registerFile('monPlugin', $ps_plugin_path.'/conf/monPlugin_translations.conf');
}
```

### Pourquoi ne pas se contenter de `_t()`

`_t()` (`app/helpers/utilityHelpers.php:74`) **retourne immédiatement** depuis la
branche `$g_translation_strings`, avant le bloc d'interpolation des `%n` situé en fin
de fonction. Toute chaîne surchargée contenant un marqueur s'affiche littéralement :

```
via gettext   : _t('Deleted %1 records', 7)  ->  'Deleted 7 records'
via surcharge : _t('Deleted %1 records', 7)  ->  '%1 fiches supprimées'
```

`IdC::_t()` fait sa propre interpolation : le défaut du cœur devient sans effet, et
**aucun correctif de CollectiveAccess n'est nécessaire** — ce qui évite de dépenser
du capital politique en amont sur un confort de plugin.

### Ordre de résolution de `IdC::_t()`

1. catalogues enregistrés, locale exacte (`fr_FR`) ;
2. catalogues enregistrés, même langue, autre variante (`fr_*`) ;
3. `_t()` du cœur — les chaînes communes (« Enregistrer », « Supprimer ») restent
   traduites sans les redéclarer ;
4. la clé elle-même.

Les clés sont en anglais : l'anglais est donc servi gratuitement, le français vient
du catalogue. Ajouter une langue = ajouter une entrée de locale dans le même fichier.

L'API doit rester **strictement additive** : si deux plugins embarquent des versions
différentes d'`IdC`, c'est la première chargée qui gagne. Consulter `IdC::VERSION`
avant d'utiliser une nouveauté.

---

## 3. Déploiement : lien symbolique, jamais une copie

Un plugin est développé dans ce dépôt et monté dans l'instance par **lien
symbolique** :

```
providence/app/plugins/bookCreator -> .../providencePlugins/bookCreator
```

Jamais une copie dans `app/plugins` : une copie diverge en silence et se perd à la
migration suivante. Les instances `henritayan` et `masson` portent aujourd'hui une
copie réelle d'une génération antérieure de bookCreator — à remplacer par un lien
lors de leur passage en 2.0.

### Propriété des fichiers

Le dépôt appartient au développeur, l'accès du serveur web passe par le groupe :

```
chown -R debian:www-data .
find . -type d -exec chmod 2775 {} +      # setgid : les nouveaux fichiers héritent du groupe
find . -type f -exec chmod 664 {} +
chmod -R g+w bookCreator/tmp              # écrit par Apache à l'exécution
```

Ne pas passer le dépôt en `www-data` : `git` devient inutilisable pour le développeur.
Attention aussi à ne pas modifier le bit exécutable en masse — `git` le suit, et un
`chmod` trop large fait apparaître des fichiers modifiés. Pour rétablir les modes
exactement tels que `git` les attend :

```bash
git ls-files -s | while read mode _ _ f; do
  case "$mode" in 100755) chmod 775 "$f";; 100644) chmod 664 "$f";; esac
done
```

---

## 4. Endpoints coûteux scrapés : filtrer sur le cookie de session

> Note : section d'exploitation serveur, pas de convention de plugin. Placée ici à
> la demande de l'équipe pour être versionnée avec le reste.

Les exports de Pawtucket
`/index.php/Browse/*/view/pdf/download/1/export_format/{basic_excel,_pdf_checklist,_pdf_thumbnails}/key/…`
génèrent **~89 Mo de temporaire PHPExcel par appel**. Ces liens sont **présents dans
l'interface** (menu d'export des pages de navigation) : les crawlers les suivent.
Relevé le 2026-08-17 sur `patrimoines-2ccam` : **3 743 appels en une journée**, soit
~333 Go écrits pour du trafic robot, saturant la partition racine de 20 Go.

### Mesurer avant de choisir l'outil

```bash
sudo grep -a 'export_format' /var/log/apache2/access_<vhost>.log | awk '{print $1}' \
  | sort | uniq -c | awk '{print $1}' | sort -n | uniq -c
```

Résultat ici : **3 735 IP distinctes pour 3 743 appels — 3 726 IP n'ont fait qu'UNE
requête sur tout le site.** Botnet à IP tournantes.

| Approche | Verdict |
|---|---|
| `fail2ban` | **Inopérant.** Il bannit après N requêtes ; ici il n'y a jamais de seconde requête, et les 89 Mo sont déjà écrits. |
| Filtrage par `User-Agent` | Inopérant : UA de navigateur usurpés (Mac, iPhone). |
| Bloquer l'endpoint | Casserait un bouton visible pour les vrais visiteurs. |
| **Exiger le cookie de session** | **Retenu.** |

### La règle

Ces clients attaquent l'URL à froid, sans avoir chargé la moindre page : ils n'ont
pas de cookie. Un humain qui clique sur le bouton en a forcément un.

```apache
# dans le <VirtualHost>, avant </VirtualHost>
<If "%{REQUEST_URI} =~ m#(export_format|/view/pdf/)# && ! %{HTTP_COOKIE} -strmatch '*collectiveaccess=*'">
	Require all denied
</If>
```

Le cookie de Pawtucket s'appelle `collectiveaccess`. Le vérifier avant de généraliser :
`curl -sD - -o /dev/null <url> | grep -i set-cookie`.

### Valider dans les deux sens

```bash
# 1. appel direct sans session — doit renvoyer 403
curl -s -o /dev/null -w '%{http_code} %{size_download}\n' "$H$EXPORT_URL"
# 2. navigation puis export — doit renvoyer 200 et le fichier complet
curl -s -o /dev/null -c /tmp/j "$H/index.php/Browse/objects"
curl -s -o /dev/null -b /tmp/j -w '%{http_code} %{size_download}\n' "$H$EXPORT_URL"
```

Mesuré : `403 239` puis `200 89312037`. Modèle prêt à l'emploi dans
`servertools/php8-migration/exports-session-gate.conf`.

### Deux pièges rencontrés en même temps

**`robots.txt` renvoie 403 sur Pawtucket.** Le `.htaccess` fait `Deny from all` puis
ré-autorise une liste d'extensions qui **ne contient pas `.txt`**. Toutes les
directives `Disallow` du fichier sont lettre morte depuis l'origine. Signature dans
les logs : `AH01630: client denied by server configuration: …/robots.txt`.

```apache
<FilesMatch "^(robots\.txt|favicon\.ico|sitemap\.xml)$">
        Allow from all
</FilesMatch>
```

**`PrivateTmp=yes` virtualise `/tmp` *et* `/var/tmp`.** Déplacer un répertoire
temporaire vers `/var/tmp` ne le sort donc **pas** de la partition racine : il
atterrit dans `/tmp/systemd-private-*/var/tmp/`. Viser un chemin tiers (`/var/php-tmp`)
et régler `sys_temp_dir`, pas seulement `upload_tmp_dir` — PHPExcel passe par
`sys_get_temp_dir()`. Vérifier où le fichier atterrit réellement plutôt que se fier
au réglage :

```bash
sudo find /tmp /var/tmp /var/php-tmp -name 'phpxltmp*' -mmin -5 -printf '%TH:%TM %s %p\n'
```

Rappel : `/` ne fait que 20 Go (`/dev/md2`), `/var` en fait 1,8 To. Garde-fou en place :
`cleanup-php-tmp.timer` (toutes les 15 min, 60 min de grâce).

---

## 5. Fichiers `.conf` : jamais de commentaire en fin de ligne

**Règle : un `#` ne commente que s'il est le premier caractère non blanc de la ligne.**

```
# corps du texte : 11,5pt -> 15,33px -> 15px
font-size-body = 11.25pt,          <- correct

font-size-body = 11.25pt,          # 11,5pt -> 15,33px -> 15px   <- CASSE LE FICHIER
```

### Ce que fait le parseur

Le reste de la ligne n'est pas ignoré : il est absorbé, et il **contamine la clé
suivante**. Mesuré sur `bookCreator/themes/default/theme.conf`, seize entrées
commentées en fin de ligne ont produit ceci dans le CSS généré :

```css
--font-size-body: 11.25pt;
--0: 5pt -> 15;                       /* jeton fantôme */
--33px -> 15pxfont-size-heading: 18pt; /* --font-size-heading n'existe plus */
```

Une entrée sur deux disparaissait : `font-size-title`, `font-size-legal`,
`line-height-title`, `line-height-tight`, `font-variant-heading`… Chaque `var()`
retombait silencieusement sur sa valeur de repli.

### Le symptôme est traître

Aucune erreur côté CollectiveAccess. Le seul signe visible était côté WeasyPrint :

```
WARNING: Error: Stop token reached before {} block for a qualified rule. at 20:39.
```

Un livre entier a été composé avec les mauvaises valeurs avant que ces lignes ne
soient remarquées. **Toujours lire le flux d'avertissements du moteur de rendu**,
même quand le PDF sort en code 0.

### Vérification

```bash
grep -nE '^[^#]*[^[:space:]].*#' <fichier>.conf     # doit ne rien renvoyer
```

Ou, plus sûr, contrôler les noms de tokens réellement émis :

```php
preg_match_all('~--([a-zA-Z0-9-]+): ~', $css, $m);
// tout nom ne matchant pas ^[a-z][a-z0-9-]*$ signale une ligne mal commentée
```
