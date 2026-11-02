<?php

/**
 * @file
 * Simulated Pathly API for the integration test (php -S 127.0.0.1:8099).
 *
 * Every request is written to /tmp/pathly-mock.jsonl: method, path, whether
 * the key was sent, and the body.
 */

declare(strict_types=1);

$ping = '11111111-1111-4111-8111-111111111111';
$flow = '22222222-2222-4222-8222-222222222222';
$pageId = '44444444-4444-4444-8444-444444444444';
$key = 'sp_integration_key_0123456789abcdef';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), TRUE);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

file_put_contents('/tmp/pathly-mock.jsonl', json_encode([
  'route' => "$method $path",
  'query' => $_SERVER['QUERY_STRING'] ?? '',
  'keyOk' => $auth === 'Bearer ' . $key,
  'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
  'body' => $body,
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

$page = [
  'id' => $pageId,
  'kind' => 'organization',
  'slug' => 'shop',
  'public' => TRUE,
  'url' => 'https://pathlyhq.com/status/shop',
  'badgeUrl' => 'https://api.pathlyhq.com/public/status/shop/badge.svg',
];

$routes = [
  'GET /v1/account' => [200, [
    'organization' => ['id' => 'o1', 'name' => 'Shop'],
    'space' => NULL,
    'plan' => ['id' => 'solo', 'name' => 'Solo', 'priceMonthlyEur' => 0],
    'quotas' => [
      'ping' => ['used' => 1, 'limit' => 5, 'remaining' => 4],
      'flow' => ['used' => 1, 'limit' => 1, 'remaining' => 0],
      'assistantTrials' => ['used' => 0, 'limit' => 2, 'remaining' => 2, 'period' => 'day'],
    ],
    'billingUrl' => 'https://pathlyhq.com/billing',
  ],
  ],
  'GET /v1/scenarios' => [
    200,
    ['items' => [['id' => $flow, 'type' => 'browser', 'name' => 'Cart']], 'nextCursor' => NULL],
  ],
  'POST /v1/scenarios' => [201, ['id' => $ping, 'type' => 'http', 'name' => $body['name'] ?? '']],
  'GET /v1/scenarios/' . $ping => [200, ['id' => $ping, 'name' => 'Ping', 'lastStatus' => 'ok']],
  'GET /v1/scenarios/' . $flow => [200, ['id' => $flow, 'name' => 'Cart', 'lastStatus' => 'ok']],
  'POST /v1/scenarios/' . $ping . '/run' => [200, ['ok' => TRUE, 'queued' => TRUE]],
  'POST /v1/scenarios/' . $flow . '/run' => [200, ['ok' => TRUE, 'queued' => TRUE]],
  'GET /v1/runs' => [200, ['items' => []]],
  'GET /v1/status-pages' => [200, ['items' => []]],
  'POST /v1/status-pages' => [201, $page],
  'PATCH /v1/status-pages/' . $pageId => [200, ['public' => FALSE] + $page],
];

[$status, $answer] = $auth !== 'Bearer ' . $key
  ? [401, ['error' => 'Invalid key']]
  : ($routes["$method $path"] ?? [404, ['error' => 'Not found']]);

http_response_code($status);
header('Content-Type: application/json');
echo json_encode($answer);
