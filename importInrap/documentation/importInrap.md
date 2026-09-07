# Documentation technique — importInrap

**Plugin CollectiveAccess Providence — INRAP**

## Vue d'ensemble

Le plugin **importInrap** permet d'**importer des données depuis des fichiers Excel** (XLS/XLSX) dans la base CollectiveAccess, avec un processus en **5 étapes guidées** : upload du fichier, sélection de la feuille, mapping des colonnes, sélection des lignes, puis import paginé.

Le plugin gère **8 types d'import** : mobilier archéologique, objets musée, opérations, documentation numérique, documentation écrite, contenants mobilier, contenants documentation et contenants numériques. Le mapping des champs est entièrement configurable via le fichier `importInrap.conf`.

### Rôle du plugin

- Importer des objets, collections et documents depuis des fichiers Excel
- Mapper interactivement les colonnes Excel vers les champs CollectiveAccess
- Créer ou mettre à jour les enregistrements existants (détection par idno)
- Gérer les relations : collections, entités, lieux, emplacements, mouvements, contenants
- Traiter les attributs complexes : containers (dimensions, notes), valeurs multiples
- Import paginé (1 ligne par requête HTTP) avec barre de progression
- Déclencher les hooks `hookSaveItem` après chaque import

### Hooks utilisés

- **hookRenderMenuBar** — Ajoute un menu « Import Inrap » dans la barre de navigation (sous « Gérer »)

**Aucune restriction de rôle** — `getRoleActionList` retourne un tableau vide.

---

## Processus d'import (5 étapes)

### Étape 1 — Upload du fichier et sélection du type (Index)

L'utilisateur sélectionne le **type d'import** et uploade un fichier Excel :

- Mobilier archéologique
- Objets musée
- Opérations
- Documentation numérique / écrite
- Contenant mobilier / documentation / numérique

### Étape 2 — Sélection de la feuille (SelectSheet)

Le fichier Excel est uploadé dans `temp/`. PhpSpreadsheet identifie le type de fichier, charge le classeur et affiche la **liste des feuilles**. L'utilisateur choisit la feuille à importer.

### Étape 3 — Mapping des colonnes (SelectBeforeImport)

Le système lit la **première ligne** (en-têtes) de la feuille sélectionnée et affiche un formulaire à deux colonnes :

- Colonne gauche : en-têtes Excel
- Colonne droite : liste déroulante des champs de mapping (depuis `importInrap.conf`)

Un script jQuery tente un **auto-matching** par similarité de nom.

### Étape 4 — Sélection des lignes (SelectLineBeforeImport)

Toutes les lignes de données sont lues et mappées. Le système :

- Applique le mapping des colonnes à chaque ligne
- Extrait les identifiants (idno) pour affichage
- Sauvegarde les données traitées en **fichier JSON** temporaire
- Affiche un tableau avec cases à cocher pour chaque ligne

### Étape 5 — Import paginé (Import)

L'import s'exécute **ligne par ligne** (1 requête HTTP par ligne) avec une barre de progression :

- Charge le JSON temporaire et filtre les lignes autorisées
- Pour chaque ligne : appelle `_importObject()` ou `_importCollection()` selon le type
- La page `progress_html.php` s'auto-soumet après 500ms pour la ligne suivante
- En fin d'import : affiche la page de succès avec liens vers les fiches créées

> **Mémoire et temps :** l'action `Import()` définit `memory_limit = -1` et `max_execution_time = 0` pour les imports volumineux.

---

## Types d'import et identifiants de type

| Type d'import | type_id CA | Table cible | Fonction d'import |
|---|---|---|---|
| Mobilier archéologique | 24 | `ca_objects` | `_importObject()` |
| Documentation écrite | 26 | `ca_objects` | `_importObject()` |
| Documentation numérique | 26 | `ca_objects` | `_importObject()` |
| Opérations | 125 | `ca_collections` | `_importCollection()` |
| Objets musée | 39119 | `ca_objects` | `_importObject()` |
| Contenant mobilier | 28 | `ca_objects` | `_importObject()` |
| Contenant numérique | 1886 | `ca_objects` | `_importObject()` |
| Contenant documentation | 30 | `ca_objects` | `_importObject()` |

