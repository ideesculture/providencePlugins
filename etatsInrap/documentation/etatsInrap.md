# Documentation technique — etatsInrap

**Plugin CollectiveAccess Providence — INRAP**

## Vue d'ensemble

Le plugin **etatsInrap** permet de générer des états et documents réglementaires (listes d'opérations, inventaires de collections, bordereaux de versement/mouvement, constats d'état, conditions de prêt, courriers) directement depuis l'interface de gestion de CollectiveAccess.

Il propose **12 modèles** couvrant différents cas métier : listes d'opérations filtrées par statut/type/région, inventaires de collection, étiquettes, bordereaux, conditions de prêt pour expositions, et génération de courriers au format DOCX. Il intègre également un système d'impression d'étiquettes prédécoupées et deux contrôleurs de listes (versements et courriers).

### Rôle du plugin

- Générer des listes d'opérations filtrées par type (diagnostics, fouilles, fouilles programmées), statut (en cours, en attente de versement, en garde, versées) et localisation (direction, région, département)
- Produire des inventaires de collection (mobilier et contenants) depuis une fiche opération
- Générer des bordereaux de versement et de mouvement au format HTML et DOCX
- Produire des courriers réglementaires (demande de garde, courrier libre, demande de versement) au format DOCX
- Générer des conditions de prêt pour les expositions au format DOCX
- Imprimer des étiquettes prédécoupées (formats INRAP 10, 16 et 24 vues)
- Afficher les listes de versements et de courriers de versement

### Hooks utilisés

- **hookRenderMenuBar** — Ajoute un menu « États » dans la barre de navigation avec le sous-menu « Liste des états »
- **hookAppendToEditorInspector** — Ajoute des boutons contextuels dans l'inspecteur latéral de plusieurs types de fiches
- **hookAddDomainSecurityPolicy** — Autorise le domaine `cdnjs.cloudflare.com` dans la politique de sécurité CSP

**Aucune restriction de rôle** — `getRoleActionList` retourne un tableau vide.

---

## Points d'entrée (boutons dans l'inspecteur)

Le plugin injecte des boutons dans l'inspecteur latéral pour plusieurs types de fiches :

| Table | Condition | Bouton | Action (modèle) |
|---|---|---|---|
| `ca_movements` | type = `versement` | « Bordereau de versement » | Modèle 4 — Bordereau de versement |
| `ca_movements` | type = `movement` | « Bordereau de mouvement » | Modèle 4b — Bordereau de mouvement |
| `ca_collections` | Toutes les collections | « Inventaire de la collection » / « Inventaire des contenants » | Modèle 2 / Modèle 2b |
| `ca_occurrences` | type = `exposition` | « Conditions générales de prêt » | Modèle 6 — Conditions de prêt |
| `ca_occurrences` | type = `demande_garde_inrap` | « Génération du courrier » | Modèle 10 — Demande de garde |
| `ca_occurrences` | type = `courrier_libre` | « Génération du courrier » | Modèle 11 — Courrier libre |
| `ca_occurrences` | type = `demande_versement` | « Génération du courrier » | Modèle 12 — Demande de versement |

---

## Contrôleurs

### GenererController (1560 lignes)

*Fichier : `controllers/GenererController.php`* — Contrôleur principal gérant les 12 modèles d'états.

