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
| `removeKeywords` | `["DL","IMPR","COP",...]` | Liste de prefixes bibliographiques a supprimer avant parsing (DL = depot legal, IMPR = imprime, COP = copyright) |

## Fonctionnement technique

- Hook utilise : `hookTimeExpressionParserPreprocessAfter`
- Detection : regex sur les noms de mois revolutionnaires
- Conversion : `frenchtojd()` (jour julien) → `jdtogregorian()`
- Gere les locales avec ordre `JJ/MM/AAAA` ou `MM/JJ/AAAA` via le fichier `.lang` du `TimeExpressionParser`
- L'expression originale est remplacee par la date gregorienne, puis le `TimeExpressionParser` standard prend le relais

## Permissions

Aucune action de role specifique. Le plugin agit en preprocess sur toutes les saisies de date.
