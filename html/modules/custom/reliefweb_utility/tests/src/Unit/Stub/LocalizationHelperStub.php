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
  public static function getCollator($language = NULL) {
    // Reset the default language so that it's not inherited between tests.
    static::$collators = [];
    return parent::getCollator($language);
  }

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
    // Reset the default language so that it's not inherited between tests.
    static::$defaultLanguage = null;
    return parent::getLanguage($language);
  }

}
