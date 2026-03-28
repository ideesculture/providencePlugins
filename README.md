# providencePlugins

Collection de plugins open-source pour [CollectiveAccess Providence](https://collectiveaccess.org/) (back-office).

Ces plugins sont principalement developpes en francais mais nous cherchons a les internationaliser progressivement. Les contributions et traductions sont les bienvenues.

## Installation

Copier le dossier du plugin dans le repertoire `app/plugins/` de Providence :

```bash
cp -r SaveAndStay /chemin/vers/providence/app/plugins/
```

Aucune configuration supplementaire n'est necessaire — les plugins sont auto-detectes par le `ApplicationPluginManager` de Providence.

## Plugins

### SaveAndStay

Rend la barre d'outils de l'editeur (Enregistrer / Annuler / Supprimer) **fixe** en haut de l'ecran, pour qu'elle reste visible lors du defilement dans les fiches longues. **Restaure egalement la position de defilement** apres l'enregistrement, pour ne pas perdre sa place dans le formulaire.

**Hooks utilises :** `hookAppendToEditorInspector`

**Fonctionnement :**
- Injecte du CSS pour rendre la `.control-box` sticky (`position: sticky`)
- Sauvegarde la position de defilement dans un cookie ephemere (par type d'editeur et ID de fiche) au clic sur Enregistrer
- Restaure la position au rechargement de la page, puis supprime le cookie

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
