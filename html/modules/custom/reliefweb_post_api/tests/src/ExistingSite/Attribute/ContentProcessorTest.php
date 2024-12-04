<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Attribute;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reliefweb_post_api\Attribute\ContentProcessor;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the content processor attribute.
 *
 * @coversDefaultClass \Drupal\reliefweb_post_api\Attribute\ContentProcessor
 *
 * @group reliefweb_post_api
 */
class ContentProcessorTest extends ExistingSiteBase {

  /**
   * @covers ::__construct
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
