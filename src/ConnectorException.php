<?php

declare(strict_types=1);

namespace Drupal\pathly;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\pathly\Api\PathlyApiException;

/**
 * Something the administrator must know: a refusal of Pathly, in plain words.
 */
class ConnectorException extends \RuntimeException {

  /**
   * Builds the exception.
   *
   * @param string $reason
   *   Short reason: invalid_key, quota, off, busy, plan_limit...
   * @param array $details
   *   Body returned by Pathly, if any.
   */
  public function __construct(
    public readonly string $reason,
    public readonly array $details = [],
  ) {
    parent::__construct($reason);
  }

  /**
   * Translates a refusal of the API into a reason.
   */
  public static function fromApi(PathlyApiException $e): self {
    $reason = match (TRUE) {
      $e->status === 0 => 'network',
      $e->status === 401 => 'invalid_key',
      $e->errorCode === 'assistant_off' => 'off',
      $e->errorCode === 'assistant_quota' => 'quota',
      $e->errorCode === 'assistant_busy' => 'busy',
      $e->errorCode === 'PLAN_LIMIT' => 'plan_limit',
      $e->errorCode === 'url_blocked' => 'url_blocked',
      $e->status === 403 => 'forbidden',
      $e->status === 429 => 'rate_limited',
      default => 'error',
    };
    return new self($reason, $e->body);
  }

  /**
   * Message for the administrator.
   */
  public function userMessage(): TranslatableMarkup {
    return match ($this->reason) {
      'network' => new TranslatableMarkup('Pathly could not be reached. Try again in a few minutes.'),
      'invalid_key' => new TranslatableMarkup('Pathly refused this API key: it is wrong, revoked or expired.'),
      'forbidden' => new TranslatableMarkup('This API key lacks a scope. Create a key with the scopes listed under the API key field.'),
      'off' => new TranslatableMarkup('Lumi, the Flow assistant, is paused. Create the Flow in Pathly instead.'),
      'quota' => new TranslatableMarkup('You have used all the Lumi trials of your plan for today. Try again tomorrow, or upgrade your plan in Pathly.'),
      'busy' => new TranslatableMarkup('Lumi is very busy. Try again in a minute.'),
      'plan_limit' => new TranslatableMarkup('Your Pathly plan is full. Upgrade it in Pathly, or remove a Ping or a Flow.'),
      'url_blocked' => new TranslatableMarkup('Pathly cannot open this site address. Check that the site is public.'),
      'unreachable' => new TranslatableMarkup('Lumi could not open the site. Check that it is public.'),
      'rate_limited' => new TranslatableMarkup('Too many calls to Pathly. Try again in a minute.'),
      'not_connected' => new TranslatableMarkup('Connect the site to Pathly first.'),
      'no_trial' => new TranslatableMarkup('Start Lumi first.'),
      default => new TranslatableMarkup('Pathly refused the request. Try again later.'),
    };
  }

}
