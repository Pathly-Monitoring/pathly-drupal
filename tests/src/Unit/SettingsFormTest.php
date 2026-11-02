<?php

declare(strict_types=1);

namespace Drupal\Tests\pathly\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\pathly\Connector;
use Drupal\pathly\Form\SettingsForm;
use Drupal\pathly_test\FakeApi;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the settings form.
 *
 * @group pathly
 */
#[Group('pathly')]
class SettingsFormTest extends PathlyUnitTestBase {

  /**
   * A status page, as returned by Pathly.
   */
  protected const STATUS_PAGE = [
    'id' => self::PAGE,
    'url' => 'https://pathlyhq.com/status/shop',
    'badgeUrl' => 'https://api.pathlyhq.com/public/status/shop/badge.svg',
    'public' => TRUE,
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Drupal 11 builds config forms by autowiring.
    $this->container->setAlias(ConfigFactoryInterface::class, 'config.factory');
    $this->container->setAlias(TypedConfigManagerInterface::class, 'config.typed');
  }

  /**
   * A new form object.
   */
  protected function form(): SettingsForm {
    return SettingsForm::create($this->container);
  }

  /**
   * Builds the form.
   */
  protected function build(): array {
    return $this->form()->buildForm([], new FormState());
  }

  /**
   * Validates and submits the form with the main button.
   */
  protected function submit(array $values): FormState {
    $form = [];
    $form_state = (new FormState())->setValues($values);
    $object = $this->form();
    $object->validateForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $object->submitForm($form, $form_state);
    }
    return $form_state;
  }

  /**
   * Calls the submit handler of a button.
   */
  protected function press(string $handler, array $values = []): void {
    $form = [];
    $this->form()->{$handler}($form, (new FormState())->setValues($values));
  }

  /**
   * Not connected: a key field, a sign-up link, the badge off and locked.
   */
  public function testBuildNotConnected(): void {
    $form = $this->build();
    $this->assertSame('pathly_settings', $this->form()->getFormId());
    $this->assertSame('password', $form['account']['api_key']['#type']);
    $this->assertFalse($form['account']['api_key']['#disabled']);
    $this->assertStringContainsString('Paste a Pathly API key', (string) $form['account']['api_key']['#description']);
    $this->assertStringContainsString('ref=drupal', (string) $form['account']['signup']['#markup']);
    $this->assertArrayNotHasKey('flow', $form);
    $this->assertArrayNotHasKey('disconnect', $form['actions']);
    $this->assertTrue($form['badge_enabled']['#disabled']);
    $this->assertFalse($form['badge_enabled']['#default_value']);
    $this->assertTrue($form['replay_after_updates']['#default_value']);
    $this->assertSame([], FakeApi::$log);
  }

  /**
   * A key in settings.php locks the field and the disconnect button.
   */
  public function testBuildOverridden(): void {
    $this->setSetting('pathly_api_key', self::KEY);
    FakeApi::reset(['GET /v1/scenarios' => [200, ['items' => []]]]);
    $form = $this->build();
    $this->assertTrue($form['account']['api_key']['#disabled']);
    $this->assertSame('The key is set in settings.php.', (string) $form['account']['api_key']['#description']);
    $this->assertArrayNotHasKey('disconnect', $form['actions']);
    $this->assertStringContainsString('http://shop.test/', (string) $form['account']['ping']['#markup']);
  }

  /**
   * Connected, no Flow: Lumi can be started. A refusal is shown.
   */
  public function testBuildConnectedWithoutFlow(): void {
    $this->connected();
    FakeApi::reset(['GET /v1/scenarios' => [500, []]]);
    $form = $this->build();
    $this->assertStringContainsString('never shown again', (string) $form['account']['api_key']['#description']);
    $this->assertStringContainsString('http://shop.test', (string) $form['account']['ping']['#markup']);
    $this->assertStringContainsString('No Flow yet', (string) $form['flow']['current']['#markup']);
    $this->assertArrayNotHasKey('existing_flow', $form['flow']);
    $this->assertArrayHasKey('prepare', $form['flow']['lumi']);
    $this->assertArrayHasKey('disconnect', $form['actions']);
    $this->assertSame(['Pathly refused the request. Try again later.'], $this->messages('error'));
  }

  /**
   * Connected with a Flow, Lumi exploring.
   */
  public function testBuildWaiting(): void {
    $this->connected(TRUE);
    $this->container->get('state')->set(Connector::TRIAL, ['id' => self::TRIAL]);
    FakeApi::reset([
      'GET /v1/scenarios' => [200, ['items' => [['id' => self::FLOW, 'type' => 'browser', 'name' => 'Cart']]]],
      'GET /v1/flow-assistant/trials/' . self::TRIAL => [200, ['item' => ['status' => 'exploring']]],
    ]);
    $form = $this->build();
    $this->assertStringContainsString('/monitors/' . self::FLOW, (string) $form['flow']['current']['#markup']);
    $this->assertSame([self::FLOW => 'Cart'], $form['flow']['existing_flow']['#options']);
    $this->assertArrayHasKey('refresh', $form['flow']['lumi']);
  }

  /**
   * Lumi done: the Flows it replayed, or none.
   */
  public function testBuildProposals(): void {
    $this->connected();
    $this->container->get('state')->set(Connector::TRIAL, ['id' => self::TRIAL]);
    FakeApi::reset([
      'GET /v1/scenarios' => [200, ['items' => []]],
      'GET /v1/flow-assistant/trials/' . self::TRIAL => [
        [
          200,
          [
            'item' => [
              'status' => 'ready',
              'proposals' => [
                ['index' => 0, 'kind' => 'contact'],
                ['index' => 1, 'kind' => 'login'],
                ['index' => 2, 'kind' => 'cart'],
                ['index' => 3, 'kind' => 'custom'],
                ['index' => 4],
              ],
            ],
          ],
        ],
        [200, ['item' => ['status' => 'not_found', 'proposals' => []]]],
        [200, []],
      ],
    ]);
    $form = $this->build();
    $radios = $form['flow']['lumi']['proposal'];
    $this->assertSame(0, $radios['#default_value']);
    $this->assertSame([
      'Contact form, stopped before sending',
      'Login page',
      'Cart, up to the checkout page (nothing is paid)',
      'Visitor path',
      'Visitor path',
    ], array_values($radios['#options']));
    $this->assertArrayHasKey('save', $form['flow']['lumi']);
    $this->assertArrayHasKey('restart', $form['flow']['lumi']);

    $this->assertArrayHasKey('none', $this->build()['flow']['lumi']);
    $this->assertArrayHasKey('none', $this->build()['flow']['lumi']);
  }

  /**
   * The key is checked before it is saved.
   */
  public function testValidateKey(): void {
    $form_state = $this->submit(['api_key' => 'not-a-key']);
    $this->assertSame('A Pathly API key starts with sp_.', (string) $form_state->getErrors()['api_key']);

    FakeApi::reset(['GET /v1/account' => [401, ['error' => 'bad']]]);
    $form_state = $this->submit(['api_key' => self::KEY]);
    $this->assertStringContainsString('refused this API key', (string) $form_state->getErrors()['api_key']);
    $this->assertNull($this->container->get('pathly.key_store')->get());
  }

  /**
   * Saving a key creates the Ping, or reuses it.
   */
  public function testConnect(): void {
    FakeApi::reset([
      'GET /v1/account' => [200, []],
      'GET /v1/scenarios' => [
        [200, ['items' => []]],
        [200, ['items' => [['id' => self::PING, 'type' => 'http', 'url' => 'http://shop.test/']]]],
        [0, []],
      ],
      'POST /v1/scenarios' => [201, ['id' => self::PING]],
    ]);
    $this->submit(['api_key' => ' ' . self::KEY . ' ', 'replay_after_updates' => 0]);
    $this->assertSame(self::KEY, $this->container->get('pathly.key_store')->get());
    $this->assertContains('Connected. A Ping now watches this site.', $this->messages('status'));
    $this->assertContains('The configuration options have been saved.', $this->messages('status'));
    $this->assertFalse($this->config('pathly.settings')->get('replay_after_updates'));
    $this->assertSame('http://shop.test', FakeApi::calls('POST /v1/scenarios')[0]['body']['url']);

    $this->container->get('messenger')->deleteAll();
    $this->submit(['api_key' => self::KEY, 'replay_after_updates' => 1]);
    $this->assertContains('Connected. The Ping of this site already existed in Pathly: the module uses it.', $this->messages('status'));
    $this->assertTrue($this->config('pathly.settings')->get('replay_after_updates'));

    $this->container->get('messenger')->deleteAll();
    $this->submit(['api_key' => self::KEY]);
    $this->assertSame(['Pathly could not be reached. Try again in a few minutes.'], $this->messages('error'));
  }

  /**
   * The badge is turned on and off, and stays as it was when Pathly refuses.
   */
  public function testBadge(): void {
    $this->connected();
    FakeApi::reset([
      'GET /v1/status-pages' => [[200, ['items' => []]], [500, []]],
      'POST /v1/status-pages' => [201, self::STATUS_PAGE],
      'PATCH /v1/status-pages/' . self::PAGE => [[200, self::STATUS_PAGE], [0, []]],
    ]);
    $this->submit(['badge_enabled' => 1]);
    $this->assertTrue($this->config('pathly.settings')->get('badge_enabled'));
    $this->submit(['badge_enabled' => 1]);
    $this->assertCount(1, FakeApi::calls('POST /v1/status-pages'));
    $this->submit(['badge_enabled' => 0]);
    $this->assertFalse($this->config('pathly.settings')->get('badge_enabled'));
    $this->assertCount(1, FakeApi::calls('PATCH /v1/status-pages/' . self::PAGE));

    $this->submit(['badge_enabled' => 1]);
    $this->assertFalse($this->config('pathly.settings')->get('badge_enabled'));

    $this->config('pathly.settings')->set('badge_enabled', TRUE)->save();
    $this->container->get('state')->set(Connector::STATUS_PAGE, ['id' => self::PAGE, 'byModule' => TRUE]);
    $this->submit(['badge_enabled' => 0]);
    $this->assertTrue($this->config('pathly.settings')->get('badge_enabled'));
  }

  /**
   * Lumi: confirm, start, wait, save, start again.
   */
  public function testLumiButtons(): void {
    $this->connected();
    $state = $this->container->get('state');
    FakeApi::reset([
      'POST /v1/flow-assistant/detect' => [
        [200, ['available' => TRUE, 'siteKind' => 'drupal']],
        [200, ['available' => FALSE, 'reason' => 'unreachable']],
      ],
      'POST /v1/flow-assistant/trials' => [202, ['id' => self::TRIAL]],
      'POST /v1/flow-assistant/trials/' . self::TRIAL . '/save' => [
        [201, ['item' => ['id' => self::FLOW]]],
        [403, ['code' => 'PLAN_LIMIT']],
      ],
    ]);
    $this->press('prepareFlow', ['confirm_owner' => 0]);
    $this->assertSame(['Confirm that you manage this site first.'], $this->messages('error'));
    $this->assertCount(0, FakeApi::calls('POST /v1/flow-assistant/detect'));

    $this->container->get('messenger')->deleteAll();
    $this->press('prepareFlow', ['confirm_owner' => 1]);
    $this->assertSame(self::TRIAL, $state->get(Connector::TRIAL)['id']);
    $this->assertContains('Lumi is exploring the site. Refresh this page in a minute.', $this->messages('status'));

    $state->delete(Connector::TRIAL);
    $this->container->get('messenger')->deleteAll();
    $this->press('prepareFlow', ['confirm_owner' => 1]);
    $this->assertSame(['Lumi could not open the site. Check that it is public.'], $this->messages('error'));

    $this->press('refresh');
    $state->set(Connector::TRIAL, ['id' => self::TRIAL]);
    $this->container->get('messenger')->deleteAll();
    $this->press('saveFlow', ['proposal' => '0']);
    $this->assertSame(self::FLOW, $state->get(Connector::FLOW_ID));
    $this->assertContains('The Flow is saved in Pathly. It runs now, then on its schedule and after each update.', $this->messages('status'));

    $state->set(Connector::TRIAL, ['id' => self::TRIAL]);
    $this->container->get('messenger')->deleteAll();
    $this->press('saveFlow', ['proposal' => '0']);
    $this->assertSame(['Your Pathly plan is full. Upgrade it in Pathly, or remove a Ping or a Flow.'], $this->messages('error'));

    $this->press('forgetTrial');
    $this->assertNull($state->get(Connector::TRIAL));
  }

  /**
   * An existing Flow can be used.
   */
  public function testAttachFlow(): void {
    $this->connected();
    $this->press('attachFlow', ['existing_flow' => self::FLOW]);
    $this->assertSame(self::FLOW, $this->container->get('state')->get(Connector::FLOW_ID));
    $this->assertContains('This Flow is now replayed after each update.', $this->messages('status'));
  }

  /**
   * Disconnecting forgets the key and turns the badge off.
   */
  public function testDisconnect(): void {
    $this->connected(TRUE);
    $this->config('pathly.settings')->set('badge_enabled', TRUE)->save();
    $this->press('disconnect');
    $this->assertFalse($this->connector()->isConnected());
    $this->assertFalse($this->config('pathly.settings')->get('badge_enabled'));
    $this->assertContains('The site is disconnected. The Ping and the Flow stay in your Pathly account.', $this->messages('status'));
  }

}
