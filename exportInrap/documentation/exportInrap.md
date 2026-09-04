# Documentation technique — exportInrap

**Plugin CollectiveAccess Providence — INRAP**

## Vue d'ensemble

Le plugin **exportInrap** génère des **catalogues PDF** d'objets archéologiques à partir des opérations, ensembles ou occurrences de CollectiveAccess.

Il propose une interface en 3 étapes (sélection de l'opération, des objets, puis des métadonnées et de la mise en page), **6 styles de mise en page** et **8 stratégies de regroupement** des objets.

La conversion HTML → PDF est assurée par **PhantomJS** (bibliothèque H2P), puis finalisée par **pdftk**.

### Rôle du plugin

- Générer des catalogues PDF d'objets archéologiques
- Sélection interactive des objets, métadonnées et mise en page
- 6 styles de mise en page (1, 2 ou 4 objets par page)
- 8 stratégies de regroupement (matériaux, domaine, inventaire, etc.)
- Page de couverture avec données de l'opération
- Export depuis une opération, un ensemble (`ca_sets`) ou une occurrence (`ca_occurrences`)

### Hooks utilisés

- **hookRenderMenuBar** — Ajoute le menu « Catalogue objets par opération » dans la barre de navigation (sous « Gérer »)
- **hookAppendToEditorInspector** — Ajoute un bouton « Catalogue d'objets » dans la barre latérale des ensembles et des occurrences (type 116)
- **hookGetRoleActionList** — Déclaré mais retourne la liste inchangée

---

## Points d'entrée

Le plugin est accessible de 3 façons :

| Point d'entrée | Source | Description |
|---|---|---|
| Menu principal | `hookRenderMenuBar` | Entrée « Catalogue objets par opération » dans Gérer → Navigation. L'utilisateur saisit un code opération INRAP. |
| Bouton sur un ensemble | `hookAppendToEditorInspector` | Bouton « Catalogue d'objets » sur les fiches `ca_sets` (table_num = 57). Exporte directement les objets de l'ensemble. |
| Bouton sur une occurrence | `hookAppendToEditorInspector` | Bouton « Catalogue d'objets » sur les fiches `ca_occurrences` de type 116. Exporte les objets liés à l'occurrence. |

---

## Processus d'export (3 étapes)

### Étape 1 — Sélection de l'opération

L'utilisateur saisit le **code opération INRAP**. Le système charge alors via AJAX (`/ajax/objectByCodeOp.php`) la liste des objets rattachés à cette opération.

*Cette étape est sautée si l'export est lancé depuis un ensemble ou une occurrence (le paramètre `set_id` ou `occurrence_id` est déjà fourni).*

### Étape 2 — Sélection des objets

L'utilisateur coche les **types d'objets** à inclure dans le catalogue :

- Mobilier (type 24)
- Prélèvement (type 25)
- Document (type 26)
- Contenant mobilier (type 28)
- Contenant documentation (type 30)
- Contenant documentation numérique (type 1886)

Des boutons « Tout cocher » / « Tout décocher » sont disponibles.

### Étape 3 — Métadonnées et mise en page

L'utilisateur configure le catalogue :

- **Métadonnées de l'opération** (11 champs : lieu d'entrée, n° OA, code INRAP, commune, etc.)
- **Métadonnées des objets** (20+ champs : photos, identification, dimensions, période, etc.)
- **Style de mise en page** (6 styles avec aperçu visuel)
- **Stratégie de regroupement** (8 options)
- **Titre du catalogue** (facultatif)

---

## Styles de mise en page

| Style | Objets/page | Disposition |
|---|---|---|
| `texte-deux-images-gauche` | 1 | Images à gauche, texte à droite |
| `texte-deux-images-droite` | 1 | Images à droite, texte à gauche |
| `ensemble-2-par-page-texte-bas` | 2 | Images en haut, texte en bas |
| `ensemble-2-par-page-texte-droite` | 2 | Images à gauche, texte à droite |
| `ensemble-4-par-page-texte-droite` | 4 | Images à gauche, texte à droite |
| `ensemble-4-par-page-texte-bas` | 4 | Images en haut, texte en bas |

> **Images :** selon le style, 1 à 3 représentations (`ca_object_representations`) sont incluses par objet. Les styles à 1 objet/page affichent jusqu'à 3 images, les styles à 4 objets/page n'en affichent qu'une.

---

## Stratégies de regroupement

| Code | Critère de regroupement | Champ source |
|---|---|---|
| `mat` | Matériaux | `ca_objects.inrap_materiaux` |
| `dom` | Domaine | `ca_objects.inrap_domaine` |
| `inv` | Numéro d'inventaire | `ca_objects.idno` (tri) |
| `iso` | Numéro d'isolation | `ca_objects.inrap_numero_isolation` |
| `pcm` | Période chronologique musée | `ca_objects.inrap_musee_chrono` |
| `matm` | Matériaux musée | `ca_objects.inrap_musee_materiaux` |
| `pc` | Période chronologique site | `ca_objects.inrap_periode_chrono_site` |
| *(défaut)* | Type de mobilier | `ca_objects.type_mobilier` |

---

## Métadonnées exportées

### Métadonnées de l'opération (page de couverture)

