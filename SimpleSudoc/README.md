# SimpleSudoc

<img src="../documentation/icons/abes.svg" alt="ABES" height="60" align="right" />

Plugin Providence d'import de notices depuis le **SUDOC** (catalogue de l'enseignement supérieur français, ABES). Récupère les notices UNIMARC via l'API SRU publique de l'ABES (par PPN, ISBN, ISSN ou recherche texte) et crée les `ca_objects` correspondants — par défaut de type `revue` pour le contexte Cognitio-Fort.

Conçu comme un complément à [SimpleGallica](../SimpleGallica/) : Gallica fournit les documents numérisés, le SUDOC fournit les notices catalographiques (notamment pour les **revues / périodiques** dont les fac-similés ne sont pas en ligne).

## Installation

Copier le dossier dans `app/plugins/` de Providence :

```bash
cp -r SimpleSudoc /chemin/vers/providence/app/plugins/
```

Aucune dépendance système (PHP standard + cURL).

## Utilisation

Une entrée **SUDOC** apparaît dans le menu **Import** du back-office. Deux modes :

- **Par identifiant** — coller un ou plusieurs PPN, ISBN ou ISSN (séparés par des virgules, espaces ou sauts de ligne). Le type est détecté automatiquement par format :
  - 8 caractères (ex. `0294-1767`) → **ISSN** → CQL `isn=...`
  - 10 ou 13 chiffres (ex. `978-2-13-051234-5`) → **ISBN** → CQL `isb=...`
  - 5-9 chiffres (ex. `008637253`) → **PPN** → CQL `ppn=...`
- **Recherche textuelle** — interroge les index `mti` (mots du titre) et `aut` (auteur) en disjonction.

Pour chaque notice retenue à l'import :
1. Création d'un `ca_objects` avec `idno = PPN`, type `revue` (configurable), label = titre UNIMARC 200$a (+ 200$e éventuel).
2. Mapping UNIMARC → `element_codes` Cognitio-Fort (auteurs, date, description, éditeurs, source, motscles, url_entry).

Les notices déjà présentes (PPN identique dans `ca_objects.idno`) sont signalées et désactivées dans la liste de résultats.

## Pourquoi UNIMARC plutôt que Dublin Core ?

L'API SRU SUDOC peut renvoyer plusieurs schémas : `dc`, `unimarc`, `pica`, `marc21`, etc. Le schéma `dc` ne contient **pas** le PPN dans `dc:identifier`, ce qui rend impossible l'identification fiable d'une notice (et donc la dédup). Le schéma `unimarc` fournit le PPN dans le `controlfield 001`. Le contrôleur fait donc tout son parsing en UNIMARC et projette les champs nécessaires sur une structure DC-like cohérente avec SimpleGallica.

## Configuration

Fichier `conf/SimpleSudoc.conf` :

| Clé                | Défaut    | Effet                                                       |
|--------------------|-----------|-------------------------------------------------------------|
| `enabled`          | `1`       | Active le plugin                                            |
| `locale_id`        | `7`       | Locale d'import (`fr_FR` sur Cognitio-Fort)                 |
| `max_results`      | `20`      | Nombre max de notices ramenées par la recherche SRU texte   |
| `default_type`     | `revue`   | Type `ca_objects` créé à l'import                           |
| `rate_limit_delay` | `2`       | Secondes entre deux requêtes consécutives au SUDOC (min 1)  |
| `user_agent`       | (interne) | UA envoyé à l'ABES                                          |

## Mapping UNIMARC → Cognitio-Fort

| Champ UNIMARC                       | Sortie               | element_code Cognitio-Fort |
|-------------------------------------|----------------------|----------------------------|
| `controlfield 001`                  | PPN                  | `ca_objects.idno`          |
| `200$a` (+ `200$e` sous-titre)      | titre                | label préféré              |
| `700/701/702/710/711/712 $a + $b`   | auteurs              | `auteurs`                  |
| `210$a + $c`                        | lieu, éditeur        | `editeurs`                 |
| `210$d`                             | date                 | `date`                     |
| `330$a` + `300$a`                   | résumé + notes       | `description`              |
| `606/607/610/600/601/602 $a`        | sujets               | `motscles`                 |
| `035$a`                             | numéros source       | `source`                   |
| `856$u` (sinon `https://www.sudoc.fr/<ppn>`) | URL          | `url_entry`                |

## Permissions

Action de rôle : `can_use_simple_sudoc_plugin`. À accorder via *Manage → Access control → Roles*. Vérifiée par `hookRenderMenuBar` (le menu n'apparaît pas sans la permission).

## Hooks utilisés

`hookRenderMenuBar` — ajoute l'entrée "SUDOC" au menu **Import**.

## Notes

- Le SUDOC est un réseau documentaire piloté par l'**ABES** (Agence bibliographique de l'enseignement supérieur). Le logo ABES utilisé dans cette documentation provient de la page officielle [Logo et ressources graphiques](https://abes.fr/l-abes/abes-pratique/logo-ressources-graphiques/).
- L'API SRU de l'ABES (`https://www.sudoc.abes.fr/cbs/sru`) est libre d'accès, sans authentification.
- Pour les utilisateurs venant d'une recherche dans Sudoc.fr, le **PPN** est l'URL canonique : `https://www.sudoc.fr/<ppn>`.
- Le SUDOC ne fournit pas d'images / fac-similés ; ce plugin crée donc une fiche biblio sans représentation. Pour récupérer un PDF d'archive scientifique lié, consulter HAL/theses.fr séparément.

## Licence

GNU GPL v3 — voir [../LICENSE](../LICENSE).
