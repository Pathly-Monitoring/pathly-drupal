<?php

declare(strict_types=1);

namespace Drupal\pathly;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\pathly\Api\PathlyApiException;
use Drupal\pathly\Api\PathlyClient;
use Psr\Log\LoggerInterface;

/**
 * Links the site to Pathly: Ping, Flow prepared by Lumi, replay and badge.
 *
 * Nothing is sent to Pathly before the administrator saves an API key. Then
 * only the address of the site goes out (and the Drupal and module versions,
 * in the User-Agent header). No customer, order or visitor data.
 */
class Connector {

  /**
   * State keys of the module.
   */
  public const PING_ID = 'pathly.ping_id';
  public const FLOW_ID = 'pathly.flow_id';
  public const TRIAL = 'pathly.trial';
  public const SITE_URL = 'pathly.site_url';
  public const STATUS_PAGE = 'pathly.status_page';

  /**
   * Every State key, removed when the module is uninstalled.
   */
  public const STATE_KEYS = [
    KeyStore::STATE_KEY,
    self::PING_ID,
    self::FLOW_ID,
    self::TRIAL,
    self::SITE_URL,
    self::STATUS_PAGE,
    UpdateWatcher::FINGERPRINT,
    UpdateWatcher::LAST_REPLAY,
  ];

  /**
   * Cache tag of what the module shows from Pathly.
   */
  public const CACHE_TAG = 'pathly_status';

  /**
   * Interval of the Ping created by the module: allowed on every plan.
   */
  public const PING_INTERVAL_SEC = 300;

  /**
   * Builds the connector.
   */
  public function __construct(
    protected PathlyClient $client,
    protected KeyStore $keys,
    protected StateInterface $state,
    protected ModuleHandlerInterface $moduleHandler,
    protected CacheTagsInvalidatorInterface $cacheTags,
    protected LoggerInterface $logger,
  ) {}

  /**
   * TRUE when an API key is set.
   */
  public function isConnected(): bool {
    return $this->keys->get() !== NULL;
  }

  /**
   * Identifier of the Ping of the site.
   */
  public function pingId(): ?string {
    return $this->state->get(self::PING_ID);
  }

  /**
   * Identifier of the Flow replayed after updates.
   */
  public function flowId(): ?string {
    return $this->state->get(self::FLOW_ID);
  }

  /**
   * Address of the site, as sent to Pathly.
   */
  public function siteUrl(): ?string {
    return $this->state->get(self::SITE_URL);
  }

  /**
   * Status page of the badge, once the badge is on.
   */
  public function statusPage(): ?array {
    return $this->state->get(self::STATUS_PAGE);
  }

  /**
   * Checks a key with Pathly before saving it.
   *
   * @return array
   *   The account seen by the key.
   */
  public function checkKey(string $key): array {
    try {
      return $this->client->account($key);
    }
    catch (PathlyApiException $e) {
      throw ConnectorException::fromApi($e);
    }
  }

  /**
   * Saves the key, then creates the Ping of the site or reuses it.
   *
   * @return string
   *   'attached' when the Ping already existed, 'created' otherwise.
   */
  public function connect(string $key, string $siteUrl): string {
    $this->keys->set($key);
    return $this->ensurePing($siteUrl);
  }

  /**
   * Creates the Ping of the site, or reuses the one that watches it.
   */
  public function ensurePing(string $siteUrl): string {
    $url = \rtrim(\trim($siteUrl), '/');
    $this->state->set(self::SITE_URL, $url);
    try {
      foreach ($this->client->listScenarios() as $scenario) {
        if (($scenario['type'] ?? '') === 'http' && self::normalizeUrl((string) ($scenario['url'] ?? '')) === self::normalizeUrl($url)) {
          $this->remember(self::PING_ID, (string) $scenario['id']);
          return 'attached';
        }
      }
      $created = $this->client->createScenario([
        'name' => 'Ping · ' . \preg_replace('#^https?://#', '', $url),
        'type' => 'http',
        'url' => $url,
        'intervalSec' => self::PING_INTERVAL_SEC,
      ], 'drupal-ping-' . \substr(\hash('sha256', self::normalizeUrl($url)), 0, 40));
    }
    catch (PathlyApiException $e) {
      throw ConnectorException::fromApi($e);
    }
    $this->remember(self::PING_ID, (string) $created['id']);
    return 'created';
  }

  /**
   * Forgets the key and what the module knows. Nothing is deleted in Pathly.
   */
  public function disconnect(): void {
    $this->keys->delete();
    $this->state->deleteMultiple([self::PING_ID, self::FLOW_ID, self::TRIAL, self::SITE_URL, self::STATUS_PAGE]);
    $this->cacheTags->invalidateTags([self::CACHE_TAG]);
  }

  /**
   * Flows the key can see, by identifier.
   *
   * @return string[]
   *   Names of the Flows.
   */
  public function browserFlows(): array {
    try {
      $flows = [];
      foreach ($this->client->listScenarios() as $scenario) {
        if (($scenario['type'] ?? '') === 'browser') {
          $flows[(string) $scenario['id']] = (string) ($scenario['name'] ?? $scenario['id']);
        }
      }
      return $flows;
    }
    catch (PathlyApiException $e) {
      throw ConnectorException::fromApi($e);
    }
  }

