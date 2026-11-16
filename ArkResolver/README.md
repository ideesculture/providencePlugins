# ArkResolver

Plugin **Pawtucket** (et non Providence) qui résout les identifiants ARK
`ark:/<NAAN>/<nom>` vers la page de détail publique de la fiche qui porte cet ARK.

Exemple : `https://example.org/ark:/12345/x6-np1-wh8k` redirige vers
`https://example.org/Detail/objects/<id>` si une seule fiche publique a cet ARK.

## Principe

- L'ARK est cherché **exactement** dans un élément de métadonnée configuré, par
  une requête SQL paramétrée. Le moteur de recherche n'est pas utilisé.
- Les **tirets sont ignorés** des deux côtés de la comparaison : les résolveurs
  comme N2T les retirent (`x6-np1-wh8k` arrive sous la forme `x6np1wh8k`).
- La valeur stockée peut être l'ARK complet (`ark:/12345/x6-np1-wh8k`, forme
  conseillée), `ark:12345/x6-np1-wh8k`, ou le nom seul.
- Les adresses de redirection sont construites par `caDetailUrl()` : elles
  respectent `__CA_URL_ROOT__` (installation à la racine ou dans un sous-dossier).
- L'accès suit les règles du portail (`caGetUserAccessValues()`, donc
  `public_access_settings` de `app.conf`).

## Réponses

| Situation | Réponse |
|---|---|
| Une seule fiche non supprimée et publique | **302** vers sa page de détail |
| Aucune correspondance, nom vide ou invalide | **404** |
| Fiches trouvées, mais aucune publique | **404** (comme une fiche introuvable) |
| Uniquement des fiches supprimées | **410** |
| Plusieurs fiches publiques | **409**, sans redirection ; les identifiants sont écrits dans le journal PHP |
| Plugin désactivé ou `conf/ArkResolver.conf` absent | **404** |
| Configuration activée mais incomplète | **500** et message « configuration invalide » dans le journal PHP |

Une fiche publique accompagnée de fiches supprimées ou non publiques est
redirigée normalement ; un avertissement est écrit dans le journal PHP.

Le nom est validé avant toute requête : caractères ARK uniquement
(`0-9 A-Z a-z = ~ * + @ _ $ . / -`), 128 caractères au plus.

## Installation

Le dépôt est cloné à côté de Pawtucket, et le plugin y est relié par un lien
symbolique :

```sh
cd /chemin/vers/collectiveaccess
git clone https://github.com/ideesculture/providencePlugins.git
ln -s ../../../providencePlugins/ArkResolver pawtucket2/app/plugins/ArkResolver
cp providencePlugins/ArkResolver/conf/ArkResolver.conf-dist \
   providencePlugins/ArkResolver/conf/ArkResolver.conf
```

Puis renseigner `conf/ArkResolver.conf` (voir ci-dessous), ajouter la règle de
réécriture, et vérifier que le serveur web peut lire les fichiers du plugin.

`conf/ArkResolver.conf` est ignoré par git : chaque installation garde ses
propres valeurs. Comme pour toute configuration CollectiveAccess, un fichier
`ArkResolver.conf` placé dans `app/conf/local/` ou dans le dossier `conf/` du
thème est aussi pris en compte, et le surcharge.

## Configuration

`conf/ArkResolver.conf` :

```
enabled = 1
naan = 12345
element_code = ark_id
table = ca_objects
```

| Clé | Rôle |
|---|---|
| `enabled` | `1` pour activer. Avec `0` ou sans fichier, toutes les demandes reçoivent 404. |
| `naan` | NAAN de l'institution (chiffres uniquement). |
| `element_code` | Code de l'élément qui contient l'ARK. |
| `table` | Table des fiches à résoudre ; elle doit avoir les champs `access` et `deleted`. |

## Règle de réécriture (.htaccess de pawtucket2)

Pawtucket détermine la page à afficher d'après l'adresse demandée par le
navigateur (`REQUEST_URI`) : une réécriture **interne** vers le plugin serait
ignorée. La règle fait donc une **redirection externe** vers le point d'entrée du
plugin, qui redirige à son tour vers la fiche (deux sauts).

À placer après `RewriteBase`, et **avant** la réécriture générale vers
`index.php` (remplacer `12345` par votre NAAN) :

```apache
# Résolution des ARK -> plugin ArkResolver. Accepte ark:/12345/<nom>,
# ark:12345/<nom>, et l'adresse sans nom (404 du résolveur).
RewriteRule ^ark:/?12345(?:/(.*))?$ ArkResolver/Resolve/Index?ark=$1 [B,NE,NC,R=302,L]
```

- La cible est relative : elle suit `RewriteBase`, sans chemin écrit en dur.
- `B` encode le nom une seule fois ; `NE` empêche Apache de l'encoder une
  seconde fois dans l'en-tête `Location`.
- Le point d'entrée direct est `<racine>/ArkResolver/Resolve/Index?ark=<nom>`.

## Affichage de l'ARK sur la fiche (vue de détail)

Ligne à ajouter dans la vue de détail du thème (par exemple
`themes/<thème>/views/Details/ca_objects_default_html.php`, et ses variantes
par langue `.en_US`, `.nl_NL`…), à l'endroit voulu. Remplacer `ark_id` par le
code de l'élément et `12345` par votre NAAN :

```php
<?php print $t_object->getWithTemplate('<ifdef code="ca_objects.ark_id"><if rule="^ca_objects.ark_id =~ /^ark:\/12345\/[0-9A-Za-z=~*+@_$.\/-]+$/"><h5 class="unit-title-h5">ARK</h5><a href="'.__CA_URL_ROOT__.'/^ca_objects.ark_id">^ca_objects.ark_id</a><br/></if></ifdef>'); ?>
```

- Rien n'est affiché si l'élément est vide (`<ifdef>`).
- La condition `<if rule>` vérifie la valeur **entière** : un ARK mal saisi
  (espace, guillemet, balise, autre NAAN…) n'est pas affiché, ce qui évite un
  lien cassé ou du HTML inattendu.
- Le lien pointe vers l'adresse ARK du site, construite avec `__CA_URL_ROOT__`.
  Il passe par la règle de réécriture et le résolveur.
- La ligne utilise du PHP plutôt qu'une balise `{{{ … }}}` : Pawtucket lit ces
  balises dans le fichier source, avant l'exécution du PHP, et le gabarit n'a pas
  accès à `__CA_URL_ROOT__`. Un lien relatif `ark:/…` serait compris par le
  navigateur comme un protocole.

## Tests

Tests unitaires de la décision (302 / 404 / 410 / 409) et de la validation du
nom, sans base de données ni serveur web :

```sh
php tests/decide_test.php
```

## Compatibilité

Développé et testé avec Pawtucket 1.7.8 et PHP 7.4.
