<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Attribute;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reliefweb_post_api\Attribute\ContentProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the content processor attribute.
 */
#[CoversClass(ContentProcessor::class)]
#[Group('reliefweb_post_api')]
class ContentProcessorTest extends ExistingSiteBase {

  /**
   * Test constructor.
   */
  public function testContructor(): void {
    $attribute = new ContentProcessor(
      'test',
      new TranslatableMarkup('test processor'),
      'test_entity_type',
      'test_bundle',
      'test_resource',
    );
    $this->assertInstanceOf(ContentProcessor::class, $attribute);
  }

}
