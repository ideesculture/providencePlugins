# frenchRevolutionaryCalendar

Plugin Providence qui permet la **saisie de dates dans le calendrier revolutionnaire francais** (vendemiaire, brumaire, frimaire, nivose, pluviose, ventose, germinal, floreal, prairial, messidor, thermidor, fructidor, sansculottide).

Le plugin intercepte les expressions de date avant le parsing par le `TimeExpressionParser` de CollectiveAccess et les convertit en dates gregoriennes.

Utile pour les fonds historiques (fin XVIIIe / debut XIXe) ou les notices bibliographiques contiennent souvent des dates de ce type.

## Exemples d'expressions reconnues

| Saisie | Conversion gregorienne approximative |
|---|---|
| `15 vendemiaire an V` | `7/10/1796` |
| `1 brumaire an III` | `22/10/1794` |
| `germinal V` | `21/3/1797` |
| `[an V]` (avec `removeSquareBrackets = 1`) | `1796` |
| `15 vendemiaire an V - 1 brumaire an III` | plage `7/10/1796 - 22/10/1794` |

L'annee peut etre saisie en chiffres romains (`an V`) ou en decimal (`an 5`). Le mot-cle `an` est optionnel.

## Installation

Copier le dossier dans `app/plugins/` de Providence :

```bash
cp -r frenchRevolutionaryCalendar /chemin/vers/providence/app/plugins/
```

Le plugin utilise les fonctions PHP natives `frenchtojd()` et `jdtogregorian()` (extension **Calendar** de PHP, generalement activee par defaut).

### Configuration requise dans `app/conf/datetime.conf`

Pour que la date saisie reste affichee **dans le calendrier revolutionnaire** apres enregistrement (et ne soit pas re-affichee sous sa forme gregorienne convertie), il faut definir dans `app/conf/datetime.conf` :

```
dateFormat = original
```

Cette option indique a CollectiveAccess de conserver la chaine saisie par l'utilisateur telle quelle pour l'affichage, tout en utilisant la valeur gregorienne convertie par le plugin pour l'indexation et le tri.

## Configuration

Le plugin est **desactive par defaut**. Pour l'activer sur une instance, copier `conf/frenchRevolutionaryCalendar.conf` vers `conf/local/frenchRevolutionaryCalendar.conf` (non versionne) et passer `enabled = 1`.

### Parametres

| Cle | Defaut | Description |
|---|---|---|
| `enabled` | `0` | `1` pour activer, `0` pour desactiver |
| `removeSquareBrackets` | `1` | `1` pour retirer les `[ ]` autour des dates incertaines (notices bibliographiques) |
| `removeKeywords` | `["DL","IMPR","COP",...]` | Liste de prefixes bibliographiques a supprimer avant parsing (DL = depot legal, IMPR = imprime, COP = copyright). La liste est appliquee du plus long au plus court, pour que `COP.` soit retire avant `COP` et qu'aucun point orphelin ne subsiste |
| `normalizeUndated` | `0` | `1` pour normaliser les marqueurs d'absence de date (voir ci-dessous) |
| `undatedMarkers` | `["?","??","???","????","s.d.","sd","sans date","n.d.","nd","nd."]` | Mentions signifiant « objet non date » |
| `undatedToken` | `undated` | Token substitue au marqueur reconnu |

### Objets non dates (`normalizeUndated`)

Le `TimeExpressionParser` de CollectiveAccess ne sait reconnaitre comme « non date »
que les mentions declarees dans `undatedDate`, au fichier `.lang` de la locale. En
francais cette liste se reduit a `[undated, unknown]` : les notations d'usage
(`?`, `s.d.`, `sans date`, `n.d.`) n'y figurent pas. Pire, `?` est declare dans
`presentDate` : une photographie non datee se retrouve enregistree comme « presente »,
avec une plage ouverte jusqu'a aujourd'hui.

Avec `normalizeUndated = 1`, le plugin remplace ces marqueurs par le token
`undatedToken` (`undated` par defaut), que le `.lang` sait deja lire. Le parsing
reussit, aucune borne historique n'est produite, rien n'est indexe comme date :
c'est la definition meme d'un objet non date.

```
normalizeUndated = 1
```

Le reglage est **desactive par defaut** : une instance qui met a jour son clone du
depot ne voit aucun changement de comportement tant qu'elle ne l'active pas
explicitement dans son `conf/local/`.

Deux precautions :

- La comparaison porte sur **l'expression entiere**, jamais sur une sous-chaine.
  `1950?` reste donc une date circa, et aucune cote contenant `sd` ou `nd` n'est
  affectee.
- Ne pas lister les memes mentions dans `removeKeywords` : elles y seraient
  supprimees en premier, et l'expression serait vide avant d'avoir pu etre reconnue.

A noter : le token substitue doit figurer dans `undatedDate` du `.lang` de la locale
utilisee, sans quoi l'expression sera rejetee comme date invalide.

### Affichage

Une valeur non datee se reaffiche **vide** tant que `app/conf/datetime.conf` est en
`dateFormat = text` (comportement par defaut de CollectiveAccess). Pour que la mention
saisie par l'utilisateur (`s.d.`) reste visible apres enregistrement, il faut, comme
pour les dates revolutionnaires, `dateFormat = original`.

## Fonctionnement technique

- Hook utilise : `hookTimeExpressionParserPreprocessAfter`
- Detection : regex sur les noms de mois revolutionnaires
- Conversion : `frenchtojd()` (jour julien) → `jdtogregorian()`
- Gere les locales avec ordre `JJ/MM/AAAA` ou `MM/JJ/AAAA` via le fichier `.lang` du `TimeExpressionParser`
- L'expression originale est remplacee par la date gregorienne, puis le `TimeExpressionParser` standard prend le relais
- Si `normalizeUndated` est actif, la reconnaissance des marqueurs d'absence de date se fait apres le nettoyage (crochets, prefixes) et court-circuite la conversion revolutionnaire
- Le jour est facultatif dans une date revolutionnaire (`germinal an V`) : a defaut, le 1er du mois est retenu

## Permissions

Aucune action de role specifique. Le plugin agit en preprocess sur toutes les saisies de date.
