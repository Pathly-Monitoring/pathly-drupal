<?php

declare(strict_types=1);

namespace Drupal\pathly\Api;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Site\Settings;
use Drupal\pathly\KeyStore;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Small client of the public Pathly API (/v1).
 *
 * It only calls https://api.pathlyhq.com. No address typed in the site is
 * ever called from Drupal. For automated tests only, settings.php may point
 * it to a loopback address (localhost, 127.0.0.1).
 */
class PathlyClient {

  /**
   * Address of the Pathly API.
   */
  public const BASE_URL = 'https://api.pathlyhq.com';

  /**
   * Pages of scenarios read at most (500 per page).
   */
  private const MAX_PAGES = 10;

  /**
   * Builds the client.
   */
  public function __construct(
    protected ClientInterface $http,
    protected KeyStore $keys,
    protected Settings $settings,
    protected ModuleExtensionList $modules,
  ) {}

  /**
   * Version of the module, set by the packaging of drupal.org.
   */
  public function moduleVersion(): string {
    return (string) ($this->modules->getExtensionInfo('pathly')['version'] ?? 'dev');
  }

  /**
   * Address of the API: Pathly, or a loopback address for the tests.
   */
  public function baseUrl(): string {
    $override = (string) $this->settings->get('pathly_api_base_url', '');
    return \preg_match('#^http://(localhost|127\.0\.0\.1)(:\d{1,5})?$#', $override) ? $override : self::BASE_URL;
  }

  /**
   * Organization, Space, plan and quotas of the key.
   *
   * @param string|null $key
   *   Key to check before saving it, or NULL for the saved key.
   */
  public function account(?string $key = NULL): array {
    return $this->request('GET', '/v1/account', NULL, NULL, $key);
  }

  /**
   * All the scenarios (Ping, Flow, Chain) the key can see.
   */
  public function listScenarios(): array {
    $items = [];
    $query = '';
    for ($page = 0; $page < self::MAX_PAGES; $page++) {
      $body = $this->request('GET', '/v1/scenarios?limit=500' . $query);
      $items = \array_merge($items, $body['items'] ?? []);
      $cursor = (string) ($body['nextCursor'] ?? '');
      if ($cursor === '') {
        break;
      }
      $query = '&cursor=' . \rawurlencode($cursor);
    }
    return $items;
  }

  /**
   * One scenario.
   */
  public function getScenario(string $id): array {
    return $this->request('GET', '/v1/scenarios/' . self::id($id));
  }

  /**
   * Creates a scenario.
   */
  public function createScenario(array $input, string $idempotencyKey): array {
    return $this->request('POST', '/v1/scenarios', $input, $idempotencyKey);
  }

  /**
   * Runs a scenario now.
   */
  public function runScenario(string $id): array {
    return $this->request('POST', '/v1/scenarios/' . self::id($id) . '/run', []);
  }

  /**
   * Latest runs of a scenario, most recent first.
   */
  public function listRuns(string $scenarioId, int $limit = 25): array {
    $body = $this->request('GET', '/v1/runs?scenarioId=' . self::id($scenarioId) . '&limit=' . $limit);
    return $body['items'] ?? [];
  }

  /**
   * One run, with the signed address of its screenshot (15 minutes).
   */
  public function getRun(string $id): array {
    return $this->request('GET', '/v1/runs/' . self::id($id));
  }

  /**
   * Asks Lumi whether it can prepare a Flow on the site.
   */
  public function detect(string $url): array {
    return $this->request('POST', '/v1/flow-assistant/detect', ['url' => $url]);
  }

  /**
   * Starts a Lumi trial on the site.
   */
  public function startTrial(string $url, string $idempotencyKey): array {
    return $this->request('POST', '/v1/flow-assistant/trials', ['url' => $url, 'confirmOwner' => TRUE], $idempotencyKey);
  }

  /**
   * State of a Lumi trial and the Flow it proposes.
   */
  public function getTrial(string $id): array {
    return $this->request('GET', '/v1/flow-assistant/trials/' . self::id($id));
  }

  /**
   * Saves a Flow proposed by Lumi.
   */
  public function saveTrial(string $id, int $index, string $idempotencyKey): array {
    return $this->request('POST', '/v1/flow-assistant/trials/' . self::id($id) . '/save', ['index' => $index], $idempotencyKey);
  }

  /**
   * Status pages the key can see.
   */
  public function listStatusPages(): array {
    return $this->request('GET', '/v1/status-pages')['items'] ?? [];
  }

  /**
   * Creates the status page of the key, or returns it.
   */
  public function createStatusPage(array $input): array {
    return $this->request('POST', '/v1/status-pages', $input);
  }

  /**
   * Publishes or unpublishes a status page.
   */
  public function patchStatusPage(string $id, array $input): array {
    return $this->request('PATCH', '/v1/status-pages/' . self::id($id), $input);
  }

  /**
   * Sends one request and decodes the answer.
   *
   * @throws \Drupal\pathly\Api\PathlyApiException
   */
  protected function request(string $method, string $path, ?array $body = NULL, ?string $idempotencyKey = NULL, ?string $key = NULL): array {
    $key ??= $this->keys->get();
    if ($key === NULL) {
      throw new PathlyApiException(401, 'no_key', 'No Pathly API key.');
    }
    $headers = [
      'Authorization' => 'Bearer ' . $key,
      'Accept' => 'application/json',
      'User-Agent' => 'pathly-drupal/' . $this->moduleVersion() . ' Drupal/' . \Drupal::VERSION,
    ];
    if ($idempotencyKey !== NULL) {
      $headers['Idempotency-Key'] = $idempotencyKey;
    }
    $options = ['headers' => $headers, 'http_errors' => FALSE, 'allow_redirects' => FALSE];
    if ($body !== NULL) {
      $options['json'] = (object) $body;
    }
    try {
      $response = $this->http->request($method, $this->baseUrl() . $path, $options);
    }
    catch (GuzzleException) {
      throw new PathlyApiException(0, 'network', 'Pathly could not be reached.');
    }
    $status = $response->getStatusCode();
    $decoded = \json_decode((string) $response->getBody(), TRUE);
    $data = \is_array($decoded) ? $decoded : [];
    if ($status >= 200 && $status < 300) {
      return $data;
    }
    throw new PathlyApiException($status, (string) ($data['code'] ?? ''), (string) ($data['error'] ?? 'HTTP ' . $status), $data);
  }

  /**
   * Checks an identifier before putting it in a path.
   */
  protected static function id(string $id): string {
    if (!\preg_match('/^[0-9a-fA-F-]{8,64}$/', $id)) {
      throw new PathlyApiException(400, 'bad_id', 'Invalid identifier.');
    }
    return $id;
  }

}
