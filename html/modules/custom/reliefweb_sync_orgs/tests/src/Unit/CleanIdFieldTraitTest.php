<?php

namespace Drupal\Tests\reliefweb_sync_orgs\Unit\Traits;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Drupal\reliefweb_sync_orgs\Traits\CleanIdFieldTrait;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @coversDefaultClass \Drupal\reliefweb_sync_orgs\Traits\CleanIdFieldTrait
 */
#[CoversClass(CleanIdFieldTrait::class)]
class CleanIdFieldTraitTest extends UnitTestCase {

  use CleanIdFieldTrait;

  /**
   * Test cleanId method.
   */
  #[DataProvider('cleanIdProvider')]
  public function testCleanId(string $input, string $expected): void {
    $result = $this->cleanId($input);
    $this->assertSame($expected, $result);
  }

  /**
   * Data provider for testCleanId.
   */
  public static function cleanIdProvider(): array {
    return [
      'plain id' => ['normal-id', 'normal-id'],
      'remove slashes' => ['a/b/c', 'abc'],
      'remove multibyte (é)' => ["café", 'caf'],
      'remove control chars' => ["line\nbreak\tend", 'linebreakend'],
      'truncate long id' => [str_repeat('a', 130), str_repeat('a', 127)],
      'combined case' => ["/long/naïve\n" . str_repeat('x', 130), 'longnave' . str_repeat('x', 119)],
    ];
  }

}
