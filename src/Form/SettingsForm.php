<?php

declare(strict_types=1);

namespace Drupal\pathly\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\pathly\Connector;
use Drupal\pathly\ConnectorException;
use Drupal\pathly\KeyStore;
use Drupal\pathly\Links;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings of the module: API key, Flow prepared by Lumi, replay and badge.
 *
 * Only for the users who may "administer pathly". Drupal adds a CSRF token
 * to the form.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * Links the site to Pathly.
   */
  protected Connector $connector;

  /**
   * Where the API key lives.
   */
  protected KeyStore $keyStore;

  /**
   * Gives the language of the page, for the sign-up link.
   */
  protected LanguageManagerInterface $languages;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = parent::create($container);
    $form->connector = $container->get('pathly.connector');
    $form->keyStore = $container->get('pathly.key_store');
    $form->languages = $container->get('language_manager');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'pathly_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['pathly.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('pathly.settings');
    $connected = $this->connector->isConnected();
    $overridden = $this->keyStore->isOverridden();
    $form['#attached']['library'][] = 'pathly/admin';

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Pathly watches the path of your visitors. Ping checks that the site answers. Flow replays a visit in a real browser, up to the form or the checkout page, and keeps a screenshot of the step that breaks.') . '</p>',
    ];

    $form['account'] = [
      '#type' => 'details',
      '#title' => $this->t('Pathly account'),
      '#open' => !$connected,
    ];
    $form['account']['signup'] = [
      '#markup' => '<p>' . $this->t('No account yet? <a href=":signup" target="_blank" rel="noopener">Create a free account</a>, then create an API key in <a href=":keys" target="_blank" rel="noopener">Pathly settings</a>. Prefer a key limited to one Space.', [
        ':signup' => Links::signup($this->languages->getCurrentLanguage()->getId()),
        ':keys' => Links::API_KEYS,
      ]) . '</p>',
    ];
    $form['account']['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API key'),
      '#disabled' => $overridden,
      '#attributes' => ['autocomplete' => 'off'],
      '#description' => $overridden
        ? $this->t('The key is set in settings.php.')
        : ($connected
          ? $this->t('A key is saved. It is never shown again. Leave the field empty to keep it.')
          : $this->t('Paste a Pathly API key (sp_...). Scopes: org:read, scenarios:read, scenarios:write, runs:read, runs:trigger, assistant:run, assistant:save, status:read, status:write.')),
    ];

    if ($connected) {
      $form['account']['ping'] = [
        '#markup' => '<p>' . $this->t('Ping watches @url.', ['@url' => $this->connector->siteUrl() ?? $this->siteUrl()]) . '</p>',
      ];
      $this->buildFlow($form, $form_state);
    }

    $form['replay_after_updates'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Replay the Flow after each update of Drupal core, a module or a theme'),
      '#default_value' => (bool) $config->get('replay_after_updates'),
    ];
    $form['badge_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show the uptime badge'),
      '#default_value' => (bool) $config->get('badge_enabled'),
      '#disabled' => !$connected,
      '#description' => $this->t('Publishes the Pathly status page of the key. Then place the "Pathly uptime badge" block. The image of the badge is loaded from api.pathlyhq.com by the browser of your visitors.'),
    ];

    $form = parent::buildForm($form, $form_state);
    if ($connected && !$overridden) {
      $form['actions']['disconnect'] = [
        '#type' => 'submit',
        '#value' => $this->t('Disconnect'),
        '#submit' => ['::disconnect'],
        '#limit_validation_errors' => [],
      ];
    }
    return $form;
  }

  /**
   * Flow section: existing Flow, or Flow prepared by Lumi.
   */
  protected function buildFlow(array &$form, FormStateInterface $form_state): void {
    $form['flow'] = [
      '#type' => 'details',
      '#title' => $this->t('Flow of the visitor path'),
      '#open' => TRUE,
    ];
    $flowId = $this->connector->flowId();
    $form['flow']['current'] = [
      '#markup' => '<p>' . ($flowId === NULL
        ? $this->t('No Flow yet: after an update, the Ping is replayed instead.')
        : $this->t('This Flow is replayed after each update: <a href=":url" target="_blank" rel="noopener">see it in Pathly</a>.', [':url' => Links::APP . '/monitors/' . \rawurlencode($flowId)])) . '</p>',
    ];
    $form['flow']['notice'] = [
      '#markup' => '<p>' . $this->t('A Flow on a shop puts a product in a test cart. Your abandoned cart reminders may count these carts.') . '</p>',
    ];

    $flows = $this->attempt(fn () => $this->connector->browserFlows(), []);
    if ($flows) {
      $form['flow']['existing_flow'] = [
        '#type' => 'select',
        '#title' => $this->t('Existing Flow'),
        '#options' => $flows,
        '#default_value' => $flowId,
      ];
      $form['flow']['attach'] = [
        '#type' => 'submit',
        '#value' => $this->t('Use this Flow'),
        '#submit' => ['::attachFlow'],
        '#limit_validation_errors' => [['existing_flow']],
      ];
    }
    $form['flow']['lumi'] = ['#type' => 'container'];
    $this->buildLumi($form['flow']['lumi']);
  }

  /**
   * Lumi part: start, wait, choose.
   */
  protected function buildLumi(array &$element): void {
    $trial = $this->attempt(fn () => $this->connector->trial(), NULL);
    if ($trial === NULL) {
      $element['confirm_owner'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('I manage this site. Lumi may open it in a test browser: it pays nothing, sends no form and only types test values.'),
      ];
      $element['prepare'] = [
        '#type' => 'submit',
        '#value' => $this->t('Prepare the Flow with Lumi'),
        '#submit' => ['::prepareFlow'],
        '#limit_validation_errors' => [['confirm_owner']],
      ];
      return;
    }
    $item = $trial['item'] ?? [];
    $status = (string) ($item['status'] ?? 'failed');
    $proposals = $item['proposals'] ?? [];
    if (!\in_array($status, ['ready', 'not_found', 'failed'], TRUE)) {
      $element['waiting'] = [
        '#markup' => '<p>' . $this->t('Lumi is exploring the site and replays each Flow it finds. This takes up to two minutes.') . '</p>',
      ];
      $element['refresh'] = [
        '#type' => 'submit',
        '#value' => $this->t('Refresh'),
        '#submit' => ['::refresh'],
        '#limit_validation_errors' => [],
      ];
      return;
    }
    if ($proposals) {
      $options = [];
      foreach ($proposals as $proposal) {
        $options[(int) $proposal['index']] = $this->proposalLabel((string) ($proposal['kind'] ?? 'custom'));
      }
      $element['proposal'] = [
        '#type' => 'radios',
        '#title' => $this->t('Flow proposed by Lumi, already replayed once'),
        '#options' => $options,
        '#default_value' => $this->connector->preferredIndex($proposals),
      ];
      $element['save'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save this Flow'),
        '#submit' => ['::saveFlow'],
        '#limit_validation_errors' => [['proposal']],
      ];
    }
    else {
      $element['none'] = [
        '#markup' => '<p>' . $this->t('Lumi found no Flow it could replay on this site. You can record the Flow in Pathly.') . '</p>',
      ];
    }
    $element['restart'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start Lumi again'),
      '#submit' => ['::forgetTrial'],
      '#limit_validation_errors' => [],
    ];
  }

  /**
   * Label of a proposed Flow.
   */
  protected function proposalLabel(string $kind): string {
    return (string) match ($kind) {
      'cart' => $this->t('Cart, up to the checkout page (nothing is paid)'),
      'login' => $this->t('Login page'),
      'contact' => $this->t('Contact form, stopped before sending'),
      default => $this->t('Visitor path'),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $key = \trim((string) $form_state->getValue('api_key'));
    if ($key === '') {
      return;
    }
    if (!KeyStore::isValidFormat($key)) {
      $form_state->setErrorByName('api_key', $this->t('A Pathly API key starts with sp_.'));
      return;
    }
    try {
      $this->connector->checkKey($key);
    }
    catch (ConnectorException $e) {
      $form_state->setErrorByName('api_key', $e->userMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('pathly.settings');
    $key = \trim((string) $form_state->getValue('api_key'));
    if ($key !== '') {
      $this->attempt(function () use ($key) {
        $how = $this->connector->connect($key, $this->siteUrl());
        $this->messenger()->addStatus($how === 'attached'
          ? $this->t('Connected. The Ping of this site already existed in Pathly: the module uses it.')
          : $this->t('Connected. A Ping now watches this site.'));
      });
    }
    $badge = (bool) $form_state->getValue('badge_enabled');
    if ($badge !== (bool) $config->get('badge_enabled')) {
      $done = $this->attempt(function () use ($badge) {
        $badge ? $this->connector->enableBadge() : $this->connector->disableBadge();
        return TRUE;
      }, FALSE);
      $badge = $done ? $badge : !$badge;
    }
    $config
      ->set('replay_after_updates', (bool) $form_state->getValue('replay_after_updates'))
      ->set('badge_enabled', $badge)
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Starts Lumi on the site.
   */
  public function prepareFlow(array &$form, FormStateInterface $form_state): void {
    if (!$form_state->getValue('confirm_owner')) {
      $this->messenger()->addError($this->t('Confirm that you manage this site first.'));
      return;
    }
    $this->attempt(function () {
      $this->connector->prepareFlow();
      $this->messenger()->addStatus($this->t('Lumi is exploring the site. Refresh this page in a minute.'));
    });
  }

  /**
   * Reloads the form to read the trial again.
   */
  public function refresh(array &$form, FormStateInterface $form_state): void {}

  /**
   * Saves the Flow chosen among the proposals of Lumi.
   */
  public function saveFlow(array &$form, FormStateInterface $form_state): void {
    $index = (int) $form_state->getValue('proposal');
    $this->attempt(function () use ($index) {
      $this->connector->saveFlow($index);
      $this->messenger()->addStatus($this->t('The Flow is saved in Pathly. It runs now, then on its schedule and after each update.'));
    });
  }

  /**
   * Forgets the Lumi trial, to start again.
   */
  public function forgetTrial(array &$form, FormStateInterface $form_state): void {
    $this->connector->forgetTrial();
  }

  /**
   * Uses an existing Flow.
   */
  public function attachFlow(array &$form, FormStateInterface $form_state): void {
    $this->connector->attachFlow((string) $form_state->getValue('existing_flow'));
    $this->messenger()->addStatus($this->t('This Flow is now replayed after each update.'));
  }

  /**
   * Forgets the key. Nothing is deleted in Pathly.
   */
  public function disconnect(array &$form, FormStateInterface $form_state): void {
    $this->attempt(fn () => $this->connector->disableBadge());
    $this->connector->disconnect();
    $this->config('pathly.settings')->set('badge_enabled', FALSE)->save();
    $this->messenger()->addStatus($this->t('The site is disconnected. The Ping and the Flow stay in your Pathly account.'));
  }

  /**
   * Address of the site, sent to Pathly for the Ping and Lumi.
   */
  protected function siteUrl(): string {
    return Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();
  }

  /**
   * Runs a call to Pathly, and shows its refusal as an error message.
   */
  protected function attempt(callable $call, mixed $fallback = NULL): mixed {
    try {
      return $call();
    }
    catch (ConnectorException $e) {
      $this->messenger()->addError($e->userMessage());
      return $fallback;
    }
  }

}
