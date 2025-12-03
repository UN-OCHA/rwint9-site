<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_meta\ExistingSite\Plugin\JsonLdEntity;

use Drupal\json_ld_schema\Entity\JsonLdEntityInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests NodeTrainingEntity getData method.
 */
class NodeTrainingEntityTest extends ExistingSiteBase {

  /**
   * Original schema.org content length state value.
   *
   * @var int|null
   */
  protected ?int $originalSchemaOrgContentLength = NULL;

  /**
   * Training format vocabulary.
   *
   * @var \Drupal\taxonomy\Entity\Vocabulary|null
   */
  protected ?Vocabulary $trainingFormatVocabulary = NULL;

  /**
   * Training format term with ID 4606 (onsite).
   *
   * @var \Drupal\taxonomy\Entity\Term|null
   */
  protected ?Term $trainingFormatOnsite = NULL;

  /**
   * Training format term with ID 4607 (online).
   *
   * @var \Drupal\taxonomy\Entity\Term|null
   */
  protected ?Term $trainingFormatOnline = NULL;

  /**
   * Training type vocabulary.
   *
   * @var \Drupal\taxonomy\Entity\Vocabulary|null
   */
  protected ?Vocabulary $trainingTypeVocabulary = NULL;

  /**
   * Training type term with ID 4610 (Academic Degree/Course).
   *
   * @var \Drupal\taxonomy\Entity\Term|null
   */
  protected ?Term $trainingTypeAcademic = NULL;

  /**
   * Training type term with ID 4608 (Call for Papers).
   *
   * @var \Drupal\taxonomy\Entity\Term|null
   */
  protected ?Term $trainingTypeCallForPapers = NULL;

  /**
   * Training type term with ID 21006 (Conference/Lecture).
   *
   * @var \Drupal\taxonomy\Entity\Term|null
   */
  protected ?Term $trainingTypeConference = NULL;

  /**
   * Training type term with ID 4609 (Training/Workshop).
   *
   * @var \Drupal\taxonomy\Entity\Term|null
   */
  protected ?Term $trainingTypeWorkshop = NULL;

