# SimpleZ3950

Plugin Providence permettant l'import de notices bibliographiques via le protocole **Z39.50** (recherche dans des catalogues de bibliothèques publics : BnF, SUDOC, KBR, etc.) et leur intégration dans CollectiveAccess via un mapping UNIMARC.

## Installation

Copier le dossier dans `app/plugins/` de Providence :

```bash
cp -r SimpleZ3950 /chemin/vers/providence/app/plugins/
```

### Dépendances système

L'extension PHP **yaz** doit être installée et activée. Sur Debian/Ubuntu :

```bash
sudo apt install php-yaz
```

Le plugin affiche un message d'erreur explicite si `yaz_connect()` n'est pas disponible.

### Mapping de données

Le plugin appelle `caUtils import-data -m z3950_import_marc -f marc`. Un mapping nommé **`z3950_import_marc`** doit donc être chargé en base au préalable. Ce mapping (UNIMARC → attributs `ca_objects`) est spécifique au modèle de chaque instance et n'est **pas** fourni avec le plugin.

Pour le charger après avoir préparé un fichier XLSX adapté à votre modèle :

```bash
php support/bin/caUtils load-import-mappings -f support/z3950_import_marc.xlsx
```

## Configuration

Le fichier `conf/SimpleZ3950.conf` liste les serveurs Z39.50 disponibles. Format :

```
servers = {
    bnf = {
        label = "BnF",
        target = "ISBN",
        user = "Z3950",
        password = "Z3950_BNF",
        url = "z3950.bnf.fr:2211/TOUT-UTF8",
        attribute = "7",
        preview = "^200/f, <i>^200/a</i>, ^210/a, ^210/c, ^210/d"
    },
    ...
}
```

Champs :
- `label` — nom affiché dans l'UI
- `target` — libellé du champ recherché (ex. ISBN, mots du titre)
- `user` / `password` — credentials Z39.50 (vides pour les serveurs publics anonymes)
- `url` — URL Z39.50 au format `host:port/database`
- `attribute` — code attribut Z39.50 (4 = mots du titre, 7 = ISBN, etc.)
- `preview` — gabarit d'aperçu utilisant la syntaxe `^TAG/subfield` UNIMARC

Serveurs préconfigurés (publics) : BnF (ISBN + titre), SUDOC (FR), KBR (BE).

## Utilisation

Une entrée **Z39.50** apparaît dans le menu **Import** du back-office. Recherche par ISBN ou mots du titre, puis import sélectif des notices cochées.

## Permissions

Action de rôle : `can_use_simple_z3950_plugin` (actuellement non vérifiée par le contrôleur — voir `SimpleZ3950Controller.php:50`).

## Hooks utilisés

`hookRenderMenuBar` — ajoute l'entrée "Z39.50" au menu Import.
