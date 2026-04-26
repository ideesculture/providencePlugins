# SimpleGallica

Plugin Providence d'import de notices depuis **Gallica** (BnF). Récupère les métadonnées Dublin Core via l'API Gallica (SRU pour la recherche, OAIRecord pour un ARK direct), crée le `ca_objects` correspondant et y attache l'image haute résolution comme représentation primaire.

Conçu pour le projet **Cognitio-Fort** (Département des Alpes-Maritimes), où les fiches Gallica utilisent l'**ARK comme `idno`** (ex. `ark:/12148/bpt6kxxxxxx`).

## Installation

Copier le dossier dans `app/plugins/` de Providence :

```bash
cp -r SimpleGallica /chemin/vers/providence/app/plugins/
```

Aucune dépendance système particulière (PHP standard + cURL).

## Utilisation

Une entrée **Gallica** apparaît dans le menu **Import** du back-office. Deux modes :

- **Par ARK** — coller un ou plusieurs ARK (`ark:/12148/...`) ou URL `gallica.bnf.fr/...`. Pour chaque ARK, le plugin appelle l'API `OAIRecord` et présente la notice DC à valider.
- **Recherche textuelle (SRU)** — recherche plein-texte via l'API SRU de Gallica (`gallica all "..."`). Présente jusqu'à `max_results` notices.

L'utilisateur coche les notices à importer, puis valide. Pour chaque notice retenue :
1. Création d'un `ca_objects` avec `idno = ARK`, type déduit de `dc:type` (voir `type_mapping`), label = `dc:title`.
2. Mapping des autres champs DC vers les `element_codes` Cognitio-Fort (auteurs, date, description, éditeurs, source, droits, motscles, url_entry, objets_lies).
3. Si `download_image = 1`, téléchargement de `https://gallica.bnf.fr/{ark}/f1.highres` et attachement comme représentation primaire (avec dédup MD5).

Les notices déjà présentes (ARK identique dans `ca_objects.idno`) sont signalées et désactivées dans la liste de résultats.

## Configuration

Fichier `conf/SimpleGallica.conf` :

| Clé                | Défaut         | Effet                                                                  |
|--------------------|----------------|------------------------------------------------------------------------|
| `enabled`          | `1`            | Active le plugin                                                       |
| `locale_id`        | `1`            | Locale d'import (à ajuster selon le profil ; `fr_FR`)                  |
| `max_results`      | `20`           | Nombre max de notices ramenées par la recherche SRU                    |
| `default_type`     | `iconographie` | Type `ca_objects` si `dc:type` n'est pas mappable                      |
| `type_mapping`     | (voir conf)    | Mots-clés `dc:type` → type code `ca_objects` (test par sous-chaîne)    |
| `download_image`   | `1`            | Télécharger automatiquement `/f1.highres` à l'import                   |
| `rate_limit_delay` | `5`            | Secondes entre deux requêtes Gallica (min 3)                           |
| `user_agent`       | UA navigateur  | UA envoyé à Gallica (`/f1.highres` refuse les UAs "bot")               |

## Mapping Dublin Core → Cognitio-Fort

| Champ DC          | element_code Cognitio-Fort |
|-------------------|----------------------------|
| `dc:title`        | label préféré (pas un attribut) |
| `dc:creator`      | `auteurs`                  |
| `dc:date`         | `date` (DateRange)         |
| `dc:description`  | `description`              |
| `dc:publisher`    | `editeurs`                 |
| `dc:source`       | `source`                   |
| `dc:rights`       | `droits`                   |
| `dc:subject`      | `motscles`                 |
| `dc:identifier`   | `idno` (ARK) + `url_entry` (URL Gallica) |
| `dc:relation`     | `objets_lies`              |

Ce mapping est codé dans `SimpleGallicaController::Import()` ; pour le faire évoluer, ajuster `$attr_map` dans le contrôleur.

## Permissions

Action de rôle : `can_use_simple_gallica_plugin`. À accorder via *Manage → Access control → Roles* aux utilisateurs autorisés. Vérifiée par `hookRenderMenuBar` (le menu n'apparaît pas sans la permission).

## Hooks utilisés

`hookRenderMenuBar` — ajoute l'entrée "Gallica" au menu **Import**.

## Notes

- Gallica rate-limite agressivement les requêtes anonymes (TLS reset). Le plugin temporise `rate_limit_delay` secondes entre chaque image téléchargée. Si vous importez en lot et obtenez des erreurs `operation failed`, augmentez ce délai.
- Le User-Agent doit ressembler à un navigateur sinon `/f1.highres` renvoie 403.
- `dc:language` n'est pas importé (pas de liste `lang` dans le profil Cognitio-Fort actuel). Pour l'activer plus tard, créer une liste de langues et un attribut `langue` sur `ca_objects`, puis ré-introduire un mapping dans le contrôleur.
- En complément du plugin, le script CLI `import_gallica_image.php` (à la racine de `providence/`) permet de rattraper les images en lot pour des fiches déjà saisies à la main.

## Licence

GNU GPL v3 — voir [../LICENSE](../LICENSE).
