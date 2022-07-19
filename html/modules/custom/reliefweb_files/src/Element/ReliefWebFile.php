<?php

namespace Drupal\reliefweb_files\Element;

use Drupal\Core\Render\Element;
use Drupal\Core\Render\Element\RenderElement;
use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Render element for the ReliefWebFile widget.
 *
 * @RenderElement("reliefweb_file")
 */
class ReliefWebFile extends RenderElement implements TrustedCallbackInterface {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = get_class($this);
    return [
      '#theme' => 'reliefweb_file_widget',
      '#pre_render' => [[$class, 'preRenderFormErrors']],
    ];
  }

  /**
   * Pre render callback to remove errors on children.
   *
   * @param array $element
   *   The render element.
   */
  public static function preRenderFormErrors(array $element) {
    static::removeErrorsOnChildren($element);
    return $element;
  }

  /**
   * Remove errors on children if there is already an error on the parent.
   *
   * @param array $element
   *   The render element or a child element.
   */
  public static function removeErrorsOnChildren(array &$element) {
    $has_error = !empty($element['#errors']);

    foreach (Element::children($element) as $key) {
      $child = &$element[$key];
      static::removeErrorsOnChildren($child);
      if ($has_error) {
        unset($child['#errors']);
        unset($child['#children_errors']);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return [
      'preRenderFormErrors',
    ];
  }

}
