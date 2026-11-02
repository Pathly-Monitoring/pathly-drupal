<?php

declare(strict_types=1);

namespace Drupal\pathly;

/**
 * Links to Pathly. They are only shown to the administrator, never called.
 */
final class Links {

  /**
   * Pathly app.
   */
  public const APP = 'https://pathlyhq.com';

  /**
   * Screen where the administrator creates an API key.
   */
  public const API_KEYS = self::APP . '/settings';

  /**
   * Languages of the Pathly site.
   */
  private const LANGUAGES = ['fr', 'en', 'es', 'de'];

  /**
   * Free sign-up, marked as coming from the Drupal module.
   */
  public static function signup(string $langcode): string {
    $lang = \in_array($langcode, self::LANGUAGES, TRUE) ? $langcode : 'en';
    return self::APP . '/' . $lang . '/login?mode=signup&ref=drupal&utm_source=drupal&utm_medium=module';
  }

}
