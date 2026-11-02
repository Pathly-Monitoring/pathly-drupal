<?php

declare(strict_types=1);

namespace Drupal\Tests\pathly\Unit;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\pathly\Connector;
use Drupal\pathly\ConnectorException;
use Drupal\pathly\KeyStore;
use Drupal\pathly_test\FakeApi;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the link between the site and Pathly.
 *
 * @group pathly
 */
#[Group('pathly')]
class ConnectorTest extends PathlyUnitTestBase {

  /**
   * The key lives in State, or in settings.php.
   */
  public function testKeyStore(): void {
    $keys = $this->container->get('pathly.key_store');
    $this->assertNull($keys->get());
    $this->assertFalse($this->connector()->isConnected());
    $keys->set(self::KEY);
    $this->assertSame(self::KEY, $keys->get());
    $this->assertFalse($keys->isOverridden());
    $keys->delete();
    $this->assertNull($keys->get());
    $this->assertTrue(KeyStore::isValidFormat(self::KEY));
    $this->assertFalse(KeyStore::isValidFormat('sp_short'));
    $this->assertFalse(KeyStore::isValidFormat('pk_test_key_0123456789abcdefABCDEF'));
  }

  /**
   * A key in settings.php wins.
   */
  public function testKeyInSettings(): void {
    $this->setSetting('pathly_api_key', 'sp_from_settings_0123456789abcdef');
    $keys = new KeyStore($this->container->get('state'), $this->container->get('settings'));
    $keys->set(self::KEY);
    $this->assertTrue($keys->isOverridden());
    $this->assertSame('sp_from_settings_0123456789abcdef', $keys->get());
  }

  /**
   * Nothing goes to Pathly before a key is saved.
   */
  public function testNothingSentWithoutKey(): void {
    $this->assertFalse($this->connector()->replay('test'));
    $this->container->get('pathly.update_watcher')->check('test');
    $this->assertSame([], FakeApi::$log);
  }

  /**
   * The key is checked with /v1/account.
   */
  public function testCheckKey(): void {
    FakeApi::reset(['GET /v1/account' => [[200, ['plan' => ['name' => 'Solo']]], [401, ['error' => 'no']]]]);
    $this->assertSame('Solo', $this->connector()->checkKey(self::KEY)['plan']['name']);
    $this->assertSame('Bearer ' . self::KEY, FakeApi::$log[0]['headers']['Authorization']);
    try {
      $this->connector()->checkKey(self::KEY);
      $this->fail('No exception');
    }
    catch (ConnectorException $e) {
      $this->assertSame('invalid_key', $e->reason);
    }
  }

  /**
   * An existing Ping on the same address is reused.
   */
  public function testConnectAttachesExistingPing(): void {
    FakeApi::reset([
      'GET /v1/scenarios' => [200, [
        'items' => [
      ['id' => 'x', 'type' => 'browser', 'url' => 'https://shop.example'],
      ['id' => 'y', 'type' => 'http'],
      ['id' => self::PING, 'type' => 'http', 'url' => 'https://Shop.example/'],
        ],
      ],
      ],
    ]);
    $this->assertSame('attached', $this->connector()->connect(self::KEY, 'https://shop.example/'));
    $this->assertTrue($this->connector()->isConnected());
    $this->assertSame(self::PING, $this->connector()->pingId());
    $this->assertSame('https://shop.example', $this->connector()->siteUrl());
    $this->assertCount(0, FakeApi::calls('POST /v1/scenarios'));
  }

  /**
   * Otherwise a Ping is created, once.
   */
  public function testConnectCreatesPing(): void {
    FakeApi::reset([
      'GET /v1/scenarios' => [200, ['items' => []]],
      'POST /v1/scenarios' => [201, ['id' => self::PING]],
    ]);
    $this->assertSame('created', $this->connector()->connect(self::KEY, 'https://Shop.example/'));
    $call = FakeApi::calls('POST /v1/scenarios')[0];
    $this->assertSame([
      'name' => 'Ping · Shop.example',
      'type' => 'http',
      'url' => 'https://Shop.example',
      'intervalSec' => 300,
    ], $call['body']);
    $this->assertStringStartsWith('drupal-ping-', $call['headers']['Idempotency-Key']);
    $this->assertSame(self::PING, $this->connector()->pingId());
  }

  /**
   * A full plan is reported.
   */
  public function testConnectPlanLimit(): void {
    FakeApi::reset([
      'GET /v1/scenarios' => [200, ['items' => []]],
      'POST /v1/scenarios' => [403, ['error' => 'Full', 'code' => 'PLAN_LIMIT']],
    ]);
    $this->expectException(ConnectorException::class);
    $this->expectExceptionMessage('plan_limit');
    $this->connector()->connect(self::KEY, 'https://shop.example');
  }

