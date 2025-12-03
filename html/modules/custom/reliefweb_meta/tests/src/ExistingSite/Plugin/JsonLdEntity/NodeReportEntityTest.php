<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_meta\ExistingSite\Plugin\JsonLdEntity;

use Drupal\json_ld_schema\Entity\JsonLdEntityInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests NodeReportEntity getData method.
 */
class NodeReportEntityTest extends ExistingSiteBase {

  /**
   * Original schema.org content length state value.
   *
   * @var int|null
   */
  protected ?int $originalSchemaOrgContentLength = NULL;

  /**
   * Content format vocabulary.
   *
   * @var \Drupal\taxonomy\Entity\Vocabulary|null
   */
  protected ?Vocabulary $contentFormatVocabulary = NULL;

  /**
   * Content format term with ID 8 (news_article).
   *
   * @var \Drupal\taxonomy\Entity\Term|null
   */
  protected ?Term $contentFormatNewsArticle = NULL;

  /**
   * Content format term with ID 12 (map).
   *
   * @var \Drupal\taxonomy\Entity\Term|null
   */
  protected ?Term $contentFormatMap = NULL;

  /**
   * Content format term with ID 13 (for default test).
   *
   * @var \Drupal\taxonomy\Entity\Term|null
   */
  protected ?Term $contentFormatDefault = NULL;

  /**
   * Theme vocabulary.
   *
   * @var \Drupal\taxonomy\Entity\Vocabulary|null
   */
  protected ?Vocabulary $themeVocabulary = NULL;

  /**
   * Disaster type vocabulary.
   *
   * @var \Drupal\taxonomy\Entity\Vocabulary|null
   */
  protected ?Vocabulary $disasterTypeVocabulary = NULL;

  /**
   * Source vocabulary.
   *
   * @var \Drupal\taxonomy\Entity\Vocabulary|null
   */
  protected ?Vocabulary $sourceVocabulary = NULL;

  /**
   * Country vocabulary.
   *
   * @var \Drupal\taxonomy\Entity\Vocabulary|null
   */
  protected ?Vocabulary $countryVocabulary = NULL;

