<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;

/**
 * Plugin implementation of the 'reliefweb_user_posting_rights' formatter.
 *
 * @FieldFormatter(
 *   id = "reliefweb_user_posting_rights",
 *   label = @Translation("ReliefWeb User Posting Rights formatter"),
 *   field_types = {
 *     "reliefweb_user_posting_rights"
 *   }
 * )
 */
class ReliefWebUserPostingRights extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    // The user posting rights are an internal editorial fields that is never
    // rendered.
    return [
      '#access' => FALSE,
    ];
  }

}
