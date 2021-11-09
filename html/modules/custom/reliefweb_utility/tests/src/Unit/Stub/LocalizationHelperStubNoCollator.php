<?php

namespace Drupal\Tests\reliefweb_utility\Unit\Stub;

/**
 * Helper to sort or format content in proper localized way.
 */
class LocalizationHelperStubNoCollator extends LocalizationHelperStub {

  /**
   * {@inheritdoc}
   */
  protected static function createCollator($language) {
    return FALSE;
  }

}
