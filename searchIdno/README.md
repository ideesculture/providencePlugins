# searchIdno

Plugin Providence qui permet une recherche rapide d'un objet par son **identifiant** (idno, cote, n° d'inventaire...) avec **wildcard `*`**, depuis un champ de saisie ajouté au header du back-office.

Comportement :
- 1 resultat trouve → redirection directe vers la fiche objet
- N resultats → affichage d'un tableau DataTables (tri, filtrage, pagination)
- 0 resultat ou pas de saisie → message vide

## Installation

Copier le dossier dans `app/plugins/` de Providence :

```bash
cp -r searchIdno /chemin/vers/providence/app/plugins/
```

### Cote client : ajout du champ de recherche dans le header

Le plugin n'ajoute **pas** automatiquement le champ de recherche au header. Il faut, dans le theme cote client, ajouter un formulaire qui pointe vers `/index.php/searchIdno/Do/Search` (par exemple dans `themes/<theme>/views/pageFormat/menuBar.php`) :

```php
<form method="get" action="<?php print __CA_URL_ROOT__; ?>/index.php/searchIdno/Do/Search">
	<input type="text" name="search2" placeholder="<?php print _t('Identifier...'); ?>" />
	<button type="submit"><?php print _t('Search'); ?></button>
</form>
```

## Configuration

Le plugin est **désactivé par défaut**. Pour l'activer sur une instance, copier `conf/searchIdno.conf` vers `conf/local/searchIdno.conf` (non versionné) et adapter les valeurs.

### Parametres

| Cle | Defaut | Description |
|---|---|---|
| `enabled` | `0` | `1` pour activer, `0` pour desactiver |
| `idno_element_code` | `idno` | `element_code` de l'attribut metadata utilise comme identifiant. La conversion en `element_id` est faite a l'execution via `ca_metadata_elements::getElementID()`. |
| `result_identifier_template` | `^ca_objects.idno` | Template d'affichage de la colonne identifiant dans le tableau de resultats |
| `result_identifier_label` | `Identifier` | Libelle de l'en-tete de la colonne identifiant |
| `datatables_lang_url` | (URL en\_us) | URL du fichier i18n DataTables (vide pour anglais par defaut) |

Exemple `conf/local/searchIdno.conf` pour une instance utilisant un attribut `cote` plutot que `idno` :

```
enabled = 1
idno_element_code = cote
result_identifier_template = ^ca_objects.cote
result_identifier_label = Cote
datatables_lang_url = //cdn.datatables.net/plug-ins/1.11.4/i18n/fr_fr.json
```

## Fonctionnement

- Plugin appele par l'URL `/index.php/searchIdno/Do/Search?search2=...`
- Le wildcard `*` est converti en `%` (LIKE SQL)
- La requete porte sur `ca_attribute_values.value_longtext1` filtre par `element_id` (resolu depuis `idno_element_code`) et `table_num = ca_objects`
- Si 1 seul resultat → redirige via `caEditorUrl()` vers la fiche
- Sinon → affiche le tableau DataTables

## Permissions

Aucune action de role specifique.

## Notes

- Plugin developpe a l'origine pour le **musee Gadagne** (Lyon), generalisee depuis. Les references "Gadagne" ont ete supprimees lors du refactoring.
- L'ancien fichier de conf `cloture.conf` (vestige d'une fonction de cloture de mouvement abandonnee) a ete renomme `searchIdno.conf`.
