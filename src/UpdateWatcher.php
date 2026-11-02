<?php

declare(strict_types=1);

namespace Drupal\pathly;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\State\StateInterface;

/**
 * Replays the Flow after an update of Drupal core, a module or a theme.
 *
 * Drupal has no single hook "an update just finished": Composer changes the
 * code, then update.php or "drush updatedb" runs the updates and rebuilds the
 * caches. So the module keeps a fingerprint of the installed versions (core,
 * modules, themes) and compares it at the moments an update shows:
 * - hook_rebuild(), at the end of update.php, "drush updatedb" and "drush cr";
 * - hook_modules_installed(), when a module is installed;
 * - the PostApplyEvent of Package Manager (Automatic Updates, Project Browser);
 * - hook_cron(), as a safety net.
 * A replay runs only when the fingerprint changed, at most every ten minutes.
 */
class UpdateWatcher {

  /**
   * State key of the fingerprint of the installed versions.
   */
  public const FINGERPRINT = 'pathly.fingerprint';

  /**
   * State key of the time of the last replay.
   */
  public const LAST_REPLAY = 'pathly.last_replay';

  /**
   * Minimum time between two replays, in seconds.
   */
  public const MIN_INTERVAL = 600;

  /**
   * Builds the watcher.
   */
  public function __construct(
    protected Connector $connector,
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleExtensionList $modules,
    protected ThemeExtensionList $themes,
    protected TimeInterface $time,
  ) {}

  /**
   * Fingerprint of Drupal core and of the installed modules and themes.
   */
  public function fingerprint(): string {
    $versions = ['drupal' => \Drupal::VERSION];
    foreach ([$this->modules, $this->themes] as $list) {
      foreach ($list->getAllInstalledInfo() as $name => $info) {
        $versions[$name] = (string) ($info['version'] ?? '');
      }
    }
    \ksort($versions);
    return \hash('sha256', \serialize($versions));
  }

  /**
   * Replays the Flow if the versions changed since the last check.
   *
   * @param string $trigger
   *   What called the check, for the log.
   *
   * @return bool
   *   TRUE when a replay was requested.
   */
  public function check(string $trigger): bool {
    $current = $this->fingerprint();
    $previous = $this->state->get(self::FINGERPRINT);
    if ($previous === $current) {
      return FALSE;
    }
    $this->state->set(self::FINGERPRINT, $current);
    // First check, replay turned off, or no key: remember the versions only.
    if ($previous === NULL || !$this->configFactory->get('pathly.settings')->get('replay_after_updates') || !$this->connector->isConnected()) {
      return FALSE;
    }
    $now = $this->time->getCurrentTime();
    if ($now - (int) $this->state->get(self::LAST_REPLAY, 0) < self::MIN_INTERVAL) {
      return FALSE;
    }
    $this->state->set(self::LAST_REPLAY, $now);
    return $this->connector->replay($trigger);
  }

}
