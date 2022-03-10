<?php

namespace Drupal\reliefweb_guidelines\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Url;

/**
 * Provides a form for deleting Guideline entities.
 *
 * @ingroup guidelines
 */
class GuidelineDeleteForm extends ContentEntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  protected function getRedirectUrl() {
    // Redirect to the moderation page for the guideline type.
    return Url::fromRoute('reliefweb_moderation.content', [
      'service' => $this->getEntity()->bundle(),
    ]);
  }

}