---

## Fonctions d'import (lib/)

### _importObject() — Import d'objets

*Fichier : `lib/_importObject.php`*

Fonction principale d'import pour tous les types d'objets (`ca_objects`). Processus :

1. **Chargement ou création :** recherche l'objet par `idno`. S'il n'existe pas, crée un nouvel objet avec le type et la locale (fr).
2. **Traitement des champs spéciaux :**
   - **Poids** — Conversion en chaîne avec unité (g par défaut)
   - **Dimensions** (hauteur, longueur, largeur, épaisseur, diamètre, profondeur) — Ajout de l'unité « cm »
   - **Titre** — Remplacement du libellé préféré
   - **Titre document** — Ajout en libellé non préféré
   - **Champs containers** — Regroupés pour insertion groupée (dimensions, notes, labo)
3. **Gestion des relations :**
   - `ca_collections` — Rattachement à une opération par idno
   - `ca_entities` — Liaison par idno (responsable, auteur, etc.)
   - `ca_places` — Recherche de commune par nom
   - `ca_objects` — Rattachement à un contenant (création automatique si inexistant)
   - `ca_storage_locations` — Liaison par ID ou recherche par idno
   - `ca_movements` — Liaison par idno
4. **Attributs standards :** suppression puis ajout. Gestion des valeurs multiples (séparateur « ; ») si `canBeMultiple = 1`.
5. **Containers :** suppression de tous les attributs containers existants, puis insertion des nouveaux.
6. **Hooks :** appel de `hookSaveItem()` pour déclencher les plugins aval (prepopulateInrap, etc.).

### _importCollection() — Import d'opérations

*Fichier : `lib/_importCollection.php`*

Fonction d'import pour les collections/opérations (`ca_collections`, type 125). Même structure que `_importObject()` :

- Chargement ou création par idno
- Gestion du titre (libellé préféré)
- Relations : `ca_places`, `ca_entities`, `ca_storage_locations`, `ca_movements`
- Attributs standards et containers
- Appel de `hookEditItem()` en fin de traitement

### migration_functionlib.php — Fonctions utilitaires

*Fichier : `lib/migration_functionlib.php`*

Bibliothèque de 20 fonctions utilitaires pour la migration de données :

| Fonction | Description |
|---|---|
| `getListID()` | Crée ou charge un vocabulaire (liste) |
| `ExistsItemID()` | Vérifie l'existence d'un élément de liste |
| `getItemID()` | Récupère ou crée un élément de liste hiérarchique |
| `getStorageLocationID()` | Trouve un emplacement de stockage par idno |
| `getStorageLocationIDfromPartialName()` | Recherche par nom partiel (requête SQL directe) |
| `getMovementID()` | Récupère ou crée un mouvement |
| `getCollectionID()` | Récupère ou crée une collection |
| `getOccurrenceID()` | Récupère ou crée une occurrence |
| `getObjectID()` | Récupère un objet par idno (sans création) |
| `getPlaceID()` | Récupère ou crée un lieu |
| `getPlaceIDByName()` | Recherche de lieu par nom (via PlaceSearch) |
| `getEntityID()` | Recherche d'entité par nom (via EntitySearch) |
| `getEntityIDByIdno()` | Recherche d'entité par idno |
| `cleanupDate()` | Normalisation des dates (ca, vers, formats DD/MM/YYYY) |

---

## Configuration — Mapping des champs

*Fichier : `conf/importInrap.conf`*

Le fichier de configuration définit le mapping entre les colonnes Excel et les champs CollectiveAccess pour chaque type d'import. Chaque champ est décrit par :

