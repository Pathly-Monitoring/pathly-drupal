<?php

declare(strict_types=1);

namespace Drupal\pathly\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\pathly\StatusReport;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Ping and Flow status for the administration dashboard.
 *
 * Only shown to the users who may "administer pathly". It can be placed in
 * the administration theme, or in the Dashboard module of Drupal CMS.
 */
#[Block(
  id: 'pathly_status',
  admin_label: new TranslatableMarkup('Pathly: Ping and Flow status'),
  category: new TranslatableMarkup('Pathly'),
)]
class PathlyStatusBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Builds the block.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected StatusReport $report) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('pathly.status_report'));
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'administer pathly');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return $this->report->render();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return StatusReport::TTL;
  }

}
