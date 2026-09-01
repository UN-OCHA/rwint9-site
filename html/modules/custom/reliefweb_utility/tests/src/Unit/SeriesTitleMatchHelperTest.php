<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\reliefweb_utility\Helpers\SeriesTitleMatchHelper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SeriesTitleMatchHelper layout matching.
 */
#[CoversClass(SeriesTitleMatchHelper::class)]
#[Group('reliefweb_utility')]
class SeriesTitleMatchHelperTest extends UnitTestCase {

  /**
   * Matches series titles to top-of-page PDF spans and extracts nearby markers.
   */
  public function testMatchPagesReturnsMatchedTitlesAndCandidates(): void {
    $pages = [
      [
        [
          'text' => "SITUATION D'URGENCE AU TCHAD",
          'x' => 72.0,
          'y' => 80.0,
          'w' => 400.0,
          'h' => 18.0,
          'size' => 16.0,
        ],
        [
          'text' => 'Mise à jour des arrivées du Soudan',
          'x' => 72.0,
          'y' => 100.0,
          'w' => 380.0,
          'h' => 14.0,
          'size' => 12.0,
        ],
        [
          'text' => '03 Mai 2026',
          'x' => 72.0,
          'y' => 118.0,
          'w' => 120.0,
          'h' => 12.0,
          'size' => 11.0,
        ],
        // Body content lowers the page extent so title lines stay in the top
        // band.
        [
          'text' => 'Body paragraph far below the title block.',
          'x' => 72.0,
          'y' => 500.0,
          'w' => 400.0,
          'h' => 12.0,
          'size' => 10.0,
        ],
      ],
    ];
    $titles = [
      "Situation d'urgence au Tchad : Mise à jour des arrivées du Soudan (03 Mai 2026)",
      "Situation d'urgence au Tchad : Mise à jour des arrivées du Soudan (26 avril 2026)",
      'UNHCR Tchad | Afflux biométrique (au 12 avril 2026)',
    ];

    $result = SeriesTitleMatchHelper::matchPages($pages, $titles, 5);

    $this->assertNotEmpty($result['matched_titles']);
    $this->assertStringContainsString(
      "Situation d'urgence au Tchad",
      $result['matched_titles'][0],
    );
    $this->assertNotEmpty($result['candidates']);
    $candidate = $result['candidates'][0];
    $this->assertSame(1, $candidate['page']);
    $this->assertStringContainsString("SITUATION D'URGENCE AU TCHAD", $candidate['title_region_text']);
    $this->assertSame('03 Mai 2026', $candidate['nearby_date']);
    $this->assertGreaterThan(0.0, $candidate['confidence']);
  }

  /**
   * Empty pages or titles yield empty matched results.
   */
  public function testMatchPagesEmptyInput(): void {
    $this->assertSame(
      ['matched_titles' => [], 'candidates' => []],
      SeriesTitleMatchHelper::matchPages([], ['Title']),
    );
    $this->assertSame(
      ['matched_titles' => [], 'candidates' => []],
      SeriesTitleMatchHelper::matchPages([[]], []),
    );
  }

}
