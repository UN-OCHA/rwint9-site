<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_utility\Unit\Stub;

use Drupal\reliefweb_utility\Helpers\LocalizationHelper;

/**
 * Helper to sort or format content in proper localized way.
 */
class LocalizationHelperStub extends LocalizationHelper {

  /**
   * {@inheritdoc}
   */
  protected static function collatorCreate($language) {
    return parent::collatorCreate($language);
  }

  /**
   * {@inheritdoc}
   */
  protected static function intlGetErrorCode() {
    return parent::intlGetErrorCode();
  }

}
