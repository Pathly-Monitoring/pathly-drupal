#!/usr/bin/env bash
# Site Drupal de démonstration pour les captures d'écran de la fiche.
#
#   docker run -d --name pathly-drupal-demo -p 8095:80 -p 8096:8099 \
#     -v "$PWD:/module:ro" drupal:11-php8.4-apache
#   docker exec pathly-drupal-demo bash /module/publication/outils/demo.sh
#   node publication/outils/captures.mjs
#   docker rm -f pathly-drupal-demo
#
# Données fictives servies par demo-api.php. Rien ne part vers Pathly.
set -euo pipefail
export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1
cd /opt/drupal
drush() { vendor/bin/drush --uri=http://localhost:8095 "$@"; }

composer require --no-interaction --no-progress --quiet drush/drush
mkdir -p web/modules/custom && rm -rf web/modules/custom/pathly
cp -r /module web/modules/custom/pathly
rm -rf web/modules/custom/pathly/{.git,build,vendor,publication}

drush site:install standard -y --db-url=sqlite://localhost/sites/default/files/.ht.sqlite \
  --site-name="Demo shop" --account-name=admin >/dev/null 2>&1
chmod u+w web/sites/default web/sites/default/settings.php
echo "\$settings['pathly_api_base_url'] = 'http://127.0.0.1:8099';" >> web/sites/default/settings.php

mkdir -p /tmp/demo-api
cp /module/publication/outils/demo-api.php /tmp/demo-api/
cp /module/publication/outils/badge.svg /tmp/demo-api/
[ -f /module/publication/outils/shot.png ] && cp /module/publication/outils/shot.png /tmp/demo-api/
nohup php -S 0.0.0.0:8099 /tmp/demo-api/demo-api.php >/tmp/demo-api.log 2>&1 &
sleep 1

drush pm:install pathly -y
drush php:eval "
  foreach ([
    ['api_key' => 'sp_demo_key_0123456789abcdefABCDEF', 'replay_after_updates' => 1, 'op' => 'Save configuration'],
    ['badge_enabled' => 1, 'replay_after_updates' => 1, 'op' => 'Save configuration'],
  ] as \$values) {
    \$fs = new \Drupal\Core\Form\FormState();
    \$fs->setValues(\$values);
    \Drupal::formBuilder()->submitForm(\Drupal\pathly\Form\SettingsForm::class, \$fs);
  }
  \Drupal::state()->set('pathly.flow_id', '22222222-2222-4222-8222-222222222222');
  \Drupal::state()->set('pathly.trial', ['id' => '33333333-3333-4333-8333-333333333333', 'siteKind' => 'drupal']);
  \Drupal\block\Entity\Block::create([
    'id' => 'olivero_pathly_badge',
    'theme' => 'olivero',
    'region' => 'footer_bottom',
    'plugin' => 'pathly_badge',
    'settings' => ['label' => 'Uptime', 'label_display' => FALSE],
  ])->save();
  \Drupal\block\Entity\Block::create([
    'id' => 'claro_pathly_status',
    'theme' => 'claro',
    'region' => 'content',
    'weight' => -10,
    'plugin' => 'pathly_status',
    'settings' => ['label' => 'Pathly', 'label_display' => 'visible'],
    'visibility' => ['request_path' => ['id' => 'request_path', 'pages' => '/admin/content', 'negate' => FALSE]],
  ])->save();
"
drush cache:rebuild
chown -R www-data:www-data web/sites/default/files
drush user:login --no-browser
