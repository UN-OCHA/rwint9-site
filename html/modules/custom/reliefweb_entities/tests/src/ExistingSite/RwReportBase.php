<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_entities\ExistingSite;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\taxonomy\VocabularyInterface;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Base class for ReliefWeb report posting tests.
 */
class RwReportBase extends ExistingSiteBase {

  /**
   * Vocabularies.
   *
   * @var \Drupal\taxonomy\VocabularyInterface[]
   */
  protected $vocabularies;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Site name.
   *
   * @var string
   */
  protected $siteName;

  /**
   * User posting rights manager service.
   *
   * @var \Drupal\reliefweb_moderation\Services\UserPostingRightsManager
   */
  protected $userPostingRightsManager;

  /**
   * Original posting rights status mapping.
   *
   * @var array
   */
  protected $originalPostingRightsStatusMapping;

  /**
   * Set up the test.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->siteName = \Drupal::config('system.site')->get('name');

    // Get the service.
    $this->userPostingRightsManager = \Drupal::service('reliefweb_moderation.user_posting_rights');

    // Save the original posting rights status mapping.
    $this->originalPostingRightsStatusMapping = $this->userPostingRightsManager->getUserPostingRightsToModerationStatusMapping();

    // Set up default moderation status mapping.
    $this->setUpDefaultModerationStatusMapping();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Reset static cache.
    $this->userPostingRightsManager->resetCache();

    // Restore the original posting rights status mapping.
    $this->userPostingRightsManager->setUserPostingRightsToModerationStatusMapping($this->originalPostingRightsStatusMapping);

    parent::tearDown();
  }

  /**
   * Create a term if it doesn't already exist.
   *
   * @param string $vocabulary
   *   The vocabulary to create the term in.
   * @param int $id
   *   The term ID.
   * @param string $title
   *   The term title.
   * @param array $extra
   *   Extra fields to add to the term.
   *
   * @return \Drupal\taxonomy\TermInterface
   *   The term.
   */
  protected function createTermIfNeeded(string $vocabulary, int $id, string $title, array $extra = []) : TermInterface {
    $term = $this->getEntityTypeManager()
      ->getStorage('taxonomy_term')
      ->load($id);

    if (!empty($term)) {
      return $term;
    }

    $extra['moderation_status'] = match ($vocabulary) {
      'country' => 'ongoing',
      'disaster' => 'ongoing',
      'source' => 'active',
      default => 'published',
    };
    $extra['status'] = 1;
    $extra['langcode'] = 'en';

    // Create the term.
    $term = $this->createTerm($this->getVocabulary($vocabulary), [
      'tid' => $id,
      'name' => $title,
    ] + $extra);

    return $term;
  }

  /**
   * Get a term, creating it if it doesn't already exist.
   *
   * @param string $vocabulary
   *   The vocabulary to create the term in.
   * @param int $id
   *   The term ID.
   * @param array $properties
   *   The properties to add to the term.
   *
   * @return \Drupal\taxonomy\TermInterface
   *   The term.
   */
  protected function getTerm(string $vocabulary, int $id, array $properties): TermInterface {
    $term = $this->getEntityTypeManager()
      ->getStorage('taxonomy_term')
      ->load($id);

    if (!empty($term)) {
      return $term;
    }
    return $this->createTerm($this->getVocabulary($vocabulary), [
      'tid' => $id,
    ] + $properties);
  }

  /**
   * Get a vocabulary, creating it if it doesn't already exist.
   *
   * @param string $id
   *   ID of the vocabulary to retrieve.
   *
   * @return \Drupal\taxonomy\VocabularyInterface
   *   The vocabulary.
   */
  protected function getVocabulary(string $id): VocabularyInterface {
    if (!isset($this->vocabularies[$id])) {
      $vocabulary = $this->getEntityTypeManager()
        ->getStorage('taxonomy_vocabulary')
        ->load($id);

      if (empty($vocabulary)) {
        $vocabulary = $this->createVocabulary($id);
      }

      $this->vocabularies[$id] = $vocabulary;
    }
    return $this->vocabularies[$id];
  }

  /**
   * Get the entity type manager.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager.
   */
  protected function getEntityTypeManager(): EntityTypeManagerInterface {
    if (!isset($this->entityTypeManager)) {
      $this->entityTypeManager = $this->container->get('entity_type.manager');
    }
    return $this->entityTypeManager;
  }

  /**
   * Set the posting rights to moderation status mapping for testing.
   *
   * @param array $mapping
   *   The mapping array with structure:
   *   [
   *     'role_name' => [
   *       'content_type' => [
   *         'blocked' => 'refused',
   *         'trusted_all' => 'published',
   *         'trusted_some_allowed' => 'published',
   *         'trusted_some_unverified' => 'pending',
   *         'allowed_all' => 'published',
   *         'allowed_some_unverified' => 'pending',
   *         'unverified_all' => 'pending',
   *       ],
   *     ],
   *   ].
   */
  protected function setPostingRightsToModerationStatusMapping(array $mapping): void {
    $this->userPostingRightsManager->setUserPostingRightsToModerationStatusMapping($mapping);
  }

