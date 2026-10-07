<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Plugin\reliefweb_post_api\ContentProcessor;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Tests\reliefweb_post_api\ExistingSite\Plugin\ContentProcessorPluginBaseTestCase;
use Drupal\reliefweb_post_api\Enum\ContentProcessorMessage;
use Drupal\reliefweb_post_api\Helpers\HashHelper;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorException;
use Drupal\reliefweb_post_api\Plugin\reliefweb_post_api\ContentProcessor\Training;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Training content processor plugin.
 */
#[CoversClass(Training::class)]
#[Group('reliefweb_post_api')]
class TrainingTest extends ContentProcessorPluginBaseTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->plugin = $this->contentProcessorPluginManager->getPluginByBundle('training');
  }

  /**
   * Test get plugin label.
   */
  public function testGetPluginLabel(): void {
    $this->assertEquals('Training', (string) $this->plugin->getPluginLabel());
  }

  /**
   * Test get entity type.
   */
  public function testGetEntityType(): void {
    $this->assertEquals('node', $this->plugin->getEntityType());
  }

  /**
   * Test get bundle.
   */
  public function testGetBundle(): void {
    $this->assertEquals('training', $this->plugin->getBundle());
  }

  /**
   * Test get resource.
   */
  public function testGetResource(): void {
    $this->assertEquals('training', $this->plugin->getResource());
  }

  /**
   * Test process.
   */
  public function testProcess(): void {
    $entity_repository = $this->createMock(EntityRepositoryInterface::class);

    $statement = $this->createStatementMock('fetchAllKeyed', [123 => 123]);
    $select = $this->createSelectMock($statement);
    $database = $this->createDatabaseMock($select);

    // Create a new instance of the current plugin with some mocked services.
    $plugin = $this->createDummyPlugin($this->plugin->getPluginDefinition(), [
      'entity.repository' => $entity_repository,
      'database' => $database,
    ]);

    $data = ['source' => [123]] + $this->getPostApiData('training');

    $provider = $this->getTestProvider();

    $entity = $this->createEntity('node', 'training');
    $entity->uuid = $plugin->generateUuid($data['url']);

    $entity_repository->expects($this->any())
      ->method('loadEntityByUuid')
      ->willReturnMap([
        ['node', $entity->uuid(), $entity],
        ['reliefweb_post_api_provider', $provider->uuid(), $provider],
      ]);

    $plugin->process($data);
    $this->assertSame($data['title'], $entity->label());
  }

  /**
   * Test process with wrong bundle.
   */
  public function testProcessWrongBundle(): void {
    $entity_repository = $this->createMock(EntityRepositoryInterface::class);

    // Create a new instance of the current plugin with some mocked services.
    $plugin = $this->createDummyPlugin($this->plugin->getPluginDefinition(), [
      'entity.repository' => $entity_repository,
    ]);

    $data = ['source' => [123]] + $this->getPostApiData('training');

    $provider = $this->getTestProvider();

    $entity = $this->createEntity('node', 'job');
    $entity->nid = 123;
    $entity->uuid = $plugin->generateUuid($data['url']);
    $entity->enforceIsNew(FALSE);

    $entity_repository->expects($this->any())
      ->method('loadEntityByUuid')
      ->willReturnMap([
        ['node', $entity->uuid(), $entity],
        ['reliefweb_post_api_provider', $provider->uuid(), $provider],
      ]);

    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage(ContentProcessorMessage::ExistingEntityWrongBundle->format([
      '@uuid' => $entity->uuid(),
      '@bundle' => 'training',
    ]));

    $plugin->process($data);
  }

  /**
   * Test process with refused status.
   */
  public function testProcessRefused(): void {
    $entity_repository = $this->createMock(EntityRepositoryInterface::class);

    // Create a new instance of the current plugin with some mocked services.
    $plugin = $this->createDummyPlugin($this->plugin->getPluginDefinition(), [
      'entity.repository' => $entity_repository,
    ]);

    $data = ['source' => [123]] + $this->getPostApiData('training');

    $provider = $this->getTestProvider();

    $entity = $this->createEntity('node', 'training');
    $entity->nid = 123;
    $entity->uuid = $plugin->generateUuid($data['url']);
    $entity->moderation_status = 'refused';
    $entity->enforceIsNew(FALSE);

    $entity_repository->expects($this->any())
      ->method('loadEntityByUuid')
      ->willReturnMap([
        ['node', $entity->uuid(), $entity],
        ['reliefweb_post_api_provider', $provider->uuid(), $provider],
      ]);

    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage(ContentProcessorMessage::SkippingTerminalEntity->format([
      '@uuid' => $entity->uuid(),
      '@status' => 'refused',
    ]));

    $plugin->process($data);
  }

  /**
   * Test process with duplicate status.
   */
  public function testProcessDuplicate(): void {
    $entity_repository = $this->createMock(EntityRepositoryInterface::class);

    $plugin = $this->createDummyPlugin($this->plugin->getPluginDefinition(), [
      'entity.repository' => $entity_repository,
    ]);

    $data = ['source' => [123]] + $this->getPostApiData('training');

    $provider = $this->getTestProvider();

    $entity = $this->createEntity('node', 'training');
    $entity->nid = 124;
    $entity->uuid = $plugin->generateUuid($data['url']);
    $entity->moderation_status = 'duplicate';
    $entity->enforceIsNew(FALSE);

    $entity_repository->expects($this->any())
      ->method('loadEntityByUuid')
      ->willReturnMap([
        ['node', $entity->uuid(), $entity],
        ['reliefweb_post_api_provider', $provider->uuid(), $provider],
      ]);

    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage(ContentProcessorMessage::SkippingTerminalEntity->format([
      '@uuid' => $entity->uuid(),
      '@status' => 'duplicate',
    ]));

    $plugin->process($data);
  }

  /**
   * Test process skips save when the payload hash is unchanged.
   */
  public function testProcessUnchanged(): void {
    $entity_repository = $this->createMock(EntityRepositoryInterface::class);

    $plugin = $this->createDummyPlugin($this->plugin->getPluginDefinition(), [
      'entity.repository' => $entity_repository,
    ]);

    $data = ['source' => [123]] + $this->getPostApiData('training');
    $provider = $this->getTestProvider();

    $entity = $this->createEntity('node', 'training');
    $entity->nid = 125;
    $entity->uuid = $plugin->generateUuid($data['url']);
    $entity->title = 'Original title';
    $entity->moderation_status = 'on-hold';
    $entity->set('field_post_api_hash', HashHelper::generateHash($data, ['provider', 'user']));
    $entity->enforceIsNew(FALSE);

    $entity_repository->expects($this->any())
      ->method('loadEntityByUuid')
      ->willReturnMap([
        ['node', $entity->uuid(), $entity],
        ['reliefweb_post_api_provider', $provider->uuid(), $provider],
      ]);

    $result = $plugin->process($data);
    $this->assertSame($entity, $result);
    $this->assertSame('Original title', $entity->label());
    $this->assertSame('on-hold', $entity->getModerationStatus());
  }

  /**
   * Test fee_information is forbidden when cost is free.
   */
  public function testValidateSchemaFreeWithFeeInformation(): void {
    $data = $this->getPostApiData('training');
    $data['cost'] = 'free';
    $data['fee_information'] = 'The course fee is 100 USD including materials.';

    $this->expectException(ContentProcessorException::class);
    $this->plugin->validateSchema($data);
  }

  /**
   * Test fee_information is required when cost is fee-based.
   */
  public function testValidateSchemaFeeBasedWithoutFeeInformation(): void {
    $data = $this->getPostApiData('training');
    $data['cost'] = 'fee-based';
    unset($data['fee_information']);

    $this->expectException(ContentProcessorException::class);
    $this->plugin->validateSchema($data);
  }

  /**
   * Test fee_information is accepted when cost is fee-based.
   */
  public function testValidateSchemaFeeBasedWithFeeInformation(): void {
    $data = $this->getPostApiData('training');
    $data['cost'] = 'fee-based';
    $data['fee_information'] = 'The course fee is 100 USD including materials.';

    $this->plugin->validateSchema($data);
    $this->assertTrue(TRUE);
  }

  /**
   * Test that conditionals are skipped on unrelated partial updates.
   */
  public function testValidatePartialConditionalsSkipsUnrelatedFields(): void {
    $data = $this->getPostApiData('training');
    $this->plugin->validate([
      'partial' => TRUE,
      'uuid' => $data['uuid'],
      'provider' => $this->getTestProvider()->uuid(),
      'theme' => [4596],
    ]);
    $this->assertTrue(TRUE);
  }

  /**
   * Test partial cannot clear country while format stays on-site.
   */
  public function testValidatePartialConditionalsClearCountryOnSite(): void {
    $uuid = $this->createTrainingNodeForConditionals([
      'field_training_format' => [['target_id' => 4606]],
      'field_country' => [['target_id' => 13]],
      'field_cost' => [['value' => 'free']],
    ]);

    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage(ContentProcessorMessage::CountryMandatoryForOnSite->value);
    $this->plugin->validate([
      'partial' => TRUE,
      'uuid' => $uuid,
      'provider' => $this->getTestProvider()->uuid(),
      'country' => NULL,
    ]);
  }

  /**
   * Test partial cannot set country when format is not on-site.
   */
  public function testValidatePartialConditionalsCountryNotAllowed(): void {
    $uuid = $this->createTrainingNodeForConditionals([
      'field_training_format' => [['target_id' => 4607]],
      'field_cost' => [['value' => 'free']],
    ]);

    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage(ContentProcessorMessage::CountryNotAllowed->value);
    $this->plugin->validate([
      'partial' => TRUE,
      'uuid' => $uuid,
      'provider' => $this->getTestProvider()->uuid(),
      'country' => [13],
    ]);
  }

  /**
   * Test partial fee-based cost requires fee_information.
   */
  public function testValidatePartialConditionalsFeeBasedRequiresFee(): void {
    $uuid = $this->createTrainingNodeForConditionals([
      'field_training_format' => [['target_id' => 4607]],
      'field_cost' => [['value' => 'free']],
    ]);

    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage(ContentProcessorMessage::FeeInformationMandatory->value);
    $this->plugin->validate([
      'partial' => TRUE,
      'uuid' => $uuid,
      'provider' => $this->getTestProvider()->uuid(),
      'cost' => 'fee-based',
    ]);
  }

  /**
   * Test partial cannot set fee_information when cost stays free.
   */
  public function testValidatePartialConditionalsFeeNotAllowedForFree(): void {
    $uuid = $this->createTrainingNodeForConditionals([
      'field_training_format' => [['target_id' => 4607]],
      'field_cost' => [['value' => 'free']],
    ]);

    $this->expectException(ContentProcessorException::class);
    $this->expectExceptionMessage(ContentProcessorMessage::FeeInformationNotAllowed->value);
    $this->plugin->validate([
      'partial' => TRUE,
      'uuid' => $uuid,
      'provider' => $this->getTestProvider()->uuid(),
      'fee_information' => 'The course fee is 100 USD including materials.',
    ]);
  }

  /**
   * Test partial can clear country when also switching away from on-site.
   */
  public function testValidatePartialConditionalsFormatAndCountryTogether(): void {
    $uuid = $this->createTrainingNodeForConditionals([
      'field_training_format' => [['target_id' => 4606]],
      'field_country' => [['target_id' => 13]],
      'field_cost' => [['value' => 'free']],
    ]);

    $this->plugin->validate([
      'partial' => TRUE,
      'uuid' => $uuid,
      'provider' => $this->getTestProvider()->uuid(),
      'format' => [4607],
      'country' => NULL,
    ]);
    $this->assertTrue(TRUE);
  }

  /**
   * Create a training node with conditional fields and return its UUID.
   *
   * @param array $fields
   *   Field values to set on the node.
   *
   * @return string
   *   Node UUID.
   */
  protected function createTrainingNodeForConditionals(array $fields): string {
    $uuid = $this->plugin->generateUuid('https://test.test/training/conditional-' . uniqid('', TRUE));
    $node = \Drupal::entityTypeManager()->getStorage('node')->create([
      'type' => 'training',
      'title' => 'Training conditional validation fixture',
      'uuid' => $uuid,
      'uid' => 1,
      'status' => 1,
      'moderation_status' => 'published',
    ] + $fields);
    $node->save();
    $this->markEntityForCleanup($node);
    return $uuid;
  }

}
