<?php

declare(strict_types=1);

namespace Drupal\Tests\pathly\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\pathly\Controller\ReportController;
use Drupal\pathly\StatusReport;
use Drupal\pathly_test\FakeApi;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the status shown in the administration, the blocks and the report.
 *
 * @group pathly
 * @runTestsInSeparateProcesses
 */
#[Group('pathly')]
#[RunTestsInSeparateProcesses]
class StatusReportTest extends PathlyKernelTestBase {

  /**
   * The report.
   */
  protected function report(): StatusReport {
    return $this->container->get('pathly.status_report');
  }

  /**
   * Routes of a healthy account.
   */
  protected function routes(array $extra = []): array {
    return $extra + [
      'GET /v1/account' => [200, [
        'organization' => ['name' => 'Shop'],
        'plan' => ['id' => 'solo', 'name' => 'Solo', 'priceMonthlyEur' => 0],
        'quotas' => [
          'ping' => ['used' => 1, 'limit' => 5, 'remaining' => 4],
          'flow' => ['used' => 1, 'limit' => NULL, 'remaining' => NULL],
          'assistantTrials' => ['used' => 0, 'limit' => 2, 'remaining' => 2],
        ],
        'billingUrl' => 'https://pathlyhq.com/billing',
      ],
      ],
      'GET /v1/scenarios/' . self::PING => [200, ['id' => self::PING, 'name' => 'Ping · shop', 'lastStatus' => 'ok']],
      'GET /v1/scenarios/' . self::FLOW => [200, ['id' => self::FLOW, 'name' => 'Cart', 'lastStatus' => 'fail']],
      'GET /v1/runs' => [[200, [
        'items' => [
          ['id' => 'f-ok', 'status' => 'ok', 'created_at' => '2026-11-02T10:05:00Z'],
          ['id' => self::RUN, 'status' => 'fail', 'created_at' => '2026-11-02T10:00:00Z'],
        ],
      ],
      ], [200, [
        'items' => [
          ['id' => 'p-old', 'status' => 'error', 'created_at' => '2026-11-01T08:00:00Z'],
          ['id' => 'p-older', 'status' => 'fail', 'created_at' => '2026-10-01T08:00:00Z'],
        ],
      ],
      ],
      ],
      'GET /v1/runs/' . self::RUN => [
        200,
        [
          'id' => self::RUN,
          'status' => 'fail',
          'created_at' => '2026-11-02T10:00:00Z',
          'monitor_name' => 'Cart',
          'message' => 'Add to cart: not found',
          'screenshotUrl' => 'https://api.pathlyhq.com/public/run-shot/t',
        ],
      ],
    ];
  }

  /**
   * Not connected: nothing is called.
   */
  public function testNotConnected(): void {
    $this->assertSame(['connected' => FALSE], $this->report()->build());
    $this->assertSame([], FakeApi::$log);
  }

  /**
   * Ping, Flow, last failure with its screenshot, plan; then from the cache.
   */
  public function testFullReport(): void {
    $this->connected(TRUE);
    FakeApi::reset($this->routes());
    $data = $this->report()->build();
    $this->assertNull($data['error']);
    $this->assertSame('ok', $data['ping']['lastStatus']);
    $this->assertSame('fail', $data['flow']['lastStatus']);
    $this->assertSame(self::RUN, $data['failure']['id']);
    $this->assertSame('Solo', $data['account']['plan']['name']);
    $this->assertStringContainsString('scenarioId=' . self::FLOW, FakeApi::$log[3]['uri']);
    $calls = count(FakeApi::$log);
    $this->assertSame($data, $this->report()->build());
    $this->assertCount($calls, FakeApi::$log);

    // The rendered report: block, report page, template.
    $build = (new ReportController($this->report()))->page();
    $this->assertSame('pathly_status', $build['#theme']);
    $this->render($build);
    $this->assertText('Add to cart: not found');
    $this->assertRaw('https://api.pathlyhq.com/public/run-shot/t');
    $this->assertText('Solo');
  }