  /**
   * Theme vocabulary.
   *
   * @var \Drupal\taxonomy\Entity\Vocabulary|null
   */
  protected ?Vocabulary $themeVocabulary = NULL;

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
   * Career category vocabulary.
   *
   * @var \Drupal\taxonomy\Entity\Vocabulary|null
   */
  protected ?Vocabulary $careerCategoryVocabulary = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->saveOriginalSchemaOrgContentLength();
    $this->setSchemaOrgContentLength(1000);
    $this->setUpVocabularies();
    $this->setUpTrainingFormatTerms();
    $this->setUpTrainingTypeTerms();
  }

  /**
   * Set up all necessary vocabularies.
   */
  protected function setUpVocabularies(): void {
    $this->trainingFormatVocabulary = Vocabulary::load('training_format');
    if (!$this->trainingFormatVocabulary) {
      $this->trainingFormatVocabulary = Vocabulary::create([
        'vid' => 'training_format',
        'name' => 'Training Format',
      ]);
      $this->trainingFormatVocabulary->save();
    }

    $this->trainingTypeVocabulary = Vocabulary::load('training_type');
    if (!$this->trainingTypeVocabulary) {
      $this->trainingTypeVocabulary = Vocabulary::create([
        'vid' => 'training_type',
        'name' => 'Training Type',
      ]);
      $this->trainingTypeVocabulary->save();
    }

    $this->themeVocabulary = Vocabulary::load('theme');
    if (!$this->themeVocabulary) {
      $this->themeVocabulary = Vocabulary::create([
        'vid' => 'theme',
        'name' => 'Theme',
      ]);
      $this->themeVocabulary->save();
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

    $this->careerCategoryVocabulary = Vocabulary::load('career_category');
    if (!$this->careerCategoryVocabulary) {
      $this->careerCategoryVocabulary = Vocabulary::create([
        'vid' => 'career_category',
        'name' => 'Career Category',
      ]);
      $this->careerCategoryVocabulary->save();
    }
  }

  /**
   * Set up training format vocabulary and terms with known IDs.
   */
  protected function setUpTrainingFormatTerms(): void {
    // Ensure training format term with ID 4606 exists (onsite).
    $this->trainingFormatOnsite = Term::load(4606);
    if (!$this->trainingFormatOnsite || $this->trainingFormatOnsite->bundle() !== 'training_format') {
      $this->trainingFormatOnsite = $this->createTerm($this->trainingFormatVocabulary, [
        'tid' => 4606,
        'name' => 'Onsite',
      ]);
    }

    // Ensure training format term with ID 4607 exists (online).
    $this->trainingFormatOnline = Term::load(4607);
    if (!$this->trainingFormatOnline || $this->trainingFormatOnline->bundle() !== 'training_format') {
      $this->trainingFormatOnline = $this->createTerm($this->trainingFormatVocabulary, [
        'tid' => 4607,
        'name' => 'Online',
      ]);
    }
  }

  /**
   * Set up training type vocabulary and terms with known IDs.
   */
  protected function setUpTrainingTypeTerms(): void {
    // Ensure training type term with ID 4610 exists (Academic Degree/Course).
    $this->trainingTypeAcademic = Term::load(4610);
    if (!$this->trainingTypeAcademic || $this->trainingTypeAcademic->bundle() !== 'training_type') {
      $this->trainingTypeAcademic = $this->createTerm($this->trainingTypeVocabulary, [
        'tid' => 4610,
        'name' => 'Academic Degree/Course',
      ]);
    }

    // Ensure training type term with ID 4608 exists (Call for Papers).
    $this->trainingTypeCallForPapers = Term::load(4608);
    if (!$this->trainingTypeCallForPapers || $this->trainingTypeCallForPapers->bundle() !== 'training_type') {
      $this->trainingTypeCallForPapers = $this->createTerm($this->trainingTypeVocabulary, [
        'tid' => 4608,
        'name' => 'Call for Papers',
      ]);
    }

    // Ensure training type term with ID 21006 exists (Conference/Lecture).
    $this->trainingTypeConference = Term::load(21006);
    if (!$this->trainingTypeConference || $this->trainingTypeConference->bundle() !== 'training_type') {
      $this->trainingTypeConference = $this->createTerm($this->trainingTypeVocabulary, [
        'tid' => 21006,
        'name' => 'Conference/Lecture',
      ]);
    }

    // Ensure training type term with ID 4609 exists (Training/Workshop).
    $this->trainingTypeWorkshop = Term::load(4609);
    if (!$this->trainingTypeWorkshop || $this->trainingTypeWorkshop->bundle() !== 'training_type') {
      $this->trainingTypeWorkshop = $this->createTerm($this->trainingTypeVocabulary, [
        'tid' => 4609,
        'name' => 'Training/Workshop',
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
    $this->originalSchemaOrgContentLength = $state->get('reliefweb_meta_schema_org_content_length:node:training', NULL);
  }

  /**
   * Restore the original schema.org content length state value.
   */
  protected function restoreOriginalSchemaOrgContentLength(): void {
    $state = \Drupal::service('state');
    if ($this->originalSchemaOrgContentLength !== NULL) {
      $state->set('reliefweb_meta_schema_org_content_length:node:training', $this->originalSchemaOrgContentLength);
    }
    else {
      $state->delete('reliefweb_meta_schema_org_content_length:node:training');
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
    $state->set('reliefweb_meta_schema_org_content_length:node:training', $length);
  }

  /**
   * Test isApplicable for training nodes.
   */
  public function testIsApplicableForTrainingNodes(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Applicable Training',
      'moderation_status' => 'published',
      'field_registration_deadline' => [
        [
          'value' => date('Y-m-d', strtotime('+1 year')),
        ],
      ],
      'field_training_date' => [
        [
          'value' => date('Y-m-d', strtotime('+1 year')),
          'end_value' => date('Y-m-d', strtotime('+1 year + 1 month')),
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $this->assertTrue($plugin->isApplicable($entity, 'default'));
  }

  /**
   * Test isApplicable rejects non-training nodes.
   */
  public function testIsApplicableRejectsNonTrainingNodes(): void {
    $entity = $this->createNode([
      'type' => 'report',
      'title' => 'Not A Training',
    ]);

    $plugin = $this->getPlugin('rw_node_training');
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
      'name' => 'Not A Training',
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $this->assertFalse($plugin->isApplicable($entity, 'default'));
  }

  /**
   * Test isApplicable rejects new entities without ID.
   */
  public function testIsApplicableRejectsNewEntities(): void {
    $entity = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->create([
        'type' => 'training',
        'title' => 'New Training',
      ]);

    $plugin = $this->getPlugin('rw_node_training');
    $this->assertFalse($plugin->isApplicable($entity, 'default'));
  }

  /**
   * Test isApplicable rejects unpublished entities.
   */
  public function testIsApplicableRejectsUnpublishedEntities(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Unpublished Training',
      'moderation_status' => 'draft',
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $this->assertFalse($plugin->isApplicable($entity, 'default'));
  }

  /**
   * Test getData with basic course schema (no dates).
   */
  public function testGetDataBasicCourse(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200, // 2021-01-01 00:00:00
      'changed' => 1609545600, // 2021-01-02 00:00:00
      // By default, CreateNode() sets the training date to the current date.
      // so, for the test, we need to set the training date to NULL.
      'field_training_date' => NULL,
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('@type', $data);
    $this->assertEquals('Course', $data['@type']);
    $this->assertArrayHasKey('name', $data);
    $this->assertEquals('Test Training', $data['name']);
    $this->assertArrayHasKey('@id', $data);
    $this->assertArrayHasKey('url', $data);
    $this->assertArrayHasKey('dateCreated', $data);
    $this->assertArrayHasKey('dateModified', $data);
    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('permanent', $data['keywords']);
  }

  /**
   * Test getData with training dates (creates CourseInstance).
   */
  public function testGetDataWithTrainingDates(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_training_date' => [
        [
          'value' => '2021-06-01',
          'end_value' => '2021-06-05',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertEquals('Course', $data['@type']);
    $this->assertArrayHasKey('hasCourseInstance', $data);
    $this->assertIsArray($data['hasCourseInstance']);
    $this->assertArrayHasKey('startDate', $data['hasCourseInstance']);
    $this->assertEquals('2021-06-01', $data['hasCourseInstance']['startDate']);
    $this->assertArrayHasKey('endDate', $data['hasCourseInstance']);
    $this->assertEquals('2021-06-05', $data['hasCourseInstance']['endDate']);
  }

  /**
   * Test getData with training dates (single date, end date equals start date).
   */
  public function testGetDataWithSingleTrainingDate(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_training_date' => [
        [
          'value' => '2021-06-01',
          // End date equals start date when not set in the form. It cannot be
          // empty, so we set it to the same value as the start date.
          'end_value' => '2021-06-01',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('hasCourseInstance', $data);
    $this->assertEquals('2021-06-01', $data['hasCourseInstance']['startDate']);
    $this->assertEquals('2021-06-01', $data['hasCourseInstance']['endDate']);
  }

  /**
   * Test getData with Academic Degree/Course type (ID 4610) -> Course.
   */
  public function testGetDataWithAcademicType(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Academic Course',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_training_type' => [
        ['target_id' => $this->trainingTypeAcademic->id()],
      ],
      'field_training_date' => [
        [
          'value' => '2021-06-01',
          'end_value' => '2021-06-05',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertEquals('Course', $data['@type']);
    $this->assertArrayHasKey('hasCourseInstance', $data);
    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('Academic Degree/Course', $data['keywords']);
  }

  /**
   * Test getData with Call for Papers type (ID 4608) -> EducationEvent.
   */
  public function testGetDataWithCallForPapersType(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Call for Papers',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_training_type' => [
        ['target_id' => $this->trainingTypeCallForPapers->id()],
      ],
      'field_training_date' => [
        [
          'value' => '2021-06-01',
          'end_value' => '2021-06-05',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertEquals('EducationEvent', $data['@type']);
    $this->assertArrayHasKey('startDate', $data);
    $this->assertEquals('2021-06-01', $data['startDate']);
    $this->assertArrayHasKey('endDate', $data);
    $this->assertEquals('2021-06-05', $data['endDate']);
    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('Call for Papers', $data['keywords']);
  }

  /**
   * Test getData with Conference/Lecture type (ID 21006) -> EducationEvent.
   */
  public function testGetDataWithConferenceType(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Conference',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_training_type' => [
        ['target_id' => $this->trainingTypeConference->id()],
      ],
      'field_training_date' => [
        [
          'value' => '2021-06-01',
          'end_value' => '2021-06-05',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertEquals('EducationEvent', $data['@type']);
    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('Conference/Lecture', $data['keywords']);
  }

  /**
   * Test getData with Training/Workshop type (ID 4609) -> EducationEvent.
   */
  public function testGetDataWithWorkshopType(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Workshop',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_training_type' => [
        ['target_id' => $this->trainingTypeWorkshop->id()],
      ],
      'field_training_date' => [
        [
          'value' => '2021-06-01',
          'end_value' => '2021-06-05',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertEquals('EducationEvent', $data['@type']);
    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('Training/Workshop', $data['keywords']);
  }

  /**
   * Test getData with onsite format (ID 4606).
   */
  public function testGetDataWithOnsiteFormat(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_training_format' => [
        ['target_id' => $this->trainingFormatOnsite->id()],
      ],
      'field_training_date' => [
        [
          'value' => '2021-06-01',
          'end_value' => '2021-06-05',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('hasCourseInstance', $data);
    $this->assertArrayHasKey('eventAttendanceMode', $data['hasCourseInstance']);
    $this->assertEquals('https://schema.org/OfflineEventAttendanceMode', $data['hasCourseInstance']['eventAttendanceMode']);
    $this->assertArrayHasKey('courseMode', $data['hasCourseInstance']);
    $this->assertEquals(['onsite'], $data['hasCourseInstance']['courseMode']);
  }

  /**
   * Test getData with online format (ID 4607).
   */
  public function testGetDataWithOnlineFormat(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_training_format' => [
        ['target_id' => $this->trainingFormatOnline->id()],
      ],
      'field_training_date' => [
        [
          'value' => '2021-06-01',
          'end_value' => '2021-06-05',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('hasCourseInstance', $data);
    $this->assertArrayHasKey('eventAttendanceMode', $data['hasCourseInstance']);
    $this->assertEquals('https://schema.org/OnlineEventAttendanceMode', $data['hasCourseInstance']['eventAttendanceMode']);
    $this->assertArrayHasKey('courseMode', $data['hasCourseInstance']);
    $this->assertEquals(['online'], $data['hasCourseInstance']['courseMode']);
  }

  /**
   * Test getData with both online and onsite formats (mixed).
   */
  public function testGetDataWithMixedFormat(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_training_format' => [
        ['target_id' => $this->trainingFormatOnsite->id()],
        ['target_id' => $this->trainingFormatOnline->id()],
      ],
      'field_training_date' => [
        [
          'value' => '2021-06-01',
          'end_value' => '2021-06-05',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('hasCourseInstance', $data);
    $this->assertArrayHasKey('eventAttendanceMode', $data['hasCourseInstance']);
    $this->assertEquals('https://schema.org/MixedEventAttendanceMode', $data['hasCourseInstance']['eventAttendanceMode']);
    $this->assertArrayHasKey('courseMode', $data['hasCourseInstance']);
    $this->assertEquals(['online', 'onsite'], $data['hasCourseInstance']['courseMode']);
  }

  /**
   * Test getData with EducationEvent and attendance mode.
   */
  public function testGetDataWithEducationEventAttendanceMode(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Event',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_training_type' => [
        ['target_id' => $this->trainingTypeWorkshop->id()],
      ],
      'field_training_format' => [
        ['target_id' => $this->trainingFormatOnline->id()],
      ],
      'field_training_date' => [
        [
          'value' => '2021-06-01',
          'end_value' => '2021-06-05',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertEquals('EducationEvent', $data['@type']);
    $this->assertArrayHasKey('eventAttendanceMode', $data);
    $this->assertEquals('https://schema.org/OnlineEventAttendanceMode', $data['eventAttendanceMode']);
    // EducationEvent should not have courseMode.
    $this->assertArrayNotHasKey('courseMode', $data);
  }

  /**
   * Test getData with registration deadline.
   */
  public function testGetDataWithRegistrationDeadline(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_training_date' => [
        [
          'value' => '2021-06-01',
          'end_value' => '2021-06-05',
        ],
      ],
      'field_registration_deadline' => [
        ['value' => '2021-05-15'],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('hasCourseInstance', $data);
    $this->assertArrayHasKey('offers', $data['hasCourseInstance']);
    $this->assertArrayHasKey('validThrough', $data['hasCourseInstance']['offers']);
    $this->assertEquals('2021-05-15', $data['hasCourseInstance']['offers']['validThrough']);
  }

  /**
   * Test getData with registration deadline on permanent training.
   */
  public function testGetDataWithRegistrationDeadlinePermanent(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      // By default, CreateNode() sets the training date to the current date.
      // so, for the test, we need to set the training date to NULL.
      'field_training_date' => NULL,
      'field_registration_deadline' => [
        ['value' => '2021-05-15'],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // For permanent training, offer should be on the schema itself.
    $this->assertArrayHasKey('offers', $data);
    $this->assertArrayHasKey('validThrough', $data['offers']);
    $this->assertEquals('2021-05-15', $data['offers']['validThrough']);
  }

  /**
   * Test getData with free cost.
   */
  public function testGetDataWithFreeCost(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_cost' => [
        ['value' => 'free'],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('isAccessibleForFree', $data);
    $this->assertTrue($data['isAccessibleForFree']);
  }

  /**
   * Test getData with fee-based cost.
   */
  public function testGetDataWithFeeBasedCost(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_cost' => [
        ['value' => 'fee-based'],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('isAccessibleForFree', $data);
    $this->assertFalse($data['isAccessibleForFree']);
  }

  /**
   * Test getData with fee-based cost and fee information.
   */
  public function testGetDataWithFeeInformation(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_cost' => [
        ['value' => 'fee-based'],
      ],
      'field_fee_information' => [
        ['value' => 'The fee is $500 per participant.'],
      ],
      'field_training_date' => [
        [
          'value' => '2021-06-01',
          'end_value' => '2021-06-05',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('hasCourseInstance', $data);
    $this->assertArrayHasKey('offers', $data['hasCourseInstance']);
    $this->assertArrayHasKey('description', $data['hasCourseInstance']['offers']);
    $this->assertEquals('The fee is $500 per participant.', $data['hasCourseInstance']['offers']['description']);
  }

  /**
   * Test getData with career categories as keywords.
   */
  public function testGetDataWithCareerCategories(): void {
    $category1 = $this->createTerm($this->careerCategoryVocabulary, [
      'name' => 'Program/Project Management',
    ]);

    $category2 = $this->createTerm($this->careerCategoryVocabulary, [
      'name' => 'Monitoring and Evaluation',
    ]);

    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_career_categories' => [
        ['target_id' => $category1->id()],
        ['target_id' => $category2->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('Program/Project Management', $data['keywords']);
    $this->assertContains('Monitoring and Evaluation', $data['keywords']);
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
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_theme' => [
        ['target_id' => $theme1->id()],
        ['target_id' => $theme2->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('Emergency', $data['keywords']);
    $this->assertContains('Health', $data['keywords']);
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
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_training_language' => [
        ['target_id' => $language1->id()],
        ['target_id' => $language2->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('inLanguage', $data);
    $this->assertIsArray($data['inLanguage']);
    $this->assertContains('en', $data['inLanguage']);
    $this->assertContains('fr', $data['inLanguage']);
  }

  /**
   * Test getData with sources as provider (Course).
   */
  public function testGetDataWithSourcesAsProvider(): void {
    $source1 = $this->createTerm($this->sourceVocabulary, [
      'name' => 'UN OCHA',
    ]);

    $source2 = $this->createTerm($this->sourceVocabulary, [
      'name' => 'IFRC',
    ]);

    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_source' => [
        ['target_id' => $source1->id()],
        ['target_id' => $source2->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertEquals('Course', $data['@type']);
    $this->assertArrayHasKey('provider', $data);
    $this->assertIsArray($data['provider']);
    $this->assertCount(2, $data['provider']);
    $this->assertEquals('UN OCHA', $data['provider'][0]['name']);
    $this->assertEquals('IFRC', $data['provider'][1]['name']);
  }

  /**
   * Test getData with sources as organizer (EducationEvent).
   */
  public function testGetDataWithSourcesAsOrganizer(): void {
    $source = $this->createTerm($this->sourceVocabulary, [
      'name' => 'UN OCHA',
    ]);

    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Event',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_training_type' => [
        ['target_id' => $this->trainingTypeWorkshop->id()],
      ],
      'field_training_date' => [
        [
          'value' => '2021-06-01',
          'end_value' => '2021-06-05',
        ],
      ],
      'field_source' => [
        ['target_id' => $source->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertEquals('EducationEvent', $data['@type']);
    $this->assertArrayHasKey('organizer', $data);
    $this->assertIsArray($data['organizer']);
    $this->assertEquals('UN OCHA', $data['organizer'][0]['name']);
  }

  /**
   * Test getData with countries as location.
   */
  public function testGetDataWithCountries(): void {
    $country1 = $this->createTerm($this->countryVocabulary, [
      'name' => 'Afghanistan',
    ]);

    $country2 = $this->createTerm($this->countryVocabulary, [
      'name' => 'Syria',
    ]);

    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_training_date' => [
        [
          'value' => '2021-06-01',
          'end_value' => '2021-06-05',
        ],
      ],
      'field_country' => [
        ['target_id' => $country1->id()],
        ['target_id' => $country2->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('hasCourseInstance', $data);
    $this->assertArrayHasKey('location', $data['hasCourseInstance']);
    $this->assertIsArray($data['hasCourseInstance']['location']);
    $this->assertCount(2, $data['hasCourseInstance']['location']);
    $this->assertEquals('Afghanistan', $data['hasCourseInstance']['location'][0]['name']);
    $this->assertEquals('Syria', $data['hasCourseInstance']['location'][1]['name']);
  }

  /**
   * Test getData with countries on EducationEvent.
   */
  public function testGetDataWithCountriesOnEducationEvent(): void {
    $country = $this->createTerm($this->countryVocabulary, [
      'name' => 'Afghanistan',
    ]);

    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Event',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_training_type' => [
        ['target_id' => $this->trainingTypeWorkshop->id()],
      ],
      'field_training_date' => [
        [
          'value' => '2021-06-01',
          'end_value' => '2021-06-05',
        ],
      ],
      'field_country' => [
        ['target_id' => $country->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertEquals('EducationEvent', $data['@type']);
    $this->assertArrayHasKey('location', $data);
    $this->assertIsArray($data['location']);
    $this->assertEquals('Afghanistan', $data['location'][0]['name']);
  }

  /**
   * Test getData with countries on permanent training (no location).
   */
  public function testGetDataWithCountriesPermanent(): void {
    $country = $this->createTerm($this->countryVocabulary, [
      'name' => 'Afghanistan',
    ]);

    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      // By default, CreateNode() sets the training date to the current date.
      // so, for the test, we need to set the training date to NULL.
      'field_training_date' => NULL,
      'field_country' => [
        ['target_id' => $country->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // Permanent training has no instance, so location should not be set.
    $this->assertArrayNotHasKey('location', $data);
    $this->assertArrayNotHasKey('hasCourseInstance', $data);
  }

  /**
   * Test getData with event URL.
   */
  public function testGetDataWithEventUrl(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_link' => [
        ['uri' => 'https://example.com/training'],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('sameAs', $data);
    $this->assertStringContainsString('example.com/training', $data['sameAs']);
  }

  /**
   * Test getData with invalid event URL.
   */
  public function testGetDataWithInvalidEventUrl(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_link' => [
        ['uri' => 'not-a-valid-url'],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // Invalid URL should not set sameAs.
    $this->assertArrayNotHasKey('sameAs', $data);
  }

  /**
   * Test getData with empty event URL.
   */
  public function testGetDataWithEmptyEventUrl(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_link' => [
        ['uri' => ''],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // Empty URL should not set sameAs.
    $this->assertArrayNotHasKey('sameAs', $data);
  }

  /**
   * Test getData with body content summary.
   */
  public function testGetDataWithBodySummary(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'body' => [
        [
          'value' => 'This is a test body content that should be summarized.',
          'format' => 'markdown',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
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
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      // CreateNode() adds a random body field if we do not provide one.
      // so, for the test, we need to set the body field to NULL.
      'body' => NULL,
    ]);

    $plugin = $this->getPlugin('rw_node_training');
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
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'body' => [
        [
          'value' => '',
          'format' => 'markdown',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // Empty body content should not set description field.
    $this->assertArrayNotHasKey('description', $data);
  }

  /**
   * Test getData without keywords (empty keywords array).
   */
  public function testGetDataWithoutKeywords(): void {
    $entity = $this->createNode([
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_training_date' => [
        [
          'value' => '2021-06-01',
          'end_value' => '2021-06-05',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // Empty keywords should not set keywords field (permanent keyword only
    // added when no dates).
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
      'type' => 'training',
      'title' => 'Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_training_language' => [
        ['target_id' => $language_other->id()],
        ['target_id' => $language_en->id()],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    $this->assertArrayHasKey('inLanguage', $data);
    $this->assertIsArray($data['inLanguage']);
    // "ot" should be skipped, only "en" should be present.
    $this->assertNotContains('ot', $data['inLanguage']);
    $this->assertContains('en', $data['inLanguage']);
  }

  /**
   * Test getData with all fields combined (Course).
   */
  public function testGetDataWithAllFieldsCourse(): void {
    $theme = $this->createTerm($this->themeVocabulary, [
      'name' => 'Emergency',
    ]);

    $category = $this->createTerm($this->careerCategoryVocabulary, [
      'name' => 'Program/Project Management',
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
      'type' => 'training',
      'title' => 'Comprehensive Test Training',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_training_type' => [
        ['target_id' => $this->trainingTypeAcademic->id()],
      ],
      'field_training_format' => [
        ['target_id' => $this->trainingFormatOnline->id()],
      ],
      'field_training_date' => [
        [
          'value' => '2021-06-01',
          'end_value' => '2021-06-05',
        ],
      ],
      'field_registration_deadline' => [
        ['value' => '2021-05-15'],
      ],
      'field_cost' => [
        ['value' => 'free'],
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
      'field_training_language' => [
        ['target_id' => $language->id()],
      ],
      'field_link' => [
        ['uri' => 'https://example.com/training'],
      ],
      'body' => [
        [
          'value' => 'This is comprehensive test content.',
          'format' => 'markdown',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // Verify all expected fields are present.
    $this->assertEquals('Course', $data['@type']);
    $this->assertEquals('Comprehensive Test Training', $data['name']);
    $this->assertArrayHasKey('hasCourseInstance', $data);
    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('Academic Degree/Course', $data['keywords']);
    $this->assertContains('Emergency', $data['keywords']);
    $this->assertContains('Program/Project Management', $data['keywords']);
    $this->assertArrayHasKey('provider', $data);
    $this->assertArrayHasKey('inLanguage', $data);
    $this->assertArrayHasKey('description', $data);
    $this->assertArrayHasKey('sameAs', $data);
    $this->assertTrue($data['isAccessibleForFree']);
  }

  /**
   * Test getData with all fields combined (EducationEvent).
   */
  public function testGetDataWithAllFieldsEducationEvent(): void {
    $theme = $this->createTerm($this->themeVocabulary, [
      'name' => 'Emergency',
    ]);

    $category = $this->createTerm($this->careerCategoryVocabulary, [
      'name' => 'Program/Project Management',
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
      'type' => 'training',
      'title' => 'Comprehensive Test Event',
      'created' => 1609459200,
      'changed' => 1609545600,
      'field_training_type' => [
        ['target_id' => $this->trainingTypeWorkshop->id()],
      ],
      'field_training_format' => [
        ['target_id' => $this->trainingFormatOnsite->id()],
      ],
      'field_training_date' => [
        [
          'value' => '2021-06-01',
          'end_value' => '2021-06-05',
        ],
      ],
      'field_registration_deadline' => [
        ['value' => '2021-05-15'],
      ],
      'field_cost' => [
        ['value' => 'fee-based'],
      ],
      'field_fee_information' => [
        ['value' => 'The fee is $500 per participant.'],
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
      'field_training_language' => [
        ['target_id' => $language->id()],
      ],
      'field_link' => [
        ['uri' => 'https://example.com/event'],
      ],
      'body' => [
        [
          'value' => 'This is comprehensive test content.',
          'format' => 'markdown',
        ],
      ],
    ]);

    $plugin = $this->getPlugin('rw_node_training');
    $schema = $plugin->getData($entity, 'default');
    $data = $schema->toArray();

    // Verify all expected fields are present.
    $this->assertEquals('EducationEvent', $data['@type']);
    $this->assertEquals('Comprehensive Test Event', $data['name']);
    $this->assertArrayHasKey('startDate', $data);
    $this->assertArrayHasKey('endDate', $data);
    $this->assertArrayHasKey('keywords', $data);
    $this->assertContains('Training/Workshop', $data['keywords']);
    $this->assertContains('Emergency', $data['keywords']);
    $this->assertContains('Program/Project Management', $data['keywords']);
    $this->assertArrayHasKey('organizer', $data);
    $this->assertArrayHasKey('location', $data);
    $this->assertArrayHasKey('inLanguage', $data);
    $this->assertArrayHasKey('description', $data);
    $this->assertArrayHasKey('sameAs', $data);
    $this->assertFalse($data['isAccessibleForFree']);
    $this->assertArrayHasKey('offers', $data);
    $this->assertArrayHasKey('validThrough', $data['offers']);
    $this->assertArrayHasKey('description', $data['offers']);
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
