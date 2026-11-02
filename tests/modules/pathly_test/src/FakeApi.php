<?php

declare(strict_types=1);

namespace Drupal\pathly_test;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Fake Pathly API: answers from a table of routes and keeps every request.
 *
 * Routes are "METHOD /path" (query string excluded). An answer is
 * [status, body], or a list of them, served in turn (the last one stays).
 * Status 0 simulates a network failure.
 */
final class FakeApi {

  /**
   * Answers by route.
   *
   * @var array<string, array>
   */
  public static array $routes = [];

  /**
   * Requests received: method, path with query, headers, body.
   *
   * @var array<int, array>
   */
  public static array $log = [];

  /**
   * Clears the routes and the log.
   */
  public static function reset(array $routes = []): void {
    self::$routes = $routes;
    self::$log = [];
  }

  /**
   * Guzzle client served by the fake API.
   */
  public static function client(): ClientInterface {
    return new Client(['handler' => HandlerStack::create([self::class, 'handle'])]);
  }

  /**
   * Requests received on a route ("METHOD /path").
   */
  public static function calls(string $route): array {
    return array_values(array_filter(self::$log, fn ($r) => $r['route'] === $route));
  }

  /**
   * Guzzle handler.
   */
  public static function handle(RequestInterface $request, array $options) {
    $path = $request->getUri()->getPath();
    $route = $request->getMethod() . ' ' . $path;
    self::$log[] = [
      'route' => $route,
      'uri' => (string) $request->getUri(),
      'headers' => array_map(fn ($v) => implode(', ', $v), $request->getHeaders()),
      'body' => json_decode((string) $request->getBody(), TRUE),
    ];
    $answer = self::$routes[$route] ?? [404, ['error' => 'Not found']];
    if (isset($answer[0]) && is_array($answer[0])) {
      $next = count($answer) > 1 ? array_shift(self::$routes[$route]) : $answer[0];
      $answer = $next;
    }
    [$status, $body] = $answer + [1 => []];
    if ($status === 0) {
      return Create::rejectionFor(new ConnectException('down', $request));
    }
    $raw = is_string($body) ? $body : json_encode($body);
    return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'], $raw));
  }

}
