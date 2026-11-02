<?php

declare(strict_types=1);

namespace Drupal\Tests\pathly\Unit;

use Drupal\pathly\Api\PathlyApiException;
use Drupal\pathly\ConnectorException;
use Drupal\pathly\Links;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the reasons and messages shown to the administrator, and the links.
 *
 * @group pathly
 */
#[Group('pathly')]
class ConnectorExceptionTest extends UnitTestCase {

  /**
   * Each refusal of the API gets its reason.
   */
  public function testFromApi(): void {
    $cases = [
      [0, 'network', 'network'],
      [401, '', 'invalid_key'],
      [403, 'assistant_off', 'off'],
      [403, 'assistant_quota', 'quota'],
      [429, 'assistant_busy', 'busy'],
      [403, 'PLAN_LIMIT', 'plan_limit'],
      [400, 'url_blocked', 'url_blocked'],
      [403, '', 'forbidden'],
      [429, '', 'rate_limited'],
      [500, '', 'error'],
    ];
    foreach ($cases as [$status, $code, $reason]) {
      $e = ConnectorException::fromApi(new PathlyApiException($status, $code, 'x', ['k' => 1]));
      $this->assertSame($reason, $e->reason);
      $this->assertSame(['k' => 1], $e->details);
      $this->assertSame($reason, $e->getMessage());
    }
  }

  /**
   * Each reason has its own message.
   */
  public function testMessages(): void {
    $reasons = [
      'network',
      'invalid_key',
      'forbidden',
      'off',
      'quota',
      'busy',
      'plan_limit',
      'url_blocked',
      'unreachable',
      'rate_limited',
      'not_connected',
      'no_trial',
      'other',
    ];
    $messages = [];
    foreach ($reasons as $reason) {
      $messages[] = (new ConnectorException($reason))->userMessage()->getUntranslatedString();
    }
    $this->assertCount(count($reasons), array_unique($messages));
  }

  /**
   * Sign-up link in the language of the page, marked "drupal".
   */
  public function testSignupLink(): void {
    $this->assertSame('https://pathlyhq.com/fr/login?mode=signup&ref=drupal&utm_source=drupal&utm_medium=module', Links::signup('fr'));
    $this->assertSame('https://pathlyhq.com/de/login?mode=signup&ref=drupal&utm_source=drupal&utm_medium=module', Links::signup('de'));
    $this->assertSame('https://pathlyhq.com/en/login?mode=signup&ref=drupal&utm_source=drupal&utm_medium=module', Links::signup('it'));
  }

}
