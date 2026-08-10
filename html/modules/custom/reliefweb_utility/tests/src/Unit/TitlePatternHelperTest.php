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
      'us month-first range' => [
        'Global Weather Hazards Summary, May 7, 2026 - May 13, 2026',
        'Global Weather Hazards Summary, %',
      ],
      'us month-first cross month' => [
        'Global Weather Hazards Summary, April 30, 2026 – May 06, 2026',
        'Global Weather Hazards Summary, %',
      ],
      'french du au range' => [
        'Bulletin du 7 au 13 mai 2026',
        'Bulletin %',
      ],
      'spanish del al range' => [
        'Informe del 7 al 13 de mayo de 2026',
        'Informe %',
      ],
      'english cross-month day range' => [
        'Report 30 April - 6 May 2026',
        'Report %',
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

  /**
   * Tests normalizeSeriesStem matches LIKE stripping without SQL escaping.
   */
  public function testNormalizeSeriesStem(): void {
    $this->assertSame('SitRep %', TitlePatternHelper::normalizeSeriesStem('SitRep 27 April 2026 #3'));
    $this->assertSame(
      TitlePatternHelper::stringToLikePattern('SitRep 27 April 2026 #3'),
      TitlePatternHelper::normalizeSeriesStem('SitRep 27 April 2026 #3'),
    );
  }

  /**
   * Data provider for extractSeriesMarkers cases.
   *
   * @return array<string, array{string, int[], int[], list<array{start: string, end: string}>}>
   *   Title and expected issues, weeks, periods.
   */
  public static function extractSeriesMarkersProvider(): array {
    return [
      'hash issue and day' => [
        'SitRep 27 April 2026 #3',
        [3],
        [],
        [['start' => '2026-04-27', 'end' => '2026-04-27']],
      ],
      'issue label equals hash' => [
        'Bulletin Issue 189',
        [189],
        [],
        [],
      ],
      'hash with space' => [
        'Update # 189',
        [189],
        [],
        [],
      ],
      'numeric slash date' => [
        'Report 2026/07/02',
        [],
        [],
        [['start' => '2026-07-02', 'end' => '2026-07-02']],
      ],
      'textual day month year' => [
        'Report 2 July 2026',
        [],
        [],
        [['start' => '2026-07-02', 'end' => '2026-07-02']],
      ],
      'month only' => [
        'Market Bulletin March 2026',
        [],
        [],
        [['start' => '2026-03-01', 'end' => '2026-03-31']],
      ],
      'month range half year' => [
        'Overview Jan-Jun 2026',
        [],
        [],
        [['start' => '2026-01-01', 'end' => '2026-06-30']],
      ],
      'month range wraps year' => [
        'Overview Nov-Feb 2026',
        [],
        [],
        [['start' => '2026-11-01', 'end' => '2027-02-28']],
      ],
      'german month year' => [
        'Bericht Januar 2026',
        [],
        [],
        [['start' => '2026-01-01', 'end' => '2026-01-31']],
      ],
      'portuguese month year' => [
        'Boletim março 2026',
        [],
        [],
        [['start' => '2026-03-01', 'end' => '2026-03-31']],
      ],
      'bare no is not issue' => [
        'We know no 5 solutions left',
        [],
        [],
        [],
      ],
      'no-dot issue label' => [
        'Report No. 5',
        [5],
        [],
        [],
      ],
      'n-degree issue label' => [
        'Report n° 5',
        [5],
        [],
        [],
      ],
      'afghanistan weekly' => [
        'Afghanistan: Weekly Market Report: Issue 295: Week 2 - May 2026',
        [295],
        [2],
        [['start' => '2026-05-01', 'end' => '2026-05-31']],
      ],
      'casualty numbers are not issues' => [
        'Haiti: More than 1,600 people killed',
        [],
        [],
        [],
      ],
      'euro amount not issue' => [
        'EU announces €235 million in humanitarian aid',
        [],
        [],
        [],
      ],
      'us month-first range' => [
        'Global Weather Hazards Summary, May 7, 2026 - May 13, 2026',
        [],
        [],
        [['start' => '2026-05-07', 'end' => '2026-05-13']],
      ],
      'us month-first shorthand' => [
        'Report May 7-13, 2026',
        [],
        [],
        [['start' => '2026-05-07', 'end' => '2026-05-13']],
      ],
      'us month-first single' => [
        'Report May 7, 2026',
        [],
        [],
        [['start' => '2026-05-07', 'end' => '2026-05-07']],
      ],
      'english cross-month day range' => [
        'Report 30 April - 6 May 2026',
        [],
        [],
        [['start' => '2026-04-30', 'end' => '2026-05-06']],
      ],
      'english dual full date range merged' => [
        'Report 7 May 2026 - 13 May 2026',
        [],
        [],
        [['start' => '2026-05-07', 'end' => '2026-05-13']],
      ],
      'french du au same month' => [
        'Bulletin du 7 au 13 mai 2026',
        [],
        [],
        [['start' => '2026-05-07', 'end' => '2026-05-13']],
      ],
      'french du au cross month' => [
        'Bulletin du 30 avril au 6 mai 2026',
        [],
        [],
        [['start' => '2026-04-30', 'end' => '2026-05-06']],
      ],
      'spanish del al same month' => [
        'Informe del 7 al 13 de mayo de 2026',
        [],
        [],
        [['start' => '2026-05-07', 'end' => '2026-05-13']],
      ],
      'spanish del al cross month' => [
        'Informe del 30 de abril al 6 de mayo de 2026',
        [],
        [],
        [['start' => '2026-04-30', 'end' => '2026-05-06']],
      ],
      'portuguese de a same month' => [
        'Relatório de 7 a 13 de maio de 2026',
        [],
        [],
        [['start' => '2026-05-07', 'end' => '2026-05-13']],
      ],
      'portuguese de a cross month' => [
        'Relatório de 30 de abril a 6 de maio de 2026',
        [],
        [],
        [['start' => '2026-04-30', 'end' => '2026-05-06']],
      ],
    ];
  }

  /**
   * Tests extractSeriesMarkers canonicalization.
   */
  #[DataProvider('extractSeriesMarkersProvider')]
  public function testExtractSeriesMarkers(
    string $title,
    array $issues,
    array $weeks,
    array $periods,
  ): void {
    $markers = TitlePatternHelper::extractSeriesMarkers($title);
    $this->assertSame($issues, $markers['issues']);
    $this->assertSame($weeks, $markers['weeks']);
    $this->assertSame($periods, $markers['periods']);
    $this->assertNotSame('', $markers['stem']);
  }

  /**
   * Tests #189 and Issue 189 extract the same issue.
   */
  public function testExtractIssueLabelEquivalence(): void {
    $a = TitlePatternHelper::extractSeriesMarkers('Report #189');
    $b = TitlePatternHelper::extractSeriesMarkers('Report Issue 189');
    $this->assertSame([189], $a['issues']);
    $this->assertSame([189], $b['issues']);
  }

  /**
   * Tests multilingual issue labels extract the same number.
   */
  public function testExtractMultilingualIssues(): void {
    $cases = [
      'Boletín número 12',
      'Bericht Ausgabe 12',
      'Отчёт выпуск 12',
      '报告第12期',
      'تقرير عدد 12',
      'Report № 12',
    ];
    foreach ($cases as $title) {
      $markers = TitlePatternHelper::extractSeriesMarkers($title);
      $this->assertSame([12], $markers['issues'], $title);
    }
  }

  /**
   * Tests multilingual week labels extract the same number.
   */
  public function testExtractMultilingualWeeks(): void {
    $cases = [
      'Bulletin Week 7',
      'Bulletin Semaine 7',
      'Boletín Semana 7',
      'Bericht Woche 7',
      'Обзор неделя 7',
      '报告第7周',
      'تقرير أسبوع 7',
      'Update wk. 7',
    ];
    foreach ($cases as $title) {
      $markers = TitlePatternHelper::extractSeriesMarkers($title);
      $this->assertSame([7], $markers['weeks'], $title);
    }
  }

  /**
   * Tests 2026/07/02 and 2 July 2026 extract the same period.
   */
  public function testExtractDateFormatEquivalence(): void {
    $a = TitlePatternHelper::extractSeriesMarkers('Doc 2026/07/02');
    $b = TitlePatternHelper::extractSeriesMarkers('Doc 2 July 2026');
    $this->assertSame($a['periods'], $b['periods']);
    $this->assertSame([['start' => '2026-07-02', 'end' => '2026-07-02']], $a['periods']);
  }

  /**
   * Data provider for compareSeriesMarkers.
   *
   * @return array<string, array{string, string, string}>
   *   Two titles and expected compare result.
   */
  public static function compareSeriesMarkersProvider(): array {
    return [
      'afghanistan consecutive issues' => [
        'Afghanistan: Weekly Market Report: Issue 295: Week 2 - May 2026',
        'Afghanistan: Weekly Market Report: Issue 294: Week 1 - May 2026',
        TitlePatternHelper::COMPARE_SERIES_SIBLING,
      ],
      'march vs may monthly' => [
        'WFP Syria Monthly Market Price Bulletin, March 2026',
        'WFP Syria Monthly Market Price Bulletin, May 2026',
        TitlePatternHelper::COMPARE_SERIES_SIBLING,
      ],
      'half year ranges' => [
        'Ukraine Operation Overview, Jan-Jun 2026',
        'Ukraine Operation Overview, Jul-Dec 2026',
        TitlePatternHelper::COMPARE_SERIES_SIBLING,
      ],
      'same issue reformatted date' => [
        'SitRep 27 April 2026 #3',
        'SitRep 2026/04/27 #3',
        TitlePatternHelper::COMPARE_INCONCLUSIVE,
      ],
      'unrelated titles' => [
        'Monthly Situation Report',
        'Drought and displacement in Somalia',
        TitlePatternHelper::COMPARE_UNRELATED,
      ],
      'date only titles unrelated' => [
        '27 April 2026',
        '15 May 2025',
        TitlePatternHelper::COMPARE_UNRELATED,
      ],
      'same issue different months still sibling' => [
        'Bulletin Issue 10, May 2026',
        'Bulletin Issue 10, June 2026',
        TitlePatternHelper::COMPARE_SERIES_SIBLING,
      ],
      'fews us week ranges sibling' => [
        'Global Weather Hazards Summary, May 7, 2026 - May 13, 2026',
        'Global Weather Hazards Summary, April 30, 2026 – May 06, 2026',
        TitlePatternHelper::COMPARE_SERIES_SIBLING,
      ],
      'fews comma vs no comma sibling' => [
        'Global Weather Hazards Summary, April 02, 2026 – April 08, 2026',
        'Global Weather Hazards Summary March 26, 2026 - April 1, 2026',
        TitlePatternHelper::COMPARE_SERIES_SIBLING,
      ],
    ];
  }

  /**
   * Tests compareSeriesMarkers outcomes.
   */
  #[DataProvider('compareSeriesMarkersProvider')]
  public function testCompareSeriesMarkers(string $title_a, string $title_b, string $expected): void {
    $a = TitlePatternHelper::extractSeriesMarkers($title_a);
    $b = TitlePatternHelper::extractSeriesMarkers($title_b);
    $this->assertSame($expected, TitlePatternHelper::compareSeriesMarkers($a, $b));
  }

}
