# bookCreator

Plugin CollectiveAccess Providence de fabrication de livres imprimés : catalogues raisonnés, livrets d'exposition, catalogues de collection. Le contenu rédactionnel est en Markdown, les œuvres viennent des ensembles CollectiveAccess, et la sortie est un PDF prêt pour l'impression.

## Ce que fait le plugin

Un livre est une suite de sections ordonnées. Chaque section porte un gabarit de mise en page — page de titre, chapitre, grille de six œuvres par page, planche hors-texte — et le rendu produit un PDF section par section, assemblé ensuite avec les couvertures. Le foliotage, les titres courants et les fonds perdus sont du CSS Paged Media standard, pas un post-traitement.

L'aperçu affiche dans le navigateur le document exact qui part au moteur de rendu, paginé par Paged.js. C'est ce qui rend l'aperçu opposable au PDF livré.

## Prérequis

| Composant | Rôle | Obligatoire |
|---|---|---|
| PHP 8.4 | — | oui |
| CollectiveAccess Providence | — | oui |
| MySQL 5.7+ / MariaDB 10.2+ | InnoDB, utf8mb4 | oui |
| **WeasyPrint 62+** | rendu HTML vers PDF | oui, sauf si Gotenberg |
| **qpdf 10+** | assemblage et comptage de pages | oui |
| Gotenberg 8 | rendu par Chromium, en service HTTP | non, alternative à WeasyPrint |

```bash
# Debian/Ubuntu
apt install weasyprint qpdf
# ou, pour une version récente de WeasyPrint
pip install weasyprint
```

Le plugin embarque tout le reste : dépendances PHP dans `vendor/`, Bootstrap, EasyMDE et Paged.js dans `assets/`, fontes dans les thèmes. **Aucune ressource n'est chargée depuis un CDN au moment du rendu** — une installation sans accès sortant fonctionne à l'identique.

## Installation

1. Déposer le répertoire `bookCreator/` dans `app/plugins/` de Providence. Il n'y a pas d'étape de construction : `vendor/` est versionné, car un `composer install` oublié se manifesterait par une erreur fatale au moment de générer un livre.
2. Ouvrir le plugin depuis le menu **Livre**. S'il manque une table, une colonne ou un index, l'écran d'installation liste ce qui va être fait et l'applique. L'opération est **additive** : elle crée et ajoute, ne renomme ni ne supprime rien.
3. Fusionner le bloc `strings` de `conf/translations.conf.dist` dans `app/conf/local/translations.conf`, puis vider le cache applicatif. **Sans cette étape l'interface reste en anglais** : un plugin ne peut pas livrer son propre catalogue de traduction.
4. Vérifier `conf/bookCreator.conf` — au minimum `renderer`, et les chemins des binaires s'ils ne sont pas dans le `PATH` du serveur web.

## Configuration

Tout est dans `conf/bookCreator.conf`, commenté sur place. Les réglages qui demandent une décision à l'installation :

| Clé | Rôle |
|---|---|
| `renderer` | `weasyprint` (défaut) ou `gotenberg` |
| `weasyprint_path`, `qpdf_path` | chemins absolus si les binaires ne sont pas dans le `PATH` du serveur web — cas courant quand WeasyPrint vient d'un virtualenv |
| `default_access` | `1` ouvre le plugin à tout utilisateur connecté qu'aucun rôle n'autorise explicitement |
| `markdown_parser` | `parsedown` (référence de la recette) ou `commonmark` |
| `media_version` | version dérivée utilisée pour les planches, jamais l'original |
| `covers_dir` | répertoire des couvertures ; vide signifie `assets/covers` |
| `job_work_dir`, `job_output_dir` | vides, ils pointent sur `tmp/` ; sur Kubernetes, un volume partagé entre le pod worker et le pod Providence |

**Les couvertures sont désignées par un nom de fichier, jamais par un chemin.** Le fichier doit être déposé dans `covers_dir`, qui porte un `.htaccess` interdisant l'accès web direct : une couverture est reliée dans le livre, elle n'a pas à être servie telle quelle.

## Le worker de génération

La génération ne se fait pas dans la requête HTTP : le bouton met un job en file, un worker CLI le traite. Voir `bin/README.md` pour l'installation en cron ou en Deployment Kubernetes.

Sans worker en fonctionnement, les jobs restent en attente et rien n'est produit.

## Thèmes et gabarits

Un thème est un répertoire auto-descriptif sous `themes/`. Son `theme.conf` déclare les formats de page, les couples typographiques et les tokens de design ; ses feuilles de style ne lisent que des custom properties. Choisir un format ou une typographie est donc de la configuration, jamais une recompilation.

Un gabarit est un répertoire sous `themes/<thème>/templates/`, avec un `manifest.conf` qui déclare son type, les formats pour lesquels il est calibré, et ses display templates de fusion. **Un gabarit est calibré pour un format, il ne s'y adapte pas** : six œuvres par page en A4 paysage et quatre en 21×21 sont deux gabarits distincts, parce que les grilles sont ajustées sur la hauteur utile de la page.

La correspondance entre les champs d'un profil CollectiveAccess et ce qui s'imprime vit dans les manifestes, sous forme de display templates, surchargeables par installation depuis `bookCreator.conf`. Adapter le plugin au profil d'un autre client ne demande pas de toucher au code.

## Droits

L'action `can_use_book_editor_plugin` s'attribue par les rôles CollectiveAccess. Quand aucun rôle ne la porte, `default_access` dans `bookCreator.conf` décide ; il est livré à `1`, de sorte qu'une installation neuve soit utilisable sans configurer les rôles au préalable. Le mécanisme natif de `user_actions.conf` n'est pas utilisable ici : il est lu directement depuis la configuration de CollectiveAccess et ignore les actions déclarées par les plugins.

## Licence

GPL v3, comme CollectiveAccess. Les dépendances embarquées conservent la leur : MIT pour Paged.js et EasyMDE, BSD pour league/commonmark, Apache 2.0 et OFL pour les fontes des thèmes.