  /**
   * The most recent failure wins, whatever the order.
   */
  public function testLatestFailureFromPing(): void {
    $this->connected(TRUE);
    FakeApi::reset($this->routes([
      'GET /v1/runs' => [
        [200, ['items' => [['id' => 'f1', 'status' => 'fail', 'created_at' => '2026-11-01T00:00:00Z']]]],
        [200, ['items' => [['id' => self::RUN, 'status' => 'fail', 'created_at' => '2026-11-02T00:00:00Z']]]],
      ],
    ]));
    $this->assertSame(self::RUN, $this->report()->build()['failure']['id']);
  }

  /**
   * No Flow, no failure.
   */
  public function testNoFailure(): void {
    $this->connected();
    FakeApi::reset($this->routes([
      'GET /v1/runs' => [
        200,
        ['items' => [['id' => 'x', 'status' => 'ok', 'created_at' => '2026-11-02T00:00:00Z'], ['id' => 'y']]],
      ],
    ]));
    $data = $this->report()->build();
    $this->assertNull($data['flow']);
    $this->assertNull($data['failure']);
    $build = $this->report()->render();
    $this->render($build);
    $this->assertText('Not set up yet.');
  }

  /**
   * A Flow deleted in Pathly is marked, and not read further.
   */
  public function testDeletedFlow(): void {
    $this->connected(TRUE);
    FakeApi::reset($this->routes([
      'GET /v1/scenarios/' . self::FLOW => [404, ['error' => 'gone']],
      'GET /v1/runs' => [200, ['items' => []]],
    ]));
    $data = $this->report()->build();
    $this->assertSame(['id' => self::FLOW, 'missing' => TRUE], $data['flow']);
    $this->assertCount(1, FakeApi::calls('GET /v1/runs'));
    $build = $this->report()->render();
    $this->render($build);
    $this->assertText('Deleted in Pathly.');
  }

  /**
   * An error is shown, and kept one minute.
   */
  public function testError(): void {
    $this->connected(TRUE);
    FakeApi::reset($this->routes(['GET /v1/scenarios/' . self::PING => [500, []]]));
    $data = $this->report()->build();
    $this->assertSame('Pathly refused the request. Try again later.', (string) $data['error']);
    $cache = $this->container->get('cache.default')->get(StatusReport::CID);
    $this->assertLessThanOrEqual(\Drupal::time()->getRequestTime() + StatusReport::ERROR_TTL, $cache->expire);
  }

  /**
   * The status block is for administrators only.
   */
  public function testStatusBlock(): void {
    $block = $this->container->get('plugin.manager.block')->createInstance('pathly_status');
    $admin = $this->createStub(AccountInterface::class);
    $admin->method('hasPermission')->willReturnMap([['administer pathly', TRUE]]);
    $visitor = $this->createStub(AccountInterface::class);
    $visitor->method('hasPermission')->willReturn(FALSE);
    $this->assertTrue($block->access($admin));
    $this->assertFalse($block->access($visitor));
    $this->assertSame(StatusReport::TTL, $block->getCacheMaxAge());
    $build = $block->build();
    $this->render($build);
    $this->assertText('The site is not connected to Pathly yet.');
  }

  /**
   * The badge block shows only once the badge is on.
   */
  public function testBadgeBlock(): void {
    $block = $this->container->get('plugin.manager.block')->createInstance('pathly_badge');
    $visitor = $this->createStub(AccountInterface::class);
    $this->assertFalse($block->access($visitor));
    $this->config('pathly.settings')->set('badge_enabled', TRUE)->save();
    $this->assertFalse($block->access($visitor));
    $this->container->get('state')->set('pathly.status_page', [
      'id' => self::PAGE,
      'url' => 'https://pathlyhq.com/status/shop',
      'badgeUrl' => 'https://api.pathlyhq.com/public/status/shop/badge.svg',
      'byModule' => TRUE,
    ]);
    $this->assertTrue($block->access($visitor));
    $build = $block->build();
    $this->render($build);
    $this->assertRaw('https://api.pathlyhq.com/public/status/shop/badge.svg');
    $this->assertRaw('https://pathlyhq.com/status/shop');
  }

}
