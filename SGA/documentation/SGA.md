# Documentation technique — SGA

**Plugin CollectiveAccess Providence — INRAP**

## Vue d'ensemble

Le plugin **SGA** permet de **synchroniser les données d'opérations archéologiques** depuis le Système de Gestion d'Archéologie (SGA) vers la base CollectiveAccess (Comodo).

Le plugin offre un **workflow complet** : consultation des opérations SGA (importées ou non), comparaison côte à côte des données SGA/Comodo, import de nouvelles opérations ou mise à jour sélective des opérations existantes. Il compare **plus de 30 champs métadonnées** et gère automatiquement les relations avec les entités, les lieux et les emplacements.

### Rôle du plugin

- Importer des opérations archéologiques depuis la table `_sga_comodo` vers les collections CollectiveAccess
- Comparer les données SGA avec les données existantes dans Comodo (plus de 30 champs)
- Permettre la mise à jour sélective (champ par champ) des collections existantes
- Créer ou rechercher automatiquement les entités (responsables, SRA, prescripteurs)
- Créer ou rechercher automatiquement les lieux (communes) et emplacements
- Afficher un bouton d'import rapide dans l'inspecteur de l'éditeur de collections
- Générer des tables HTML (via DataTables) pour lister les opérations importées et non importées

### Hooks utilisés

- **hookRenderMenuBar** — Ajoute deux sous-menus dans la barre de navigation : « Liste des opérations importées SGA » et « Liste des opérations non importées SGA »
- **hookAppendToEditorInspector** — Affiche un bouton « Importation donnée du SGA » dans l'inspecteur latéral de l'éditeur de collections lorsque des différences sont détectées avec le SGA

**Aucune restriction de rôle** — `getRoleActionList` retourne un tableau vide.

---

## Workflow de synchronisation

### Import d'une nouvelle opération

