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
  protected static function createCollator($language) {
    return parent::createCollator($language);
  }

  /**
   * {@inheritdoc}
   */
  protected static function intlGetErrorCode() {
    return parent::intlGetErrorCode();
  }

  /**
   * {@inheritdoc}
   */
  public static function getLanguage($language = NULL) {
    // As opposed to the parent method, we don't store the default language
    // in a static variable because starting from PHP 8.1, the default would
    // be inherited between tests so we would not be able to change it to use
    // a different current language.
    if (empty($language)) {
      $language = \Drupal::languageManager()->getCurrentLanguage()->getId();
    }
    return $language;
  }

}
