<?php

namespace Drupal\reliefweb_users;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Modifies the masquerade callbacks service.
 */
class ReliefwebUsersServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if ($container->hasDefinition('masquerade.callbacks')) {
      $definition = $container->getDefinition('masquerade.callbacks');
      $definition->setClass('Drupal\reliefweb_users\ReliefwebMasqueradeCallbacks')
        ->addArgument(new Reference('redirect.destination'));
    }
  }

}
