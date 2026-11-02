#!/usr/bin/env bash
# Crée un projet Drupal d'essai (drupal/recommended-project) avec les outils
# de test : drupal/core-dev (PHPUnit), drupal/coder (phpcs) et Drush.
# Usage : setup-project.sh <contrainte Drupal> <dossier>, ex. "~10.3.0" /work/project
set -euo pipefail
CONSTRAINT="$1"
DIR="$2"
if [ ! -f "$DIR/composer.json" ]; then
  composer create-project --no-interaction --no-progress "drupal/recommended-project:$CONSTRAINT" "$DIR"
fi
cd "$DIR"
composer config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
composer config --no-plugins allow-plugins.phpstan/extension-installer true
composer config --no-plugins allow-plugins.tbachert/spi true
# Composer 2.9 refuses packages with security advisories. Old Drupal minor
# versions (10.3) are tested on purpose, in this throwaway project only.
composer config audit.block-insecure false || true
composer require --dev --no-interaction --no-progress -W "drupal/core-dev:$CONSTRAINT" drupal/coder drush/drush
mkdir -p web/modules/custom
