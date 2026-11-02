<?php

/**
 * @file
 * API Pathly de démonstration pour les captures d'écran de la fiche.
 *
 * Lancée par demo.sh : php -S 0.0.0.0:8099 demo-api.php. Données fictives.
 */

declare(strict_types=1);

$ping = '11111111-1111-4111-8111-111111111111';
$flow = '22222222-2222-4222-8222-222222222222';
$trial = '33333333-3333-4333-8333-333333333333';
$run = '55555555-5555-4555-8555-555555555555';
$public = getenv('DEMO_PUBLIC_URL') ?: 'http://localhost:8096';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/badge.svg' || $path === '/shot.png') {
  $file = __DIR__ . $path;
  header('Content-Type: ' . ($path === '/badge.svg' ? 'image/svg+xml' : 'image/png'));
  readfile($file);
  return;
}

$routes = [
  'GET /v1/account' => [
    'organization' => ['id' => 'o1', 'name' => 'Demo shop'],
    'space' => NULL,
    'plan' => ['id' => 'pro', 'name' => 'Pro', 'priceMonthlyEur' => 69],
    'quotas' => [
      'ping' => ['used' => 3, 'limit' => 50, 'remaining' => 47],
      'flow' => ['used' => 2, 'limit' => NULL, 'remaining' => NULL],
      'assistantTrials' => ['used' => 1, 'limit' => 15, 'remaining' => 14],
    ],
    'billingUrl' => 'https://pathlyhq.com/billing',
  ],
  'GET /v1/scenarios' => [
    'items' => [
      ['id' => $ping, 'type' => 'http', 'name' => 'Ping · localhost:8095', 'url' => 'http://localhost:8095'],
      ['id' => $flow, 'type' => 'browser', 'name' => 'Contact form'],
    ],
    'nextCursor' => NULL,
  ],
  "GET /v1/scenarios/$ping" => ['id' => $ping, 'name' => 'Ping · localhost:8095', 'lastStatus' => 'ok'],
  "GET /v1/scenarios/$flow" => ['id' => $flow, 'name' => 'Contact form', 'lastStatus' => 'fail'],
  'GET /v1/runs' => [
    'items' => [
      ['id' => $run, 'status' => 'fail', 'created_at' => '2026-11-02T08:14:00Z'],
    ],
  ],
  "GET /v1/runs/$run" => [
    'id' => $run,
    'status' => 'fail',
    'created_at' => '2026-11-02T08:14:00Z',
    'monitor_name' => 'Contact form',
    'message' => 'Step 2: the contact page answered "Page not found".',
    'screenshotUrl' => "$public/shot.png",
  ],
  "GET /v1/flow-assistant/trials/$trial" => [
    'item' => [
      'id' => $trial,
      'status' => 'ready',
      'siteKind' => 'drupal',
      'proposals' => [
        ['index' => 0, 'kind' => 'contact', 'stoppedBefore' => 'Send message'],
        ['index' => 1, 'kind' => 'login'],
      ],
    ],
  ],
  'GET /v1/status-pages' => ['items' => []],
  'POST /v1/status-pages' => [
    'id' => '44444444-4444-4444-8444-444444444444',
    'public' => TRUE,
    'url' => 'https://pathlyhq.com',
    'badgeUrl' => "$public/badge.svg",
  ],
];

$answer = $routes["$method $path"] ?? NULL;
http_response_code($answer === NULL ? 404 : 200);
header('Content-Type: application/json');
echo json_encode($answer ?? ['error' => 'Not found']);
