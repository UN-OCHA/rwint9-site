<?php

namespace Drupal\reliefweb_form\ParamConverter;

use Drupal\node\ParamConverter\NodePreviewConverter as OriginalNodePreviewConverter;

/**
 * Provides upcasting for a node entity in preview.
 */
class NodePreviewConverter extends OriginalNodePreviewConverter {

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults) {
    $store = $this->tempStoreFactory->get('node_preview');
    $form_state = $store->get($value);
    if (!empty($form_state)) {
      // We repopulate the fields using inline entity forms with the referenced
      // entities marked as new so that the entity reference field formatter
      // doesn't try to load the entities and use the given cloned ones with the
      // changes made to them in the form.
      $entity = $form_state->getFormObject()->getEntity();
      $widget_states = $form_state->get('inline_entity_form');
      if (!empty($widget_states)) {
        foreach ($widget_states as $widget_state) {
          $field_name = $widget_state['instance']->getName();

          if (!empty($widget_state['entities'])) {
            foreach ($widget_state['entities'] as $item) {
              if (isset($item['entity'])) {
                $item['entity']->enforceIsNew(TRUE);
              }
            }

            $entity->set($field_name, $widget_state['entities']);
          }
        }
      }

      return $entity;
    }
  }

}
