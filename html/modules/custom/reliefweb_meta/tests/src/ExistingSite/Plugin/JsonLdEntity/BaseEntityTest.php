<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_meta\ExistingSite\Plugin\JsonLdEntity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\pathauto\PathautoState;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\reliefweb_meta\BaseEntity;
use Spatie\SchemaOrg\Schema;
use Spatie\SchemaOrg\Type;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the shared helpers implemented by BaseEntity.
 */
class BaseEntityTest extends ExistingSiteBase {

  /**
   * Original schema.org content length configuration.
   */
  protected ?int $originalSchemaOrgContentLength = NULL;

  /**
   * Cached reference to the language vocabulary.
   */
  protected ?Vocabulary $languageVocabulary = NULL;

  /**
   * Cached reference to the source vocabulary.
   */
  protected ?Vocabulary $sourceVocabulary = NULL;

  /**
   * Cached reference to the country vocabulary.
   */
  protected ?Vocabulary $countryVocabulary = NULL;

  /**
   * Base entity test plugin.
   */
  protected ?TestBaseEntityPlugin $plugin = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->saveOriginalSchemaOrgContentLength();
    $this->languageVocabulary = $this->ensureVocabulary('language', 'Language');
    $this->sourceVocabulary = $this->ensureVocabulary('source', 'Source');
    $this->countryVocabulary = $this->ensureVocabulary('country', 'Country');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->restoreOriginalSchemaOrgContentLength();
    parent::tearDown();
  }

  /**
   * Ensure a vocabulary exists and return it.
   */
  protected function ensureVocabulary(string $vid, string $name): Vocabulary {
    $vocabulary = Vocabulary::load($vid);
    if (!$vocabulary) {
      $vocabulary = Vocabulary::create([
        'vid' => $vid,
        'name' => $name,
      ]);
      $vocabulary->save();
    }
    return $vocabulary;
  }

  /**
   * Save the current schema.org content length.
   */
  protected function saveOriginalSchemaOrgContentLength(): void {
    $this->originalSchemaOrgContentLength = $this->state()->get('reliefweb_meta_schema_org_content_length:node:report');
  }

  /**
   * Restore the schema.org content length to its previous value.
   */
  protected function restoreOriginalSchemaOrgContentLength(): void {
    $state = $this->state();
    if ($this->originalSchemaOrgContentLength === NULL) {
      $state->delete('reliefweb_meta_schema_org_content_length:node:report');
      return;
    }
    $state->set('reliefweb_meta_schema_org_content_length:node:report', $this->originalSchemaOrgContentLength);
  }

  /**
   * Convenience wrapper around the state service.
   */
  protected function state(): StateInterface {
    return $this->container->get('state');
  }

  /**
   * Get (or build) the test plugin.
   */
  protected function getPlugin(): TestBaseEntityPlugin {
    if (!$this->plugin) {
      $this->plugin = TestBaseEntityPlugin::create($this->container, [], 'test_reliefweb_base', []);
    }
    return $this->plugin;
  }

  /**
   * Set schema.org content length configuration.
   */
  protected function setSchemaOrgContentLength(int $length): void {
    $this->state()->set('reliefweb_meta_schema_org_content_length:node:report', $length);
  }

  /**
   * Create a report node pre-populated with required defaults.
   */
  protected function createReportNode(array $values = []): NodeInterface {
    $base_values = [
      'type' => 'report',
      'title' => $values['title'] ?? 'Base Entity Test Report',
      'created' => 1609459200,
      'changed' => 1609545600,
    ];
    return $this->createNode($base_values + $values);
  }

  /**
   * Test canonical and permalink URL builders.
   */
  public function testEntityUrlBuildersUseExpectedPaths(): void {
    $node = $this->createReportNode([
      'path' => [
        'alias' => '/reports/url-test',
        'pathauto' => PathautoState::SKIP,
      ],
    ]);

    $plugin = $this->getPlugin();
    $permalink = $plugin->callGetEntityPermalinkUrl($node);
    $canonical = $plugin->callGetEntityCanonicalUrl($node);

    $this->assertStringContainsString("/node/{$node->id()}", $permalink);
    $this->assertStringContainsString('/reports/url-test', $canonical);
  }

  /**
   * Test building source references for report nodes.
   */
  public function testBuildSourceReference(): void {
    $source = $this->createTerm($this->sourceVocabulary, [
      'name' => 'UN OCHA',
      'path' => [
        'alias' => '/sources/un-ocha',
        'pathauto' => PathautoState::SKIP,
      ],
    ]);

    $reference = $this->getPlugin()
      ->callBuildSourceReference($source)
      ->toArray();

    $this->assertSame('Organization', $reference['@type']);
    $this->assertSame('UN OCHA', $reference['name']);
    $this->assertStringContainsString("/taxonomy/term/{$source->id()}", $reference['@id']);
  }

  /**
   * Test building country references for report nodes.
   */
  public function testBuildCountryReference(): void {
    $country = $this->createTerm($this->countryVocabulary, [
      'name' => 'Afghanistan',
      'path' => [
        'alias' => '/countries/afghanistan',
        'pathauto' => PathautoState::SKIP,
      ],
    ]);

    $reference = $this->getPlugin()
      ->callBuildCountryReference($country)
      ->toArray();

    $this->assertSame('Country', $reference['@type']);
    $this->assertSame('Afghanistan', $reference['name']);
    $this->assertStringContainsString("/taxonomy/term/{$country->id()}", $reference['@id']);
  }

  /**
   * Verify that language codes are extracted and filtered correctly.
   */
  public function testGetEntityLanguageCodesSkipsInvalidCodes(): void {
    $languageOther = $this->createTerm($this->languageVocabulary, [
      'name' => 'Other',
      'field_language_code' => [
        ['value' => 'ot'],
      ],
    ]);
    $languageEn = $this->createTerm($this->languageVocabulary, [
      'name' => 'English',
      'field_language_code' => [
        ['value' => 'en'],
      ],
    ]);
    $languageFr = $this->createTerm($this->languageVocabulary, [
      'name' => 'French',
      'field_language_code' => [
        ['value' => 'fr'],
      ],
    ]);

    $node = $this->createReportNode([
      'field_language' => [
        ['target_id' => $languageOther->id()],
        ['target_id' => $languageEn->id()],
        ['target_id' => $languageFr->id()],
      ],
    ]);

    $language_codes = $this->getPlugin()->callGetEntityLanguageCodes($node, 'field_language');
    $this->assertSame(['en', 'fr'], $language_codes);
  }

  /**
   * Verify that summaries respect the schema.org content length configuration.
   */
  public function testSummarizeContentHonorsStateLength(): void {
    $node = $this->createReportNode([
      'body' => [
        [
          'value' => 'This is a fairly long body that should be summarized nicely by the helper.',
          'format' => 'plain_text',
        ],
      ],
    ]);

    $this->setSchemaOrgContentLength(0);
    $this->assertSame('', $this->getPlugin()->callSummarizeContent($node, 'body', 100));

    $this->setSchemaOrgContentLength(-1);
    $summary = $this->getPlugin()->callSummarizeContent($node, 'body', 100);
    $this->assertNotEmpty($summary);
    $this->assertStringContainsString('This is a fairly long body', $summary);
  }

}

