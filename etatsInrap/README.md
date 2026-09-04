# Plugin de suivi de l'inventaire des églises, CIPAR

## Qu'est-ce que le CIPAR

![CIPAR](https://github.com/ideesculture/suiviInventaireEglises/raw/master/documentation/cipar.jpg)

Le CIPAR est une ASBL fondée par les évêchés francophones de Belgique dans le but de coordonner leurs efforts en matière de protection, conservation et valorisation du patrimoine religieux.

En se préoccupant du patrimoine ancien et de diverses formes d’expressions artistiques, l’association veut valoriser tout ce qui constitue le support matériel de la culture chrétienne.

Plus d'informations : https://www.cathobel.be/eglise-en-belgique/cipar-centre-interdiocesain-patrimoine-arts-religieux/

## Le plugin de suivi de l'inventaire des églises

https://github.com/ideesculture/suiviInventaireEglises

[Idéesculture](http://www.ideesculture.com) a déployé pour le CIPAR [CollectiveAccess](http://www.collectiveaccess.org), système de gestion des collections en opensource et héberge les collections du CIPAR sur un serveur dédié.

Ce plugin n'est probablement pas transportable dans une autre installation de CollectiveAccess directement, mais elle peut servir de point de départ au développement d'un autre plugin, ou être adapté à tout suivi du workflow des objets dans CollectiveAccess.

## Concepts centraux de la gestion du workflow dans la base du CIPAR

Au CIPAR, les objets sont gérés dans l'arborescence suivante :

- diocèce > fabrique d'église > église > objet physique

A noter, d'autres types existent dans la base (musée diocésain, ASBL, objets muséaux) mais ne sont pas la cible de ce suivi.

Identifiants (ID) dans la base correspondants, utilisés pour les requêtes SQL

- 261 > 23 > 262 > 27

Ce plugin de suivi vise essentiellement à suivre le statut des église (sous-entendu des inventaires d'églises) parmi les valeurs suivantes :

- 0 = en attente
- 1 = en cours
- 2 = à valider
- 3 = validé

Les diocèses sont limités à 4, pour plus de facilité de report, voici les 4 id utilisés dans la base :

- 4471 : Diocèse de Tournai
- 4569 : Diocèse de Liège
- 4470 : Diocèse de Namur
- 4571 : Diocèse de Brabant Wallon
- 235008 : Diocèse de Bruxelles

## Requêtes SQL utilisées dans la base 

**Suivi par type pour toutes les églises de la base**

```
select status, count(*) from ca_objects where type_id = 262 and deleted=0 group by status
```

**Eglises en erreur**

Il s'agit de fiches églises qui ne sont pas rangées sous une fabrique, elle-même sous un diocèse.

**Fabriques en erreur**

Il s'agit de fiches de fabriques d'église (type 23) qui ne sont pas rattachées à un diocèse.

```
select parents.parent_id, objects.parent_id as fabrique from ca_objects as objects left join ca_objects as parents on parents.object_id=objects.parent_id where objects.type_id = 262 and objects.deleted=0 and parents.type_id=23 and parents.parent_id is null group by objects.parent_id;
```

Pour plus de facilité, cette requête est accessible depuis le menu Statistiques de la base CIPAR : https://acf.lescollections.be/gestion/index.php/statisticsViewer/Statistics/ShowStat/stat/acf/id/9001

**Suivi par type pour toutes les églises de la base par diocèse**

```
select grandsparents.idno, CASE objects.status WHEN 0 THEN "en attente" WHEN 1 THEN "en cours" WHEN 2 THEN "à valider" WHEN 3 THEN "validé" ELSE "valeur incohérente" END as statut, count(*) as nombre from ca_objects as objects left join ca_objects as parents on parents.object_id=objects.parent_id left join ca_objects as grandsparents on parents.parent_id=grandsparents.object_id and grandsparents.type_id=261 where objects.type_id = 262 and objects.deleted=0 and parents.type_id=23 and parents.parent_id is not null and grandsparents.object_id is not null group by parents.parent_id, objects.status;

```

Exemple de résultat (valeurs tests, non actualisées) :

| idno | statut | nombre |
|------|--------|--------|
|tournai|en attente|461|
|tournai|en cours|2|
|liege|en attente|157|
|namur|en attente|815|
|namur|en cours|6|
|brabant_wallon|en attente|24|
|bruxelles|en attente|1|

