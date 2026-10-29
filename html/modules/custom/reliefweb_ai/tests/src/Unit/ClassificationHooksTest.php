<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_ai\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reliefweb_ai\Hook\ClassificationHooks;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests classification simulate form annotation helpers.
 */
#[CoversClass(ClassificationHooks::class)]
#[Group('reliefweb_ai')]
class ClassificationHooksTest extends UnitTestCase {

  /**
   * Outcome note is appended to the simulated language cell.
   */
  public function testAnnotateLanguageRow(): void {
    $rows = [
      [
        'Country',
        ['data' => ['#markup' => 'Stored country']],
        ['data' => ['#markup' => 'Simulated country']],
      ],
      [
        'Language',
        ['data' => ['#markup' => 'English']],
        ['data' => ['#markup' => 'French']],
      ],
    ];

    ClassificationHooks::annotateLanguageRow(
      $rows,
      'Language',
      new TranslatableMarkup('(detected)'),
    );

    $this->assertSame(['#markup' => 'Simulated country'], $rows[0][2]['data']);
    $this->assertSame(['#markup' => 'French'], $rows[1][2]['data']['value']);
    $this->assertSame('em', $rows[1][2]['data']['note']['#tag']);
    $this->assertInstanceOf(TranslatableMarkup::class, $rows[1][2]['data']['note']['#value']);
    $this->assertSame('(detected)', $rows[1][2]['data']['note']['#value']->getUntranslatedString());
  }

}