  /**
   * Set up default contributor moderation status mapping.
   *
   * This method sets up the standard mapping that allows tests to work with
   * expected moderation status transitions based on posting rights.
   */
  protected function setUpDefaultModerationStatusMapping(): void {
    $mapping = [
      'contributor' => [
        'report' => [
          'blocked' => 'refused',
          'trusted_all' => 'published',
          'trusted_some_allowed' => 'published',
          'trusted_some_unverified' => 'pending',
          'allowed_all' => 'to-review',
          'allowed_some_unverified' => 'to-review',
          'unverified_all' => 'pending',
        ],
      ],
    ];
    $this->setPostingRightsToModerationStatusMapping($mapping);
  }

  /**
   * Get the moderation status based on the posting rights scenario.
   *
   * @param string $scenario
   *   The scenario to get the moderation status for.
   * @param string $role
   *   The role to get the moderation status for. Defaults to 'contributor'.
   *
   * @return string
   *   The moderation status. Defaults to 'draft' if the scenario is not found.
   */
  protected function getModerationStatusForScenario(string $scenario, string $role = 'contributor'): string {
    $mapping = $this->userPostingRightsManager->getUserPostingRightsToModerationStatusMapping();
    return $mapping[$role]['report'][$scenario] ?? 'draft';
  }

  /**
   * Get the expected response status as anonymous from moderation status.
   *
   * @param string $status
   *   The moderation status.
   *
   * @return int
   *   The expected response status.
   */
  protected function getExpectedResponseStatusAsAnonymous(string $status): int {
    return match ($status) {
      'published' => 200,
      'to-review' => 200,
      default => 404,
    };
  }

  /**
   * Create a report source term with posting rights.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user to assign posting rights to.
   * @param string|null $report_right
   *   The report posting right (unverified, blocked, allowed or trusted).
   *   If not provided, no posting rights will be assigned.
   * @param string|null $moderation_status
   *   The moderation status. Defaults to 'active'.
   * @param string|null $name
   *   The source name. Defaults to a random name.
   *
   * @return \Drupal\taxonomy\TermInterface
   *   The created source term.
   */
  protected function createReportSource(
    AccountInterface $user,
    ?string $report_right = NULL,
    ?string $moderation_status = 'active',
    ?string $name = NULL,
  ): TermInterface {
    if (!$name) {
      $name = $this->randomMachineName(16);
    }

    $status = match ($moderation_status) {
      'active' => 1,
      'inactive' => 1,
      default => 0,
    };

    $report_right = match ($report_right) {
      'unverified' => 0,
      'blocked' => 1,
      'allowed' => 2,
      'trusted' => 3,
      default => NULL,
    };

    return $this->createTerm($this->getVocabulary('source'), [
      'name' => $name,
      'moderation_status' => $moderation_status,
      'status' => $status,
      'langcode' => 'en',
      'field_shortname' => $name,
      'field_allowed_content_types' => [
        1,
      ],
      'field_user_posting_rights' => $report_right ? [
        [
          'id' => $user->id(),
          'report' => $report_right,
          'job' => 0,
          'training' => 0,
        ],
      ] : NULL,
    ]);
  }

  /**
   * Get the fields to populate the report form.
   *
   * @param string $title
   *   The title of the report.
   * @param \Drupal\taxonomy\TermInterface|null $source
   *   The source term to use. If not provided, will create a default one.
   *
   * @return array
   *   The fields to populate the form.
   */
  protected function getEditFields(string $title, ?TermInterface $source = NULL): array {
    // Load or create the terms.
    $term_language = $this->createTermIfNeeded('language', 267, 'English');
    $term_country = $this->createTermIfNeeded('country', 34, 'Belgium');
    $term_format = $this->createTermIfNeeded('content_format', 11, 'UN Document');
    // Use provided source or create a default one.
    if (!$source) {
      $source = $this->createTermIfNeeded('source', 43679, 'ABC Color', [
        'field_shortname' => 'ABC Color',
        'field_allowed_content_types' => [
          1,
        ],
      ]);
    }

    // Prepare the form fields.
    $fields = [];
    $fields['title[0][value]'] = $title;
    $fields['field_language[' . $term_language->id() . ']'] = $term_language->id();
    $fields['field_country[]'] = [$term_country->id()];
    $fields['field_primary_country'] = $term_country->id();
    $fields['field_content_format'] = $term_format->id();
    $fields['field_source[]'] = [$source->id()];

    return $fields;
  }

}
