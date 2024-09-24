<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_entraid\ExistingSite;

use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests entraid login widgets.
 */
class ReliefwebEntraidTestLogin extends ExistingSiteBase {

  /**
   * Test entra id login redirect.
   */
  public function testLoginRedirect() {
    // Check that the entraid login link does a redirect.
    $this->drupalGet('user/login/entraid');
    $this->assertSession()->statusCodeEquals(301);
  }

}
