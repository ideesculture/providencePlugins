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

Plugins concernes par ce mecanisme : `searchIdno`, `searchParent`, `frenchRevolutionaryCalendar`.

## Plugins

### SaveAndStay

Rend la barre d'outils de l'editeur (Enregistrer / Annuler / Supprimer) **fixe** en haut de l'ecran, pour qu'elle reste visible lors du defilement dans les fiches longues. **Restaure egalement la position de defilement** apres l'enregistrement, pour ne pas perdre sa place dans le formulaire.

**Hooks utilises :** `hookAppendToEditorInspector`

**Fonctionnement :**
- Injecte du CSS pour rendre la `.control-box` sticky (`position: sticky`)
- Sauvegarde la position de defilement dans un cookie ephemere (par type d'editeur et ID de fiche) au clic sur Enregistrer
- Restaure la position au rechargement de la page, puis supprime le cookie

### SimpleZ3950

Permet l'import de notices bibliographiques via le protocole **Z39.50** (catalogues BnF, SUDOC, KBR, etc.) directement dans CollectiveAccess. Une entree "Z39.50" est ajoutee au menu Import du back-office.

**Hooks utilises :** `hookRenderMenuBar`

**Dependances :**
- Extension PHP `yaz` (`apt install php-yaz`)
- Un mapping `z3950_import_marc` (UNIMARC → `ca_objects`) charge en base, specifique au modele de chaque instance

Voir [SimpleZ3950/README.md](SimpleZ3950/README.md) pour la configuration des serveurs et les details d'utilisation.

### frenchRevolutionaryCalendar

Permet la saisie de dates dans le **calendrier revolutionnaire francais** (vendemiaire, brumaire, frimaire... an X). Intercepte les expressions de date avant le `TimeExpressionParser` et les convertit en dates gregoriennes.

**Hook utilise :** `hookTimeExpressionParserPreprocessAfter`

**Activation :** copier `conf/frenchRevolutionaryCalendar.conf` vers `conf/local/frenchRevolutionaryCalendar.conf` et passer `enabled = 1`. Voir [frenchRevolutionaryCalendar/README.md](frenchRevolutionaryCalendar/README.md).

### searchIdno

Recherche rapide d'un objet `ca_objects` par son **identifiant** (idno, cote, n° d'inventaire...) avec wildcard `*`. Si un seul resultat, redirige directement vers la fiche ; sinon affiche un tableau DataTables filtrable.

**Activation :** copier `conf/searchIdno.conf` vers `conf/local/searchIdno.conf` et passer `enabled = 1`. L'attribut recherche est configurable via `idno_element_code`.

**Note :** le plugin n'ajoute pas de champ de recherche au header — c'est au theme cote client de pointer un formulaire vers `/index.php/searchIdno/Do/Search`. Voir [searchIdno/README.md](searchIdno/README.md).

### searchParent

Ajoute un lien **"Rechercher les parents d'objets"** dans l'inspecteur d'un lot (`ca_sets`). Lance une recherche federee sur les `parent_id` de tous les objets du lot.

**Hooks utilises :** `hookAppendToEditorInspector`

**Activation :** copier `conf/searchParent.conf` vers `conf/local/searchParent.conf` et passer `enabled = 1`. Voir [searchParent/README.md](searchParent/README.md).

### etatsMTE

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
