# PrestaShop : repérer les faux comptes clients

Un fichier, une page, une liste. Le script lit votre base et vous montre quels comptes clients sont suspects, et pourquoi.

Les boutiques PrestaShop sont régulièrement inondées de fausses inscriptions : plusieurs milliers de comptes créés par des robots, souvent la nuit, avec des adresses jetables. Ça pollue les statistiques, gonfle la base, et noie les vrais clients dans la liste.

Ce script les repère et vous montre **pourquoi** chaque compte est suspect. Vous décidez ensuite.

## Ce que ça fait

- lit uniquement les tables `customer` et `orders` ;
- calcule un score par compte, à partir de six signaux ;
- affiche la liste triée par score, avec les raisons ;
- exporte en CSV ;
- fonctionne en mode démonstration, **sans base de données**.

## Zéro risque pour votre boutique

Le script n'écrit rien, ne supprime rien, ne modifie aucune donnée. Vous gardez la main sur chaque décision, et aucun vrai client n'est effacé par erreur.

Il ne supprime pas, ne désactive pas et ne bloque pas les inscriptions : ce sont des décisions qui vous appartiennent, et une suppression en masse est irréversible. Un vrai client peut s'inscrire sans jamais commander.

## Installation

1. Envoyez `spam-check.php` à la **racine** de la boutique, à côté du dossier `app` ou `config`.
2. Ouvrez `https://votre-boutique.fr/spam-check.php` dans un navigateur.
3. Cliquez sur **Lancer l'analyse**.
4. **Supprimez le fichier** quand vous avez terminé (un bouton est prévu dans la page).

Aucune configuration : le script lit les identifiants de la base directement dans `app/config/parameters.php` (PrestaShop 1.7 à 9) ou `config/settings.inc.php` (1.6).

## Démonstration, sans rien installer

Un jeu de 47 comptes fictifs est fourni, dont 22 créés en rafale.

```
https://votre-boutique.fr/spam-check.php?demo=1
```

Ou en ligne de commande :

```bash
php spam-check.php --demo
```

Ces données sont **synthétiques**. Elles reproduisent les motifs observés en pratique (rafale nocturne, adresses jetables, prénoms aléatoires), pas un cas client réel.

## Comment le score est calculé

Chaque signal ajoute des points. À partir de **3**, le compte est affiché.

| Signal | Poids | Pourquoi |
|---|---|---|
| 10 inscriptions ou plus dans la même heure | 2 | le signal le plus fiable : un bot s'inscrit en série |
| Adresse email jetable | 2 | yopmail, mailinator, temp-mail, etc. |
| Prénom identique au nom | 2 | signature des inscriptions automatiques |
| Nom improbable (lien, chiffres, suite de consonnes) | 2 | `Brxvty`, `SEO services http://…` |
| Adresse de test ou technique (`test@test.com`) | 2 | compte de démonstration oublié |
| Anniversaire non renseigné | 1 | fréquent chez les bots, mais aussi chez les humains |
| Aucune commande | 1 | idem : un prospect peut ne pas avoir commandé |

Le cumul compte plus que chaque signal pris seul : un compte sans commande inscrit à 14 h avec un vrai nom n'est pas signalé. Un compte sans commande inscrit à 3 h du matin avec un email jetable l'est.

## Que faire de la liste

Ne supprimez pas en masse. Le bon geste, en deux temps :

1. **Désactiver** le compte (`active = 0`) plutôt que le supprimer : c'est réversible, et la personne peut vous écrire si c'est un vrai client.
2. **Attendre 30 jours**, puis supprimer ceux qui n'ont toujours aucune commande.

Un compte signalé n'est pas forcément un faux : c'est un compte qui mérite un coup d'œil.

## Spam de comptes ou piratage ? Ce n'est pas la même chose

Des comptes suspects ne signifient **pas** que la boutique est piratée. Ce sont généralement des robots qui se sont inscrits, sans jamais accéder à autre chose que le formulaire.

En revanche, si vous constatez en plus l'un de ces signes, c'est un autre problème :

- des pages ou des articles que vous n'avez pas créés ;
- des redirections vers des sites inconnus (souvent depuis Google) ;
- du texte étranger dans les résultats Google, type pharmacie ou japonais ;
- des fichiers modifiés dans votre hébergement, ou un compte administrateur inconnu ;
- des commandes ou des données de paiement qui semblent compromises.

Dans ce cas, il ne s'agit pas de spam d'inscription : il faut traiter le site lui-même.

