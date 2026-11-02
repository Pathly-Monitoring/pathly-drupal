<?php

declare(strict_types=1);

namespace Drupal\Tests\pathly\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\pathly\Controller\ReportController;
use Drupal\pathly\Plugin\Block\PathlyBadgeBlock;
use Drupal\pathly\Plugin\Block\PathlyStatusBlock;
use Drupal\pathly\StatusReport;
use Drupal\pathly_test\FakeApi;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the status shown in the administration, the blocks and the report.
 *
 * @group pathly
 */
#[Group('pathly')]
class StatusReportTest extends PathlyUnitTestBase {

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
      'GET /v1/account' => [200, ['plan' => ['id' => 'solo', 'name' => 'Solo'], 'quotas' => []]],
      'GET /v1/scenarios/' . self::PING => [200, ['id' => self::PING, 'name' => 'Ping', 'lastStatus' => 'ok']],
      'GET /v1/scenarios/' . self::FLOW => [200, ['id' => self::FLOW, 'name' => 'Cart', 'lastStatus' => 'fail']],
      'GET /v1/runs' => [
        [
          200,
          [
            'items' => [
              ['id' => 'f-ok', 'status' => 'ok', 'created_at' => '2026-11-02T10:05:00Z'],
              ['id' => self::RUN, 'status' => 'fail', 'created_at' => '2026-11-02T10:00:00Z'],
            ],
          ],
        ],
        [
          200,
          [
            'items' => [
              ['id' => 'p-old', 'status' => 'error', 'created_at' => '2026-11-01T08:00:00Z'],
            ],
          ],
        ],
      ],
      'GET /v1/runs/' . self::RUN => [
        200,
        ['id' => self::RUN, 'status' => 'fail', 'screenshotUrl' => 'https://api.pathlyhq.com/public/run-shot/t'],
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
    $this->assertSame('http://shop.test', $data['siteUrl']);
    $this->assertSame('ok', $data['ping']['lastStatus']);
    $this->assertSame('fail', $data['flow']['lastStatus']);
    $this->assertSame(self::RUN, $data['failure']['id']);
    $this->assertSame('Solo', $data['account']['plan']['name']);
    $calls = \count(FakeApi::$log);
    $this->assertSame($data, $this->report()->build());
    $this->assertCount($calls, FakeApi::$log);
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
      'GET /v1/runs' => [200, ['items' => [['id' => 'x', 'status' => 'ok'], ['id' => 'y']]]],
    ]));
    $data = $this->report()->build();
    $this->assertNull($data['flow']);
    $this->assertNull($data['failure']);
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
  }

  /**
   * An error is shown, and kept one minute.
   */
  public function testError(): void {
    $this->connected(TRUE);
    FakeApi::reset($this->routes(['GET /v1/scenarios/' . self::PING => [500, []]]));
    $data = $this->report()->build();
    $this->assertSame('Pathly refused the request. Try again later.', $data['error']);
    $cache = $this->container->get('cache.default')->get(StatusReport::CID);
    $this->assertSame($this->now + StatusReport::ERROR_TTL, $cache->expire);
  }

  /**
   * Render array for the block and the report page.
   */
  public function testRenderAndController(): void {
    $build = ReportController::create($this->container)->page();
    $this->assertSame('pathly_status', $build['#theme']);
    $this->assertSame(['connected' => FALSE], $build['#data']);
    $this->assertSame('/admin/config/services/pathly', $build['#settings_url']);
    $this->assertStringContainsString('/fr/login?mode=signup&ref=drupal', $build['#signup_url']);
    $this->assertSame([StatusReport::TTL], [$build['#cache']['max-age']]);
  }

  /**
   * The status block is for administrators only.
   */
  public function testStatusBlock(): void {
    $block = PathlyStatusBlock::create($this->container, [], 'pathly_status', [
      'provider' => 'pathly',
      'admin_label' => 'Pathly',
    ]);
    $admin = $this->createStub(AccountInterface::class);
    $admin->method('hasPermission')->willReturn(TRUE);
    $visitor = $this->createStub(AccountInterface::class);
    $visitor->method('hasPermission')->willReturn(FALSE);
    $this->assertTrue($block->access($admin));
    $this->assertFalse($block->access($visitor));
    $this->assertSame(StatusReport::TTL, $block->getCacheMaxAge());
    $this->assertSame('pathly_status', $block->build()['#theme']);
  }

  /**
   * The badge block shows only once the badge is on.
   */
  public function testBadgeBlock(): void {
    $block = PathlyBadgeBlock::create($this->container, [], 'pathly_badge', [
      'provider' => 'pathly',
      'admin_label' => 'Badge',
    ]);
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
    $access = $block->access($visitor, TRUE);
    $this->assertTrue($access->isAllowed());
    $this->assertContains('pathly_status', $access->getCacheTags());
    $build = $block->build();
    $this->assertSame('https://api.pathlyhq.com/public/status/shop/badge.svg', $build['#title']['#uri']);
    $this->assertInstanceOf(Url::class, $build['#url']);
    $this->assertSame('https://pathlyhq.com/status/shop', $build['#url']->getUri());
  }

}
