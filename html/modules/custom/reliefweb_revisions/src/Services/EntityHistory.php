<?php

namespace Drupal\reliefweb_revisions\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_utility\Traits\EntityDatabaseInfoTrait;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Entity history service class.
 */
class EntityHistory {

  use DependencySerializationTrait;
  use EntityDatabaseInfoTrait;
  use StringTranslationTrait;

  /**
   * ReliefWeb API config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * Filters definition for the filter block on the moderation page.
   *
   * @var array
   */
  protected $filterDefinitions;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The translation manager service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    AccountProxyInterface $current_user,
    Connection $database,
    DateFormatterInterface $date_formatter,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeManagerInterface $entity_type_manager,
    RequestStack $request_stack,
    TranslationInterface $string_translation
  ) {
    $this->config = $config_factory->get('reliefweb_revisions.settings');
    $this->currentUser = $current_user;
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->request = $request_stack->getCurrentRequest();
    $this->stringTranslation = $string_translation;
  }

  /**
   * Get the revision history of an entity.
   *
   * @param \Drupal\reliefweb_revisions\EntityRevisionedInterface $entity
   *   Entity.
   *
   * @return array
   *   Render array with the revision history.
   */
  public function getEntityHistory(EntityRevisionedInterface $entity) {
    $entity_type_id = $entity->getEntityTypeId();

    // Get the list of revisions for the entity.
    $revision_ids = $this->getRevisionIds($entity);

    foreach ($revision_ids as $revision_id) {
      $revision = $this->loadRevision($entity_type_id, $revision_id);

      if (!isset($previous_revision)) {
        $this->loadRevision($entity_type_id, $revision_id);
      }

      $this->getRevisionDiff($revision, $previous_revision);
    }

    return [];
  }

  /**
   * Get the diff of 2 entity revisions.
   *
   * @param \Drupal\reliefweb_revisions\EntityRevisionedInterface $revision
   *   Revision to compare with the previous one.
   * @param \Drupal\reliefweb_revisions\EntityRevisionedInterface $previous_revision
   *   Previous revision.
   *
   * @return array
   *   Differences as an associative with the added and removed properties,
   *   each containing the list of field changes between the 2 revisions.
   */
  protected function getRevisionDiff(EntityRevisionedInterface $revision, EntityRevisionedInterface $previous_revision) {

    $diff = [
      'added' => [],
      'removed' => [],
    ];

    return $diff;
  }

  /**
   * Load an entity revision.
   *
   * @param string $entity_type_id
   *   Entity type id.
   * @param string $revision_id
   *   Revision id.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The revision entity or NULL if none could be found.
   */
  protected function loadRevision($entity_type_id, $revision_id) {
    return $this->getEntityTypeManager()
      ->getStorage($entity_type_id)
      ->loadRevision($revision_id);
  }

  /**
   * Get the ids of the revisions of the given entity.
   *
   * @param \Drupal\reliefweb_revisions\EntityRevisionedInterface $entity
   *   Entity.
   *
   * @return array
   *   List of revision ids ordered by most recent first.
   */
  protected function getRevisionIds(EntityRevisionedInterface $entity) {
    if ($entity->id() === NULL) {
      return [];
    }

    $entity_type_id = $entity->getEntityTypeId();
    $id_field = $this->getEntityTypeIdField($entity_type_id);
    $revision_id_field = $this->getEntityTypeRevisionIdField($entity_type_id);

    $storage = $this->getEntityTypeManager()
      ->getStorage($entity_type_id);

    $results = $storage->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition($id_field, $entity->id(), '=')
      ->sort($revision_id_field, 'DESC')
      ->execute();

    $ids = array_keys($results);

    // Extract the first revision.
    $first = pop($ids);

    // Limit the number of revisions to the most recent and add back the first
    // revision.
    $ids = array_slice($ids, 0, $this->config->get('limit') ?? 10);
    $ids[] = $first;

    return $ids;
  }

}
