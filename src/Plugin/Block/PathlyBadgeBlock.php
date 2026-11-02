<?php

declare(strict_types=1);

namespace Drupal\pathly\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\pathly\Connector;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Uptime badge of the site, linked to its Pathly status page.
 *
 * Off by default: it shows only once the badge is turned on in the settings.
 */
#[Block(
  id: 'pathly_badge',
  admin_label: new TranslatableMarkup('Pathly uptime badge'),
  category: new TranslatableMarkup('Pathly'),
)]
class PathlyBadgeBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Builds the block.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected Connector $connector,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('pathly.connector'), $container->get('config.factory'));
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    $enabled = (bool) $this->configFactory->get('pathly.settings')->get('badge_enabled');
    return AccessResult::allowedIf($enabled && $this->connector->statusPage() !== NULL)
      ->addCacheTags(['config:pathly.settings', Connector::CACHE_TAG]);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $page = $this->connector->statusPage();
    return [
      '#type' => 'link',
      '#title' => [
        '#theme' => 'image',
        '#uri' => $page['badgeUrl'],
        '#alt' => $this->t('Uptime watched by Pathly'),
      ],
      '#url' => Url::fromUri($page['url']),
      '#attributes' => ['rel' => 'noopener', 'class' => ['pathly-badge']],
      '#cache' => ['tags' => ['config:pathly.settings', Connector::CACHE_TAG]],
    ];
  }

}