1. L'utilisateur accède à « Liste des opérations non importées SGA »
2. Sélection d'une opération → action `Compare()`
3. Affichage des données SGA (la collection n'existe pas dans Comodo)
4. Clic sur « Importer » → action `Importer()`
5. Création de la collection avec toutes les métadonnées et relations
6. Page de résultat avec lien vers la fiche créée

### Mise à jour d'une opération existante

1. L'utilisateur accède à « Liste des opérations importées SGA » ou via le bouton dans l'inspecteur
2. Sélection d'une opération → action `Compare()`
3. Affichage côte à côte : valeur SGA vs valeur Comodo
4. Sélection des champs à mettre à jour (cases à cocher)
5. Clic sur « Mettre à jour » → action `Update()`
6. Mise à jour sélective et rapport d'écarts restants

> **Intégration éditeur :** Lorsqu'un utilisateur consulte une fiche collection dans l'éditeur, le plugin vérifie automatiquement s'il existe des différences avec le SGA. Si c'est le cas, un bouton bleu « Importation donnée du SGA » apparaît dans l'inspecteur latéral.

---

## Champs comparés et importés

Le plugin compare et synchronise plus de 30 champs métadonnées entre le SGA et Comodo :

| Champ SGA | Métadonnée CA | Type |
|---|---|---|
| Code INRAP (idno) | `idno` | Identifiant |
| Ancien code INRAP | `inrap_ancien_code` | Attribut |
| Type d'opération | `inrap_type_op.inrap_type_ope` | Attribut (container) |
| Axe analytique | `inrap_type_op.inrap_axe_analytique` | Attribut (container) |
| Numéro OA | `inrap_numero_oa` | Attribut |
| Responsable d'opération | `ca_entities` (rel. type 1515) | Relation |
| Commune | `ca_places` (rel. type 1474) | Relation |
| Lieu-dit | `inrap_lieu_dit` | Attribut |
| Code INSEE | `inrap_code_insee` | Attribut |
| Parcelle | `inrap_parcelle` | Attribut |
| Centre opérationnel | `inrap_centre_op` | Attribut |
| SRA | `ca_entities` (rel. type 252) | Relation |
| Agent prescripteur | `ca_entities` (rel. type 1519) | Relation |
| Direction INRAP | `ca_entities` (rel. type 1516) | Relation |
| N° autorisation | `inrap_numero_autorisation` | Attribut |
| Date d'autorisation | `inrap_date_autorisation` | Attribut |
| Dates terrain (début/fin) | `inrap_date_terrain.*` | Container |
| Date remise rapport | `inrap_date_rapport.*` | Container |
| Dates programmation | `inrap_date_programmation.*` | Container |
| Surface | `inrap_surface_ope` | Attribut |

---

## Mapping des directions

Le plugin convertit les noms de direction SGA en codes de direction INRAP :

| Direction SGA | Code INRAP |
|---|---|
| Centre Ile de France | `DIR CIF` |
| Grand Ouest | `DIR GO` |
| Grand Est | `DIR GE` |
| Auvergne-Rhône-Alpes | `DIR ARA` |
| Hauts-de-France | `DIR HDF` |
| Midi-Méditerranée | `DIR MIDIMED` |
| Outre-mer | `DIR NAOM` |
| Nouvelle Aquitaine | `DIR NAOM` |
| Bourgogne-Franche-Comté | `DIR BFC` |

---

## Contrôleur

### SGAController (711 lignes)

*Fichier : `controllers/SGAController.php`*

Contrôleur principal gérant les actions d'import, comparaison et mise à jour.

| Action | Description | Vue |
|---|---|---|
| `Compare()` | **Comparaison SGA/Comodo** — Récupère l'enregistrement SGA depuis `_sga_comodo`, charge la collection par idno, compare 30+ champs et affiche les différences côte à côte. | `compare_html.php` |
| `IndexImporte()` | **Opérations importées** — Affiche la liste des opérations SGA déjà présentes dans Comodo. | `index_importe_html.php` |
| `IndexNonImporte()` | **Opérations non importées** — Affiche la liste des opérations SGA pas encore importées. | `index_non_import_html.php` |
| `Importer()` | **Import complet** — Crée une nouvelle collection avec toutes les métadonnées SGA, crée/lie les entités et lieux, génère le libellé automatique, déclenche les hooks. | `import_html.php` |
| `Update()` | **Mise à jour sélective** — Met à jour uniquement les champs sélectionnés par l'utilisateur (via cases à cocher), génère le nouveau libellé, déclenche les hooks. | `update_html.php` |

> **Génération du libellé :** Lors de l'import ou de la mise à jour, le plugin génère automatiquement le libellé de la collection au format : *Commune / Lieu-dit / Année / Responsable d'opération*.

> **Détection d'erreurs :** Après chaque import ou mise à jour, le plugin effectue une nouvelle comparaison pour détecter les écarts restants. Les causes courantes sont : différences de formatage de noms, entités ou lieux non trouvés, ou valeurs similaires mais pas identiques.

---

## Fonctions utilitaires (lib/)

### migration_functionlib.php (561 lignes)

*Fichier : `lib/migration_functionlib.php`*

Bibliothèque de fonctions utilitaires pour la migration de données :

| Fonction | Description |
|---|---|
| `getListID()` | Crée ou charge un vocabulaire (liste) |
| `ExistsItemID()` | Vérifie l'existence d'un élément de liste |
| `getItemID()` | Récupère ou crée un élément de liste hiérarchique |
| `getStorageLocationID()` | Crée ou récupère un emplacement de stockage |
| `getStorageLocationIDfromPartialName()` | Recherche d'emplacement par nom partiel (SQL direct) |
| `getMovementID()` | Récupère ou crée un mouvement |
| `getCollectionID()` | Récupère ou crée une collection |
| `getOccurrenceID()` | Récupère ou crée une occurrence |
| `getObjectID()` | Récupère ou crée un objet |
| `getPlaceID()` | Récupère ou crée un lieu |
| `getEntityID()` | Recherche d'entité par nom (via EntitySearch, multiples formats) |
| `getEntityIDByIdno()` | Recherche d'entité par idno (création si inexistante) |
| `insertRelationEntitiesXPlaces()` | Crée une relation entité-lieu (sans doublons) |
| `updateObjetLot()` | Met à jour l'affectation lot d'un objet |
| `cleanupDate()` | Normalise les dates (suppression approximations, conversion formats DD/MM/YYYY) |
| `show_status()` | Affiche une barre de progression console |

### update_table.php

*Fichier : `lib/update_table.php`*

Script utilitaire qui génère les tables HTML statiques des opérations SGA :

- Interroge la table `_sga_comodo` pour toutes les opérations
- Pour chaque opération, vérifie son existence dans `ca_collections` par idno
- Génère deux fichiers HTML statiques :
  - `table_importe.html` — Opérations déjà importées (avec lien « Mettre à jour »)
  - `table_non_importe.html` — Opérations non importées (avec lien « Importer »)
- Colonnes : Code INRAP, Direction, Centre opérationnel, Commune, Lieu-dit, Action
- Utilise le plugin jQuery **DataTables** pour le tri et la recherche

> **Fichiers volumineux :** Les tables générées peuvent être très volumineuses (~5,7 Mo pour les opérations importées, ~13 Mo pour les non importées).

---

## Vues

| Fichier | Description |
|---|---|
| `compare_html.php` | **Page de comparaison** — Tableau à trois colonnes (Champ / Valeur SGA / Valeur Comodo). Si la collection n'existe pas, propose « Importer ». Sinon, cases à cocher pour mise à jour sélective. |
| `import_html.php` | **Résultat d'import** — Message de succès avec lien vers la fiche créée. Tableau des écarts restants si applicable. |
| `update_html.php` | **Résultat de mise à jour** — Similaire à `import_html.php` pour les mises à jour sélectives. |
| `index_importe_html.php` | **Liste importées** — Intègre `table_importe.html` avec DataTables. |
| `index_non_import_html.php` | **Liste non importées** — Intègre `table_non_importe.html` dans une iframe. |
| `table_importe.html` | Table HTML générée (~5,7 Mo) — opérations déjà importées. |
| `table_non_importe.html` | Table HTML générée (~13 Mo) — opérations non importées. |

---

## Traduction des noms de champs

Les vues de comparaison et d'import traduisent les noms techniques en libellés français :

| Nom technique | Libellé |
|---|---|
| `idno` | Code Inrap |
| `inrap_ancien_code` | Ancien code Inrap |
| `inrap_type_op.inrap_type_ope` | Type de l'opération |
| `inrap_type_op.inrap_axe_analytique` | Axe analytique |
| `inrap_numero_oa` | Numéro OA |
| `ro` | Responsable de l'opération |
| `commune` | Commune |
| `sra` | SRA |
| `dir_inrap` | Direction Inrap |
| `inrap_centre_op` | Centre opérationnel |
| `inrap_parcelle` | Parcelle |
| `inrap_lieu_dit` | Lieu-dit |
| `inrap_numero_autorisation` | N° d'autorisation |
| `inrap_surface_ope` | Surface |
| `agent_prescripteur` | Agent prescripteur |

---

## Configuration

*Fichier : `conf/sga.conf`*

| Paramètre | Valeur | Description |
|---|---|---|
| `enabled` | `1` | Active ou désactive le plugin. **Activé par défaut.** |

---

## Structure des fichiers

```
SGA/
  SGAPlugin.php                    # Classe principale du plugin (hooks)
  conf/
    sga.conf                       # Configuration (enabled = 1)
  controllers/
    SGAController.php              # Contrôleur principal (711 lignes)
  lib/
    migration_functionlib.php      # Fonctions utilitaires migration (561 lignes)
    update_table.php               # Générateur de tables HTML
  views/
    compare_html.php               # Comparaison SGA / Comodo
    import_html.php                # Résultat d'import
    update_html.php                # Résultat de mise à jour
    index_importe_html.php         # Liste opérations importées
    index_non_import_html.php      # Liste opérations non importées
    table_importe.html             # Table HTML générée (importées)
    table_non_importe.html         # Table HTML générée (non importées)
    index_html.php                 # Page index (vide)
  documentation/                   # Documentation (cette page)
```

---

## Dépendances et interactions

| Composant | Type d'interaction |
|---|---|
| **Table `_sga_comodo`** | Table source contenant les données SGA synchronisées. Accès direct en SQL pour la lecture des opérations. |
| **DataTables (jQuery)** | Plugin JavaScript chargé depuis CDN pour le tri, la pagination et la recherche dans les listes d'opérations. |
| **EntitySearch** | Classe de recherche CA utilisée pour retrouver les entités (responsables, SRA, prescripteurs) par nom. |
| **ApplicationPluginManager** | Déclenche `hookSaveItem` après chaque import ou mise à jour pour activer les plugins aval (prepopulate, etc.). |
| **ca_collections** | Table cible : les opérations SGA sont importées comme collections de type 125. |
| **ca_entities / ca_places** | Création ou recherche automatique des entités et lieux lors de l'import. |

---

## Activation

Le plugin est **activé par défaut** (`enabled = 1` dans `sga.conf`).

### Prérequis

- Table `_sga_comodo` alimentée et à jour dans la base de données
- Entités (responsables, SRA, directions) pré-créées pour la recherche par nom
- Tables de listes (`ca_list_items`) configurées pour les types d'opération et axes analytiques
- Exécuter `lib/update_table.php` pour régénérer les tables HTML

---

**Date de documentation :** 2026-02-15
**Plugin :** SGA
**Version CollectiveAccess :** Providence