  /**
   * Disconnecting forgets everything locally.
   */
  public function testDisconnect(): void {
    $this->connected(TRUE);
    $this->connector()->disconnect();
    $this->assertFalse($this->connector()->isConnected());
    $this->assertNull($this->connector()->pingId());
    $this->assertNull($this->connector()->flowId());
    $this->assertSame([], FakeApi::$log);
  }

  /**
   * Flows of the account, and attaching one.
   */
  public function testBrowserFlows(): void {
    $this->connected();
    FakeApi::reset([
      'GET /v1/scenarios' => [[200, [
        'items' => [
      ['id' => self::FLOW, 'type' => 'browser', 'name' => 'Cart'],
      ['id' => 'b2', 'type' => 'browser'],
      ['id' => self::PING, 'type' => 'http'],
      ['id' => 'c', 'name' => 'no type'],
        ],
      ],
      ], [500, []],
      ],
    ]);
    $this->assertSame([self::FLOW => 'Cart', 'b2' => 'b2'], $this->connector()->browserFlows());
    $this->connector()->attachFlow(self::FLOW);
    $this->assertSame(self::FLOW, $this->connector()->flowId());
    $this->expectException(ConnectorException::class);
    $this->connector()->browserFlows();
  }

  /**
   * Lumi needs the site address.
   */
  public function testPrepareFlowNotConnected(): void {
    $this->expectExceptionMessage('not_connected');
    $this->connector()->prepareFlow();
  }

  /**
   * Lumi starts once it says it can.
   */
  public function testPrepareFlow(): void {
    $this->connected();
    FakeApi::reset([
      'POST /v1/flow-assistant/detect' => [
        [200, ['available' => FALSE, 'reason' => 'quota']],
        [200, ['available' => FALSE]],
        [200, ['available' => TRUE, 'siteKind' => 'drupal']],
        [200, ['available' => TRUE]],
      ],
      'POST /v1/flow-assistant/trials' => [
        [202, ['id' => self::TRIAL]],
        [202, ['id' => self::TRIAL]],
        [429, ['code' => 'assistant_busy']],
      ],
    ]);
    foreach (['quota', 'error'] as $reason) {
      try {
        $this->connector()->prepareFlow();
        $this->fail('No exception');
      }
      catch (ConnectorException $e) {
        $this->assertSame($reason, $e->reason);
      }
    }
    $this->assertSame('drupal', $this->connector()->prepareFlow()['siteKind']);
    $this->assertSame(['id' => self::TRIAL, 'siteKind' => 'drupal'], $this->container->get('state')->get(Connector::TRIAL));
    $call = FakeApi::calls('POST /v1/flow-assistant/trials')[0];
    $this->assertSame(['url' => 'http://shop.test', 'confirmOwner' => TRUE], $call['body']);

    $this->connector()->prepareFlow();
    $this->assertSame('other', $this->container->get('state')->get(Connector::TRIAL)['siteKind']);

    $this->expectExceptionMessage('busy');
    $this->connector()->prepareFlow();
  }

  /**
   * The trial is read from Pathly, and forgotten when it is gone.
   */
  public function testTrial(): void {
    $this->connected();
    $this->assertNull($this->connector()->trial());
    $this->container->get('state')->set(Connector::TRIAL, ['id' => self::TRIAL]);
    FakeApi::reset([
      'GET /v1/flow-assistant/trials/' . self::TRIAL => [
        [200, ['item' => ['status' => 'exploring']]],
        [500, []],
        [404, []],
      ],
    ]);
    $this->assertSame('exploring', $this->connector()->trial()['item']['status']);
    try {
      $this->connector()->trial();
      $this->fail('No exception');
    }
    catch (ConnectorException $e) {
      $this->assertSame('error', $e->reason);
    }
    $this->assertNull($this->connector()->trial());
    $this->assertNull($this->container->get('state')->get(Connector::TRIAL));
  }

