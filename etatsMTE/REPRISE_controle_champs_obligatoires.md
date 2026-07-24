# Reprise — Contrôle de présence des champs obligatoires (éditeur objet MTE)

**Date :** 2026-06-18
**Statut :** Code écrit, lint OK. **En attente d'1 test de sauvegarde live** pour valider l'extraction des valeurs, puis retrait du diagnostic temporaire.

---

## Objectif

Dans l'éditeur d'objet (ex. `https://mobiliersclasses.ideesculture.fr/gestion/index.php/editor/objects/ObjectEditor/Edit/object_id/9311`),
empêcher l'enregistrement tant que les champs obligatoires ne sont pas remplis, et avertir (non bloquant) si pas de photo.

Champs demandés par le client :
- nom de déposant
- numéro d'inventaire du déposant
- date de dépôt
- photo d'identification (représentation attachée)
- localisation de dépôt (sous-champs obligatoires : Site, adresse, bâtiment, étage)
- catégorie
- type
- conteneur inventaire (sous-champs obligatoires : date inventaire, constat présence objet, site, adresse, bâtiment, étage)

---

## Décisions actées

- **Emplacement du code :** override de `_beforeSave()` (+ `_afterSave()`) dans le **contrôleur**
  [`app/controllers/editor/objects/ObjectEditorController.php`](../../providence/app/controllers/editor/objects/ObjectEditorController.php) — *pas* dans le plugin (un hook de plugin ne peut PAS annuler un save, cf. ci-dessous).
