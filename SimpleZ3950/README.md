# SimpleZ3950

Plugin Providence permettant la recherche et l'import de notices bibliographiques via le protocole **Z39.50** (catalogues publics : BnF, SUDOC, KBR, etc.). L'import s'appuie sur un **mapping MARC déclaré directement dans le fichier de configuration** — plus besoin de charger un mapping XLSX externe en base (un mode legacy reste disponible).

## Installation

Copier le dossier dans `app/plugins/` de Providence :

```bash
cp -r SimpleZ3950 /chemin/vers/providence/app/plugins/
```

### Dépendances système

L'extension PHP **yaz** doit être installée et activée. Sur Debian/Ubuntu :

```bash
sudo apt install php-yaz
sudo systemctl restart apache2     # ou php-fpm selon le setup
```

Le plugin affiche un message d'erreur explicite si `yaz_connect()` n'est pas disponible.

## Configuration

Le fichier `conf/SimpleZ3950.conf` (ou `conf/local/SimpleZ3950.conf` qui prend la priorité s'il existe) regroupe trois choses : flags globaux, mapping MARC → modèle CA, liste des serveurs.

### Flags globaux

| Clé               | Défaut | Effet                                                                 |
|-------------------|--------|-----------------------------------------------------------------------|
| `enabled`         | `1`    | Active le plugin                                                      |
| `import_disabled` | `0`    | Si `1`, neutralise l'action *Import* (recherche seule)                |
| `locale_id`       | (auto) | Locale d'import (à défaut : locale de catalogage par défaut)          |
| `default_type`    | `object` | Type `ca_objects` créé à l'import (peut être un `idno` de type)     |

### Mapping MARC → ca_objects / ca_entities

Le bloc `mappings` déclare la correspondance entre les champs MARC de la notice reçue et les attributs (ou entités liées) de votre modèle CA. Il est organisé par format de notice :

```
mappings = {
    unimarc = {
        "001"   = "ca_objects.idno",
        "200a"  = "ca_objects.preferred_labels",
        "200e"  = "ca_objects.preferred_labels:append",
        "210c"  = "ca_objects.editeur",
        "210d"  = "ca_objects.date",
        "330a"  = "ca_objects.description",
        "606a"  = "ca_objects.motscles",
        "700ab" = "ca_entities[ind]%relation:author",
        "710ab" = "ca_entities[org]%relation:author"
    },
    marc21 = {
        "245a" = "ca_objects.preferred_labels"
    }
}
```

#### Syntaxe source

| Forme    | Signification                                                           |
|----------|-------------------------------------------------------------------------|
| `001`    | Controlfield 001 (par convention l'identifiant de notice)              |
| `200a`   | Datafield 200, sous-champ `a`                                           |
| `200ae`  | Datafield 200, sous-champs `a` et `e` concaténés avec un espace         |
| `700ab`  | Datafield 700, sous-champs `a` et `b` concaténés (ex. surname + forename) |

Si plusieurs occurrences du même tag existent dans la notice (ex. plusieurs `700`), chacune est traitée comme une entrée distincte.

#### Syntaxe cible

| Forme                                  | Comportement                                                                |
|----------------------------------------|-----------------------------------------------------------------------------|
| `ca_objects.preferred_labels`          | Définit le label préféré (la 1ère valeur gagne)                             |
| `ca_objects.preferred_labels:append`   | Concatène à la valeur existante (séparateur : espace)                       |
| `ca_objects.idno`                      | Définit l'identifiant unique (overwrite l'idno auto-généré)                 |
| `ca_objects.<element_code>`            | Définit l'attribut. Plusieurs sources → cible : concat avec `; ` automatique |
| `ca_objects.<element_code>:append`     | Force la concat à un attribut existant (utile pour discriminer)             |
| `ca_entities[ind]%relation:<rel_code>` | Crée/réutilise une entité personne (type `ind`) liée par la relation        |
| `ca_entities[org]%relation:<rel_code>` | Crée/réutilise une entité collectivité (type `org`)                         |

Pour les entités liées : si le sous-champ `$3` est présent dans le datafield (PPN d'autorité SUDOC typiquement), il est utilisé comme `idno` de l'entité (préfixé `ppn:`) et permet la dédup d'un import à l'autre. Sinon, la dédup se fait sur le label préféré exact (`displayname`). Le type d'entité (`ind` / `org`) doit correspondre à un `idno` de la liste `entity_types` du profil. Le `rel_code` doit correspondre à un code de la liste `entity_object_relationship_types`.

### Mapping legacy (caUtils)

Si la clé `mappings` n'est **pas** définie dans la conf, le plugin retombe sur l'ancien comportement : il appelle `caUtils import-data -m z3950_import_marc -f marc` et nécessite donc qu'un mapping XLSX nommé **`z3950_import_marc`** soit chargé en base au préalable :

```bash
php support/bin/caUtils load-import-mappings -f votre_mapping.xlsx
```

Cette voie reste utile pour les déploiements pré-existants ou pour des modèles compliqués où un XLSX éprouvé existe déjà.

### Serveurs Z39.50

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

| Champ       | Effet                                                                 |
|-------------|-----------------------------------------------------------------------|
| `label`     | Nom affiché dans l'UI                                                 |
| `target`    | Libellé du champ recherché (ex. ISBN, mots du titre)                  |
| `user/pwd`  | Credentials Z39.50 (vides pour les serveurs publics anonymes)         |
| `url`       | URL Z39.50 au format `host:port/database`                             |
| `attribute` | Code attribut Z39.50 (4 = mots du titre, 7 = ISBN, etc.)              |
| `preview`   | Gabarit d'aperçu utilisant la syntaxe `^TAG/subfield` UNIMARC         |

Serveurs préconfigurés (publics) : BnF (ISBN + titre), SUDOC, KBR.

## Utilisation

Une entrée **Z39.50** apparaît dans le menu **Import** du back-office. Recherche par ISBN ou mots du titre, prévisualisation des notices, import sélectif des notices cochées. Le résultat d'import liste pour chaque notice : la fiche `ca_objects` créée (avec lien direct vers son éditeur) et les entités liées créées ou réutilisées.

## Permissions

Action de rôle : `can_use_simple_z3950_plugin`. À accorder via *Manage → Access control → Roles* aux utilisateurs autorisés. Vérifiée actuellement par le menu (le `hookRenderMenuBar` la teste implicitement) ; le contrôleur ne refuse pas l'accès direct par URL — à durcir si nécessaire.

## Hooks utilisés

`hookRenderMenuBar` — ajoute l'entrée "Z39.50" au menu **Import**.