- Lieu d'entrée (`ca_storage_locations_entree`)
- Numéro OA
- Code INRAP
- Commune / Lieu-dit (`ca_places`)
- Responsable d'opération (`ca_entities_RO`)
- Année d'intervention
- Type d'opération / Statut
- SRA (`ca_entities_SRA`)
- Direction INRAP (`ca_entities_DIR`)
- Volume total

### Métadonnées des objets (fiches)

- Photos (1 à 3 selon le style)
- Identification / Désignation
- N° OA, N° inventaire, N° isolation
- Unité d'enregistrement
- Période chronologique / Datation
- Matériaux / Précision matériau
- Quantification / Fragmentation
- État sanitaire (`ca_occurrences`)
- Dimensions (mm et cm)
- Contexte archéologique
- Emplacement de stockage
- Valeur d'assurance / Labo
- Contenants liés / Traitements

---

## Actions du contrôleur (ExportController)

*Fichier : `controllers/ExportController.php`*

| Action | Description |
|---|---|
| `Index` | Affiche l'interface de configuration de l'export (formulaire Bootstrap 5 en 3 étapes). Reçoit optionnellement `set_id` ou `occurrence_id`. |
| `Export` | Génère le catalogue PDF : collecte les données, appelle `generateHTML()`, convertit via PhantomJS, finalise avec pdftk, et redirige vers le fichier PDF. |

### Génération du PDF (Export)

Le processus de génération du PDF suit ces étapes :

1. Collecte des données des objets via `getWithTemplate()` (modèle CA)
2. Regroupement selon la stratégie choisie
3. Génération du HTML avec CSS intégré (`assets/css/pdf.css`) et logo INRAP
4. Sauvegarde en fichier temporaire (`tmp/pdf-content_[timestamp].html`)
5. Conversion HTML → PDF via **PhantomJS** (A4 paysage, zoom 0.4, marge 1cm)
6. Finalisation via **pdftk** (`tmp/rapport_[timestamp].pdf`)
7. Redirection vers le PDF pour téléchargement

> **Fichiers temporaires :** les fichiers HTML et PDF intermédiaires sont stockés dans le dossier `tmp/` du plugin. Ils ne sont pas automatiquement nettoyés.

---

## Configuration

*Fichier : `conf/exportInrap.conf`*

| Paramètre | Valeur | Description |
|---|---|---|
| `enabled` | `1` | Active le plugin |
| `menu_title` | `Catalogue objets par opération` | Texte du menu principal |

---

## Structure des fichiers

```
exportInrap/
  exportInrapPlugin.php        # Plugin principal (hooks, menu, boutons)
  conf/
    exportInrap.conf            # Configuration (enabled, menu_title)
  controllers/
    ExportController.php        # Contrôleur (Index, Export, generateHTML)
  views/
    index_html.php              # Interface de configuration (Bootstrap 5)
    view_pdf_html.php           # Téléchargement du PDF généré
  lib/
    h2p/                        # Bibliothèque H2P (PhantomJS wrapper)
      bin/                      # Binaires PhantomJS (linux, mac)
      src/H2P/                  # Classes PHP du convertisseur
  assets/
    css/pdf.css                 # Styles CSS du catalogue PDF
    fonts/EBGaramond/           # Police EB Garamond (ttf, otf)
    img/inrap_0.jpg             # Logo INRAP pour le PDF
    styles/                     # Aperçus des styles de mise en page
    sass/                       # Sources SASS du CSS
  tmp/                          # Fichiers temporaires (HTML, PDF)
```

---

## Dépendances externes

- **H2P (PhantomJS wrapper)** — Bibliothèque PHP incluse dans `lib/h2p/` pour la conversion HTML → PDF. Binaires PhantomJS inclus pour Linux (x86, x86_64) et Mac.
- **pdftk** — Outil externe (ligne de commande) utilisé pour finaliser le PDF. Doit être installé sur le serveur (`apt install pdftk` sur Debian/Ubuntu).
- **Bootstrap 5** — Utilisé dans l'interface de configuration (vue `index_html.php`)
- **EB Garamond** — Police de caractères embarquée pour le rendu PDF

---

## Paramètres de rendu PDF

| Paramètre | Valeur |
|---|---|
| Orientation | Paysage (landscape) |
| Format | A4 |
| Zoom | 0.4 |
| Marge | 1 cm |
| Pied de page | Numéro de page + métadonnées de l'opération |
| Police | EB Garamond |

---

## Notes techniques

> **Bug identifié :** dans le constructeur de `ExportController.php`, le nom du plugin est défini comme `"importInrap"` au lieu de `"exportInrap"` (ligne 20). Cela charge potentiellement une mauvaise configuration, mais n'affecte pas le fonctionnement car le fichier de configuration est chargé correctement via le chemin du plugin.

> **Pas de restriction par rôle :** `getRoleActionList()` retourne un tableau vide. Le plugin est accessible à tous les utilisateurs ayant accès à l'interface d'administration.

> **Données via templates CA :** toutes les données des objets sont extraites via la syntaxe de templates CollectiveAccess (`getWithTemplate()`) et les méthodes de relations (`restrictToRelationshipTypes`, `restrictToTypes`).

---

**Date de documentation :** 2026-02-15
**Plugin :** exportInrap
**Version CollectiveAccess :** Providence
