<?php

namespace Drupal\content_entity_clone\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a content entity clone field processor plugin annotation object.
 *
 * Plugin Namespace: Plugin\content_entity_clone\FieldProcessor.
 *
 * @see \Drupal\content_entity_clone\Plugin\FieldProcessorInterface
 * @see \Drupal\content_entity_clone\Plugin\FieldProcessorPluginBase
 * @see \Drupal\content_entity_clone\Plugin\FieldProcessorPluginManager
 * @see plugin_api
 *
 * @Annotation
 */
class ContentEntityCloneFieldProcessor extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public $id;

  /**
   * The human-readable name of the field processor.
   *
   * @var \Drupal\Core\Annotation\Translation
   * @ingroup plugin_translatable
   */
  public $label;


  /**
   * A short description of the field processor.
   *
   * @var \Drupal\Core\Annotation\Translation
   * @ingroup plugin_translatable
   */
  public $description;

  /**
   * An array of field types the field processor supports.
   *
   * If empty, then it applies to all the field types.
   *
   * @var array
   */
  public $fieldTypes = [];

}