/**
 * Simple wrapper plugin around BaseEntity to expose protected helpers.
 */
class TestBaseEntityPlugin extends BaseEntity {

  /**
   * {@inheritdoc}
   */
  public function isApplicable(EntityInterface $entity, $view_mode): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getData(EntityInterface $entity, $view_mode): Type {
    return Schema::thing();
  }

  /**
   * Expose the permalink helper.
   */
  public function callGetEntityPermalinkUrl(EntityInterface $entity): string {
    return $this->getEntityPermalinkUrl($entity);
  }

  /**
   * Expose the canonical URL helper.
   */
  public function callGetEntityCanonicalUrl(EntityInterface $entity): string {
    return $this->getEntityCanonicalUrl($entity);
  }

  /**
   * Expose the source reference builder.
   */
  public function callBuildSourceReference(Term $source): Type {
    return $this->buildSourceReference($source);
  }

  /**
   * Expose the country reference builder.
   */
  public function callBuildCountryReference(Term $country): Type {
    return $this->buildCountryReference($country);
  }

  /**
   * Expose the language code helper.
   */
  public function callGetEntityLanguageCodes(EntityInterface $entity, string $field = 'field_language'): array {
    return $this->getEntityLanguageCodes($entity, $field);
  }

  /**
   * Expose the summarize helper.
   */
  public function callSummarizeContent(EntityInterface $entity, string $field, int $default_length = 1000): string {
    return $this->summarizeContent($entity, $field, $default_length);
  }

}
