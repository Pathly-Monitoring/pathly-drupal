# Tests du module Pathly pour Drupal

Trois niveaux, tous automatisés et lancés par la CI GitHub Actions
(`.github/workflows/ci.yml`).

## 1. Tests unitaires : couverture de 100 % des lignes et des branches

`tests/src/Unit`. Le module tourne sur des services Drupal légers (State en
mémoire, configuration en mémoire, messagerie réelle) et parle à une API Pathly
simulée (`tests/modules/pathly_test/src/FakeApi.php`). Xdebug mesure les lignes
et les branches. `tests/coverage-check.php` échoue sous 100 % et liste chaque
ligne ou branche non couverte.

Pourquoi la couverture vient des tests unitaires : Xdebug met près de deux
minutes par test Kernel en mode branches (il analyse tout le cœur de Drupal),
contre quelques secondes pour toute la suite unitaire.

## 2. Tests Kernel : un vrai Drupal, sans navigateur

`tests/src/Kernel`. Drupal installé sur SQLite, avec le module de test
`pathly_test` qui envoie les appels vers l'API simulée. Ils vérifient le
formulaire par le Form API, le rendu Twig de l'encart, les blocs, les hooks
réels (installation d'un module, désinstallation de Pathly).

## 3. Test d'intégration : Drupal complet en Docker

`tests/integration/run.sh`, dans l'image officielle `drupal`. Il installe
Drupal, installe le module, vérifie que rien ne part avant la configuration,
configure la clé par le formulaire, installe puis met à jour le module Token
avec Composer et vérifie le rejeu du Flow, puis désinstalle le module.
L'API Pathly est simulée par `tests/integration/mock-api.php`.

```bash
docker run --rm -v "$PWD:/module:ro" drupal:11-php8.4-apache \
  bash /module/tests/integration/run.sh
docker run --rm -v "$PWD:/module:ro" drupal:10.3-php8.2-apache \
  bash /module/tests/integration/run.sh
```

## Sur un poste de travail (Docker seulement)

```bash
# Image PHP avec Xdebug, puis projet Drupal d'essai dans un volume.
docker build -t pathly-drupal-test:php8.4 tests/docker
docker run --rm -v pathly-drupal-d11:/work -v "$PWD:/module" \
  pathly-drupal-test:php8.4 bash /module/tests/docker/setup-project.sh "^11" /work/project

# Tests unitaires, couverture, Kernel, phpcs.
tests/docker/dtest.sh pathly-drupal-d11 pathly-drupal-test:php8.4 \
  "../../../../vendor/bin/phpunit --testsuite unit && php tests/coverage-check.php build/coverage.cov"
tests/docker/dtest.sh pathly-drupal-d11 pathly-drupal-test:php8.4 \
  "../../../../vendor/bin/phpunit --testsuite kernel --no-coverage"
tests/docker/dtest.sh pathly-drupal-d11 pathly-drupal-test:php8.4 \
  "../../../../vendor/bin/phpcs"
```
