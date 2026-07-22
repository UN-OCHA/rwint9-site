<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\reliefweb_utility\Helpers\TitlePatternHelper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for TitlePatternHelper.
 */
#[CoversClass(TitlePatternHelper::class)]
#[Group('reliefweb_utility')]
class TitlePatternHelperTest extends UnitTestCase {

  /**
   * Data provider for stringToLikePattern tests.
   *
   * @return array<string, array{string, string}>
   *   Input title and expected LIKE pattern pairs.
   */
  public static function stringToLikePatternProvider(): array {
    return [
      'english full and hash' => [
        'SitRep 27 April 2026 #3',
        'SitRep %',
      ],
      'english short month' => [
        'Update 15 Jan 2026',
        'Update %',
      ],
      'french abbreviated' => [
        'Bulletin 15 janv. 2026',
        'Bulletin %',
      ],
      'french le and 1er' => [
        'Bulletin le 1er avril 2026',
        'Bulletin %',
      ],
      'spanish de' => [
        'Informe 27 de abril de 2026',
        'Informe %',
      ],
      'russian genitive' => [
        'Отчёт 27 апреля 2026',
        'Отчёт %',
      ],
      'chinese month year western order' => [
        '报告 十二月 2025',
        '报告 %',
      ],
      'chinese numeric month year' => [
        '报告 1月 2026',
        '报告 %',
      ],
      'chinese year month day no space' => [
        '报告2026年4月27日',
        '报告%',
      ],
      'chinese year month no space' => [
        '报告2026年4月',
        '报告%',
      ],
      'arabic fi' => [
        'تقرير في 15 مارس 2026',
        'تقرير %',
      ],
      'arabic no preposition' => [
        'تقرير 15 مارس 2026',
        'تقرير %',
      ],
      'no date stripping' => [
        'Monthly Situation Report',
        'Monthly Situation Report',
      ],
      'english abbreviated month range' => [
        'Ukraine Operation Overview, Jan-Mar 2026',
        'Ukraine Operation Overview, %',
      ],
      'english mixed month range' => [
        'Ukraine Operation Overview, Jan-March 2026',
        'Ukraine Operation Overview, %',
      ],
      'english full month range spaced' => [
        'Ukraine Operation Overview, October - December 2025',
        'Ukraine Operation Overview, %',
      ],
      'english full month range no spaces' => [
        'Ukraine Operation Overview, October-December 2025',
        'Ukraine Operation Overview, %',
      ],
      'french month range' => [
        'Aperçu opérationnel, janvier - mars 2026',
        'Aperçu opérationnel, %',
      ],
    ];
  }

  /**
   * Tests multilingual date stripping for SQL LIKE patterns.
   */
  #[DataProvider('stringToLikePatternProvider')]
  public function testStringToLikePattern(string $input, string $expected): void {
    $this->assertSame($expected, TitlePatternHelper::stringToLikePattern($input));
  }

  /**
   * Tests regex pattern generation strips dates like LIKE patterns.
   */
  #[DataProvider('stringToLikePatternProvider')]
  public function testStringToRegexPattern(string $input, string $expectedLike): void {
    $regex = TitlePatternHelper::stringToRegexPattern($input);
    $this->assertNotSame('', $regex);
    $this->assertNotFalse(@preg_match('/^' . $regex . '$/iu', $input));
    $this->assertTrue(
      TitlePatternHelper::titleMatchesLikePattern($input, $expectedLike),
      'Original title should match the LIKE pattern derived from the same input.',
    );
  }

  /**
   * Tests titleToRegexPatterns returns non-empty prefix patterns.
   */
  public function testTitleToRegexPatterns(): void {
    $patterns = TitlePatternHelper::titleToRegexPatterns('SitRep 27 April 2026 #3');
    $this->assertNotSame([], $patterns);
    $this->assertTrue(
      (bool) preg_match('/^' . $patterns[0] . '$/iu', 'SitRep 27 April 2026 #3'),
    );
  }

  /**
   * Tests LIKE pattern converts to regex that matches dated titles.
   */
  public function testLikePatternToRegex(): void {
    $regex = TitlePatternHelper::likePatternToRegex('SitRep %');
    $this->assertTrue((bool) preg_match('/^' . $regex . '$/iu', 'SitRep 27 April 2026'));
    $this->assertTrue((bool) preg_match('/^' . $regex . '$/iu', 'SitRep #3'));
  }

  /**
   * Tests round-trip matching via titleMatchesLikePattern.
   */
  #[DataProvider('stringToLikePatternProvider')]
  public function testTitleMatchesLikePattern(string $input, string $expectedLike): void {
    $this->assertTrue(TitlePatternHelper::titleMatchesLikePattern($input, $expectedLike));
  }

  /**
   * Tests LIKE pattern converts to Elasticsearch title query.
   */
  public function testLikePatternToTitleQuery(): void {
    $this->assertSame('title:SitRep **', TitlePatternHelper::likePatternToTitleQuery('SitRep %'));
    $this->assertSame('', TitlePatternHelper::likePatternToTitleQuery(''));
    $this->assertSame('', TitlePatternHelper::likePatternToTitleQuery('   '));
  }

  /**
   * Tests Lucene special characters in literal segments are escaped.
   */
  public function testLikePatternToTitleQueryEscapesSpecialChars(): void {
    $query = TitlePatternHelper::likePatternToTitleQuery('Report (draft) %');
    $this->assertStringStartsWith('title:', $query);
    $this->assertStringContainsString('\\(', $query);
    $this->assertStringContainsString('\\)', $query);
    $this->assertStringContainsString('*', $query);
  }

}
