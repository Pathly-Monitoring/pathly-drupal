<?php

declare(strict_types=1);

namespace Drupal\Tests\pathly\Kernel;

use Drupal\pathly\Connector;
use Drupal\pathly\EventSubscriber\PackageManagerSubscriber;
use Drupal\pathly\UpdateWatcher;
use Drupal\pathly_test\FakeApi;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Tests the replay of the Flow after an update, and the hooks.
 *
 * @group pathly
 * @runTestsInSeparateProcesses
 */
#[Group('pathly')]
#[RunTestsInSeparateProcesses]
class UpdateWatcherTest extends PathlyKernelTestBase {

  /**
   * Route of the replay of the Flow.
   */
  protected const RUN_ROUTE = 'POST /v1/scenarios/' . self::FLOW . '/run';

  /**
   * The watcher.
   */
  protected function watcher(): UpdateWatcher {
    return $this->container->get('pathly.update_watcher');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    FakeApi::reset([self::RUN_ROUTE => [200, ['ok' => TRUE, 'queued' => TRUE]]]);
  }

  /**
   * Pretends the versions changed since the last check.
   */
  protected function versionsChanged(): void {
    $this->container->get('state')->set(UpdateWatcher::FINGERPRINT, 'old');
  }

  /**
   * The fingerprint follows the installed versions.
   */
  public function testFingerprint(): void {
    $before = $this->watcher()->fingerprint();
    $this->assertSame($before, $this->watcher()->fingerprint());
    $this->container->get('module_installer')->install(['dblog']);
    $this->assertNotSame($before, $this->watcher()->fingerprint());
  }

  /**
   * The Flow is replayed once the versions change, at most every ten minutes.
   */
  public function testCheck(): void {
    $state = $this->container->get('state');
    $state->delete(UpdateWatcher::FINGERPRINT);
    $this->connected(TRUE);
    // First check: the versions are only remembered.
    $this->assertFalse($this->watcher()->check('test'));
    $this->assertNotNull($state->get(UpdateWatcher::FINGERPRINT));
    // Same versions: nothing.
    $this->assertFalse($this->watcher()->check('test'));

    $this->versionsChanged();
    $this->assertTrue($this->watcher()->check('test'));
    $this->assertCount(1, FakeApi::calls(self::RUN_ROUTE));

    // Within ten minutes: no second replay.
    $this->versionsChanged();
    $this->assertFalse($this->watcher()->check('test'));
    $this->assertCount(1, FakeApi::calls(self::RUN_ROUTE));

    $state->set(UpdateWatcher::LAST_REPLAY, 0);
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
   * Installing a module, rebuilding the caches and cron trigger the check.
   */
  public function testHooks(): void {
    $this->connected(TRUE);

    $this->versionsChanged();
    $this->container->get('module_installer')->install(['dblog']);
    $this->assertCount(1, FakeApi::calls(self::RUN_ROUTE));
    // The container was rebuilt with the module: take its State service.
    $state = $this->container->get('state');

    $state->set(UpdateWatcher::LAST_REPLAY, 0);
    $this->versionsChanged();
    pathly_rebuild();
    $this->assertCount(2, FakeApi::calls(self::RUN_ROUTE));

    $state->set(UpdateWatcher::LAST_REPLAY, 0);
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
    $this->container->get('event_dispatcher')->dispatch(new Event(), PackageManagerSubscriber::POST_APPLY);
    $this->assertCount(1, FakeApi::calls(self::RUN_ROUTE));
  }

  /**
   * Help, theme, uninstall: the State keys are removed.
   */
  public function testHelpAndUninstall(): void {
    $this->installSchema('user', ['users_data']);
    $route = $this->container->get('current_route_match');
    $this->assertNull(pathly_help('other.page', $route));
    $this->assertStringContainsString('Ping', pathly_help('help.page.pathly', $route));
    $this->assertArrayHasKey('pathly_status', pathly_theme());

    $this->connected(TRUE);
    $state = $this->container->get('state');
    $state->set(Connector::STATUS_PAGE, ['id' => self::PAGE, 'byModule' => TRUE]);
    FakeApi::reset(['PATCH /v1/status-pages/' . self::PAGE => [[0, []], [200, []]]]);

    // Another module: nothing.
    pathly_module_preuninstall('dblog');
    // Pathly cannot be reached: the uninstall goes on.
    pathly_module_preuninstall('pathly');
    $this->assertCount(1, FakeApi::$log);

    $state->set(Connector::STATUS_PAGE, ['id' => self::PAGE, 'byModule' => TRUE]);
    $this->container->get('module_installer')->uninstall(['pathly']);
    $this->assertCount(2, FakeApi::calls('PATCH /v1/status-pages/' . self::PAGE));
    foreach (Connector::STATE_KEYS as $key) {
      $this->assertNull($this->container->get('state')->get($key), $key);
    }
    $this->assertNull($this->container->get('config.factory')->get('pathly.settings')->get('badge_enabled'));
  }

}
