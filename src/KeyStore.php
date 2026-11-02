<?php

declare(strict_types=1);

namespace Drupal\pathly;

use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;

/**
 * Where the Pathly API key lives.
 *
 * The key is kept in the State API: it is not part of the configuration, so
 * it never leaves the site with a configuration export. A host can also set
 * it in settings.php ($settings['pathly_api_key']), for example from an
 * environment variable. The key is never shown after it is saved, and never
 * written to the logs.
 */
class KeyStore {

  /**
   * State key of the API key.
   */
  public const STATE_KEY = 'pathly.api_key';

  /**
   * Builds the store.
   */
  public function __construct(
    protected StateInterface $state,
    protected Settings $settings,
  ) {}

  /**
   * The key, or NULL when none is set.
   */
  public function get(): ?string {
    $key = $this->isOverridden() ? $this->settings->get('pathly_api_key') : $this->state->get(self::STATE_KEY);
    return \is_string($key) && $key !== '' ? $key : NULL;
  }

  /**
   * TRUE when settings.php sets the key.
   */
  public function isOverridden(): bool {
    return (string) $this->settings->get('pathly_api_key', '') !== '';
  }

  /**
   * Saves the key.
   */
  public function set(string $key): void {
    $this->state->set(self::STATE_KEY, $key);
  }

  /**
   * Forgets the saved key.
   */
  public function delete(): void {
    $this->state->delete(self::STATE_KEY);
  }

  /**
   * TRUE when the text looks like a Pathly API key (sp_...).
   */
  public static function isValidFormat(string $key): bool {
    return (bool) \preg_match('/^sp_[A-Za-z0-9_-]{20,200}$/', $key);
  }

}
