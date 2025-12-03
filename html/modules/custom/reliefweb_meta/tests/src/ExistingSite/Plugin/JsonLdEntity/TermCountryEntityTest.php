<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_meta\ExistingSite\Plugin\JsonLdEntity;

use Drupal\json_ld_schema\Entity\JsonLdEntityInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests TermCountryEntity getData method.
 */
class TermCountryEntityTest extends ExistingSiteBase {

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
    $this->setUpCountryVocabulary();
  }

  /**
   * Ensure the country vocabulary exists for the tests.
   */
  protected function setUpCountryVocabulary(): void {
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
   * Test isApplicable for country terms.
   */
  public function testIsApplicableForCountryTerms(): void {
    $entity = $this->createTerm($this->countryVocabulary, [
      'name' => 'Applicable Country',
      'moderation_status' => 'ongoing',
    ]);

    $plugin = $this->getPlugin('rw_term_country');
    $this->assertTrue($plugin->isApplicable($entity, 'default'));
  }

  /**
   * Test isApplicable rejects non-country terms.
   */
  public function testIsApplicableRejectsNonCountryTerms(): void {
    $vocabulary = Vocabulary::create([
      'vid' => 'test_' . $this->randomMachineName(),
      'name' => 'Test Vocabulary',
    ]);
    $vocabulary->save();

    $entity = $this->createTerm($vocabulary, [
      'name' => 'Not A Country',
    ]);

    $plugin = $this->getPlugin('rw_term_country');
    $this->assertFalse($plugin->isApplicable($entity, 'default'));
  }

  /**
   * Test isApplicable rejects new entities without ID.
   */
  public function testIsApplicableRejectsNewEntities(): void {
    $entity = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->create([
        'vid' => 'country',
        'name' => 'New Country',
      ]);

    $plugin = $this->getPlugin('rw_term_country');
    $this->assertFalse($plugin->isApplicable($entity, 'default'));
  }

  /**
   * Test isApplicable rejects unpublished entities.
   */
  public function testIsApplicableRejectsUnpublishedEntities(): void {
    $entity = $this->createTerm($this->countryVocabulary, [
      'name' => 'Unpublished Country',
      'moderation_status' => 'draft',
    ]);

    $plugin = $this->getPlugin('rw_term_country');
    $this->assertFalse($plugin->isApplicable($entity, 'default'));
  }

  /**
   * Test getData with the basic country schema.
   */
  public function testGetDataBasicCountry(): void {
    $entity = $this->createTerm($this->countryVocabulary, [
      'name' => 'Test Country',
    ]);

    $plugin = $this->getPlugin('rw_term_country');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertEquals('CollectionPage', $data['@type']);
    $this->assertEquals('Test Country', $data['name']);
    $this->assertArrayHasKey('@id', $data);
    $this->assertArrayHasKey('url', $data);
    $this->assertEquals($data['@id'], $data['url']);

    $this->assertArrayHasKey('about', $data);
    $about = $data['about'];
    $this->assertIsArray($about);
    $this->assertEquals('Country', $about['@type']);
    $this->assertEquals('Test Country', $about['name']);
    $this->assertArrayHasKey('@id', $about);
    $this->assertArrayNotHasKey('alternateName', $about);
    // No ISO3 code for the country so no identifier.
    $this->assertArrayNotHasKey('identifier', $about);
  }

  /**
   * Test getData adds ISO3 identifier when available.
   */
  public function testGetDataWithIso3Identifier(): void {
    $entity = $this->createTerm($this->countryVocabulary, [
      'name' => 'Afghanistan',
      'field_iso3' => [
        ['value' => 'AFG'],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_country');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $about = $data['about'];
    $this->assertArrayHasKey('identifier', $about);
    $this->assertIsArray($about['identifier']);
    // Since there is only one ISO3 code, the `identifier` property is an
    // associative array with the propertyID and value.
    $this->assertEquals('ISO 3166-1 alpha-3', $about['identifier']['propertyID']);
    $this->assertEquals('AFG', $about['identifier']['value']);
  }

  /**
   * Test getData aggregates alternate names from multiple fields.
   */
  public function testGetDataWithAlternateNames(): void {
    $entity = $this->createTerm($this->countryVocabulary, [
      'name' => 'Test Country',
      'field_shortname' => [
        ['value' => 'Test'],
      ],
      'field_longname' => [
        ['value' => 'Test Country Long'],
      ],
      'field_aliases' => [
        ['value' => "Test Country\nAlias One\nAlias One\n  Alternate Two  "],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_country');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $about = $data['about'];
    $this->assertArrayHasKey('alternateName', $about);
    $this->assertEquals([
      'Test',
      'Test Country Long',
      'Alias One',
      'Alternate Two',
    ], $about['alternateName']);
  }

  /**
   * Test getData adds geo coordinates when available.
   */
  public function testGetDataWithGeoCoordinates(): void {
    $entity = $this->createTerm($this->countryVocabulary, [
      'name' => 'Geo Country',
      'field_location' => [
        [
          'value' => 'POINT (20.456 10.123)',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_term_country');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $about = $data['about'];
    $this->assertArrayHasKey('geo', $about);
    $this->assertEquals('GeoCoordinates', $about['geo']['@type']);
    $this->assertEquals(10.123, (float) $about['geo']['latitude']);
    $this->assertEquals(20.456, (float) $about['geo']['longitude']);
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