| Action | Description | Sortie |
|---|---|---|
| `Index()` | Page d'accueil listant tous les états disponibles, avec sélecteurs de direction/région/département. | HTML |
| `Modele1()` | **Listes d'opérations** filtrées par type (diagnostic, fouille, fouille programmée) et statut (en cours, en attente de versement, en garde INRAP/État, non défini, versées). Utilise `CollectionBrowse` avec facettes géographiques. **20 variantes** configurées. | HTML |
| `Modele2()` | **Inventaire de la collection** — Liste le mobilier (objets) d'une opération avec 23 colonnes d'information. Filtre par type d'objet (mobilier, document). | HTML |
| `Modele2b()` | **Inventaire des contenants** — Liste les contenants d'une opération avec 16 colonnes (dimensions, volume, emplacements). | HTML |
| `Modele3()` | **Étiquettes** — Génère les données pour les étiquettes (temporaires, conditionnement, contenant) d'une collection. | HTML |
| `Modele4()` | **Bordereau de versement** — Génère le bordereau complet depuis une fiche mouvement de type `versement`. Export HTML ou DOCX. | HTML / DOCX |
| `Modele4b()` | **Bordereau de mouvement** — Génère le bordereau depuis une fiche mouvement générique. Export HTML ou DOCX. | HTML / DOCX |
| `Modele6()` | **Conditions de prêt exposition** — Génère le document depuis une fiche occurrence de type `exposition`. Liste les objets prêtés avec détails. | HTML / DOCX |
| `Modele7()` | **Liste des objets pour exposition** — Liste les objets liés à une exposition. | HTML |
| `Modele8()` | **Lettres par mouvement** — Lettres liées aux mouvements d'objets. | HTML |
| `Modele9()` | **Stock centre** — État du stock d'un centre de recherche. | HTML |
| `Modele10()` | **Courrier de demande de garde INRAP** — Génère un courrier DOCX depuis une fiche occurrence. Inclut les informations SRA, DIR, gestionnaire du centre. | DOCX |
| `Modele11()` | **Courrier libre** — Génère un courrier DOCX avec objet, ouverture, texte libre et clôture personnalisables. | DOCX |
| `Modele12()` | **Demande de versement** — Génère une lettre de demande de versement multi-collections. Utilise `pagemerger` pour fusionner les DOCX. | DOCX |

> **Pages d'aide :** Certains modèles disposent d'une page d'aide dédiée : `Modele2_help()`, `Modele2b_help()`, `Modele3_help()`, `Modele4_help()`, `Modele6_help()`.

### PrintLabelsController (268 lignes)

*Fichier : `controllers/PrintLabelsController.php`* — Contrôleur d'impression d'étiquettes prédécoupées.

| Action | Description |
|---|---|
| `Index()` | Page d'accueil de l'impression d'étiquettes. |
| `Generate()` | Génère les étiquettes au format PDF via `PDFRenderer`. Supporte trois formats : **INRAP 10 vues** (105×59,4 mm), **INRAP 16 vues** (105×37,1 mm), **INRAP 24 vues** (70×37,1 mm). |

### ListedesversementsController (88 lignes)

*Fichier : `controllers/ListedesversementsController.php`*

| Action | Description |
|---|---|
| `Index()` | Liste des versements (mouvements de type `versement`) pour un emplacement donné. Limite à 300 résultats. |

### ListedescourriersversementsController (128 lignes)

*Fichier : `controllers/ListedescourriersversementsController.php`*

| Action | Description |
|---|---|
| `Index()` | Liste des courriers de versement (occurrences de type `demande_versement`). Filtrage par région géographique. |

---

## Modèle 1 — Listes d'opérations (détail)

Le modèle 1 est le plus utilisé. Il génère des listes d'opérations filtrées selon trois critères combinés.

### Types d'opérations

- **Diagnostics** (type_ope = 1443)
- **Fouilles** (type_ope = 1444)
- **Fouilles programmées** (type_ope = 1650)
- **Toutes opérations** (type_ope = 0)

### Statuts

- **En cours d'étude** (statut = 1553)
- **En attente de versement** (statut = 1554)
- **En garde pour INRAP** (statut = 1555)
- **En garde pour État** (statut = 1634)
- **Non défini**
- **Versées** (statut = 1556)

Colonnes du tableau : DIR, Lieu d'entrée, Volume (m3), Statut collection, Code OA, Type opération, Code INRAP, Région, Département, Commune, Lieudit, RO, Fin terrain, Prév. remise du rapport, Remise du rapport.

---

## Génération DOCX

Les modèles 4, 4b, 6, 10, 11 et 12 génèrent des documents Word (DOCX) à partir de templates stockés dans le répertoire `templates/`.

| Template | Modèle |
|---|---|
| `bordereau-versement.docx` | Modèle 4 — Bordereau de versement |
| `bordereau-mouvement.docx` | Modèle 4b — Bordereau de mouvement |
| `conditions_generales_de_pret.docx` | Modèle 6 — Conditions de prêt exposition |
| `lettre_garde_inrap.docx` | Modèle 10 — Courrier de demande de garde |
| `lettre_vierge.docx` | Modèle 11 — Courrier libre |
| `lettre_versement.docx` | Modèle 12 — Demande de versement (base) |
| `lettre_versement_sous_partie.docx` | Modèle 12 — Sous-partie par collection |
| `formulaire_deplacement_bam.docx` | Formulaire de déplacement BAM |
| `constat_etat_base.docx` | Constat d'état (base) |

