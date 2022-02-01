<?php

namespace Drupal\content_entity_clone\Plugin;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Plugin\PluginBase;

/**
 * Base field processor plugin.
 */
abstract class FieldProcessorPluginBase extends PluginBase implements FieldProcessorPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function getPluginLabel() {
    $definition = $this->getPluginDefinition();
    return $definition['label'] ?? $this->getPluginId();
  }

  /**
   * {@inheritdoc}
   */
  public function supports(FieldDefinitionInterface $field_definition) {
    $type = $field_definition->getType();
    $definition = $this->getPluginDefinition();
    return empty($definition['types']) || in_array($type, $definition['types']);
  }

}
