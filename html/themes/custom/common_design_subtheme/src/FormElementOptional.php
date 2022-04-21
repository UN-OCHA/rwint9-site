<?php

namespace Drupal\common_design_subtheme;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Trusted callback to add the optional mark next to a form element title.
 */
class FormElementOptional implements TrustedCallbackInterface {

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['afterBuild', 'preRenderTitle', 'preRenderFieldTitle'];
  }

  /**
   * After build callback: add a pre render callback to add the optional marl.
   *
   * @param array $element
   *   Form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state interface.
   *
   * @return array
   *   Form element.
   */
  public static function afterBuild(array $element, FormStateInterface $form_state) {
    if (!empty($element['#title'])) {
      $element['#pre_render'][] = [static::class, 'preRenderTitle'];
    }
    if (!empty($element['#field_title'])) {
      $element['#pre_render'][] = [static::class, 'preRenderFieldTitle'];
    }
    return $element;
  }

  /**
   * Pre-render callback: add the optional mark to the form element title.
   *
   * @param array $element
   *   Form element.
   *
   * @return array
   *   Form element.
   */
  public static function preRenderTitle(array $element) {
    if (!empty($element['#not_required']) && !empty($element['#title'])) {
      $element['#title'] = static::addOptionalMark($element['#title']);
    }
    return $element;
  }

  /**
   * Pre-render callback: add the optional mark to the form element field title.
   *
   * @param array $element
   *   Form element.
   *
   * @return array
   *   Form element.
   */
  public static function preRenderFieldTitle(array $element) {
    if (!empty($element['#not_required']) && !empty($element['#field_title'])) {
      $element['#field_title'] = static::addOptionalMark($element['#field_title']);
    }
    return $element;
  }

  /**
   * Add the optional mark to a form element title.
   *
   * @param string|\Drupal\Component\Render\MarkupInterface $title
   *   Form element title.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   Title with "optional" appended.
   */
  public static function addOptionalMark($title) {
    return new FormattableMarkup('@title <span class="form-optional"> - optional</span>', [
      '@title' => $title,
    ]);
  }

  /**
   * Mark an element and its children as optional.
   *
   * @param mixed $element
   *   Form element.
   */
  public static function markChildrenAsOptional(&$element) {
    $types_to_skip = [
      // Grouped checkboxes and radios.
      'checkbox',
      'radio',
      // Same list as ::markAsOptional().
      'button',
      'hidden',
      'link',
      'submit',
      'text_format',
      'token',
      'vertical_tabs',
      'weight',
    ];

    // Skip if the element is invalid or already processed or required.
    if (!is_array($element) || isset($element['#not_required']) || !empty($element['#required'])) {
      return;
    }

    // Skip if the field is not of a type we want to mark as optional.
    if (isset($element['#type']) && in_array($element['#type'], $types_to_skip)) {
      return;
    }

    $element['#not_required'] = TRUE;
    if (!empty($element['#title']) || !empty($element['#field_title'])) {
      $element['#after_build'][] = [static::class, 'afterBuild'];
    }

    foreach (Element::children($element) as $key) {
      static::markChildrenAsOptional($element[$key]);
    }
  }

  /**
   * Implements hook_reliefweb_form_mark_optional_alter().
   *
   * Mark form elements as optional.
   *
   * Note: we cannot use '#optional' as it has a different meaning in Drupal 9
   * so we use '#not_required' to flag optional elements.
   *
   * @param array $element
   *   Form element.
   * @param array $form
   *   Full form.
   */
  public static function markAsOptional(array &$element, array $form) {
    $types_to_skip = [
      'button',
      'hidden',
      'link',
      'submit',
      'text_format',
      'token',
      'vertical_tabs',
      'weight',
    ];

    $container_types = [
      'container',
      'details',
      'fieldset',
      'checkboxes',
      'radios',
    ];

    // Skip if the field is not of a type we want to mark as optional.
    if (!isset($element['#type']) || in_array($element['#type'], $types_to_skip)) {
      return;
    }

    // Skip if the field is required.
    if (!empty($element['#required']) || !empty($elment['widget']['#required'])) {
      return;
    }

    // Skipped if already processed.
    if (isset($element['#not_required'])) {
      return;
    }

    // Process container elements.
    if (in_array($element['#type'], $container_types)) {
      static::markChildrenAsOptional($element);
    }
    // Process other elements.
    else {
      // Flag the element as optional.
      $element['#not_required'] = TRUE;
      $type = $element['#type'];

      // Special handling of checkbox and radio elements. We need to check if
      // they are part of a group of checkboxes or radios in which case we'll
      // mark the grouping element as optional when processed by
      // ::markChildrenAsOptional().
      if ($type === 'checkbox' || $type === 'radio') {
        if (isset($element['#array_parents'])) {
          $parents = array_slice($element['#array_parents'], 0, -1);

          while (!empty($parents)) {
            $parent = NestedArray::getValue($form, $parents);

            switch ($parent['#type'] ?? NULL) {
              case 'checkboxes':
              case 'radios':
                return;
            }

            array_pop($parents);
          }
        }
      }

      if (isset($element['#title'])) {
        $element['#after_build'][] = [static::class, 'afterBuild'];
      }
    }
  }

}
