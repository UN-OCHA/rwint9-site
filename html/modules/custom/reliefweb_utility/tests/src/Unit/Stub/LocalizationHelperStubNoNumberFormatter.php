<?php

namespace Drupal\Tests\reliefweb_utility\Unit\Stub;

/**
 * Helper to sort or format content in proper localized way.
 */
class LocalizationHelperStubNoNumberFormatter extends LocalizationHelperStub {

  /**
   * {@inheritdoc}
   */
  protected static function createNumberFormatter($language) {
    return FALSE;
  }

}
