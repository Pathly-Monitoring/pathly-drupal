<?php

declare(strict_types=1);

namespace Drupal\pathly\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\pathly\StatusReport;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports, Pathly: Ping and Flow status, last failure, plan and quotas.
 */
class ReportController extends ControllerBase {

  /**
   * Builds the controller.
   */
  public function __construct(protected StatusReport $report) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('pathly.status_report'));
  }

  /**
   * The report page.
   */
  public function page(): array {
    return $this->report->render();
  }

}