| Propriété | Description |
|---|---|
| `metadata` | Chemin du métadonnée CA (ex: `ca_objects.identification`) |
| `label` | Libellé affiché dans l'interface de mapping |
| `type` | Type de champ : `standard`, `title`, `relation`, `idno` |
| `relation_table` | Table cible de la relation (ca_collections, ca_entities, etc.) |
| `relation_type` | ID du type de relation |
| `container` | Nom du container parent pour les champs groupés |
| `canBeMultiple` | `1` = valeurs multiples séparées par « ; » |

### Types d'import configurés

| Section | Nb champs | Champs principaux |
|---|---|---|
| `mobilier` | 40+ | idno, identification, matériaux, dimensions (6), poids, commune, contenant, période chrono, datation, notes, labo |
| `operation` | 11 | idno, parcelle, responsable, lieu-dit, année, n° OA, type opération, commune, lieu d'entrée |
| `documentation_numerique` | 14 | idno, titre, code OA, contenant, auteur, famille document, support, format |
| `documentation_ecrite` | 13 | idno, code OA, contenant, auteur, famille document, support, format |
| `musee` | 25 | idno, commune, matériaux, dimensions, bibliographie, période, domaine, lien externe |
| `contenant_mobilier` | 13 | idno, code INRAP, dimensions (6), matériaux, emplacements, référentiels |
| `contenant_doc` | 6 | idno, code INRAP, emplacements, référentiels, description |
| `contenant_num` | 8 | idno, code INRAP, matériaux, emplacements, référentiels |

> **Paramètres globaux :** `enabled = 1` et `menu_title = "Import Inrap"`.

---

## Structure des fichiers

```
importInrap/
  importInrapPlugin.php              # Classe principale du plugin (hooks)
  conf/
    importInrap.conf                 # Configuration et mapping des champs (600+ lignes)
  controllers/
    ImportController.php             # Contrôleur (5 actions, 256 lignes)
  lib/
    _importObject.php                # Fonction d'import objets (222 lignes)
    _importCollection.php            # Fonction d'import collections (121 lignes)
    migration_functionlib.php        # 20 fonctions utilitaires (561 lignes)
  views/
    index_html.php                   # Page d'accueil (type + upload)
    select_sheet_html.php            # Sélection de la feuille Excel
    before_import_html.php           # Mapping colonnes ↔ champs
    select_line_html.php             # Sélection des lignes à importer
    progress_html.php                # Barre de progression (auto-submit)
    imported_html.php                # Page de succès avec liens
  temp/                              # Fichiers temporaires (Excel uploadés, JSON)
  backup/                            # Copies de sauvegarde
```

---

## Dépendances et interactions

| Composant | Type d'interaction |
|---|---|
| **PhpSpreadsheet** | Bibliothèque PHP (`PhpOffice\PhpSpreadsheet\IOFactory`) pour la lecture des fichiers Excel. Chargée via Composer. |
| **EntitySearch / PlaceSearch / StorageLocationSearch / ObjectSearch** | Classes de recherche CA utilisées pour retrouver les entités, lieux, emplacements et objets. |
| **ApplicationPluginManager** | Déclenche `hookSaveItem` / `hookEditItem` après chaque import. |
| **prepopulateInrap** | Le hook `hookSaveItem` déclenche les calculs automatiques (volumes, comptages). |

> **PhpSpreadsheet requis :** la bibliothèque `PhpOffice/PhpSpreadsheet` doit être installée via Composer.

> **Création automatique de contenants :** lors de l'import d'objets mobilier, si le contenant référencé n'existe pas dans l'opération, `_importObject()` le crée automatiquement (type 28) et le rattache à la collection.

---

## Activation

Le plugin est **activé par défaut** (`enabled = 1` dans `importInrap.conf`).

### Prérequis

- `PhpSpreadsheet` installé via Composer
- Répertoire `temp/` accessible en écriture par le serveur web
- Mapping des champs configuré dans `conf/importInrap.conf`
- Les types d'objets et de relations référencés doivent exister dans la base

---

**Date de documentation :** 2026-02-15
**Plugin :** importInrap
**Version CollectiveAccess :** Providence
