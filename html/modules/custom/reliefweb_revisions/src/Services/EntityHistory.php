<?php

namespace Drupal\reliefweb_revisions\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
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
    if (!$this->currentUser->hasPermission('view entity history') || $entity->id() === NULL) {
      return [];
    }

    $entity_id = $entity->id();
    $entity_type_id = $entity->getEntityTypeId();

    // Get the list of revisions for the entity.
    $revision_ids = $this->getRevisionIds($entity);

    // Create a stub entity so we can show the diff for the first revision.
    $previous_revision = $this->createStubRevision($entity);

    $rows = [];
    foreach ($revision_ids as $revision_id) {
      $revision = $this->loadRevision($entity_type_id, $revision_id);

      // Get the changes between the revisions.
      $diff = $this->getRevisionDiff($revision, $previous_revision);
      dpm($diff);

      // Format the differences.
      //$formatted = $this->formatRevisionDiff($diff)
      //dpm($diff);

      $previous_revision = $revision;
    }

    return [];
  }

  protected function formatRevisionDiff(EntityRevisionedInterface $revision, array $diff) {
    $fields = [];
    foreach ($revision->getFieldDefinitions() as $field_name => $field_definition) {
      if (isset($diff[$field_name])) {
        $fields[$field_name] = $this->formatFieldDiff($field_definition, $diff[$field_name]);
      }
    }
    return $fields;
  }

  /**
   * Format the difference between 2 fields.
   */
  protected function formatFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    $label = $field_definition->getLabel();
    dpm($field_definition->getName() . ' - ' . $field_definition->getType());

    /*witch ($field_definition->getType()) {

    }*/
  }

  /**
   * Create a stub entity revision.
   *
   * @param \Drupal\reliefweb_revisions\EntityRevisionedInterface $entity
   *   Entity.
   *
   * @return \Drupal\reliefweb_revisions\EntityRevisionedInterface
   *   Stub entity revision.
   */
  protected function createStubRevision(EntityRevisionedInterface $entity) {
    $class = get_class($entity);

    $values = [];
    foreach ($entity->getEntityType()->getKeys() as $field) {
      $values[$field] = $entity->get($field)->getValue();
    }
    foreach ($entity->getEntityType()->getRevisionMetadataKeys() as $field) {
      $values[$field] = $entity->get($field)->getValue();
    }

    return $class::create($values);
  }

  /**
   * Get the diff of 2 entity revisions.
   *
   * @param \Drupal\reliefweb_revisions\EntityRevisionedInterface $revision
   *   Revision to compare with the previous one.
   * @param \Drupal\reliefweb_revisions\EntityRevisionedInterface|null $previous_revision
   *   Previous revision (NULL if there is none).
   *
   * @return array
   *   Differences as an associative with the added and removed properties,
   *   each containing the list of field changes between the 2 revisions.
   */
  protected function getRevisionDiff(EntityRevisionedInterface $revision, ?EntityRevisionedInterface $previous_revision = NULL) {
    /** @var \Drupal\Core\Field\FieldDefintionInterface[] $field_definitions */
    $field_definitions = $revision->getFieldDefinitions();

    $diff = [];
    foreach ($field_definitions as $field_name => $field_definition) {
      $current = $revision->get($field_name)->getValue();
      $previous = $previous_revision->get($field_name)->getValue();

      $added = $this->getArrayDiff($current, $previous);
      $removed = $this->getArrayDiff($previous, $current);

      if (!empty($added) || !empty($removed)) {
        $diff[$field_name] = [
          'added' => $added,
          'removed' => $removed,
        ];
      }
    }

    return $diff;
  }

  /**
   * Get the difference between 2 nested associative arrays.
   *
   * @param array $array1
   *   First array.
   * @param array $array2
   *   Second array.
   *
   * @return array
   *   Values from the first array not present in the second array.
   *
   * @todo move to a helper function?
   */
  protected function getArrayDiff(array $array1, array $array2) {
    if (empty($array2)) {
      return $array1;
    }
    elseif (empty($array1)) {
      return $array2;
    }

    $diff = [];
    foreach ($array1 as $key => $value1) {
      if (array_search($value1, $array2) === FALSE) {
        $diff[$key] = $value1;
      }
    }
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
    $first = array_pop($ids);

    // Limit the number of revisions to the most recent and add back the first
    // revision.
    $ids = array_slice($ids, 0, $this->config->get('limit') ?? 10);
    $ids[] = $first;

    return $ids;
  }

  /**
   * Add the entity history to a form.
   *
   * @param array $form
   *   Entity form.
   *
   * @return array
   *   Render array with the history
   */
  public static function addHistoryToForm(array &$form, FormStateInterface $form_state) {
    $entity = $form_state?->getFormObject()?->getEntity();
    if (!empty($entity) && $entity instanceof EntityRevisionedInterface) {
      $history = \Drupal::service('reliefweb_revisions.entity.history')
        ->getEntityHistory($entity);

      if (!empty($history)) {
        $form['revision_history'] = $history;
        $form['revision_history']['#group'] = 'revision_information';
      }
    }
  }

}
