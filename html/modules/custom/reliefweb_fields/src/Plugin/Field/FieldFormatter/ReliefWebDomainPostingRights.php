<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;

/**
 * Plugin implementation of the 'reliefweb_domain_posting_rights' formatter.
 *
 * @FieldFormatter(
 *   id = "reliefweb_domain_posting_rights",
 *   label = @Translation("ReliefWeb Domain Posting Rights formatter"),
 *   field_types = {
 *     "reliefweb_domain_posting_rights"
 *   }
 * )
 */
class ReliefWebDomainPostingRights extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    // The domain posting rights are an internal editorial fields that is never
    // rendered.
    return [
      '#access' => FALSE,
    ];
  }

}