  /**
   * The cart first with Drupal Commerce, else the first Flow.
   */
  public function testPreferredIndex(): void {
    $proposals = [['index' => 1, 'kind' => 'contact'], ['index' => 3, 'kind' => 'cart']];
    $this->assertSame(1, $this->connector()->preferredIndex($proposals));
    $this->assertNull($this->connector()->preferredIndex([]));

    $modules = $this->createStub(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturnMap([['commerce_cart', TRUE]]);
    $commerce = new Connector(
      $this->container->get('pathly.client'),
      $this->container->get('pathly.key_store'),
      $this->container->get('state'),
      $modules,
      $this->container->get('cache_tags.invalidator'),
      $this->container->get('logger.channel.pathly'),
    );
    $this->assertSame(3, $commerce->preferredIndex($proposals));
    $this->assertSame(1, $commerce->preferredIndex([['index' => 1, 'kind' => 'login'], ['index' => 2]]));
  }

  /**
   * Saving a Flow, even one already saved.
   */
  public function testSaveFlow(): void {
    $this->connected();
    try {
      $this->connector()->saveFlow(0);
      $this->fail('No exception');
    }
    catch (ConnectorException $e) {
      $this->assertSame('no_trial', $e->reason);
    }
    $route = 'POST /v1/flow-assistant/trials/' . self::TRIAL . '/save';
    FakeApi::reset([
      $route => [
      [201, ['item' => ['id' => self::FLOW]]],
      [409, ['code' => 'already_saved', 'monitorId' => 'f2']],
      [409, ['code' => 'already_saved']],
      [403, ['code' => 'PLAN_LIMIT']],
      ],
    ]);
    $state = $this->container->get('state');
    $state->set(Connector::TRIAL, ['id' => self::TRIAL]);
    $this->assertSame(self::FLOW, $this->connector()->saveFlow(2));
    $this->assertSame(['index' => 2], FakeApi::calls($route)[0]['body']);
    $this->assertSame('drupal-save-' . self::TRIAL . '-2', FakeApi::calls($route)[0]['headers']['Idempotency-Key']);
    $this->assertNull($state->get(Connector::TRIAL));

    $state->set(Connector::TRIAL, ['id' => self::TRIAL]);
    $this->assertSame('f2', $this->connector()->saveFlow(0));
    $state->set(Connector::TRIAL, ['id' => self::TRIAL]);
    $this->assertSame('', $this->connector()->saveFlow(0));
    $state->set(Connector::TRIAL, ['id' => self::TRIAL]);
    $this->expectExceptionMessage('plan_limit');
    $this->connector()->saveFlow(0);
  }

  /**
   * The Flow is replayed, or the Ping without a Flow.
   */
  public function testReplay(): void {
    $state = $this->container->get('state');
    $state->set('pathly.api_key', self::KEY);
    $this->assertFalse($this->connector()->replay('test'));
    FakeApi::reset([
      'POST /v1/scenarios/' . self::PING . '/run' => [200, ['ok' => TRUE]],
      'POST /v1/scenarios/' . self::FLOW . '/run' => [[200, ['ok' => TRUE]], [0, []]],
    ]);
    $state->set(Connector::PING_ID, self::PING);
    $this->assertTrue($this->connector()->replay('test'));
    $state->set(Connector::FLOW_ID, self::FLOW);
    $this->assertTrue($this->connector()->replay('test'));
    $this->assertFalse($this->connector()->replay('test'));
    $this->assertSame([
      'POST /v1/scenarios/' . self::PING . '/run',
      'POST /v1/scenarios/' . self::FLOW . '/run',
      'POST /v1/scenarios/' . self::FLOW . '/run',
    ], array_column(FakeApi::$log, 'route'));
  }

  /**
   * The badge publishes the status page, and only unpublishes its own.
   */
  public function testBadge(): void {
    $this->connected();
    $page = [
      'id' => self::PAGE,
      'url' => 'https://pathlyhq.com/status/shop',
      'badgeUrl' => 'https://api.pathlyhq.com/public/status/shop/badge.svg',
      'public' => TRUE,
    ];
    FakeApi::reset([
      'GET /v1/status-pages' => [[200, ['items' => []]], [200, ['items' => [$page]]], [500, []]],
      'POST /v1/status-pages' => [201, $page],
      'PATCH /v1/status-pages/' . self::PAGE => [[200, $page], [0, []]],
    ]);
    $this->connector()->enableBadge();
    $this->assertTrue($this->connector()->statusPage()['byModule']);
    $this->assertSame(['public' => TRUE], FakeApi::calls('POST /v1/status-pages')[0]['body']);
    $this->connector()->disableBadge();
    $this->assertSame(['public' => FALSE], FakeApi::calls('PATCH /v1/status-pages/' . self::PAGE)[0]['body']);
    $this->assertNull($this->connector()->statusPage());

    $this->connector()->enableBadge();
    $this->assertFalse($this->connector()->statusPage()['byModule']);
    $this->connector()->disableBadge();
    $this->assertCount(1, FakeApi::calls('PATCH /v1/status-pages/' . self::PAGE));

    try {
      $this->connector()->enableBadge();
      $this->fail('No exception');
    }
    catch (ConnectorException $e) {
      $this->assertSame('error', $e->reason);
    }

    $this->container->get('state')->set(Connector::STATUS_PAGE, ['id' => self::PAGE, 'byModule' => TRUE]);
    $this->expectExceptionMessage('network');
    $this->connector()->disableBadge();
  }

}
