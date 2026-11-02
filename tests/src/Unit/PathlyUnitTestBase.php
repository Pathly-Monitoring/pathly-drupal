<?php

declare(strict_types=1);

namespace Drupal\Tests\pathly\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\State;
use Drupal\pathly\Api\PathlyClient;
use Drupal\pathly\Connector;
use Drupal\pathly\EventSubscriber\PackageManagerSubscriber;
use Drupal\pathly\KeyStore;
use Drupal\pathly\StatusReport;
use Drupal\pathly\UpdateWatcher;
use Drupal\pathly_test\FakeApi;
use Drupal\Tests\UnitTestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;

/**
 * The module on light Drupal services, talking to the fake Pathly API.
 *
 * Unit tests carry the coverage of lines and branches: Xdebug measures the
 * branches quickly here, while it takes minutes per Kernel test.
 */
abstract class PathlyUnitTestBase extends UnitTestCase {

  /**
   * A key in the format of Pathly.
   */
  protected const KEY = 'sp_test_key_0123456789abcdefABCDEF';

  /**
   * Identifiers used by the fake API.
   */
  protected const PING = '11111111-1111-4111-8111-111111111111';
  protected const FLOW = '22222222-2222-4222-8222-222222222222';
  protected const TRIAL = '33333333-3333-4333-8333-333333333333';
  protected const PAGE = '44444444-4444-4444-8444-444444444444';
  protected const RUN = '55555555-5555-4555-8555-555555555555';

  /**
   * The service container.
   */
  protected ContainerBuilder $container;

  /**
   * Current time, in seconds.
   */
  protected int $now = 1_800_000_000;

  /**
   * Installed modules and their version, as the extension list gives them.
   */
  protected array $installedModules = ['system' => ['version' => '11.0.0'], 'pathly' => []];

  /**
   * Modules reported as present by the module handler.
   */
  protected array $presentModules = [];

  /**
   * Messages written to the log of the module.
   */
  public array $logged = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    FakeApi::reset();
    $this->logged = [];
    $c = new ContainerBuilder();
    $this->container = $c;
    $test = $this;

    $time = $this->createStub(TimeInterface::class);
    $time->method('getRequestTime')->willReturnCallback(fn () => $test->now);
    $time->method('getCurrentTime')->willReturnCallback(fn () => $test->now);
    $c->set('datetime.time', $time);
    $c->set('settings', new Settings([]));
    $c->set('state', new State(new KeyValueMemoryFactory(), new MemoryBackend($time), new NullLockBackend()));
    $c->set('cache.default', new MemoryBackend($time));
    $c->set('cache_tags.invalidator', $this->createStub(CacheTagsInvalidatorInterface::class));
    $c->set('messenger', new Messenger(new FlashBag(), new KillSwitch()));
    $c->set('string_translation', $this->getStringTranslationStub());
    $c->set('event_dispatcher', new EventDispatcher());

    $typed = $this->createStub(TypedConfigManagerInterface::class);
    $typed->method('hasConfigSchema')->willReturn(FALSE);
    $c->set('config.typed', $typed);
    $config = new ConfigFactory(new MemoryStorage(), $c->get('event_dispatcher'), $typed);
    $c->get('event_dispatcher')->addSubscriber($config);
    $contexts = $this->createStub(CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $c->set('cache_contexts_manager', $contexts);
    $config->getEditable('pathly.settings')->setData(['replay_after_updates' => TRUE, 'badge_enabled' => FALSE])->save();
    $c->set('config.factory', $config);

    $modules = $this->createStub(ModuleExtensionList::class);
    $modules->method('getAllInstalledInfo')->willReturnCallback(fn () => $test->installedModules);
    $modules->method('getExtensionInfo')->willReturn([]);
    $c->set('extension.list.module', $modules);
    $themes = $this->createStub(ThemeExtensionList::class);
    $themes->method('getAllInstalledInfo')->willReturn(['claro' => ['version' => '11.0.0']]);
    $c->set('extension.list.theme', $themes);
    $handler = $this->createStub(ModuleHandlerInterface::class);
    $handler->method('moduleExists')->willReturnCallback(fn ($name) => in_array($name, $test->presentModules, TRUE));
    $c->set('module_handler', $handler);

    $language = $this->createStub(LanguageManagerInterface::class);
    $language->method('getCurrentLanguage')->willReturn(new Language(['id' => 'fr']));
    $c->set('language_manager', $language);
    $urls = $this->createStub(UrlGeneratorInterface::class);
    $urls->method('generateFromRoute')->willReturnCallback(
      fn ($route) => $route === '<front>' ? 'http://shop.test/' : '/admin/config/services/pathly',
    );
    $c->set('url_generator', $urls);
    $c->set('logger.channel.pathly', new class($test) extends AbstractLogger {

      public function __construct(private PathlyUnitTestBase $test) {}

      /**
       * Keeps the message for the test.
       */
      public function log($level, $message, array $context = []): void {
        $this->test->logged[] = [$level, (string) $message, $context];
      }

    });

    $c->register('pathly.key_store', KeyStore::class)
      ->setArguments([new Reference('state'), new Reference('settings')]);
    $c->register('pathly.client', PathlyClient::class)
      ->setArguments([
        FakeApi::client(),
        new Reference('pathly.key_store'),
        new Reference('settings'),
        new Reference('extension.list.module'),
      ]);
    $c->register('pathly.connector', Connector::class)
      ->setArguments([
        new Reference('pathly.client'),
        new Reference('pathly.key_store'),
        new Reference('state'),
        new Reference('module_handler'),
        new Reference('cache_tags.invalidator'),
        new Reference('logger.channel.pathly'),
      ]);
    $c->register('pathly.status_report', StatusReport::class)
      ->setArguments([
        new Reference('pathly.client'),
        new Reference('pathly.connector'),
        new Reference('cache.default'),
        new Reference('datetime.time'),
        new Reference('language_manager'),
      ]);
    $c->register('pathly.update_watcher', UpdateWatcher::class)
      ->setArguments([
        new Reference('pathly.connector'),
        new Reference('state'),
        new Reference('config.factory'),
        new Reference('extension.list.module'),
        new Reference('extension.list.theme'),
        new Reference('datetime.time'),
      ]);
    $c->register('pathly.package_manager_subscriber', PackageManagerSubscriber::class)
      ->setArguments([
        new Reference('pathly.update_watcher'),
        new Reference('extension.list.module'),
        new Reference('extension.list.theme'),
      ]);
    \Drupal::setContainer($c);
  }

  /**
   * Replaces settings.php values, before any service reads them.
   */
  protected function setSetting(string $name, mixed $value): void {
    $this->container->set('settings', new Settings([$name => $value]));
  }

  /**
   * Puts a key, a Ping and the site address in State, as after connecting.
   */
  protected function connected(bool $withFlow = FALSE): void {
    $state = $this->container->get('state');
    $state->set('pathly.api_key', self::KEY);
    $state->set(Connector::PING_ID, self::PING);
    $state->set(Connector::SITE_URL, 'http://shop.test');
    if ($withFlow) {
      $state->set(Connector::FLOW_ID, self::FLOW);
    }
  }

  /**
   * The connector.
   */
  protected function connector(): Connector {
    return $this->container->get('pathly.connector');
  }

  /**
   * Editable configuration.
   */
  protected function config(string $name) {
    return $this->container->get('config.factory')->getEditable($name);
  }

  /**
   * Messages of a type shown to the administrator.
   */
  protected function messages(string $type): array {
    return array_map('strval', $this->container->get('messenger')->messagesByType($type));
  }

}
