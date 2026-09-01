<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\reliefweb_content_analyzer\Helpers\Stopwords;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests stopword list helpers.
 */
#[CoversClass(Stopwords::class)]
#[Group('reliefweb_content_analyzer')]
class StopwordsTest extends UnitTestCase {

  /**
   * English stopwords are recognized.
   */
  public function testEnglish(): void {
    $this->assertTrue(Stopwords::isStopword('the', ['en']));
    $this->assertTrue(Stopwords::isStopword('AND', ['en']));
    $this->assertFalse(Stopwords::isStopword('msf', ['en']));
  }

  /**
   * Empty language list defaults to English.
   */
  public function testDefaultsToEnglish(): void {
    $set = Stopwords::setForLanguages([]);
    $this->assertArrayHasKey('the', $set);
    $this->assertArrayNotHasKey('le', $set);
  }

  /**
   * Unsupported codes fall back to English.
   */
  public function testUnsupportedFallsBackToEnglish(): void {
    $en = Stopwords::setForLanguages(['en']);
    $fallback = Stopwords::setForLanguages(['zz']);
    $this->assertSame($en, $fallback);
  }

}
