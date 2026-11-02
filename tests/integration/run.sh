#!/usr/bin/env bash
# Integration test of the module on a real Drupal, in the official Docker image.
#
#   docker run --rm -v "$PWD:/module:ro" drupal:11-php8.4-apache bash /module/tests/integration/run.sh
#
# Installs Drupal (SQLite), installs the module, checks that nothing goes to
# Pathly before the configuration, configures it through the settings form,
# installs then updates a contributed module (Token) and checks the replay of
# the Flow, then uninstalls the module. Pathly is simulated by
# tests/integration/mock-api.php on 127.0.0.1:8099.
set -euo pipefail

TOKEN_OLD="${TOKEN_OLD:-1.16.0}"
TOKEN_NEW="${TOKEN_NEW:-1.17.0}"
KEY="sp_integration_key_0123456789abcdef"
FLOW="22222222-2222-4222-8222-222222222222"
LOG=/tmp/pathly-mock.jsonl
export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1

cd /opt/drupal
step() { printf '\n== %s\n' "$*"; }
fail() { printf 'ÉCHEC : %s\n' "$*" >&2; exit 1; }
calls() { grep -c "\"route\":\"$1\"" "$LOG" 2>/dev/null || true; }
drush() { vendor/bin/drush --uri=http://shop.test "$@"; }
# Submits the settings form through the Form API, with the given values.
submit_form() {
  drush php:eval "
    \$fs = new \Drupal\Core\Form\FormState();
    \$fs->setValues($1);
    \Drupal::formBuilder()->submitForm(\Drupal\pathly\Form\SettingsForm::class, \$fs);
    if (\$fs->getErrors()) { echo json_encode(array_map('strval', \$fs->getErrors())); exit(1); }
  "
}

step "Drupal $(php -r 'include "web/core/lib/Drupal.php"; echo \Drupal::VERSION;'), PHP $(php -r 'echo PHP_VERSION;')"
composer require --no-interaction --no-progress --quiet drush/drush "drupal/token:$TOKEN_OLD"

step "Copie du module"
mkdir -p web/modules/custom
rm -rf web/modules/custom/pathly
cp -r /module web/modules/custom/pathly
rm -rf web/modules/custom/pathly/{.git,build,vendor}

step "Installation de Drupal"
drush site:install minimal -y --db-url=sqlite://sites/default/files/.ht.sqlite --site-name=Shop >/dev/null 2>&1
chmod u+w web/sites/default web/sites/default/settings.php
echo "\$settings['pathly_api_base_url'] = 'http://127.0.0.1:8099';" >> web/sites/default/settings.php

step "API simulée"
rm -f "$LOG"
php -S 127.0.0.1:8099 /module/tests/integration/mock-api.php >/tmp/mock-server.log 2>&1 &
MOCK_PID=$!
trap 'kill $MOCK_PID 2>/dev/null || true' EXIT
for _ in $(seq 1 20); do
  curl -s -o /dev/null http://127.0.0.1:8099/ 2>/dev/null && break
  php -r 'usleep(200000);'
done
rm -f "$LOG"

step "Activation : rien ne part vers Pathly"
drush pm:install pathly -y
drush cache:rebuild
drush cron
[ ! -s "$LOG" ] || fail "des appels sont partis avant la configuration : $(cat "$LOG")"

step "Configuration par le formulaire (clé, badge)"
submit_form "['api_key' => '$KEY', 'replay_after_updates' => 1, 'op' => 'Save configuration']"
[ "$(calls 'GET /v1/account')" -ge 1 ] || fail "clé non vérifiée"
[ "$(calls 'POST /v1/scenarios')" -eq 1 ] || fail "Ping non créé"
# The badge can only be turned on once the site is connected.
submit_form "['badge_enabled' => 1, 'replay_after_updates' => 1, 'op' => 'Save configuration']"
[ "$(calls 'POST /v1/status-pages')" -eq 1 ] || fail "page de statut non publiée"
grep -q '"url":"http://shop.test"' "$LOG" || fail "adresse de la boutique absente"
grep -q '"keyOk":false' "$LOG" && fail "un appel sans la bonne clé"
grep -q 'pathly-drupal/' "$LOG" || fail "User-Agent du module absent"
drush php:eval "echo \Drupal::state()->get('pathly.api_key') ? 'cle-ok' : 'cle-absente';" | grep -q cle-ok || fail "clé non enregistrée"
drush config:get pathly.settings badge_enabled | grep -q true || fail "badge non activé"

step "Rattachement d'un Flow existant"
submit_form "['existing_flow' => '$FLOW', 'op' => 'Use this Flow']"
drush state:get pathly.flow_id | grep -q "$FLOW" || fail "Flow non rattaché"

step "Encart d'administration"
drush php:eval "echo \Drupal::service('renderer')->renderInIsolation(\Drupal::service('pathly.status_report')->render());" | grep -q 'Solo' || fail "encart sans l'offre"

step "Installation d'un module : rejeu du Flow"
drush pm:install token -y
[ "$(calls "POST /v1/scenarios/$FLOW/run")" -eq 1 ] || fail "pas de rejeu après l'installation d'un module"

step "Mise à jour d'un module avec Composer, puis updatedb et cache:rebuild : rejeu du Flow"
drush state:delete pathly.last_replay
composer require --no-interaction --no-progress --quiet "drupal/token:$TOKEN_NEW"
drush updatedb -y
drush cache:rebuild
[ "$(calls "POST /v1/scenarios/$FLOW/run")" -eq 2 ] || fail "pas de rejeu après la mise à jour"

step "Sans changement de version : pas de rejeu"
drush state:delete pathly.last_replay
drush cache:rebuild
drush cron
[ "$(calls "POST /v1/scenarios/$FLOW/run")" -eq 2 ] || fail "rejeu sans mise à jour"

step "Désinstallation : page dépubliée, clé, réglages et état effacés"
drush pm:uninstall pathly -y
[ "$(calls "PATCH /v1/status-pages/44444444-4444-4444-8444-444444444444")" -eq 1 ] || fail "page de statut non dépubliée"
drush php:eval "
  foreach (['pathly.api_key','pathly.ping_id','pathly.flow_id','pathly.site_url','pathly.status_page','pathly.fingerprint','pathly.last_replay'] as \$k) {
    if (\Drupal::state()->get(\$k) !== NULL) { echo \"reste \$k\"; exit(1); }
  }
  if (!\Drupal::config('pathly.settings')->isNew()) { echo 'reste pathly.settings'; exit(1); }
"

step "Appels reçus par l'API simulée"
cut -c1-160 "$LOG"
printf '\nTest d intégration réussi.\n'
