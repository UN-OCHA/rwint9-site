<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite;

use Drupal\reliefweb_post_api\ProviderListBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the ReliefWeb Post API provider list builder.
 */
#[CoversClass(ProviderListBuilder::class)]
#[Group('reliefweb_post_api')]
class ProviderListBuilderTest extends ExistingSiteBase {

  /**
   * Test build header.
   */
  public function testBuildHeader(): void {
    $list_builder = \Drupal::entityTypeManager()->getListBuilder('reliefweb_post_api_provider');
    $this->assertArrayHasKey('uuid', $list_builder->buildHeader());
  }

  /**
   * Test build row.
   */
  public function testBuildRow(): void {
    $source = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->create([
      'tid' => 123,
      'vid' => 'source',
      'name' => 'test source',
      'field_shortname' => 'ts',
    ]);

    $provider = \Drupal::entityTypeManager()->getStorage('reliefweb_post_api_provider')->create([
      'id' => 456,
      'name' => 'test',
      'uuid' => Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_URL), 'test')->toRfc4122(),
      'field_source' => [$source],
      'status' => 1,
    ]);

    $list_builder = \Drupal::entityTypeManager()->getListBuilder('reliefweb_post_api_provider');
    $row = $list_builder->buildRow($provider);
    $this->assertArrayHasKey('uuid', $row);
    $this->assertNotEmpty($row['source']['data']['#items']);
  }

}
