<?php

declare(strict_types=1);

namespace Drupal\Tests\pathly\Unit;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\pathly\Connector;
use Drupal\pathly\EventSubscriber\PackageManagerSubscriber;
use Drupal\pathly\UpdateWatcher;
use Drupal\pathly_test\FakeApi;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the replay of the Flow after an update, and the hooks.
 *
 * @group pathly
 */
#[Group('pathly')]
class UpdateWatcherTest extends PathlyUnitTestBase {

  /**
   * Route of the replay of the Flow.
   */
  protected const RUN_ROUTE = 'POST /v1/scenarios/' . self::FLOW . '/run';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    FakeApi::reset([self::RUN_ROUTE => [200, ['ok' => TRUE, 'queued' => TRUE]]]);
    if (!\function_exists('t')) {
      require_once $this->root . '/core/includes/bootstrap.inc';
    }
    require_once __DIR__ . '/../../../pathly.module';
    require_once __DIR__ . '/../../../pathly.install';
  }

  /**
   * The watcher.
   */
  protected function watcher(): UpdateWatcher {
    return $this->container->get('pathly.update_watcher');
  }

  /**
   * Pretends the versions changed since the last check.
   */
  protected function versionsChanged(): void {
    $this->container->get('state')->set(UpdateWatcher::FINGERPRINT, 'old');
    $this->now += UpdateWatcher::MIN_INTERVAL;
  }

  /**
   * The fingerprint follows the installed versions.
   */
  public function testFingerprint(): void {
    $before = $this->watcher()->fingerprint();
    $this->assertSame($before, $this->watcher()->fingerprint());
    $this->installedModules['token'] = ['version' => '8.x-1.15'];
    $after = $this->watcher()->fingerprint();
    $this->assertNotSame($before, $after);
    $this->installedModules['token'] = ['version' => '8.x-1.16'];
    $this->assertNotSame($after, $this->watcher()->fingerprint());
  }

  /**
   * The Flow is replayed once the versions change, at most every ten minutes.
   */
  public function testCheck(): void {
    $state = $this->container->get('state');
    $this->connected(TRUE);
    $this->assertFalse($this->watcher()->check('test'));
    $this->assertNotNull($state->get(UpdateWatcher::FINGERPRINT));
    $this->assertFalse($this->watcher()->check('test'));

    $this->versionsChanged();
    $this->assertTrue($this->watcher()->check('test'));
    $this->assertSame($this->now, $state->get(UpdateWatcher::LAST_REPLAY));
    $this->assertCount(1, FakeApi::calls(self::RUN_ROUTE));
    $this->assertSame('notice', $this->logged[0][0]);

    // Within ten minutes: no second replay, but the versions are remembered.
    $state->set(UpdateWatcher::FINGERPRINT, 'old');
    $this->now += UpdateWatcher::MIN_INTERVAL - 1;
    $this->assertFalse($this->watcher()->check('test'));
    $this->assertNotSame('old', $state->get(UpdateWatcher::FINGERPRINT));
    $this->assertCount(1, FakeApi::calls(self::RUN_ROUTE));

    $this->versionsChanged();
    $this->assertTrue($this->watcher()->check('test'));
    $this->assertCount(2, FakeApi::calls(self::RUN_ROUTE));
  }

  /**
   * No replay when it is turned off, or without a key.
   */
  public function testNoReplay(): void {
    $this->versionsChanged();
    $this->assertFalse($this->watcher()->check('test'));

    $this->connected(TRUE);
    $this->config('pathly.settings')->set('replay_after_updates', FALSE)->save();
    $this->versionsChanged();
    $this->assertFalse($this->watcher()->check('test'));
    $this->assertSame([], FakeApi::$log);
  }

  /**
   * A refused replay is logged, without the key.
   */
  public function testReplayRefused(): void {
    $this->connected(TRUE);
    FakeApi::reset([self::RUN_ROUTE => [403, ['code' => 'PLAN_LIMIT']]]);
    $this->versionsChanged();
    $this->assertFalse($this->watcher()->check('test'));
    $this->assertSame('warning', $this->logged[0][0]);
    $this->assertSame(['@trigger' => 'test', '@status' => 403, '@code' => 'PLAN_LIMIT'], $this->logged[0][2]);
    $this->assertStringNotContainsString(self::KEY, serialize($this->logged));
  }

  /**
   * Installing a module, rebuilding the caches and cron trigger the check.
   */
  public function testHooks(): void {
    $this->connected(TRUE);
    $this->versionsChanged();
    pathly_modules_installed(['token'], FALSE);
    $this->assertCount(1, FakeApi::calls(self::RUN_ROUTE));
    $this->versionsChanged();
    pathly_rebuild();
    $this->assertCount(2, FakeApi::calls(self::RUN_ROUTE));
    $this->versionsChanged();
    pathly_cron();
    $this->assertCount(3, FakeApi::calls(self::RUN_ROUTE));
  }

  /**
   * Package Manager (Automatic Updates, Project Browser) triggers the check.
   */
  public function testPackageManager(): void {
    $this->assertSame([PackageManagerSubscriber::POST_APPLY => ['onPostApply', -1000]], PackageManagerSubscriber::getSubscribedEvents());
    $this->connected(TRUE);
    $this->versionsChanged();
    $this->container->get('pathly.package_manager_subscriber')->onPostApply();
    $this->assertCount(1, FakeApi::calls(self::RUN_ROUTE));
  }

  /**
   * Help and theme.
   */
  public function testHelpAndTheme(): void {
    $route = $this->createStub(RouteMatchInterface::class);
    $this->assertNull(pathly_help('other.page', $route));
    $this->assertStringContainsString('Ping', (string) pathly_help('help.page.pathly', $route));
    $this->assertArrayHasKey('pathly_status', pathly_theme());
  }

  /**
   * Uninstall unpublishes the page of the module and empties State.
   *
   * An unreachable Pathly does not block the uninstall.
   */
  public function testUninstall(): void {
    $this->connected(TRUE);
    $state = $this->container->get('state');
    $state->set(Connector::STATUS_PAGE, ['id' => self::PAGE, 'byModule' => TRUE]);
    $state->set(UpdateWatcher::FINGERPRINT, 'x');
    FakeApi::reset(['PATCH /v1/status-pages/' . self::PAGE => [[0, []], [200, []]]]);

    pathly_module_preuninstall('token');
    $this->assertSame([], FakeApi::$log);
    pathly_module_preuninstall('pathly');
    $this->assertCount(1, FakeApi::$log);

    $state->set(Connector::STATUS_PAGE, ['id' => self::PAGE, 'byModule' => TRUE]);
    pathly_module_preuninstall('pathly');
    $this->assertCount(2, FakeApi::$log);

    $this->container->get('cache.default')->set('pathly:status', ['x']);
    pathly_uninstall(FALSE);
    foreach (Connector::STATE_KEYS as $key) {
      $this->assertNull($state->get($key), $key);
    }
    $this->assertFalse($this->container->get('cache.default')->get('pathly:status'));
  }

}