- **Portée :** seulement les 9 types déposants mappés : `3454, 3581, 3582, 3651, 3650, 3649, 3652, 3647, 3648`.
- **« catégorie » = `domaine_logement`** (élément id 663, « Domaine ») et **« type » = `denomination`** (élément id 585, liste) — *pas* le `type_id` CA. Cf. mémoire `project_categorie_objet_mobilier`.
- **Photo = avertissement non bloquant** (notification en haut d'écran : « Cet objet n'a pas de photo associée. »), pas un blocage.
- **Déposant = non bloqué** : ajouté automatiquement par `etatsMTEPlugin::hookSaveItem()` pour ces types (le bloquer empêcherait l'auto-ajout). Le « type » client = attribut `denomination` (585), lui est validé ; le `type_id` CA n'est pas contrôlé (garanti par la portée).

> Erreurs IDE « Undefined method getTypeID / extractValuesFromRequest / getRepresentations » = **faux positifs** de l'analyseur (pas de type-hint sur `$pt_subject`). `php -l` passe. Méthodes réelles : `BundlableLabelableBaseModelWithAttributes` + `RepresentableBaseModel`.

---

## Faits techniques établis (CollectiveAccess)

1. **Les hooks de plugin ne peuvent pas annuler un save.**
   `hookBeforeSaveItem` / `hookSaveItem` sont bien appelés (cf. `BaseEditorController::Save()` ~ligne 324 / 437) mais **leur valeur de retour est ignorée**. Le plugin etatsMTE utilise déjà `hookSaveItem` (auto-déposant + champs calculés).

2. **Seul point d'abandon propre = `_beforeSave($t_subject, $is_insert)`** dans le contrôleur (doc explicite : `return false` => save annulé). Voir `BaseEditorController.php:1867`.

3. **Piège de timing :** `_beforeSave` s'exécute **avant** `saveBundlesForScreen()` (BaseEditorController.php:337-338). À ce stade l'instance N'A PAS encore les valeurs soumises. => on lit la **requête** via
   `BundlableLabelableBaseModelWithAttributes::extractValuesFromRequest($screen, $request, $opts)`
   (BundlableLabelableBaseModelWithAttributes.php:3728).
   Structure retournée : `attributes[ROOT_ELEMENT_ID][index][SUB_ELEMENT_ID] = valeur` (sous-champs keyés par **element_id**, confirmé dans `themes/default/views/bundles/ca_attributes.php`). Pour un attribut simple : `attributes[eid][index][eid]`.

4. **L'éditeur enregistre un seul écran (onglet) à la fois.** Les champs des autres écrans ne sont pas dans la requête. **MAIS** : les 6 bundles concernés sont **tous sur le même écran** (`screen_id = 195`, idno `screen_42_0`, unique UI `ui_id = 42`, `editor_type = 57`). On ne valide donc un champ que si son bundle est présent sur l'écran courant (via `ca_editor_uis::getScreenBundlePlacements($screen, $type_id)`).

5. **Les étoiles rouges sont décoratives** : codées en dur dans le **libellé** de l'élément (`<span style="color:#bb0000">*</span>` dans `ca_metadata_element_labels.name`). Aucune validation réelle aujourd'hui. Le metadata dictionary (mandatory/règles) est **mou** (affichage + éventuel prompt JS), ne bloque pas le save serveur.

6. **Pour bloquer proprement avec un message clair** : poster des action-errors avec un **numéro 3600-3699** =>
   le contrôleur affiche le bloc « erreurs empêchant TOUTES les informations d'être enregistrées » et **supprime** le faux message « Saved changes » (bug ligne 392 quand `_beforeSave` renvoie false sur un update).
   Pattern utilisé : `$pt_subject->postError(3620, $msg, $ctx, $bundle)` puis `$this->request->addActionErrors($pt_subject->errors())` puis `clearErrors()`. (`errors()` renvoie les objets ; `getErrors()` renvoie des chaînes.)

---

## Mapping des codes (vérifié en base)

| Exigence | bundle_name | element_id | datatype |
|---|---|---|---|
| N° inventaire déposant | `ca_objects.numinv_deposant` | 692 | 1 (texte) |
| Date de dépôt | `ca_objects.date_depot` | 616 | 2 (date) |
| Catégorie (Domaine) | `ca_objects.domaine_logement` | 663 | 3 (liste, list_id 157) |
| Type (dénomination) | `ca_objects.denomination` | 585 | 3 (liste, list_id 168) |
| **Localisation** (conteneur) | `ca_objects.site` | 707 | 0 |
| → Site | | 709 | 3 |
| → Adresse | | 799 | 3 |
| → Bâtiment | | 800 | 3 |
| → Étage | | 712 | 3 |
| **Inventaire** (conteneur) | `ca_objects.inventaire_cont` | 736 | 0 |
| → Date inventaire | | 775 | 2 |
| → Constat présence objet | | 776 | 3 |
| → Site | | 777 | 3 |
| → Adresse | | 795 | 3 |
| → Bâtiment | | 796 | 3 |
| → Étage | | 778 | 3 |
| Photo | `ca_object_representations` | (relation) | — |
| Déposant | relation `ca_entities` | type `depositaire` = **type_id 172** | — |

Présence : valeur considérée vide si `trim($v) === '' || $v === '0'` (le `'0'` couvre les listes non sélectionnées).
Conteneur OK = il existe au moins une occurrence où **tous** les sous-champs requis sont remplis.

Accès DB : `php -r 'require "setup.php"; echo __CA_DB_PASSWORD__;'` ; user/db = `mobiliersclasses`.

---

## État du code

Fichier modifié : [`app/controllers/editor/objects/ObjectEditorController.php`](../../providence/app/controllers/editor/objects/ObjectEditorController.php)
- ajout `private function mteScopeTypeIDs()`
- ajout `protected function _beforeSave(...)` (blocage dur)
- ajout `protected function _afterSave(...)` (avertissement photo)
- `php -l` : OK.

### ⚠️ Diagnostic temporaire À RETIRER
Dans `_beforeSave`, juste après le calcul de `$va_attrs`, un bloc écrit dans `/tmp/mte_validation.log` :
```php
// [DIAG TEMPORAIRE - à retirer] ...
@file_put_contents('/tmp/mte_validation.log', ...);
```
**À supprimer une fois l'extraction validée.**

---

## PROCHAINE ÉTAPE (reprise)

1. Demander au client de faire **un enregistrement de la fiche 9311** (écran principal, champs remplis).
2. Lire `/tmp/mte_validation.log` :
   - **Si** `attr_element_ids` contient bien `616, 692, 663, 707, 736` avec valeurs => extraction OK.
     → **Retirer le bloc DIAG**, terminé.
   - **Si vide / incorrect** (préfixe de formulaire différent sur l'éditeur complet) => l'approche `extractValuesFromRequest` ne marche pas telle quelle.
     → **Stratégie de secours** : validation hybride « soumis OU stocké » :
       - lire la valeur soumise ET `$pt_subject->get('ca_objects.<code>')` (valeur en base) ;
       - ne bloquer que si les deux sont vides (évite tout blocage abusif, au prix de ne pas attraper le cas « l'utilisateur vide un champ existant »).
     → Alternative : inspecter le vrai nommage des champs POST de l'éditeur complet (capture des clés `$_REQUEST`) et adapter le parsing.
3. Tester un cas **bloquant** (vider un champ requis) => doit refuser le save avec le bloc d'erreurs.
4. Vérifier l'avertissement photo (objet sans représentation).

## Risque principal
Si l'extraction renvoie vide sur le formulaire complet et que le blocage reste actif, **toutes les fiches MTE de l'écran 195 seraient bloquées**. D'où le test obligatoire avant mise en service. En cas de doute, neutraliser temporairement en commentant le `return false;` du `_beforeSave`.
