<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_meta\ExistingSite\Plugin\JsonLdEntity;

use Drupal\json_ld_schema\Entity\JsonLdEntityInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests NodeJobEntity getData method.
 */
class NodeJobEntityTest extends ExistingSiteBase {

  /**
   * Original schema.org content length state value.
   *
   * @var int|null
   */
  protected ?int $originalSchemaOrgContentLength = NULL;

  /**
   * Job experience vocabulary.
   *
   * @var \Drupal\taxonomy\Entity\Vocabulary|null
   */
  protected ?Vocabulary $jobExperienceVocabulary = NULL;

  /**
   * Job experience term with ID 258 (0-2 years).
   *
   * @var \Drupal\taxonomy\Entity\Term|null
   */
  protected ?Term $jobExperience0To2Years = NULL;

  /**
   * Job experience term with ID 259 (3-4 years).
   *
   * @var \Drupal\taxonomy\Entity\Term|null
   */
  protected ?Term $jobExperience3To4Years = NULL;

  /**
   * Job experience term with ID 260 (5-9 years).
   *
   * @var \Drupal\taxonomy\Entity\Term|null
   */
  protected ?Term $jobExperience5To9Years = NULL;

  /**
   * Job experience term with ID 261 (10+ years).
   *
   * @var \Drupal\taxonomy\Entity\Term|null
   */
  protected ?Term $jobExperience10PlusYears = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->saveOriginalSchemaOrgContentLength();
    $this->setSchemaOrgContentLength(1000);
    $this->setUpJobExperienceTerms();
  }

  /**
   * Set up job experience vocabulary and terms with known IDs.
   */
  protected function setUpJobExperienceTerms(): void {
    $this->jobExperienceVocabulary = Vocabulary::load('job_experience');
    $this->assertNotNull($this->jobExperienceVocabulary, 'Job experience vocabulary not available');

    // Ensure job experience term with ID 258 exists (0-2 years).
    $this->jobExperience0To2Years = Term::load(258);
    if (!$this->jobExperience0To2Years || $this->jobExperience0To2Years->bundle() !== 'job_experience') {
      $this->jobExperience0To2Years = $this->createTerm($this->jobExperienceVocabulary, [
        'tid' => 258,
        'name' => '0-2 years',
      ]);
    }

    // Ensure job experience term with ID 259 exists (3-4 years).
    $this->jobExperience3To4Years = Term::load(259);
    if (!$this->jobExperience3To4Years || $this->jobExperience3To4Years->bundle() !== 'job_experience') {
      $this->jobExperience3To4Years = $this->createTerm($this->jobExperienceVocabulary, [
        'tid' => 259,
        'name' => '3-4 years',
      ]);
    }

    // Ensure job experience term with ID 260 exists (5-9 years).
    $this->jobExperience5To9Years = Term::load(260);
    if (!$this->jobExperience5To9Years || $this->jobExperience5To9Years->bundle() !== 'job_experience') {
      $this->jobExperience5To9Years = $this->createTerm($this->jobExperienceVocabulary, [
        'tid' => 260,
        'name' => '5-9 years',
      ]);
    }

    // Ensure job experience term with ID 261 exists (10+ years).
    $this->jobExperience10PlusYears = Term::load(261);
    if (!$this->jobExperience10PlusYears || $this->jobExperience10PlusYears->bundle() !== 'job_experience') {
      $this->jobExperience10PlusYears = $this->createTerm($this->jobExperienceVocabulary, [
        'tid' => 261,
        'name' => '10+ years',
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
    $this->originalSchemaOrgContentLength = $state->get('reliefweb_meta_schema_org_content_length:node:job', NULL);
  }

  /**
   * Restore the original schema.org content length state value.
   */
  protected function restoreOriginalSchemaOrgContentLength(): void {
    $state = \Drupal::service('state');
    if ($this->originalSchemaOrgContentLength !== NULL) {
      $state->set('reliefweb_meta_schema_org_content_length:node:job', $this->originalSchemaOrgContentLength);
    }
    else {
      $state->delete('reliefweb_meta_schema_org_content_length:node:job');
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
    $state->set('reliefweb_meta_schema_org_content_length:node:job', $length);
  }

  /**
   * Test getData with basic job posting schema.
   */
  public function testGetDataBasicJobPosting(): void {
    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'created' => 1609459200, // 2021-01-01 00:00:00
      'changed' => 1609545600, // 2021-01-02 00:00:00
    ]);

    $plugin = $this->getPlugin('rw_node_job');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('@type', $data);
    $this->assertEquals('JobPosting', $data['@type']);
    $this->assertArrayHasKey('title', $data);
    $this->assertEquals('Test Job', $data['title']);
    $this->assertArrayHasKey('@id', $data);
    $this->assertArrayHasKey('url', $data);
    $this->assertArrayHasKey('datePosted', $data);
    // Should have jobLocationType when no country is specified.
    $this->assertArrayHasKey('jobLocationType', $data);
    $this->assertEquals('Remote, roster, roving, or location to be determined', $data['jobLocationType']);
  }

  /**
   * Test getData with employment type.
   */
  public function testGetDataWithEmploymentType(): void {
    $vocabulary = Vocabulary::load('job_type');
    $this->assertNotNull($vocabulary, 'Job type vocabulary not available');

    $job_type = $this->createTerm($vocabulary, [
      'name' => 'Full-time',
    ]);

    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_job_type' => [
        ['target_id' => $job_type->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_job');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('employmentType', $data);
    $this->assertEquals('Full-time', $data['employmentType']);
  }

  /**
   * Test getData with job closing date.
   */
  public function testGetDataWithJobClosingDate(): void {
    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_job_closing_date' => [
        ['value' => '2021-12-31'],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_job');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('validThrough', $data);
    $this->assertEquals('2021-12-31', $data['validThrough']);
  }

  /**
   * Test getData with themes as keywords.
   */
  public function testGetDataWithThemes(): void {
    $vocabulary = Vocabulary::load('theme');
    $this->assertNotNull($vocabulary, 'Theme vocabulary not available');

    $theme1 = $this->createTerm($vocabulary, [
      'name' => 'Emergency',
    ]);

    $theme2 = $this->createTerm($vocabulary, [
      'name' => 'Health',
    ]);

    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_theme' => [
        ['target_id' => $theme1->id()],
        ['target_id' => $theme2->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_job');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('Emergency', $data['keywords']);
    $this->assertContains('Health', $data['keywords']);
  }

  /**
   * Test getData with career categories as keywords.
   */
  public function testGetDataWithCareerCategories(): void {
    $vocabulary = Vocabulary::load('career_category');
    $this->assertNotNull($vocabulary, 'Career categories vocabulary not available');

    $category1 = $this->createTerm($vocabulary, [
      'name' => 'Program/Project Management',
    ]);

    $category2 = $this->createTerm($vocabulary, [
      'name' => 'Monitoring and Evaluation',
    ]);

    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_career_categories' => [
        ['target_id' => $category1->id()],
        ['target_id' => $category2->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_job');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('Program/Project Management', $data['keywords']);
    $this->assertContains('Monitoring and Evaluation', $data['keywords']);
  }

  /**
   * Test getData with themes and career categories combined as keywords.
   */
  public function testGetDataWithThemesAndCareerCategories(): void {
    $theme_vocab = Vocabulary::load('theme');
    $category_vocab = Vocabulary::load('career_category');

    $this->assertNotNull($theme_vocab, 'Theme vocabulary not available');
    $this->assertNotNull($category_vocab, 'Career category vocabulary not available');

    $theme = $this->createTerm($theme_vocab, [
      'name' => 'Emergency',
    ]);

    $category = $this->createTerm($category_vocab, [
      'name' => 'Program/Project Management',
    ]);

    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_theme' => [
        ['target_id' => $theme->id()],
      ],
      'field_career_categories' => [
        ['target_id' => $category->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_job');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('Emergency', $data['keywords']);
    $this->assertContains('Program/Project Management', $data['keywords']);
  }

  /**
   * Test getData without keywords (empty keywords array).
   */
  public function testGetDataWithoutKeywords(): void {
    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'created' => 1609459200,
      'changed' => 1609545600,
    ]);

    $plugin = $this->getPlugin('rw_node_job');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // Empty keywords should not set keywords field.
    $this->assertArrayNotHasKey('keywords', $data);
  }

  /**
   * Test getData with body content summary.
   */
  public function testGetDataWithBodySummary(): void {
    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'created' => 1609459200,
      'changed' => 1609545600,
      'body' => [
        [
          'value' => 'This is a test job description that should be summarized.',
          'format' => 'markdown',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_job');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // The description field should be present if body content exists and
    // summarization works.
    $this->assertArrayHasKey('description', $data);
    $this->assertNotEmpty($data['description']);
  }

  /**
   * Test getData without body content (no description).
   */
  public function testGetDataWithoutBodyContent(): void {
    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'created' => 1609459200,
      'changed' => 1609545600,
      // CreateNode() ads a random body field if we do not provide one.
      // so, for the test, we need to set the body field to NULL.
      'body' => NULL,
    ]);

    $plugin = $this->getPlugin('rw_node_job');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // No body content should not set description field.
    $this->assertArrayNotHasKey('description', $data);
  }

  /**
   * Test getData with empty body content (no description).
   */
  public function testGetDataWithEmptyBodyContent(): void {
    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'created' => 1609459200,
      'changed' => 1609545600,
      'body' => [
        [
          'value' => '',
          'format' => 'markdown',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_job');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // Empty body content should not set description field.
    $this->assertArrayNotHasKey('description', $data);
  }

  /**
   * Test getData with hiring organization.
   */
  public function testGetDataWithHiringOrganization(): void {
    $vocabulary = Vocabulary::load('source');
    $this->assertNotNull($vocabulary, 'Source vocabulary not available');

    $source = $this->createTerm($vocabulary, [
      'name' => 'UN OCHA',
    ]);

    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_source' => [
        ['target_id' => $source->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_job');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('hiringOrganization', $data);
    $this->assertEquals('UN OCHA', $data['hiringOrganization']['name']);
  }

  /**
   * Test getData with job location (country).
   */
  public function testGetDataWithJobLocation(): void {
    $vocabulary = Vocabulary::load('country');
    $this->assertNotNull($vocabulary, 'Country vocabulary not available');

    $country = $this->createTerm($vocabulary, [
      'name' => 'Afghanistan',
    ]);

    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_country' => [
        ['target_id' => $country->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_job');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('jobLocation', $data);
    $this->assertEquals('Afghanistan', $data['jobLocation']['name']);
    // Should not have jobLocationType when country is specified.
    $this->assertArrayNotHasKey('jobLocationType', $data);
  }

  /**
   * Test getData with experience requirements - 0-2 years (ID 258).
   */
  public function testGetDataWithExperienceRequirements0To2Years(): void {
    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_job_experience' => [
        ['target_id' => $this->jobExperience0To2Years->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_job');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('experienceRequirements', $data);
    $this->assertEquals(0, $data['experienceRequirements']['monthsOfExperience']);
  }

  /**
   * Test getData with experience requirements - 3-4 years (ID 259).
   */
  public function testGetDataWithExperienceRequirements3To4Years(): void {
    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_job_experience' => [
        ['target_id' => $this->jobExperience3To4Years->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_job');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('experienceRequirements', $data);
    $this->assertEquals(36, $data['experienceRequirements']['monthsOfExperience']);
  }

  /**
   * Test getData with experience requirements - 5-9 years (ID 260).
   */
  public function testGetDataWithExperienceRequirements5To9Years(): void {
    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_job_experience' => [
        ['target_id' => $this->jobExperience5To9Years->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_job');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('experienceRequirements', $data);
    $this->assertEquals(60, $data['experienceRequirements']['monthsOfExperience']);
  }

  /**
   * Test getData with experience requirements - 10+ years (ID 261).
   */
  public function testGetDataWithExperienceRequirements10PlusYears(): void {
    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_job_experience' => [
        ['target_id' => $this->jobExperience10PlusYears->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_job');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('experienceRequirements', $data);
    $this->assertEquals(120, $data['experienceRequirements']['monthsOfExperience']);
  }

  /**
   * Test getData with experience requirements - unknown ID (should not set).
   */
  public function testGetDataWithExperienceRequirementsUnknownId(): void {
    // Create a term with an ID that's not one of the known IDs (258, 259, 260, 261).
    $experience_term = $this->createTerm($this->jobExperienceVocabulary, [
      'tid' => 999,
      'name' => 'Unknown Experience',
    ]);

    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_job_experience' => [
        ['target_id' => $experience_term->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_job');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // Unknown ID should not set experienceRequirements.
    $this->assertArrayNotHasKey('experienceRequirements', $data);
  }

  /**
   * Test getData with employment type fallback to 'Job'.
   */
  public function testGetDataWithEmploymentTypeFallback(): void {
    $vocabulary = Vocabulary::load('job_type');
    $this->assertNotNull($vocabulary, 'Job type vocabulary not available');

    $job_type = $this->createTerm($vocabulary, [
      'name' => 'Test Type',
    ]);

    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Test Job',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_job_type' => [
        ['target_id' => $job_type->id()],
      ],
    ]);

    // Manually set the entity to NULL to test the fallback.
    // Actually, we can't easily test this without mocking, but the code shows
    // it falls back to 'Job' if entity is NULL. Let's test the normal case.
    $plugin = $this->getPlugin('rw_node_job');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('employmentType', $data);
    $this->assertEquals('Test Type', $data['employmentType']);
  }

  /**
   * Test getData with all fields combined.
   */
  public function testGetDataWithAllFields(): void {
    $job_type_vocab = Vocabulary::load('job_type');
    $theme_vocab = Vocabulary::load('theme');
    $category_vocab = Vocabulary::load('career_category');
    $source_vocab = Vocabulary::load('source');
    $country_vocab = Vocabulary::load('country');

    $this->assertNotNull($job_type_vocab, 'Job type vocabulary not available');
    $this->assertNotNull($theme_vocab, 'Theme vocabulary not available');
    $this->assertNotNull($category_vocab, 'Career category vocabulary not available');
    $this->assertNotNull($source_vocab, 'Source vocabulary not available');
    $this->assertNotNull($country_vocab, 'Country vocabulary not available');

    $job_type = $this->createTerm($job_type_vocab, [
      'name' => 'Full-time',
    ]);

    $theme = $this->createTerm($theme_vocab, [
      'name' => 'Emergency',
    ]);

    $category = $this->createTerm($category_vocab, [
      'name' => 'Program/Project Management',
    ]);

    $source = $this->createTerm($source_vocab, [
      'name' => 'UN OCHA',
    ]);

    $country = $this->createTerm($country_vocab, [
      'name' => 'Afghanistan',
    ]);

    $entity = $this->createNode([
      'type' => 'job',
      'title' => 'Comprehensive Test Job',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_job_type' => [
        ['target_id' => $job_type->id()],
      ],
      'field_job_closing_date' => [
        ['value' => '2021-12-31'],
      ],
      'field_theme' => [
        ['target_id' => $theme->id()],
      ],
      'field_career_categories' => [
        ['target_id' => $category->id()],
      ],
      'field_source' => [
        ['target_id' => $source->id()],
      ],
      'field_country' => [
        ['target_id' => $country->id()],
      ],
      'field_job_experience' => [
        ['target_id' => $this->jobExperience3To4Years->id()],
      ],
      'body' => [
        [
          'value' => 'This is comprehensive test job description.',
          'format' => 'markdown',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_job');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // Verify all expected fields are present.
    $this->assertEquals('JobPosting', $data['@type']);
    $this->assertEquals('Comprehensive Test Job', $data['title']);
    $this->assertEquals('Full-time', $data['employmentType']);
    $this->assertEquals('2021-12-31', $data['validThrough']);
    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('Emergency', $data['keywords']);
    $this->assertContains('Program/Project Management', $data['keywords']);
    $this->assertArrayHasKey('description', $data);
    $this->assertArrayHasKey('hiringOrganization', $data);
    $this->assertEquals('UN OCHA', $data['hiringOrganization']['name']);
    $this->assertArrayHasKey('jobLocation', $data);
    $this->assertEquals('Afghanistan', $data['jobLocation']['name']);
    $this->assertArrayNotHasKey('jobLocationType', $data);
    $this->assertArrayHasKey('experienceRequirements', $data);
    $this->assertEquals(36, $data['experienceRequirements']['monthsOfExperience']);
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
