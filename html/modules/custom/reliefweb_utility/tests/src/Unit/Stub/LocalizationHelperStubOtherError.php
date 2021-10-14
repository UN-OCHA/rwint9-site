<?php

namespace Drupal\Tests\reliefweb_utility\Unit\Stub;

/**
 * Helper to sort or format content in proper localized way.
 */
class LocalizationHelperStubOtherError extends LocalizationHelperStub {

  /**
   * {@inheritdoc}
   */
  protected static function intlGetErrorCode() {
    return 666;
  }

}