> **Dépendance :** Le modèle 12 utilise l'outil `pagemerger` pour fusionner plusieurs fichiers DOCX en un seul document final.

> **Bibliothèque PHPWord :** Le plugin embarque la bibliothèque PHPWord dans `lib/PHPWord/` pour la génération et la manipulation des fichiers DOCX.

---

## Configuration

*Fichier : `conf/etatsInrap.conf`*

| Paramètre | Valeur | Description |
|---|---|---|
| `enabled` | `0` | Active ou désactive le plugin. **Désactivé par défaut.** |

> **Attention :** Ce plugin est désactivé par défaut. Pour l'activer, passez `enabled` à `1`.

---

## Structure des fichiers

```
etatsInrap/
  etatsInrapPlugin.php              # Classe principale du plugin (hooks)
  conf/
    etatsInrap.conf                 # Configuration
  controllers/
    GenererController.php           # Contrôleur principal (12 modèles, 1560 lignes)
    PrintLabelsController.php       # Impression d'étiquettes PDF
    ListedesversementsController.php # Liste des versements
    ListedescourriersversementsController.php # Liste des courriers
  views/
    index_html.php                  # Page d'accueil des états
    modele1_html.php                # Listes d'opérations
    modele2_html.php                # Inventaire collection
    modele2b_html.php               # Inventaire contenants
    modele3_html.php                # Étiquettes
    modele4_html.php / modele4_docx.php   # Bordereau versement
    modele4b_html.php / modele4b_docx.php # Bordereau mouvement
    modele5_html.php / modele5_docx.php   # Constat d'état
    modele5b_html.php / modele5b_docx.php # Constat d'état itinérance
    modele6_html.php / modele6_docx.php   # Conditions de prêt
    modele7_html.php                # Liste objets exposition
    modele8_html.php                # Lettres mouvement
    modele9_html.php                # Stock centre
    modele10_docx.php               # Courrier demande de garde
    modele11_docx.php               # Courrier libre
    modele12_docx.php               # Demande de versement (base)
    modele12_sp_docx.php            # Sous-partie par collection
    modele*_help_html.php           # Pages d'aide
    error_html.php                  # Page d'erreur
    listedesversements_html.php     # Liste des versements
    listedescourriersversements_html.php # Liste des courriers
  templates/                        # Templates DOCX
  printTemplates/summary/           # Templates d'impression PDF
  lib/PHPWord/                      # Bibliothèque PHPWord
  css/                              # Feuilles de styles
  tmp/                              # Fichiers temporaires (DOCX générés)
  results.json                      # Cache JSON des régions/départements
  documentation/                    # Documentation (cette page)
```

---

## Dépendances et interactions

| Composant | Type d'interaction |
|---|---|
| **PHPWord** | Bibliothèque PHP embarquée dans `lib/PHPWord/` pour la génération de fichiers DOCX à partir de templates. |
| **pagemerger** | Outil en ligne de commande pour fusionner des fichiers DOCX. Utilisé par le modèle 12. |
| **PDFRenderer** | Composant CollectiveAccess utilisé par `PrintLabelsController` pour générer les étiquettes PDF. |
| **CollectionBrowse / ObjectBrowse** | Composants de navigation CA utilisés pour filtrer les opérations et objets avec facettes (lieu, type, statut). |
| **ResultContext** | Utilisé pour récupérer les résultats de la dernière recherche. |
| **results.json** | Fichier JSON statique contenant les listes de régions, anciennes régions et départements pour les filtres dynamiques. |

---

## Activation

Le plugin est **désactivé par défaut** (`enabled = 0` dans `etatsInrap.conf`).

Pour l'activer, modifier le fichier `conf/etatsInrap.conf` :

```
enabled = 1
```

### Prérequis

- `pagemerger` installé (pour la fusion DOCX du modèle 12)
- Répertoire `tmp/` accessible en écriture par le serveur web
- Fichier `results.json` à jour pour les filtres géographiques
- Templates DOCX présents dans `templates/`

---

**Date de documentation :** 2026-02-15
**Plugin :** etatsInrap
**Version CollectiveAccess :** Providence
