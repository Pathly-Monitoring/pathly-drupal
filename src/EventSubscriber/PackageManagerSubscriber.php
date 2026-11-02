<?php

declare(strict_types=1);

namespace Drupal\pathly\EventSubscriber;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\pathly\UpdateWatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Replays the Flow when Package Manager has applied an update.
 *
 * Package Manager (used by Automatic Updates and Project Browser) is
 * optional: the event is named by its class, so nothing is loaded when it
 * is not installed.
 */
class PackageManagerSubscriber implements EventSubscriberInterface {

  /**
   * Event sent once Package Manager has copied the new code into the site.
   */
  public const POST_APPLY = 'Drupal\package_manager\Event\PostApplyEvent';

  /**
   * Builds the subscriber.
   */
  public function __construct(
    protected UpdateWatcher $watcher,
    protected ModuleExtensionList $modules,
    protected ThemeExtensionList $themes,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [self::POST_APPLY => ['onPostApply', -1000]];
  }

  /**
   * Reads the new versions from the disk, then compares them.
   */
  public function onPostApply(): void {
    $this->modules->reset();
    $this->themes->reset();
    $this->watcher->check('package_manager');
  }

}