→ **[PrestaShop piraté : le guide du nettoyage](https://www.adrienmansard.com/prestashop-piratage)**

## Version phpMyAdmin

Pour ceux qui préfèrent le SQL, `spam-accounts.sql` contient les mêmes requêtes, prêtes à coller dans l'onglet SQL. Remplacez `ps_` par votre préfixe de tables.

## Ce qui a été testé, et ce qui ne l'a pas été

Environnement des tests : **Ubuntu 26.10, PHP 8.5.9, MySQL 9.7.2**.

| Élément | État | Détail |
|---|---|---|
| Scoring et signaux | ✅ testé | 13 tests unitaires, 0 échec |
| Connexion à la base (`pdo_mysql`) | ✅ testé | |
| Lecture automatique de `app/config/parameters.php` (1.7 à 9) | ✅ testé | |
| Requête principale sur 47 comptes | ✅ testé | 24 suspects, comme attendu |
| Faux positifs sur clients légitimes | ✅ testé | 0 sur 15 |
| Faux positifs sur cas limites (0 commande, vrai nom) | ✅ testé | 0 sur 8 |
| `spam-accounts.sql`, les 6 requêtes | ✅ testé | aucune erreur |
| Cohérence entre le PHP et le SQL | ✅ testé | 24 suspects des deux côtés |
| Mode web (bouton « Lancer l'analyse ») | ✅ testé | |
| **Boutique PrestaShop complète** | ❌ **non testé** | validé sur un schéma minimal `customer` + `orders`, jamais sur une installation entière |
| **`config/settings.inc.php` (PrestaShop 1.6)** | ❌ **non testé** | code présent, jamais exécuté |
| **Autres bases** (MySQL 5.7, MariaDB 10.x) | ❌ **non testé** | |
| **Préfixe de tables différent de `ps_`** | ❌ **non testé** | lu automatiquement dans la configuration, jamais vérifié |
| **Gros volumes** (100 000 commandes et plus) | ❌ **non testé** | la requête principale est limitée à 50 000 clients |
| **Navigateurs** | ⚠️ partiel | rendu vérifié dans Chrome uniquement |

**Si votre configuration figure dans la colonne « non testé »**, commencez par le mode démonstration (`?demo=1`) : il ne touche pas à la base et montre exactement ce que l'outil produit.

## Rejouer les tests

### Unitaires, sans base de données

```bash
php tests/run.php
```

13 tests couvrent le volume, l'absence de faux positifs sur les clients légitimes et les cas limites, la détection de la rafale, chaque signal, le tri, le seuil et la date zéro.

### Intégration, avec MySQL

```bash
mysql -u root -e "CREATE DATABASE ps_test CHARACTER SET utf8mb4"
mysql -u root ps_test < tests/schema.sql
mysql -u root ps_test < tests/seed.sql
```

`tests/schema.sql` recrée les tables `ps_customer` et `ps_orders` avec les types réels de PrestaShop, et `tests/seed.sql` (généré depuis `demo/customers.json` par `tests/generate-seed.php`) y injecte les 47 comptes.

Il reste à placer `spam-check.php` dans un dossier contenant :

```
app/config/parameters.php   -> pointe sur ps_test
spam-check.php
```

puis `php spam-check.php`. Résultat attendu, identique au mode démo :

```
47 comptes analysés, 24 suspects (51 %)
```

La requête principale de `spam-accounts.sql` doit renvoyer exactement 24 lignes sur la même base.

## Limites connues

- **Pas d'adresse IP** dans cette version : la table `guest` change de structure entre 1.6 et 1.7+, et une IP mal lue vaut pire que pas d'IP.
- **Faux positifs possibles** sur un compte légitime sans commande ni anniversaire : c'est pour ça que le seuil est à 3 et que rien n'est automatique.
- **Dates zéro** : MySQL 8.0.35 et 9 refusent `0000-00-00`. La requête de `spam-accounts.sql` utilise donc `birthday IS NULL OR birthday < '1900-01-01'`, qui couvre les boutiques anciennes comme les récentes. Le script PHP gère les deux cas.

## Un besoin plus large ?

L'outil liste. Il ne traite pas. Si vous avez des milliers de comptes à nettoyer, ou si les inscriptions continuent d'arriver, c'est un travail à part : désactivation, purge, sauvegarde, et surtout blocage à l'inscription.

→ **[adrienmansard.com](https://www.adrienmansard.com/)** — consultant PrestaShop depuis 2013.

## Licence

MIT.

---

## English

A single-file, read-only script that scores PrestaShop customer accounts and lists the suspicious ones with the reasons.

Upload `spam-check.php` to the shop root, open it in a browser. It reads the database credentials from `app/config/parameters.php` (1.7 to 9) or `config/settings.inc.php` (1.6). No configuration, no writing, no deletion.

Try it without a shop: `spam-check.php?demo=1` (47 synthetic accounts, 22 of them created in a burst).

Scoring: burst of 10+ signups in the same hour (2), disposable email (2), first name equals last name (2), improbable name (2), test address (2), empty birthday (1), no order (1). Flagged at 3 or more.

Tested on PHP 8.5 / MySQL 9.7 with a minimal PrestaShop schema. Not yet tested on a full PrestaShop install, on PrestaShop 1.6 config files, or on other databases. The full test matrix is in the French section above.

The tool deliberately does not delete, deactivate or block anything. `spam-accounts.sql` contains the same queries for phpMyAdmin users.

MIT licensed.
