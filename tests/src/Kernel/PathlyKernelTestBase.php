<?php

declare(strict_types=1);

namespace Drupal\Tests\pathly\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\pathly\Connector;
use Drupal\pathly_test\FakeApi;

/**
 * Drupal with the module, talking to the fake Pathly API.
 */
abstract class PathlyKernelTestBase extends KernelTestBase {

  /**
   * A key in the format of Pathly.
   */
  protected const KEY = 'sp_test_key_0123456789abcdefABCDEF';

  /**
   * Identifiers used by the fake API.
   */
  protected const PING = '11111111-1111-4111-8111-111111111111';
  protected const FLOW = '22222222-2222-4222-8222-222222222222';
  protected const TRIAL = '33333333-3333-4333-8333-333333333333';
  protected const PAGE = '44444444-4444-4444-8444-444444444444';
  protected const RUN = '55555555-5555-4555-8555-555555555555';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'block', 'pathly', 'pathly_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'pathly']);
    $this->container->get('router.builder')->rebuild();
    FakeApi::reset();
  }

  /**
   * Puts a key, a Ping and the site address in State, as after connecting.
   */
  protected function connected(bool $withFlow = FALSE): void {
    $state = $this->container->get('state');
    $state->set('pathly.api_key', self::KEY);
    $state->set(Connector::PING_ID, self::PING);
    $state->set(Connector::SITE_URL, 'http://localhost');
    if ($withFlow) {
      $state->set(Connector::FLOW_ID, self::FLOW);
    }
  }

  /**
   * The connector.
   */
  protected function connector(): Connector {
    return $this->container->get('pathly.connector');
  }

  /**
   * Messages of a type shown to the administrator.
   */
  protected function messages(string $type): array {
    return array_map('strval', $this->container->get('messenger')->messagesByType($type));
  }

}
