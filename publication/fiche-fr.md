# Fiche du projet sur drupal.org (version française, pour relecture)

drupal.org est en anglais : c'est `fiche-en.md` qui se colle dans le
formulaire. Ce fichier en donne la traduction, pour que Simon relise le fond.

## Titre du projet

Pathly

## Nom court (nom machine)

pathly

## Résumé court (une phrase, listes de projets)

Surveille le parcours de votre site Drupal avec Pathly : Ping s'il répond,
Flow si un visiteur peut aller au bout, et le Flow rejoué après chaque mise à
jour.

## Description

Pathly watches the path. Ping if it's up. Flow if a user can walk it. Chain if
the services behind it hold. (Phrase de marque, identique dans toutes les
langues.)

Ce module relie un site Drupal 10 ou 11 à [Pathly](https://pathlyhq.com), un
service de surveillance externe. Il faut un compte Pathly. L'offre Solo est
gratuite, sans carte.

### Fonctions

- **Ping** : créé sur l'adresse du site dès que la clé d'API est enregistrée.
  Si un Ping surveille déjà cette adresse, le module l'utilise.
- **Flow préparé par Lumi** : Lumi, l'assistant Flow de Pathly, reconnaît
  Drupal et propose un Flow (formulaire de contact arrêté avant l'envoi, page
  de connexion). Chaque Flow proposé est rejoué une fois avant d'être montré.
  Vous le choisissez en un clic. Vous pouvez aussi utiliser un Flow qui existe
  déjà dans votre compte Pathly.
- **Rejeu après les mises à jour** : le Flow tourne de nouveau après chaque
  mise à jour du cœur, d'un module ou d'un thème (update.php, drush updatedb,
  reconstruction des caches, installation d'un module, Automatic Updates).
- **État dans l'administration** : un bloc et une page de rapport montrent
  l'état du Ping et du Flow, la date et la capture du dernier échec, l'offre
  et ses quotas.
- **Badge de disponibilité** : un bloc relié à la page de statut Pathly.
  Éteint par défaut.

### Prérequis

- Drupal 10.3 ou plus, ou Drupal 11. PHP 8.1 ou plus.
- Un compte Pathly et une clé d'API, de préférence limitée à un Espace.

### Données et vie privée

- Rien ne part vers Pathly avant qu'un administrateur enregistre une clé.
- Ensuite, seule l'adresse du site part vers Pathly, avec les versions de
  Drupal et du module. Aucune donnée de client, de commande ou de visiteur.
- Le module n'appelle que `https://api.pathlyhq.com`.
- La clé est gardée dans l'API State, jamais réaffichée, jamais journalisée.
- Le badge est une image chargée depuis `api.pathlyhq.com` par le navigateur
  des visiteurs. Il est éteint par défaut.
- Sur une boutique, un Flow met un produit dans un panier de test : les
  relances de panier abandonné peuvent compter ces paniers.

### Projets proches

Les modules de disponibilité vérifient qu'une page répond. Pathly rejoue en
plus le parcours d'un visiteur dans un vrai navigateur (Flow), et le rejoue
après chaque mise à jour.

## Questions fréquentes

**Le service Pathly est-il gratuit ?**
L'offre Solo est gratuite, sans carte et sans limite de durée : 5 Ping et
1 Flow. Les offres payantes sont décrites sur https://pathlyhq.com.

**Le module marche-t-il sans compte Pathly ?**
Non. C'est un client de l'API Pathly. Sans clé, il n'envoie rien.

**Lumi paie-t-il ou envoie-t-il quelque chose sur mon site ?**
Non. Il ne paie rien, n'envoie aucun formulaire et ne saisit que des valeurs
de test. Vous validez chaque Flow avant son enregistrement.

**Lumi connaît-il Drupal Commerce ?**
Quand Lumi propose un Flow de panier, le module le choisit en premier sur un
site avec Drupal Commerce. Sinon, il propose le formulaire de contact et la
page de connexion. Vous pouvez aussi enregistrer le Flow dans Pathly et
l'utiliser depuis le module.

**Que se passe-t-il à la désinstallation ?**
Les réglages, la clé et ce que le module gardait sont effacés. Si le module a
publié la page de statut pour le badge, il la dépublie. Le Ping et le Flow
restent dans votre compte Pathly.

## Journal des changements, 1.0.0 (première version)

- Réglages : clé d'API, lien d'inscription.
- Ping créé ou rattaché sur l'adresse du site.
- Flow préparé par Lumi, ou Flow existant.
- Rejeu du Flow après les mises à jour.
- Bloc d'état et page de rapport : Ping, Flow, dernier échec avec capture,
  offre et quotas.
- Bloc du badge de disponibilité, éteint par défaut.
- Traduction française.
