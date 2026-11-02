<?php

declare(strict_types=1);

namespace Drupal\pathly_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Sends the calls of the module to the fake Pathly API.
 */
class PathlyTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition('pathly.http_client')) {
      $container->getDefinition('pathly.http_client')
        ->setFactory([FakeApi::class, 'client'])
        ->setArguments([]);
    }
  }

}
