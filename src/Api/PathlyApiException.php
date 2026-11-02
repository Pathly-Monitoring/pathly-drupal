<?php

declare(strict_types=1);

namespace Drupal\pathly\Api;

/**
 * Refusal or failure of a call to the Pathly API.
 *
 * The message never contains the API key: only the HTTP status, the error
 * code returned by Pathly and its readable message.
 */
class PathlyApiException extends \RuntimeException {

  /**
   * Builds the exception.
   *
   * @param int $status
   *   HTTP status, or 0 when Pathly could not be reached.
   * @param string $errorCode
   *   Code returned by Pathly (assistant_quota, PLAN_LIMIT...), or ''.
   * @param string $message
   *   Readable message.
   * @param array $body
   *   Decoded response body.
   */
  public function __construct(
    public readonly int $status,
    public readonly string $errorCode,
    string $message,
    public readonly array $body = [],
  ) {
    parent::__construct($message, $status);
  }

}