  /**
   * Uses an existing Flow for the replay after updates.
   */
  public function attachFlow(string $id): void {
    $this->remember(self::FLOW_ID, $id);
  }

  /**
   * Asks Lumi to prepare a Flow on the site.
   *
   * @return array
   *   What Lumi recognized (siteKind, remaining trials...).
   */
  public function prepareFlow(): array {
    $url = $this->siteUrl() ?? throw new ConnectorException('not_connected');
    try {
      $detect = $this->client->detect($url);
      if (empty($detect['available'])) {
        throw new ConnectorException((string) ($detect['reason'] ?? 'error'), $detect);
      }
      // The same click twice in a minute starts one trial, not two.
      $trial = $this->client->startTrial($url, 'drupal-trial-' . \substr(\hash('sha256', $url . \intdiv(\time(), 60)), 0, 40));
    }
    catch (PathlyApiException $e) {
      throw ConnectorException::fromApi($e);
    }
    $this->state->set(self::TRIAL, [
      'id' => (string) $trial['id'],
      'siteKind' => (string) ($detect['siteKind'] ?? 'other'),
    ]);
    return $detect;
  }

  /**
   * The current Lumi trial, or NULL.
   */
  public function trial(): ?array {
    $trial = $this->state->get(self::TRIAL);
    if (!\is_array($trial)) {
      return NULL;
    }
    try {
      return $this->client->getTrial($trial['id']);
    }
    catch (PathlyApiException $e) {
      if ($e->status !== 404) {
        throw ConnectorException::fromApi($e);
      }
      $this->forgetTrial();
      return NULL;
    }
  }

  /**
   * Forgets the current Lumi trial.
   */
  public function forgetTrial(): void {
    $this->state->delete(self::TRIAL);
  }

  /**
   * Flow to propose first: the cart with Drupal Commerce, else the first one.
   */
  public function preferredIndex(array $proposals): ?int {
    if ($this->moduleHandler->moduleExists('commerce_cart')) {
      foreach ($proposals as $proposal) {
        if (($proposal['kind'] ?? '') === 'cart') {
          return (int) $proposal['index'];
        }
      }
    }
    return isset($proposals[0]['index']) ? (int) $proposals[0]['index'] : NULL;
  }

  /**
   * Saves the Flow chosen among the proposals of Lumi.
   *
   * @return string
   *   Identifier of the Flow.
   */
  public function saveFlow(int $index): string {
    $trial = $this->state->get(self::TRIAL);
    if (!\is_array($trial)) {
      throw new ConnectorException('no_trial');
    }
    try {
      $saved = $this->client->saveTrial($trial['id'], $index, 'drupal-save-' . $trial['id'] . '-' . $index);
      $id = (string) $saved['item']['id'];
    }
    catch (PathlyApiException $e) {
      if ($e->errorCode !== 'already_saved') {
        throw ConnectorException::fromApi($e);
      }
      $id = (string) ($e->body['monitorId'] ?? '');
    }
    $this->forgetTrial();
    $this->remember(self::FLOW_ID, $id);
    return $id;
  }

  /**
   * Runs the Flow (or the Ping, without a Flow) after an update.
   */
  public function replay(string $trigger): bool {
    $id = $this->flowId() ?? $this->pingId();
    if ($id === NULL) {
      return FALSE;
    }
    try {
      $this->client->runScenario($id);
    }
    catch (PathlyApiException $e) {
      $this->logger->warning('The replay after an update (@trigger) failed: HTTP @status @code.', [
        '@trigger' => $trigger,
        '@status' => $e->status,
        '@code' => $e->errorCode,
      ]);
      return FALSE;
    }
    $this->logger->notice('Replay requested in Pathly after an update (@trigger).', ['@trigger' => $trigger]);
    return TRUE;
  }

  /**
   * Publishes the status page of the key, for the badge.
   */
  public function enableBadge(): array {
    try {
      $pages = $this->client->listStatusPages();
      $page = $this->client->createStatusPage(['public' => TRUE]);
    }
    catch (PathlyApiException $e) {
      throw ConnectorException::fromApi($e);
    }
    $this->remember(self::STATUS_PAGE, [
      'id' => (string) $page['id'],
      'url' => (string) $page['url'],
      'badgeUrl' => (string) $page['badgeUrl'],
      // Unpublish later only what the module published itself.
      'byModule' => empty($pages[0]['public']),
    ]);
    return $page;
  }

  /**
   * Turns the badge off, and unpublishes the page if the module published it.
   */
  public function disableBadge(): void {
    $page = $this->statusPage();
    if (!empty($page['byModule'])) {
      try {
        $this->client->patchStatusPage($page['id'], ['public' => FALSE]);
      }
      catch (PathlyApiException $e) {
        throw ConnectorException::fromApi($e);
      }
    }
    $this->state->delete(self::STATUS_PAGE);
    $this->cacheTags->invalidateTags([self::CACHE_TAG]);
  }

  /**
   * Same address for the site and its Ping, whatever the final slash or case.
   */
  public static function normalizeUrl(string $url): string {
    return \strtolower(\rtrim(\trim($url), '/'));
  }

  /**
   * Saves a value and refreshes what the module shows.
   */
  protected function remember(string $key, mixed $value): void {
    $this->state->set($key, $value);
    $this->cacheTags->invalidateTags([self::CACHE_TAG]);
  }

}
