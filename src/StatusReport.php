<?php

declare(strict_types=1);

namespace Drupal\pathly;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\pathly\Api\PathlyApiException;
use Drupal\pathly\Api\PathlyClient;

/**
 * What the administration shows: Ping, Flow, last failure, plan and quotas.
 *
 * Read from Pathly at most every five minutes (one minute after an error).
 * The address of the screenshot is signed by Pathly and valid 15 minutes.
 */
class StatusReport {

  /**
   * Cache identifier.
   */
  public const CID = 'pathly:status';

  /**
   * Seconds the report is kept.
   */
  public const TTL = 300;

  /**
   * Seconds an error is kept.
   */
  public const ERROR_TTL = 60;

  /**
   * Builds the report.
   */
  public function __construct(
    protected PathlyClient $client,
    protected Connector $connector,
    protected CacheBackendInterface $cache,
    protected TimeInterface $time,
    protected LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Data of the report.
   */
  public function build(): array {
    if (!$this->connector->isConnected()) {
      return ['connected' => FALSE];
    }
    $cached = $this->cache->get(self::CID);
    if ($cached) {
      return $cached->data;
    }
    try {
      $data = $this->fetch();
      $ttl = self::TTL;
    }
    catch (PathlyApiException $e) {
      $data = ['connected' => TRUE, 'error' => ConnectorException::fromApi($e)->userMessage()->render()];
      $ttl = self::ERROR_TTL;
    }
    $this->cache->set(self::CID, $data, $this->time->getRequestTime() + $ttl, [Connector::CACHE_TAG]);
    return $data;
  }

  /**
   * Render array of the report, for the block and the report page.
   */
  public function render(): array {
    return [
      '#theme' => 'pathly_status',
      '#data' => $this->build(),
      '#settings_url' => Url::fromRoute('pathly.settings')->toString(),
      '#app_url' => Links::APP,
      '#signup_url' => Links::signup($this->languageManager->getCurrentLanguage()->getId()),
      '#attached' => ['library' => ['pathly/admin']],
      '#cache' => ['max-age' => self::TTL, 'tags' => [Connector::CACHE_TAG]],
    ];
  }

  /**
   * Reads everything from Pathly.
   */
  protected function fetch(): array {
    $account = $this->client->account();
    $ping = $this->scenario($this->connector->pingId());
    $flow = $this->scenario($this->connector->flowId());
    return [
      'connected' => TRUE,
      'error' => NULL,
      'siteUrl' => $this->connector->siteUrl(),
      'account' => $account,
      'ping' => $ping,
      'flow' => $flow,
      'failure' => $this->lastFailure([$flow, $ping]),
    ];
  }

  /**
   * One scenario, or a marker when it was deleted in Pathly.
   */
  protected function scenario(?string $id): ?array {
    if ($id === NULL) {
      return NULL;
    }
    try {
      return $this->client->getScenario($id);
    }
    catch (PathlyApiException $e) {
      if ($e->status !== 404) {
        throw $e;
      }
      return ['id' => $id, 'missing' => TRUE];
    }
  }

  /**
   * Most recent failed run of the Flow or the Ping, with its screenshot.
   */
  protected function lastFailure(array $scenarios): ?array {
    $latest = NULL;
    foreach ($scenarios as $scenario) {
      if (!isset($scenario['id']) || !empty($scenario['missing'])) {
        continue;
      }
      foreach ($this->client->listRuns((string) $scenario['id']) as $run) {
        if (!\in_array($run['status'] ?? '', ['fail', 'error'], TRUE)) {
          continue;
        }
        if ($latest === NULL || \strcmp((string) $run['created_at'], (string) $latest['created_at']) > 0) {
          $latest = $run;
        }
        break;
      }
    }
    return $latest === NULL ? NULL : $this->client->getRun((string) $latest['id']);
  }

}
