<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_sync_orgs\Unit;

use Drupal\reliefweb_sync_orgs\Service\FuzySearchService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test fuse matches for ReliefWeb Sync Orgs.
 */
#[CoversClass(FuzySearchService::class)]
class FuseMatchesTest extends TestCase {

  /**
   * Fuse.
   *
   * @var \Drupal\reliefweb_sync_orgs\Service\FuzySearchService
   */
  protected $fuse;

  /**
   * Test exact fuse matches.
   */
  #[DataProvider('tagExtractionProvider')]
  public function testExactFuseMatches($search, $expected, $status) {
    $terms = [
      [
        'tid' => 1,
        'name' => 'Internal Displacement Monitoring Centre',
      ],
      [
        'tid' => 2,
        'name' => 'International Federation of Red Cross and Red Crescent Societies',
      ],
      [
        'tid' => 3,
        'name' => 'World Food Programme',
      ],
      [
        'tid' => 4,
        'name' => 'International Organization for Migration',
      ],
      [
        'tid' => 5,
        'name' => 'Housing Recovery and Reconstruction Platform – Nepal',
      ],
      [
        'tid' => 6,
        'name' => 'Global Child Protection Services Ltd',
      ],
    ];

    $fuse = new FuzySearchService($terms);

    $result = $fuse->search($search);
    if (!$result) {
      $this->assertNull($expected);
      return;
    }

    $this->assertEquals($expected, $result['tid'], "Expected tid for '$search' is $expected");
    $this->assertEquals($status, $result['status'], "Expected status for '$search' is $status");
  }

  /**
   * Test cases for ExactFuseMatches method.
   */
  public static function tagExtractionProvider() {
    return [
      ['Internal Displacement Monitoring Centre', 1, 'exact'],
      ['International Federation of Red Cross and Red Crescent Societies', 2, 'exact'],
      ['World Food Programme', 3, 'exact'],
      ['Non-existing Organization', NULL, 'skipped'],
      ['Internal Displacement Monitoring Centre (IDMC)', 1, 'exact'],
      ['International Organization for Migration (IOM)', 4, 'exact'],
      ['Housing recovery and reconstruction platform (HRRP) - Nepal', 5, 'partial'],
      ['Global Child Protection AoR', 6, 'mismatch'],
    ];
  }

}