  /**
   * Language vocabulary.
   *
   * @var \Drupal\taxonomy\Entity\Vocabulary|null
   */
  protected ?Vocabulary $languageVocabulary = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->saveOriginalSchemaOrgContentLength();
    $this->setSchemaOrgContentLength(1000);
    $this->setUpVocabularies();
    $this->setUpContentFormatTerms();
  }

  /**
   * Set up all necessary vocabularies.
   */
  protected function setUpVocabularies(): void {
    $this->contentFormatVocabulary = Vocabulary::load('content_format');
    if (!$this->contentFormatVocabulary) {
      $this->contentFormatVocabulary = Vocabulary::create([
        'vid' => 'content_format',
        'name' => 'Content Format',
      ]);
      $this->contentFormatVocabulary->save();
    }

    $this->themeVocabulary = Vocabulary::load('theme');
    if (!$this->themeVocabulary) {
      $this->themeVocabulary = Vocabulary::create([
        'vid' => 'theme',
        'name' => 'Theme',
      ]);
      $this->themeVocabulary->save();
    }

    $this->disasterTypeVocabulary = Vocabulary::load('disaster_type');
    if (!$this->disasterTypeVocabulary) {
      $this->disasterTypeVocabulary = Vocabulary::create([
        'vid' => 'disaster_type',
        'name' => 'Disaster Type',
      ]);
      $this->disasterTypeVocabulary->save();
    }

    $this->sourceVocabulary = Vocabulary::load('source');
    if (!$this->sourceVocabulary) {
      $this->sourceVocabulary = Vocabulary::create([
        'vid' => 'source',
        'name' => 'Source',
      ]);
      $this->sourceVocabulary->save();
    }

    $this->countryVocabulary = Vocabulary::load('country');
    if (!$this->countryVocabulary) {
      $this->countryVocabulary = Vocabulary::create([
        'vid' => 'country',
        'name' => 'Country',
      ]);
      $this->countryVocabulary->save();
    }

    $this->languageVocabulary = Vocabulary::load('language');
    if (!$this->languageVocabulary) {
      $this->languageVocabulary = Vocabulary::create([
        'vid' => 'language',
        'name' => 'Language',
      ]);
      $this->languageVocabulary->save();
    }
  }

  /**
   * Set up content format vocabulary and terms with known IDs.
   */
  protected function setUpContentFormatTerms(): void {

    // Ensure content format term with ID 8 exists (news_article).
    $this->contentFormatNewsArticle = Term::load(8);
    if (!$this->contentFormatNewsArticle || $this->contentFormatNewsArticle->bundle() !== 'content_format') {
      $this->contentFormatNewsArticle = $this->createTerm($this->contentFormatVocabulary, [
        'tid' => 8,
        'name' => 'News Article',
        'field_json_schema' => [
          ['value' => 'news_article'],
        ],
      ]);
    }

    // Ensure content format term with ID 12 exists (map).
    $this->contentFormatMap = Term::load(12);
    if (!$this->contentFormatMap || $this->contentFormatMap->bundle() !== 'content_format') {
      $this->contentFormatMap = $this->createTerm($this->contentFormatVocabulary, [
        'tid' => 12,
        'name' => 'Map',
        'field_json_schema' => [
          ['value' => 'map'],
        ],
      ]);
    }

    // Create content format term with ID 13 for default test.
    $this->contentFormatDefault = Term::load(13);
    if (!$this->contentFormatDefault || $this->contentFormatDefault->bundle() !== 'content_format') {
      $this->contentFormatDefault = $this->createTerm($this->contentFormatVocabulary, [
        'tid' => 13,
        'name' => 'Other Format',
      ]);
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
    $this->originalSchemaOrgContentLength = $state->get('reliefweb_meta_schema_org_content_length:node:report', NULL);
  }

  /**
   * Restore the original schema.org content length state value.
   */
  protected function restoreOriginalSchemaOrgContentLength(): void {
    $state = \Drupal::service('state');
    if ($this->originalSchemaOrgContentLength !== NULL) {
      $state->set('reliefweb_meta_schema_org_content_length:node:report', $this->originalSchemaOrgContentLength);
    }
    else {
      $state->delete('reliefweb_meta_schema_org_content_length:node:report');
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
    $state->set('reliefweb_meta_schema_org_content_length:node:report', $length);
  }

  /**
   * Test isApplicable for report nodes.
   */
  public function testIsApplicableForReportNodes(): void {
    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Applicable Report',
      'moderation_status' => 'published',
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $this->assertTrue($plugin->isApplicable($entity, 'default'));
  }

  /**
   * Test isApplicable rejects non-report nodes.
   */
  public function testIsApplicableRejectsNonReportNodes(): void {
    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Not A Report',
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $this->assertFalse($plugin->isApplicable($entity, 'default'));
  }

  /**
   * Test isApplicable rejects wrong entity type.
   */
  public function testIsApplicableRejectsWrongEntityType(): void {
    $vocabulary = Vocabulary::create([
      'vid' => 'test_' . $this->randomMachineName(),
      'name' => 'Test Vocabulary',
    ]);
    $vocabulary->save();

    $entity = $this->createTerm($vocabulary, [
      'name' => 'Not A Report',
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $this->assertFalse($plugin->isApplicable($entity, 'default'));
  }

  /**
   * Test isApplicable rejects new entities without ID.
   */
  public function testIsApplicableRejectsNewEntities(): void {
    $entity = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->create([
        'type' => 'report',
        'title' => 'New Report',
      ]);

    $plugin = $this->getPlugin('rw_node_report');
    $this->assertFalse($plugin->isApplicable($entity, 'default'));
  }

  /**
   * Test isApplicable rejects unpublished entities.
   */
  public function testIsApplicableRejectsUnpublishedEntities(): void {
    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Unpublished Report',
      'moderation_status' => 'draft',
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $this->assertFalse($plugin->isApplicable($entity, 'default'));
  }

  /**
   * Test getData with basic report schema.
   */
  public function testGetDataBasicReport(): void {
    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Report',
      'created' => 1609459200, // 2021-01-01 00:00:00
      'changed' => 1609545600, // 2021-01-02 00:00:00
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('@type', $data);
    $this->assertEquals('Report', $data['@type']);
    $this->assertArrayHasKey('headline', $data);
    $this->assertEquals('Test Report', $data['headline']);
    $this->assertArrayHasKey('@id', $data);
    $this->assertArrayHasKey('url', $data);
    $this->assertArrayHasKey('dateCreated', $data);
    $this->assertArrayHasKey('dateModified', $data);
    $this->assertArrayHasKey('isAccessibleForFree', $data);
    $this->assertTrue($data['isAccessibleForFree']);
    $this->assertArrayHasKey('sdPublisher', $data);
    $this->assertEquals('ReliefWeb', $data['sdPublisher']['name']);
  }

  /**
   * Test getData with news_article schema type via content format ID 8.
   */
  public function testGetDataNewsArticleById(): void {
    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test News Article',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_content_format' => [
        ['target_id' => $this->contentFormatNewsArticle->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertEquals('NewsArticle', $data['@type']);
    $this->assertEquals('Test News Article', $data['headline']);
  }

  /**
   * Test getData with map schema type via content format ID 12.
   */
  public function testGetDataMapById(): void {
    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Map',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_content_format' => [
        ['target_id' => $this->contentFormatMap->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertEquals('Map', $data['@type']);
    $this->assertEquals('Test Map', $data['name']);
  }

  /**
   * Test getData with custom json_schema field.
   */
  public function testGetDataCustomJsonSchema(): void {
    $content_format = $this->createTerm($this->contentFormatVocabulary, [
      'name' => 'Custom Format',
      'field_json_schema' => [
        ['value' => 'creative_work'],
      ],
    ]);

    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Creative Work',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_content_format' => [
        ['target_id' => $content_format->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertEquals('CreativeWork', $data['@type']);
    $this->assertEquals('Test Creative Work', $data['name']);
  }

  /**
   * Test getData with original publication date.
   */
  public function testGetDataWithOriginalPublicationDate(): void {
    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Report',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_original_publication_date' => [
        ['value' => '2020-12-01'],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('datePublished', $data);
    $this->assertEquals('2020-12-01', $data['datePublished']);
  }

  /**
   * Test getData with origin URL.
   */
  public function testGetDataWithOriginUrl(): void {
    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Report',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_origin_notes' => [
        ['value' => 'https://example.com/original'],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('isBasedOn', $data);
    $this->assertStringContainsString('example.com/original', $data['isBasedOn']);
  }

  /**
   * Test getData with themes as keywords.
   */
  public function testGetDataWithThemes(): void {
    $theme1 = $this->createTerm($this->themeVocabulary, [
      'name' => 'Emergency',
    ]);

    $theme2 = $this->createTerm($this->themeVocabulary, [
      'name' => 'Health',
    ]);

    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Report',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_theme' => [
        ['target_id' => $theme1->id()],
        ['target_id' => $theme2->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('Emergency', $data['keywords']);
    $this->assertContains('Health', $data['keywords']);
  }

  /**
   * Test getData with disaster types as keywords.
   */
  public function testGetDataWithDisasterTypes(): void {
    $disaster1 = $this->createTerm($this->disasterTypeVocabulary, [
      'name' => 'Earthquake',
    ]);

    $disaster2 = $this->createTerm($this->disasterTypeVocabulary, [
      'name' => 'Flood',
    ]);

    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Report',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_disaster_type' => [
        ['target_id' => $disaster1->id()],
        ['target_id' => $disaster2->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('Earthquake', $data['keywords']);
    $this->assertContains('Flood', $data['keywords']);
  }

  /**
   * Test getData with content format in keywords.
   */
  public function testGetDataWithContentFormatKeyword(): void {
    $content_format = $this->createTerm($this->contentFormatVocabulary, [
      'name' => 'News Article',
    ]);

    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Report',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_content_format' => [
        ['target_id' => $content_format->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('News Article', $data['keywords']);
  }

  /**
   * Test getData with sources as author and publisher.
   */
  public function testGetDataWithSources(): void {
    $source1 = $this->createTerm($this->sourceVocabulary, [
      'name' => 'UN OCHA',
    ]);

    $source2 = $this->createTerm($this->sourceVocabulary, [
      'name' => 'IFRC',
    ]);

    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Report',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_source' => [
        ['target_id' => $source1->id()],
        ['target_id' => $source2->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('author', $data);
    $this->assertIsArray($data['author']);
    $this->assertCount(2, $data['author']);
    $this->assertEquals('UN OCHA', $data['author'][0]['name']);
    $this->assertEquals('IFRC', $data['author'][1]['name']);

    $this->assertArrayHasKey('publisher', $data);
    $this->assertIsArray($data['publisher']);
    $this->assertCount(2, $data['publisher']);
  }

  /**
   * Test getData with countries as spatial coverage.
   */
  public function testGetDataWithCountries(): void {
    $country1 = $this->createTerm($this->countryVocabulary, [
      'name' => 'Afghanistan',
    ]);

    $country2 = $this->createTerm($this->countryVocabulary, [
      'name' => 'Syria',
    ]);

    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Report',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_country' => [
        ['target_id' => $country1->id()],
        ['target_id' => $country2->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('spatialCoverage', $data);
    $this->assertIsArray($data['spatialCoverage']);
    $this->assertCount(2, $data['spatialCoverage']);
    $this->assertEquals('Afghanistan', $data['spatialCoverage'][0]['name']);
    $this->assertEquals('Syria', $data['spatialCoverage'][1]['name']);
  }

  /**
   * Test getData with body content summary.
   */
  public function testGetDataWithBodySummary(): void {
    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Report',
      'created' => 1609459200,
      'changed' => 1609545600,
      'body' => [
        [
          'value' => 'This is a test body content that should be summarized.',
          'format' => 'markdown',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // The abstract field should be present if body content exists and
    // summarization works.
    $this->assertArrayHasKey('abstract', $data);
    $this->assertNotEmpty($data['abstract']);
  }

  /**
   * Test getData with languages.
   */
  public function testGetDataWithLanguages(): void {
    $language1 = $this->createTerm($this->languageVocabulary, [
      'name' => 'English',
      'field_language_code' => [
        ['value' => 'en'],
      ],
    ]);

    $language2 = $this->createTerm($this->languageVocabulary, [
      'name' => 'French',
      'field_language_code' => [
        ['value' => 'fr'],
      ],
    ]);

    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Report',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_language' => [
        ['target_id' => $language1->id()],
        ['target_id' => $language2->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('inLanguage', $data);
    $this->assertIsArray($data['inLanguage']);
    $this->assertContains('en', $data['inLanguage']);
    $this->assertContains('fr', $data['inLanguage']);
  }

  /**
   * Test getData with original publication date fallback to creation date.
   */
  public function testGetDataWithOriginalPublicationDateFallback(): void {
    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Report',
      'created' => 1609459200, // 2021-01-01 00:00:00
      'changed' => 1609545600,
      'field_original_publication_date' => [
        ['value' => ''],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('datePublished', $data);
    // Should fallback to creation date (2021-01-01).
    $this->assertEquals('2021-01-01', $data['datePublished']);
  }

  /**
   * Test getData with invalid origin URL.
   */
  public function testGetDataWithInvalidOriginUrl(): void {
    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Report',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_origin_notes' => [
        ['value' => 'not-a-valid-url'],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // Invalid URL should not set isBasedOn.
    $this->assertArrayNotHasKey('isBasedOn', $data);
  }

  /**
   * Test getData with empty origin URL.
   */
  public function testGetDataWithEmptyOriginUrl(): void {
    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Report',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_origin_notes' => [
        ['value' => ''],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // Empty URL should not set isBasedOn.
    $this->assertArrayNotHasKey('isBasedOn', $data);
  }

  /**
   * Test getData with content format ID that's not 8 or 12.
   */
  public function testGetDataWithContentFormatDefaultId(): void {
    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Report',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_content_format' => [
        ['target_id' => $this->contentFormatDefault->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // Should default to 'report' schema type.
    $this->assertEquals('Report', $data['@type']);
    $this->assertArrayHasKey('headline', $data);
  }

  /**
   * Test getData without keywords (empty keywords array).
   */
  public function testGetDataWithoutKeywords(): void {
    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Report',
      'created' => 1609459200,
      'changed' => 1609545600,
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // Empty keywords should not set keywords field.
    $this->assertArrayNotHasKey('keywords', $data);
  }

  /**
   * Test getData with language code "ot" (Other) which should be skipped.
   */
  public function testGetDataWithLanguageCodeOther(): void {
    $language_other = $this->createTerm($this->languageVocabulary, [
      'name' => 'Other',
      'field_language_code' => [
        ['value' => 'ot'],
      ],
    ]);

    $language_en = $this->createTerm($this->languageVocabulary, [
      'name' => 'English',
      'field_language_code' => [
        ['value' => 'en'],
      ],
    ]);

    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Report',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_language' => [
        ['target_id' => $language_other->id()],
        ['target_id' => $language_en->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('inLanguage', $data);
    $this->assertIsArray($data['inLanguage']);
    // "ot" should be skipped, only "en" should be present.
    $this->assertNotContains('ot', $data['inLanguage']);
    $this->assertContains('en', $data['inLanguage']);
  }

  /**
   * Test getData without body content (no abstract).
   */
  public function testGetDataWithoutBodyContent(): void {
    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Report',
      'created' => 1609459200,
      'changed' => 1609545600,
      // CreateNode() ads a random body field if we do not provide one.
      // so, for the test, we need to set the body field to NULL.
      'body' => NULL,
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // No body content should not set abstract field.
    $this->assertArrayNotHasKey('abstract', $data);
  }

  /**
   * Test getData with empty body content (no abstract).
   */
  public function testGetDataWithEmptyBodyContent(): void {
    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Test Report',
      'created' => 1609459200,
      'changed' => 1609545600,
      'body' => [
        [
          'value' => '',
          'format' => 'markdown',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // Empty body content should not set abstract field.
    $this->assertArrayNotHasKey('abstract', $data);
  }

  /**
   * Test getData with all fields combined.
   */
  public function testGetDataWithAllFields(): void {
    $content_format = $this->createTerm($this->contentFormatVocabulary, [
      'name' => 'News Article',
      'field_json_schema' => [
        ['value' => 'news_article'],
      ],
    ]);

    $theme = $this->createTerm($this->themeVocabulary, [
      'name' => 'Emergency',
    ]);

    $disaster = $this->createTerm($this->disasterTypeVocabulary, [
      'name' => 'Earthquake',
    ]);

    $source = $this->createTerm($this->sourceVocabulary, [
      'name' => 'UN OCHA',
    ]);

    $country = $this->createTerm($this->countryVocabulary, [
      'name' => 'Afghanistan',
    ]);

    $language = $this->createTerm($this->languageVocabulary, [
      'name' => 'English',
      'field_language_code' => [
        ['value' => 'en'],
      ],
    ]);

    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Comprehensive Test Report',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_content_format' => [
        ['target_id' => $content_format->id()],
      ],
      'field_original_publication_date' => [
        ['value' => '2020-12-01'],
      ],
      'field_origin_notes' => [
        ['value' => 'https://example.com/original'],
      ],
      'field_theme' => [
        ['target_id' => $theme->id()],
      ],
      'field_disaster_type' => [
        ['target_id' => $disaster->id()],
      ],
      'field_source' => [
        ['target_id' => $source->id()],
      ],
      'field_country' => [
        ['target_id' => $country->id()],
      ],
      'field_language' => [
        ['target_id' => $language->id()],
      ],
      'body' => [
        [
          'value' => 'This is comprehensive test content.',
          'format' => 'markdown',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_report');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // Verify all expected fields are present.
    $this->assertEquals('NewsArticle', $data['@type']);
    $this->assertEquals('Comprehensive Test Report', $data['headline']);
    $this->assertEquals('2020-12-01', $data['datePublished']);
    $this->assertArrayHasKey('isBasedOn', $data);
    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('News Article', $data['keywords']);
    $this->assertContains('Emergency', $data['keywords']);
    $this->assertContains('Earthquake', $data['keywords']);
    $this->assertArrayHasKey('author', $data);
    $this->assertArrayHasKey('publisher', $data);
    $this->assertArrayHasKey('spatialCoverage', $data);
    $this->assertArrayHasKey('inLanguage', $data);
    $this->assertArrayHasKey('abstract', $data);
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
