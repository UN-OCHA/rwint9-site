<?php

namespace Drupal\reliefweb_form;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Modifies the node preview parameter converter.
 */
class ReliefWebFormServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if ($container->hasDefinition('node_preview')) {
      $definition = $container->getDefinition('node_preview');
      $definition->setClass('Drupal\reliefweb_form\ParamConverter\NodePreviewConverter');
      $definition->setLazy(FALSE);
    }
  }

}
