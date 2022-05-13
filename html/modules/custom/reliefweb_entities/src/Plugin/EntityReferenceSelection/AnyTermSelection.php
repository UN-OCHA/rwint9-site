<?php

namespace Drupal\reliefweb_entities\Plugin\EntityReferenceSelection;

use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\reliefweb_utility\Traits\EntityDatabaseInfoTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Term Entity Reference Selection plugin implementation for any status.
 *
 * Notes:
 * - This is a term selection that doesn't care about the publication
 *   status, allowing the selection of term saved as draft for example.
 * - It's intended to be used instead of the taxonomy termn selection plugin:
 *   \Drupal\taxonomy\Plugin\EntityReferenceSelection\TermSelection.
 * - As opposed to the default selection plugin, this doesn't try to load all
 *   the entities when building the selectable entities but just load the
 *   relevant label to avoid out of memory issues with large vocabularies.
 *
 * @see \Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManager
 * @see \Drupal\Core\Entity\Annotation\EntityReferenceSelection
 * @see \Drupal\Core\Entity\EntityReferenceSelection\SelectionInterface
 * @see \Drupal\Core\Entity\Plugin\Derivative\DefaultSelectionDeriver
 * @see plugin_api
 *
 * @EntityReferenceSelection(
 *   id = "any_term",
 *   label = @Translation("Taxonomy term with any status"),
 *   entity_types = {"taxonomy_term"},
 *   group = "any_term",
 *   weight = 0
 * )
 */
class AnyTermSelection extends DefaultSelection {

  use EntityDatabaseInfoTrait;

  /**
   * The database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Constructs a new DefaultSelection object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param \Drupal\Core\Database\Connection $database
   *   The database.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler,
    AccountInterface $current_user,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeBundleInfoInterface $entity_type_bundle_info = NULL,
    EntityRepositoryInterface $entity_repository,
    Connection $database,
    LanguageManagerInterface $language_manager
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $entity_type_manager,
      $module_handler,
      $current_user,
      $entity_field_manager,
      $entity_type_bundle_info,
      $entity_repository
    );

    $this->database = $database;
    $this->languageManager = $language_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('current_user'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity.repository'),
      $container->get('database'),
      $container->get('language_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getReferenceableEntities($match = NULL, $match_operator = 'CONTAINS', $limit = 0) {
    $configuration = $this->getConfiguration();
    $entity_type_id = $configuration['target_type'];
    $sort = $configuration['sort'];

    $query = $this->buildEntityQuery($match, $match_operator);
    if ($limit > 0) {
      $query->range(0, $limit);
    }

    $ids = $query->execute();

    if (empty($ids)) {
      return [];
    }

    // Get the datbase info for the referenced entity type.
    $table = $this->getEntityTypeDataTable($entity_type_id);
    $id_field = $this->getEntityTypeIdField($entity_type_id);
    $label_field = $this->getEntityTypeLabelField($entity_type_id);
    $bundle_field = $this->getEntityTypeBundleField($entity_type_id);
    $langcode_field = $this->getEntityTypeLangcodeField($entity_type_id);

    // Get the current and default language codes.
    $current_langcode = $this->languageManager->getCurrentLanguage()->getId();
    $default_langcode = $this->languageManager->getDefaultLanguage()->getId();
    $langcodes = array_unique([$current_langcode, $default_langcode]);

    // We just want the label for the current language (or default one),
    // so we do another query with the entity IDs retrieved previously.
    // This much less resource intensive than loading all the entities,
    // especially for large vocabularies like disaster and source.
    $query = $this->getDatabase()->select($table, $table);
    $query->addField($table, $id_field, 'id');
    $query->addField($table, $label_field, 'label');
    $query->addField($table, $langcode_field, 'langcode');
    $query->addField($table, $bundle_field, 'bundle');
    $query->condition($table . '.' . $id_field, $ids, 'IN');
    $query->condition($table . '.' . $langcode_field, $langcodes, 'IN');

    // Add the sort option.
    $sortable_fields = [$id_field => TRUE, $label_field => TRUE];
    if (isset($sortable_fields[$sort['field']])) {
      $query->orderBy($sort['field'], $sort['direction']);
    }

    $options = [];
    foreach ($query->execute() ?? [] as $record) {
      $id = (int) $record->id;
      $langcode = $record->langcode;
      $bundle = $record->bundle;

      // The record in the current language takes precedence over the default
      // language version.
      if ($langcode === $current_langcode || !isset($options[$bundle][$id])) {
        // Note: for RW, there is not hierarchical terms so we can get away with
        // simply using the label. However if at some point, RW starts using
        // nested terms then we'll want to join the taxonomy_term__parent table,
        // compute the term depth and prefix the label with something like
        // str_repeat('-', $term->depth) like it's done in TermSelection.
        $options[$bundle][$id] = Html::escape($record->label);
      }
    }

    return $options;
  }

}
