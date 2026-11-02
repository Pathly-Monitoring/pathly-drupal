# Publication du module Drupal sur drupal.org

Dossier préparé pour Simon. Rien n'a été créé ni soumis : ni compte, ni
projet, ni demande de revue.

## Contenu du dossier

| Fichier | Rôle |
|---|---|
| `fiche-en.md` | Textes à coller sur drupal.org (anglais) : titre, nom court, résumé, description, FAQ, notes de version |
| `fiche-fr.md` | Les mêmes textes en français, pour relecture |
| `captures/1-reglages.png` | Écran des réglages : Flow existant, Flow proposé par Lumi, badge |
| `captures/2-rapport.png` | Rapports, Pathly : Ping, Flow, dernier échec avec sa capture, offre |
| `captures/3-encart-contenu.png` | Le bloc d'état placé dans l'administration |
| `captures/4-badge.png` | Le badge dans le pied de page du site, vu par un visiteur |
| `../logo.png` | Logo du projet, 512 × 512, à la racine du dépôt (drupal.org le lit là) |
| `outils/` | Site de démonstration Docker et script Playwright qui refont les captures |

Les captures viennent d'un Drupal 11.4 de test en Docker, avec une API Pathly
de démonstration (données fictives, `outils/demo-api.php`). drupal.org n'a pas
de bannière : seul le logo compte.

## Avant de soumettre

1. **#662 en production.** Le module appelle `/v1/account`,
   `/v1/flow-assistant/*`, `/v1/status-pages` et la capture de
   `GET /v1/runs/{id}`. Ces routes sont sur `release/0.4.1`, pas encore sur
   `main` ni en prod. Sans elles, l'encart et Lumi répondent « Pathly a refusé
   la demande ».
2. **Essai réel.** Avec une vraie clé limitée à un Espace sur un Drupal de
   test public : Ping créé, Lumi lancé, Flow enregistré, rejeu après
   `drush pm:install`, badge. Non fait : il faut les routes de #662 en prod.
3. **Écran Clés d'API.** Le module pointe vers `https://pathlyhq.com/settings`
   pour créer une clé. Vérifier que c'est le bon écran (non vérifié).

## Étapes de soumission (à faire par Simon)

drupal.org est gratuit : pas de frais d'inscription, pas de commission.

### 1. Compte drupal.org

- Créer un compte sur https://www.drupal.org/user/register avec
  `simon@pathlyhq.com`. Les comptes drupal.org sont personnels : nom
  « Simon Raynaud », pas « Pathly ». Pathly peut avoir ensuite une page
  d'organisation (à vérifier).
- Confirmer l'adresse, puis compléter le profil.
- Onglet **Git access** du profil : accepter les conditions d'utilisation de
  Git et choisir un nom d'utilisateur Git. Ajouter une clé SSH dans
  https://git.drupalcode.org (profil, SSH Keys).

### 2. Projet complet (pas de « sandbox »)

Les projets « sandbox » sont dépréciés : la documentation de drupal.org
demande de choisir directement un projet complet (« Full project »).

- https://www.drupal.org/project/add, type **Module**.
- Titre : `Pathly`. Nom court : `pathly` (libre le 02/11/2026 : la page
  drupal.org/project/pathly répond 404).
- Type de projet : **Full project**. Statut de maintenance : *Actively
  maintained*. Statut de développement : *Under active development*.
- Catégories conseillées : *Integrations*, *Administration tools*.
- Description, résumé : `fiche-en.md`.

### 3. Code sur git.drupalcode.org

```bash
cd C:\exploit\pathly-drupal
git remote add drupal git@git.drupal.org:project/pathly.git
git push drupal main:1.0.x
git tag 1.0.0 && git push drupal 1.0.0
```

- La branche se nomme `1.0.x` (convention de drupal.org).
- `.gitlab-ci.yml` (à la racine) lance la CI de drupal.org : phpcs, PHPStan,
  cspell, PHPUnit sur Drupal 11 et 10. Modèle repris de la documentation de
  drupal.org, non essayé sur leur GitLab.
- Le dépôt GitHub `Pathly-Monitoring/pathly-drupal` reste la source. Le passer
  en public au même moment (décision de Simon).

### 4. Première version

- Page du projet, **Add new release**, choisir le tag `1.0.0`, coller les notes
  de version de `fiche-en.md`.
- drupal.org fabrique l'archive en quelques minutes. Ensuite :
  `composer require drupal/pathly`.

### 5. Couverture par l'équipe de sécurité (facultative, conseillée)

Sans elle, la page affiche « not covered by Drupal's security advisory
policy ». Beaucoup d'agences filtrent sur ce critère.

- Une seule demande par personne, valable ensuite pour tous ses projets.
- File : https://www.drupal.org/project/issues/projectapplications.
- Titre : `[1.0.x] Pathly`. Catégorie : *Task*. Statut : *Needs review*.
  Composant : *module*. Description : ce que fait le module, ce qui le
  distingue, lien vers le projet.
- Condition : avoir écrit soi-même la plus grande part du code du projet (les
  commits sont au nom de Simon).
- Délai : revue par des bénévoles, plusieurs semaines. Pour accélérer : le
  programme « Review bonus » demande de relire trois autres demandes et de les
  citer dans la sienne.

### 6. Traductions

Le module embarque `translations/fr.po` (déclaré dans `pathly.info.yml`). Après
la première version, les textes apparaissent sur localize.drupal.org. Pour
passer par localize.drupal.org, retirer alors les deux lignes
`interface translation` de `pathly.info.yml` (à décider).

## Refaire les captures

```bash
docker run -d --name pathly-drupal-demo -p 8095:80 -p 8096:8099 \
  -v "$PWD:/module:ro" drupal:11-php8.4-apache
docker exec pathly-drupal-demo bash -c "sed 's/\r$//' /module/publication/outils/demo.sh > /tmp/demo.sh && bash /tmp/demo.sh"
docker exec -d pathly-drupal-demo php -S 0.0.0.0:8099 /tmp/demo-api/demo-api.php
PLAYWRIGHT_MODULE=<chemin>/node_modules/playwright node publication/outils/captures.mjs x shot
docker cp publication/outils/shot.png pathly-drupal-demo:/tmp/demo-api/shot.png
node publication/outils/captures.mjs "$(docker exec pathly-drupal-demo bash -c 'cd /opt/drupal && vendor/bin/drush --uri=http://localhost:8095 user:login --no-browser')"
docker rm -f pathly-drupal-demo
```
