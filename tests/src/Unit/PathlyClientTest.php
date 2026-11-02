<?php

declare(strict_types=1);

namespace Drupal\Tests\pathly\Unit;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Site\Settings;
use Drupal\pathly\Api\PathlyApiException;
use Drupal\pathly\Api\PathlyClient;
use Drupal\pathly\KeyStore;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the HTTP client of the Pathly API, with a simulated API.
 *
 * @group pathly
 */
#[Group('pathly')]
class PathlyClientTest extends UnitTestCase {

  /**
   * Requests sent.
   */
  protected array $history = [];

  /**
   * Builds a client answering with the given responses.
   */
  protected function client(
    array $responses,
    ?string $key = 'sp_saved_key_0123456789abcdef',
    array $settings = [],
    array $info = ['version' => '1.2.3'],
  ): PathlyClient {
    $this->history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));
    $keys = $this->createStub(KeyStore::class);
    $keys->method('get')->willReturn($key);
    $modules = $this->createStub(ModuleExtensionList::class);
    $modules->method('getExtensionInfo')->willReturn($info);
    return new PathlyClient(new Client(['handler' => $stack]), $keys, new Settings($settings), $modules);
  }

  /**
   * JSON response.
   */
  protected static function json(int $status, array $body): Response {
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
  }

  /**
   * Request number $i.
   */
  protected function sent(int $i = 0): Request {
    return $this->history[$i]['request'];
  }

  /**
   * Only api.pathlyhq.com, or a loopback address set in settings.php.
   */
  public function testBaseUrl(): void {
    $this->assertSame(PathlyClient::BASE_URL, $this->client([])->baseUrl());
    $this->assertSame('http://127.0.0.1:8099', $this->client([], NULL, [
      'pathly_api_base_url' => 'http://127.0.0.1:8099',
    ])->baseUrl());
    $this->assertSame('http://localhost', $this->client([], NULL, ['pathly_api_base_url' => 'http://localhost'])->baseUrl());
    $this->assertSame(PathlyClient::BASE_URL, $this->client([], NULL, ['pathly_api_base_url' => 'https://evil.example'])->baseUrl());
    $this->assertSame(PathlyClient::BASE_URL, $this->client([], NULL, [
      'pathly_api_base_url' => 'http://localhost.evil.example',
    ])->baseUrl());
  }

  /**
   * The key goes in the Authorization header, the versions in User-Agent.
   */
  public function testAccountHeaders(): void {
    $client = $this->client([self::json(200, ['plan' => ['name' => 'Solo']]), self::json(200, [])]);
    $this->assertSame(['plan' => ['name' => 'Solo']], $client->account());
    $request = $this->sent();
    $this->assertSame('GET', $request->getMethod());
    $this->assertSame('https://api.pathlyhq.com/v1/account', (string) $request->getUri());
    $this->assertSame('Bearer sp_saved_key_0123456789abcdef', $request->getHeaderLine('Authorization'));
    $this->assertSame('pathly-drupal/1.2.3 Drupal/' . \Drupal::VERSION, $request->getHeaderLine('User-Agent'));
    $this->assertFalse($request->hasHeader('Idempotency-Key'));
    $this->assertSame('', (string) $request->getBody());

    $client->account('sp_other_key_0123456789abcdef');
    $this->assertSame('Bearer sp_other_key_0123456789abcdef', $this->sent(1)->getHeaderLine('Authorization'));
  }

  /**
   * A copy of the module from git has no version.
   */
  public function testDevVersion(): void {
    $this->assertSame('dev', $this->client([], NULL, [], [])->moduleVersion());
  }

  /**
   * Without a key, nothing is sent.
   */
  public function testNoKey(): void {
    $client = $this->client([], NULL);
    try {
      $client->account();
      $this->fail('No exception');
    }
    catch (PathlyApiException $e) {
      $this->assertSame(401, $e->status);
      $this->assertSame('no_key', $e->errorCode);
    }
    $this->assertCount(0, $this->history);
  }

  /**
   * Scenarios are read page after page.
   */
  public function testListScenariosPages(): void {
    $client = $this->client([
      self::json(200, ['items' => [['id' => 'a']], 'nextCursor' => 'c/1']),
      self::json(200, ['nextCursor' => NULL]),
    ]);
    $this->assertSame([['id' => 'a']], $client->listScenarios());
    $this->assertSame('/v1/scenarios?limit=500', $this->sent(0)->getRequestTarget());
    $this->assertSame('/v1/scenarios?limit=500&cursor=c%2F1', $this->sent(1)->getRequestTarget());
  }

  /**
   * No more than ten pages.
   */
  public function testListScenariosStopsAfterTenPages(): void {
    $responses = array_fill(0, 11, self::json(200, ['items' => [['id' => 'x']], 'nextCursor' => 'more']));
    $this->assertCount(10, $this->client($responses)->listScenarios());
    $this->assertCount(10, $this->history);
  }

  /**
   * Every route of the contract.
   */
  public function testRoutes(): void {
    $id = '0b5c2f1e-1111-4222-8333-944455556666';
    $client = $this->client(array_fill(0, 16, self::json(200, ['items' => [['id' => 'r']], 'ok' => TRUE])));
    $client->getScenario($id);
    $client->createScenario(['name' => 'Ping'], 'idem-key-1');
    $client->runScenario($id);
    $this->assertSame([['id' => 'r']], $client->listRuns($id, 5));
    $client->getRun($id);
    $client->detect('https://shop.example');
    $client->startTrial('https://shop.example', 'idem-key-2');
    $client->getTrial($id);
    $client->saveTrial($id, 2, 'idem-key-3');
    $this->assertSame([['id' => 'r']], $client->listStatusPages());
    $client->createStatusPage(['public' => TRUE]);
    $client->patchStatusPage($id, ['public' => FALSE]);

    $expected = [
      ['GET', "/v1/scenarios/$id", ''],
      ['POST', '/v1/scenarios', '{"name":"Ping"}'],
      ['POST', "/v1/scenarios/$id/run", '{}'],
      ['GET', "/v1/runs?scenarioId=$id&limit=5", ''],
      ['GET', "/v1/runs/$id", ''],
      ['POST', '/v1/flow-assistant/detect', '{"url":"https:\/\/shop.example"}'],
      ['POST', '/v1/flow-assistant/trials', '{"url":"https:\/\/shop.example","confirmOwner":true}'],
      ['GET', "/v1/flow-assistant/trials/$id", ''],
      ['POST', "/v1/flow-assistant/trials/$id/save", '{"index":2}'],
      ['GET', '/v1/status-pages', ''],
      ['POST', '/v1/status-pages', '{"public":true}'],
      ['PATCH', "/v1/status-pages/$id", '{"public":false}'],
    ];
    foreach ($expected as $i => [$method, $target, $body]) {
      $this->assertSame($method, $this->sent($i)->getMethod());
      $this->assertSame($target, $this->sent($i)->getRequestTarget());
      $this->assertSame($body, (string) $this->sent($i)->getBody());
    }
    $this->assertSame('idem-key-1', $this->sent(1)->getHeaderLine('Idempotency-Key'));
    $this->assertSame('idem-key-2', $this->sent(6)->getHeaderLine('Idempotency-Key'));
    $this->assertSame('idem-key-3', $this->sent(8)->getHeaderLine('Idempotency-Key'));
  }

  /**
   * Lists without items give empty arrays.
   */
  public function testEmptyLists(): void {
    $client = $this->client([self::json(200, []), self::json(200, [])]);
    $this->assertSame([], $client->listRuns('0b5c2f1e-1111-4222-8333-944455556666'));
    $this->assertSame([], $client->listStatusPages());
  }

  /**
   * Refusals keep the status, the code and the body.
   */
  public function testErrors(): void {
    $client = $this->client([
      self::json(403, ['error' => 'Quota', 'code' => 'assistant_quota', 'limit' => 2]),
      new Response(502, [], '<html>'),
      new ConnectException('down', new Request('GET', '/')),
    ]);
    try {
      $client->detect('https://shop.example');
      $this->fail('No exception');
    }
    catch (PathlyApiException $e) {
      $this->assertSame([403, 'assistant_quota', 'Quota', 2], [
        $e->status,
        $e->errorCode,
        $e->getMessage(),
        $e->body['limit'],
      ]);
    }
    try {
      $client->account();
      $this->fail('No exception');
    }
    catch (PathlyApiException $e) {
      $this->assertSame([502, '', 'HTTP 502'], [$e->status, $e->errorCode, $e->getMessage()]);
    }
    try {
      $client->account();
      $this->fail('No exception');
    }
    catch (PathlyApiException $e) {
      $this->assertSame([0, 'network'], [$e->status, $e->errorCode]);
      $this->assertStringNotContainsString('sp_', $e->getMessage());
    }
  }

  /**
   * An identifier never changes the path.
   */
  public function testBadIdentifier(): void {
    $client = $this->client([]);
    $this->expectException(PathlyApiException::class);
    $this->expectExceptionMessage('Invalid identifier.');
    $client->runScenario('../../v1/account');
  }

}
