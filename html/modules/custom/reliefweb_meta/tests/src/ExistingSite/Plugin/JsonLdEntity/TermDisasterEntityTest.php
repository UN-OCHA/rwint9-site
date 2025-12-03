<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_meta\ExistingSite\Plugin\JsonLdEntity;

use Drupal\json_ld_schema\Entity\JsonLdEntityInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests TermDisasterEntity getData method.
 */
class TermDisasterEntityTest extends ExistingSiteBase {

  /**
   * Original schema.org content length state value.
   *
   * @var int|null
   */
  protected ?int $originalSchemaOrgContentLength = NULL;

  /**
   * Disaster vocabulary.
   *
   * @var \Drupal\taxonomy\Entity\Vocabulary|null
   */
  protected ?Vocabulary $disasterVocabulary = NULL;

  /**
   * Disaster type vocabulary.
   *
   * @var \Drupal\taxonomy\Entity\Vocabulary|null
   */
  protected ?Vocabulary $disasterTypeVocabulary = NULL;

  /**
   * Country vocabulary.
   *
   * @var \Drupal\taxonomy\Entity\Vocabulary|null
   */
  protected ?Vocabulary $countryVocabulary = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->saveOriginalSchemaOrgContentLength();
    $this->setSchemaOrgContentLength(1000);
    $this->setUpVocabularies();
  }

  /**
   * Set up all necessary vocabularies.
   */
  protected function setUpVocabularies(): void {
    $this->disasterVocabulary = Vocabulary::load('disaster');
    if (!$this->disasterVocabulary) {
      $this->disasterVocabulary = Vocabulary::create([
        'vid' => 'disaster',
        'name' => 'Disaster',
      ]);
      $this->disasterVocabulary->save();
    }

    $this->disasterTypeVocabulary = Vocabulary::load('disaster_type');
    if (!$this->disasterTypeVocabulary) {
      $this->disasterTypeVocabulary = Vocabulary::create([
        'vid' => 'disaster_type',
        'name' => 'Disaster Type',
      ]);
      $this->disasterTypeVocabulary->save();
    }

    $this->countryVocabulary = Vocabulary::load('country');
    if (!$this->countryVocabulary) {
      $this->countryVocabulary = Vocabulary::create([
        'vid' => 'country',
        'name' => 'Country',
      ]);
      $this->countryVocabulary->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->restoreOriginalSchemaOrgContentLength();
    parent::tearDown();
  }

  /**
   * Save the original schema.org content length state value.
   */
  protected function saveOriginalSchemaOrgContentLength(): void {
    $state = \Drupal::service('state');
    $this->originalSchemaOrgContentLength = $state->get('reliefweb_meta_schema_org_content_length:taxonomy_term:disaster', NULL);
  }

  /**
   * Restore the original schema.org content length state value.
   */
  protected function restoreOriginalSchemaOrgContentLength(): void {
    $state = \Drupal::service('state');
    if ($this->originalSchemaOrgContentLength !== NULL) {
      $state->set('reliefweb_meta_schema_org_content_length:taxonomy_term:disaster', $this->originalSchemaOrgContentLength);
    }
    else {
      $state->delete('reliefweb_meta_schema_org_content_length:taxonomy_term:disaster');
    }
  }

  /**
   * Set the schema.org content length state value for testing.
   *
   * @param int $length
   *   Content length. -1 means no limit, 0 means no content.
   */
  protected function setSchemaOrgContentLength(int $length): void {
    $state = \Drupal::service('state');
    $state->set('reliefweb_meta_schema_org_content_length:taxonomy_term:disaster', $length);
  }

  /**
   * Test isApplicable for disaster terms.
   */
  public function testIsApplicableForDisasterTerms(): void {
    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Applicable Disaster',
      'moderation_status' => 'ongoing',
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $this->assertTrue($plugin->isApplicable($entity, 'default'));
  }

  /**
   * Test isApplicable rejects non-disaster terms.
   */
  public function testIsApplicableRejectsNonDisasterTerms(): void {
    $vocabulary = Vocabulary::create([
      'vid' => 'test_' . $this->randomMachineName(),
      'name' => 'Test Vocabulary',
    ]);
    $vocabulary->save();

    $entity = $this->createTerm($vocabulary, [
      'name' => 'Not A Disaster',
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $this->assertFalse($plugin->isApplicable($entity, 'default'));
  }

  /**
   * Test isApplicable rejects wrong entity type.
   */
  public function testIsApplicableRejectsWrongEntityType(): void {
    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Not A Disaster',
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $this->assertFalse($plugin->isApplicable($entity, 'default'));
  }

  /**
   * Test isApplicable rejects new entities without ID.
   */
  public function testIsApplicableRejectsNewEntities(): void {
    $entity = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->create([
        'vid' => 'disaster',
        'name' => 'New Disaster',
      ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $this->assertFalse($plugin->isApplicable($entity, 'default'));
  }

  /**
   * Test isApplicable rejects unpublished entities.
   */
  public function testIsApplicableRejectsUnpublishedEntities(): void {
    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Unpublished Disaster',
      'moderation_status' => 'draft',
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $this->assertFalse($plugin->isApplicable($entity, 'default'));
  }

  /**
   * Test getData with basic disaster schema.
   */
  public function testGetDataBasicDisaster(): void {
    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Test Disaster',
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('@type', $data);
    $this->assertEquals('Event', $data['@type']);
    $this->assertArrayHasKey('name', $data);
    $this->assertEquals('Test Disaster', $data['name']);
    $this->assertArrayHasKey('@id', $data);
    $this->assertArrayHasKey('url', $data);
    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('Disaster', $data['keywords']);
    // Moderation status should be present.
    $this->assertNotEmpty($data['keywords']);
  }

  /**
   * Test getData with disaster date.
   */
  public function testGetDataWithDisasterDate(): void {
    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Test Disaster',
      'field_disaster_date' => [
        ['value' => '2021-06-01'],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('startDate', $data);
    $this->assertEquals('2021-06-01', $data['startDate']);
  }

  /**
   * Test getData without disaster date.
   */
  public function testGetDataWithoutDisasterDate(): void {
    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Test Disaster',
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayNotHasKey('startDate', $data);
  }

  /**
   * Test getData with GLIDE number.
   */
  public function testGetDataWithGlideNumber(): void {
    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Test Disaster',
      'field_glide' => [
        ['value' => 'GL-2021-000001-ABC'],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('identifier', $data);
    $this->assertIsArray($data['identifier']);
    $this->assertCount(1, $data['identifier']);
    $this->assertEquals('GL-2021-000001-ABC', $data['identifier'][0]['value']);
    $this->assertEquals('https://glidenumber.net/', $data['identifier'][0]['propertyID']);
  }

  /**
   * Test getData with related GLIDE numbers.
   */
  public function testGetDataWithRelatedGlideNumbers(): void {
    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Test Disaster',
      'field_glide_related' => [
        ['value' => "GL-2021-000001-ABC\nGL-2021-000002-DEF"],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('identifier', $data);
    $this->assertIsArray($data['identifier']);
    $this->assertCount(2, $data['identifier']);
    $this->assertEquals('GL-2021-000001-ABC', $data['identifier'][0]['value']);
    $this->assertEquals('GL-2021-000002-DEF', $data['identifier'][1]['value']);
  }

  /**
   * Test getData with both GLIDE and related GLIDE numbers.
   */
  public function testGetDataWithGlideAndRelatedGlideNumbers(): void {
    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Test Disaster',
      'field_glide' => [
        ['value' => 'GL-2021-000001-ABC'],
      ],
      'field_glide_related' => [
        ['value' => "GL-2021-000002-DEF\nGL-2021-000003-GHI"],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('identifier', $data);
    $this->assertIsArray($data['identifier']);
    $this->assertCount(3, $data['identifier']);
    $glide_values = array_column($data['identifier'], 'value');
    $this->assertContains('GL-2021-000001-ABC', $glide_values);
    $this->assertContains('GL-2021-000002-DEF', $glide_values);
    $this->assertContains('GL-2021-000003-GHI', $glide_values);
  }

  /**
   * Test getData with duplicate GLIDE numbers (should be unique).
   */
  public function testGetDataWithDuplicateGlideNumbers(): void {
    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Test Disaster',
      'field_glide' => [
        ['value' => 'GL-2021-000001-ABC'],
      ],
      'field_glide_related' => [
        ['value' => "GL-2021-000001-ABC\nGL-2021-000002-DEF"],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('identifier', $data);
    $this->assertIsArray($data['identifier']);
    // Duplicate should be removed.
    $this->assertCount(2, $data['identifier']);
    $glide_values = array_column($data['identifier'], 'value');
    $this->assertContains('GL-2021-000001-ABC', $glide_values);
    $this->assertContains('GL-2021-000002-DEF', $glide_values);
  }

  /**
   * Test getData with empty/whitespace GLIDE numbers (should be filtered).
   */
  public function testGetDataWithEmptyGlideNumbers(): void {
    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Test Disaster',
      'field_glide_related' => [
        ['value' => "GL-2021-000001-ABC\n\n  \nGL-2021-000002-DEF"],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('identifier', $data);
    $this->assertIsArray($data['identifier']);
    // Empty lines should be filtered out.
    $this->assertCount(2, $data['identifier']);
  }

  /**
   * Test getData with primary disaster type.
   */
  public function testGetDataWithPrimaryDisasterType(): void {
    $disaster_type = $this->createTerm($this->disasterTypeVocabulary, [
      'name' => 'Earthquake',
    ]);

    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Test Disaster',
      'field_primary_disaster_type' => [
        ['target_id' => $disaster_type->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('Disaster', $data['keywords']);
    $this->assertContains('Earthquake', $data['keywords']);
  }

  /**
   * Test getData with disaster types.
   */
  public function testGetDataWithDisasterTypes(): void {
    $disaster_type1 = $this->createTerm($this->disasterTypeVocabulary, [
      'name' => 'Earthquake',
    ]);

    $disaster_type2 = $this->createTerm($this->disasterTypeVocabulary, [
      'name' => 'Flood',
    ]);

    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Test Disaster',
      'field_disaster_type' => [
        ['target_id' => $disaster_type1->id()],
        ['target_id' => $disaster_type2->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('Disaster', $data['keywords']);
    $this->assertContains('Earthquake', $data['keywords']);
    $this->assertContains('Flood', $data['keywords']);
  }

  /**
   * Test getData with both primary and additional disaster types.
   */
  public function testGetDataWithPrimaryAndDisasterTypes(): void {
    $primary_type = $this->createTerm($this->disasterTypeVocabulary, [
      'name' => 'Earthquake',
    ]);

    $disaster_type = $this->createTerm($this->disasterTypeVocabulary, [
      'name' => 'Flood',
    ]);

    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Test Disaster',
      'field_primary_disaster_type' => [
        ['target_id' => $primary_type->id()],
      ],
      'field_disaster_type' => [
        ['target_id' => $disaster_type->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('Disaster', $data['keywords']);
    $this->assertContains('Earthquake', $data['keywords']);
    $this->assertContains('Flood', $data['keywords']);
  }

  /**
   * Test getData with duplicate disaster types (should be unique).
   */
  public function testGetDataWithDuplicateDisasterTypes(): void {
    $disaster_type = $this->createTerm($this->disasterTypeVocabulary, [
      'name' => 'Earthquake',
    ]);

    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Test Disaster',
      'field_primary_disaster_type' => [
        ['target_id' => $disaster_type->id()],
      ],
      'field_disaster_type' => [
        ['target_id' => $disaster_type->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('keywords', $data);
    // Duplicate should be removed.
    $keyword_count = array_count_values($data['keywords']);
    $this->assertEquals(1, $keyword_count['Earthquake']);
  }

  /**
   * Test getData with primary country.
   */
  public function testGetDataWithPrimaryCountry(): void {
    $country = $this->createTerm($this->countryVocabulary, [
      'name' => 'Afghanistan',
    ]);

    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Test Disaster',
      'field_primary_country' => [
        ['target_id' => $country->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('location', $data);
    $this->assertIsArray($data['location']);
    $this->assertCount(1, $data['location']);
    $this->assertEquals('Afghanistan', $data['location'][0]['name']);
  }

  /**
   * Test getData with countries.
   */
  public function testGetDataWithCountries(): void {
    $country1 = $this->createTerm($this->countryVocabulary, [
      'name' => 'Afghanistan',
    ]);

    $country2 = $this->createTerm($this->countryVocabulary, [
      'name' => 'Syria',
    ]);

    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Test Disaster',
      'field_country' => [
        ['target_id' => $country1->id()],
        ['target_id' => $country2->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('location', $data);
    $this->assertIsArray($data['location']);
    $this->assertCount(2, $data['location']);
    $country_names = array_column($data['location'], 'name');
    $this->assertContains('Afghanistan', $country_names);
    $this->assertContains('Syria', $country_names);
  }

  /**
   * Test getData with both primary and additional countries.
   */
  public function testGetDataWithPrimaryAndCountries(): void {
    $primary_country = $this->createTerm($this->countryVocabulary, [
      'name' => 'Afghanistan',
    ]);

    $country = $this->createTerm($this->countryVocabulary, [
      'name' => 'Syria',
    ]);

    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Test Disaster',
      'field_primary_country' => [
        ['target_id' => $primary_country->id()],
      ],
      'field_country' => [
        ['target_id' => $country->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('location', $data);
    $this->assertIsArray($data['location']);
    $this->assertCount(2, $data['location']);
    $country_names = array_column($data['location'], 'name');
    $this->assertContains('Afghanistan', $country_names);
    $this->assertContains('Syria', $country_names);
  }

  /**
   * Test getData with duplicate countries (should be deduplicated by ID).
   */
  public function testGetDataWithDuplicateCountries(): void {
    $country = $this->createTerm($this->countryVocabulary, [
      'name' => 'Afghanistan',
    ]);

    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Test Disaster',
      'field_primary_country' => [
        ['target_id' => $country->id()],
      ],
      'field_country' => [
        ['target_id' => $country->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('location', $data);
    $this->assertIsArray($data['location']);
    // Duplicate should be removed (deduplicated by ID).
    $this->assertCount(1, $data['location']);
    $this->assertEquals('Afghanistan', $data['location'][0]['name']);
  }

  /**
   * Test getData without countries.
   */
  public function testGetDataWithoutCountries(): void {
    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Test Disaster',
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayNotHasKey('location', $data);
  }

  /**
   * Test getData with description content.
   */
  public function testGetDataWithDescription(): void {
    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Test Disaster',
      'description' => [
        [
          'value' => 'This is a test disaster description that should be summarized.',
          'format' => 'markdown',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // The description field should be present if description content exists and
    // summarization works.
    $this->assertArrayHasKey('description', $data);
    $this->assertNotEmpty($data['description']);
  }

  /**
   * Test getData without description content.
   */
  public function testGetDataWithoutDescription(): void {
    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Test Disaster',
      'description' => NULL,
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // No description content should not set description field.
    $this->assertArrayNotHasKey('description', $data);
  }

  /**
   * Test getData with empty description content.
   */
  public function testGetDataWithEmptyDescription(): void {
    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Test Disaster',
      'description' => [
        [
          'value' => '',
          'format' => 'markdown',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // Empty description content should not set description field.
    $this->assertArrayNotHasKey('description', $data);
  }

  /**
   * Test getData with all fields combined.
   */
  public function testGetDataWithAllFields(): void {
    $primary_disaster_type = $this->createTerm($this->disasterTypeVocabulary, [
      'name' => 'Earthquake',
    ]);

    $disaster_type = $this->createTerm($this->disasterTypeVocabulary, [
      'name' => 'Flood',
    ]);

    $primary_country = $this->createTerm($this->countryVocabulary, [
      'name' => 'Afghanistan',
    ]);

    $country = $this->createTerm($this->countryVocabulary, [
      'name' => 'Syria',
    ]);

    $entity = $this->createTerm($this->disasterVocabulary, [
      'name' => 'Comprehensive Test Disaster',
      'field_disaster_date' => [
        ['value' => '2021-06-01'],
      ],
      'field_glide' => [
        ['value' => 'GL-2021-000001-ABC'],
      ],
      'field_glide_related' => [
        ['value' => 'GL-2021-000002-DEF'],
      ],
      'field_primary_disaster_type' => [
        ['target_id' => $primary_disaster_type->id()],
      ],
      'field_disaster_type' => [
        ['target_id' => $disaster_type->id()],
      ],
      'field_primary_country' => [
        ['target_id' => $primary_country->id()],
      ],
      'field_country' => [
        ['target_id' => $country->id()],
      ],
      'description' => [
        [
          'value' => 'This is comprehensive test disaster description.',
          'format' => 'markdown',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_disaster');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // Verify all expected fields are present.
    $this->assertEquals('Event', $data['@type']);
    $this->assertEquals('Comprehensive Test Disaster', $data['name']);
    $this->assertEquals('2021-06-01', $data['startDate']);
    $this->assertArrayHasKey('identifier', $data);
    $this->assertIsArray($data['identifier']);
    $this->assertCount(2, $data['identifier']);
    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('Disaster', $data['keywords']);
    $this->assertContains('Earthquake', $data['keywords']);
    $this->assertContains('Flood', $data['keywords']);
    $this->assertArrayHasKey('location', $data);
    $this->assertIsArray($data['location']);
    $this->assertCount(2, $data['location']);
    $this->assertArrayHasKey('description', $data);
  }

  /**
   * Get the plugin instance.
   *
   * @param string $plugin_id
   *   The plugin ID.
   *
   * @return \Drupal\json_ld_schema\Entity\JsonLdEntityInterface
   *   The plugin instance.
   */
  protected function getPlugin(string $plugin_id): JsonLdEntityInterface {
    return $this->container->get('plugin.manager.json_ld_schema.entity')->createInstance($plugin_id);
  }

}
