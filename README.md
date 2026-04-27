# providencePlugins

Collection de plugins open-source pour [CollectiveAccess Providence](https://collectiveaccess.org/) (back-office).

Ces plugins sont principalement developpes en francais mais nous cherchons a les internationaliser progressivement. Les contributions et traductions sont les bienvenues.

## Installation

Copier le dossier du plugin dans le repertoire `app/plugins/` de Providence :

```bash
cp -r SaveAndStay /chemin/vers/providence/app/plugins/
```

Les plugins sont auto-detectes par le `ApplicationPluginManager` de Providence.

## Activation et configuration locale

Certains plugins lisent un fichier `conf/<plugin>.conf` qui contient une cle `enabled` (defaut : `0`). Pour activer un plugin sur une instance specifique sans modifier le defaut versionne :

1. Creer le fichier `conf/local/<plugin>.conf` (le dossier `conf/local/` est gitignore)
2. Y placer les cles a surcharger, notamment `enabled = 1`

Si `conf/local/<plugin>.conf` existe, il est utilise en lieu et place de `conf/<plugin>.conf`. Cela permet de versionner une configuration par defaut "off" tout en activant les plugins voulus instance par instance.

Plugins concernes par ce mecanisme : `searchIdno`, `searchParent`, `frenchRevolutionaryCalendar`, `SimpleZ3950`.

## Plugins

### SaveAndStay

<img src="documentation/icons/saveandstay.png" alt="SaveAndStay" height="150" align="right" />

Rend la barre d'outils de l'editeur (Enregistrer / Annuler / Supprimer) **fixe** en haut de l'ecran, pour qu'elle reste visible lors du defilement dans les fiches longues. **Restaure egalement la position de defilement** apres l'enregistrement, pour ne pas perdre sa place dans le formulaire.

**Hooks utilises :** `hookAppendToEditorInspector`

**Fonctionnement :**
- Injecte du CSS pour rendre la `.control-box` sticky (`position: sticky`)
- Sauvegarde la position de defilement dans un cookie ephemere (par type d'editeur et ID de fiche) au clic sur Enregistrer
- Restaure la position au rechargement de la page, puis supprime le cookie

### SimpleZ3950

<img src="documentation/icons/simplez3950.png" alt="SimpleZ3950" height="150" align="right" />

Permet l'import de notices bibliographiques via le protocole **Z39.50** (catalogues BnF, SUDOC, KBR, etc.) directement dans CollectiveAccess. Une entree "Z39.50" est ajoutee au menu Import du back-office.

**Hooks utilises :** `hookRenderMenuBar`

**Dependances :**
- Extension PHP `yaz` (`apt install php-yaz`)
- Un mapping `z3950_import_marc` (UNIMARC → `ca_objects`) charge en base, specifique au modele de chaque instance

Voir [SimpleZ3950/README.md](SimpleZ3950/README.md) pour la configuration des serveurs et les details d'utilisation.

### SimplePubmed

<img src="documentation/icons/simplepubmed.png" alt="SimplePubmed" height="150" align="right" />

Permet l'import de notices bibliographiques depuis **PubMed** (NCBI E-utilities) par PMID ou recherche textuelle. Une entree "PubMed" est ajoutee au menu Import du back-office.

**Hook utilise :** `hookRenderMenuBar`

**Configuration (`conf/SimplePubmed.conf`) :**
- `ncbi_api_key` — cle API NCBI optionnelle (augmente le quota de requetes)
- `max_results` — nombre maximum de resultats par recherche texte (defaut 20)
- `locale_id` — locale utilisee pour les valeurs importees
- `default_type` — type d'objet de repli si le type PubMed n'est pas mappe
- `type_mapping` — correspondance entre types de publication PubMed et types `ca_objects`

### SimpleGallica

Permet l'import de notices depuis **Gallica** (BnF) directement dans CollectiveAccess. Une entree "Gallica" est ajoutee au menu Import du back-office. Deux modes : par ARK (un ou plusieurs ARK colles, appel `OAIRecord`) ou recherche textuelle plein-texte (SRU). Pour chaque notice retenue, cree un `ca_objects` avec `idno = ARK`, mappe les champs Dublin Core vers les attributs Cognitio-Fort (`auteurs`, `date`, `description`, `editeurs`, `source`, `droits`, `motscles`, `url_entry`, `objets_lies`) et attache automatiquement l'image haute resolution (`/f1.highres`) comme representation primaire (avec dedup MD5).

**Hook utilise :** `hookRenderMenuBar`

**Permission requise :** `can_use_simple_gallica_plugin`

**Configuration (`conf/SimpleGallica.conf`) :**
- `locale_id` — locale d'import
- `max_results` — nombre max de notices via SRU (defaut 20)
- `default_type` — type `ca_objects` de repli (defaut `iconographie`)
- `type_mapping` — correspondance `dc:type` → type code `ca_objects` (test par sous-chaine)
- `download_image` — telechargement auto de l'image haute resolution
- `rate_limit_delay` — delai en secondes entre deux requetes Gallica (defaut 5, min 3)
- `user_agent` — UA envoye a Gallica (UA navigateur requis pour `/f1.highres`)

Voir [SimpleGallica/README.md](SimpleGallica/README.md).

### SimpleSudoc

<img src="documentation/icons/abes.svg" alt="ABES" height="60" align="right" />

Permet l'import de notices depuis le **SUDOC** (catalogue ABES) directement dans CollectiveAccess. Une entree "SUDOC" est ajoutee au menu Import du back-office. Deux modes : par identifiant (PPN, ISBN ou ISSN, type detecte automatiquement par format) ou recherche textuelle (titre / auteur via les index `mti` et `aut`). Pour chaque notice retenue, cree un `ca_objects` avec `idno = PPN`, type par defaut `revue`, et mappe les champs UNIMARC vers les attributs Cognitio-Fort (`auteurs`, `date`, `description`, `editeurs`, `source`, `motscles`, `url_entry`). Le parsing est fait en UNIMARC car le schema Dublin Core SRU du SUDOC ne contient pas le PPN.

**Hook utilise :** `hookRenderMenuBar`

**Permission requise :** `can_use_simple_sudoc_plugin`

**Configuration (`conf/SimpleSudoc.conf`) :**
- `locale_id` — locale d'import (defaut `7` = `fr_FR` sur Cognitio-Fort)
- `max_results` — nombre max de notices via SRU texte (defaut 20)
- `default_type` — type `ca_objects` cree (defaut `revue`)
- `rate_limit_delay` — delai en secondes entre deux requetes SUDOC (defaut 2)
- `user_agent` — UA envoye a l'ABES

Voir [SimpleSudoc/README.md](SimpleSudoc/README.md).

> Le SUDOC est un reseau documentaire pilote par l'**ABES** (Agence bibliographique de l'enseignement superieur). Le logo ABES utilise ici est disponible sur la page officielle [Logo et ressources graphiques](https://abes.fr/l-abes/abes-pratique/logo-ressources-graphiques/).

### providencePluginUserMenu

<img src="documentation/icons/usermenu.png" alt="providencePluginUserMenu" height="150" align="right" />

Remplace la barre noire en bas de page (liens *Preferences* et *Logout*) par un **menu utilisateur en haut a droite** (icone 👤). Cache le footer d'origine pour gagner de la place a l'ecran, particulierement utile sur tablette ou petit ecran.

**Hook utilise :** `hookRenderMenuBar`

**Configuration (`conf/providencePluginUserMenu.conf`) :**
- `footer` — si `1`, affiche une ligne de footer minimaliste personnalisee en bas de page

### frenchRevolutionaryCalendar

<img src="documentation/icons/frenchrevolutionary.png" alt="frenchRevolutionaryCalendar" height="150" align="right" />

Permet la saisie de dates dans le **calendrier revolutionnaire francais** (vendemiaire, brumaire, frimaire... an X). Intercepte les expressions de date avant le `TimeExpressionParser` et les convertit en dates gregoriennes.

**Hook utilise :** `hookTimeExpressionParserPreprocessAfter`

**Activation :** copier `conf/frenchRevolutionaryCalendar.conf` vers `conf/local/frenchRevolutionaryCalendar.conf` et passer `enabled = 1`. Voir [frenchRevolutionaryCalendar/README.md](frenchRevolutionaryCalendar/README.md).

### searchIdno

<img src="documentation/icons/searchidno.png" alt="searchIdno" height="150" align="right" />

Recherche rapide d'un objet `ca_objects` par son **identifiant** (idno, cote, n° d'inventaire...) avec wildcard `*`. Si un seul resultat, redirige directement vers la fiche ; sinon affiche un tableau DataTables filtrable.

**Activation :** copier `conf/searchIdno.conf` vers `conf/local/searchIdno.conf` et passer `enabled = 1`. L'attribut recherche est configurable via `idno_element_code`.

**Note :** le plugin n'ajoute pas de champ de recherche au header — c'est au theme cote client de pointer un formulaire vers `/index.php/searchIdno/Do/Search`. Voir [searchIdno/README.md](searchIdno/README.md).

### searchParent

<img src="documentation/icons/searchparent.png" alt="searchParent" height="150" align="right" />

Ajoute un lien **"Rechercher les parents d'objets"** dans l'inspecteur d'un lot (`ca_sets`). Lance une recherche federee sur les `parent_id` de tous les objets du lot.

**Hooks utilises :** `hookAppendToEditorInspector`

**Activation :** copier `conf/searchParent.conf` vers `conf/local/searchParent.conf` et passer `enabled = 1`. Voir [searchParent/README.md](searchParent/README.md).

### simpleList

Editeur **leger de listes et vocabulaires** (`ca_lists`). Affiche un ensemble configurable de listes sous forme d'arbre hierarchique, permet le chargement progressif des items et l'**ajout en masse** de nouvelles valeurs depuis une zone de texte (un `idno` par ligne). Pensee comme une alternative plus rapide a l'editeur de listes natif quand un curateur doit parcourir ou enrichir plusieurs vocabulaires controles cote a cote.

**Hook utilise :** `hookRenderMenuBar`

**Controller :** `EditorController`

**Permission requise :** `can_use_simplelist_plugin` (a accorder via *Manage → Access control → Roles*).

**Configuration (`conf/simpleList.conf`) :**
- `pages` — liste des *pages* a exposer ; chaque page regroupe plusieurs listes sous une seule entree de menu
- chaque bloc de page definit `label` (intitule du menu / titre de page), `menu` (cle du menu de premier niveau : `find`, `edit`, `manage`, `import`...) et `lists` (tableau de `ca_lists.list_code` a afficher)

Voir [simpleList/README.md](simpleList/README.md).

### exemplaires

<img src="documentation/icons/exemplaires.png" alt="exemplaires" height="150" align="right" />

Plugin metier **bibliotheque/serie**. Ajoute dans l'inspecteur de fiche d'objet, selon le type :
- pour les types listes dans `TypesNoticesAvecExemplaires` (ex. `book`, `bibliotheques`) : un bouton **"Ajouter un exemplaire"** qui cree un objet enfant de type `750` (l'exemplaire physique). Si la fiche n'a pas encore de representation, tente de recuperer une couverture depuis **OpenLibrary** et **Google Books** a partir de l'ISBN (`idno`).
- pour les types listes dans `TypesNoticesAvecEtatDesCollections` (ex. `serial`) : un bouton **"Ajouter un etat des collections"** qui cree un objet enfant de type `34764`.
- sur les fiches *Etat des collections* : un bouton ouvrant un panneau CKEditor 4 pour editer le contenu (route `/exemplaires/EtatDesCollections/Index/id/<id>`).

**Hooks utilises :** `hookAppendToEditorInspector`

**Controllers :** `EtatDesCollectionsController`, `ExternalController`

**Configuration (`conf/exemplaires.conf` ou `conf/local/exemplaires.conf`) :**
- `TypesNoticesAvecExemplaires` — liste des type codes `ca_objects` qui doivent recevoir le bouton "Ajouter un exemplaire"
- `TypesNoticesAvecEtatDesCollections` — liste des type codes qui recoivent le bouton "Ajouter un etat des collections"

**Note :** les `type_id` cibles (750, 34764) sont en dur dans le code et **specifiques au modele** de l'instance qui a vu naitre le plugin. A ajuster avant deploiement sur une autre installation.

### etatsMTE

<img src="documentation/icons/etatsmte.png" alt="etatsMTE" height="150" align="right" />

Plugin developpe pour le *Ministere de la Transition Ecologique* (MTE). Permet la generation de constats d'etat et de catalogues directement depuis l'interface Providence.

**Hooks utilises :** `hookAppendToEditorInspector`, `hookRenderMenuBar`

**Fonctionnalites :**
- Ajoute un bouton "Constat d'etat" dans la barre laterale de l'editeur d'objet, generant un rapport au format DOCX a partir d'un modele
- Ajoute un menu "Catalogue" dans la barre de navigation principale avec deux entrees :
  - *Standard* — generation de catalogue standard
  - *Specifique* — generation de catalogue personnalise
- Inclut la recherche, le filtrage et l'export PDF/DOCX pour les vues de catalogue

## Licence

GNU General Public License v3.0 — voir [LICENSE](LICENSE) pour les details.
