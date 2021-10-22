<?php

namespace Drupal\reliefweb_revisions\Services;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\media\MediaInterface;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_utility\Helpers\DateHelper;
use Drupal\reliefweb_utility\Helpers\MediaHelper;
use Drupal\reliefweb_utility\Helpers\TextHelper;
use Drupal\reliefweb_utility\Traits\EntityDatabaseInfoTrait;
use Drupal\user\Entity\User;
use Drupal\user\EntityOwnerInterface;
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

    $entity_type_id = $entity->getEntityTypeId();
    $revision_user_field = $this->getEntityTypeRevisionUserField($entity_type_id);
    $revision_created_field = $this->getEntityTypeRevisionCreatedField($entity_type_id);

    // Retrieve the author of the entity.
    $author = $this->getEntityAuthor($entity);

    // Get the list of revisions for the entity.
    $revision_ids = $this->getRevisionIds($entity);

    // Keep track of the previous revision.
    $previous_revision = NULL;

    $history = [];
    foreach ($revision_ids as $revision_id) {
      $revision = $this->loadRevision($entity_type_id, $revision_id);

      // Get the changes between the revisions.
      // @todo skip some fields.
      $diff = $this->getRevisionDiff($revision, $previous_revision);

      // Format the differences.
      $content = $this->formatRevisionDiff($revision, $diff);

      // Skip if nothing of value changed.
      $skip = empty($content) &&
        !empty($previous_revision) &&
        $revision->getRevisionLogMessage() === $previous_revision->getRevisionLogMessage() &&
        $revision->getModerationStatus() === $previous_revision->getModerationStatus();

      if (!$skip) {
        $user = $revision->get($revision_user_field)->entity;
        $date = $revision->get($revision_created_field)->value;
        $message = trim($revision->getRevisionLogMessage());

        $history[] = [
          'date' => DateHelper::getDateTimeStamp($date),
          'user' => $user,
          'status' => [
            'value' => $revision->getModerationStatus(),
            'label' => $revision->getModerationStatusLabel(),
          ],
          'message' => [
            'type' => isset($user, $author) && $user->id() === $author->id() ? 'instruction' : 'feedback',
            'content' => !empty($message) ? check_markup($message, 'markdown') : '',
          ],
          'content' => $content,
        ];
      }

      // Keep track of the previous revision.
      $previous_revision = $revision;
    }

    return [
      '#theme' => 'reliefweb_revisions_history',
      '#entity' => $entity,
      // Show the most recent history first.
      '#history' => array_reverse($history),
    ];
  }

  /**
   * Get the author of the entity.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   User entity.
   */
  protected function getEntityAuthor(EntityRevisionedInterface $entity) {
    if ($entity instanceof EntityOwnerInterface) {
      return $entity->getOwner();
    }

    $entity_type_id = $entity->getEntityTypeId();
    $id_field = $this->getEntityTypeIdField($entity_type_id);
    $revision_table = $this->getEntityTypeRevisionTable($entity_type_id);
    $revision_id_field = $this->getEntityTypeRevisionIdField($entity_type_id);
    $revision_user_field = $this->getEntityTypeRevisionUserField($entity_type_id);

    $uid = $this->getDatabase()
      ->select($revision_table, $revision_table)
      ->fields($revision_table, [$revision_user_field])
      ->condition($revision_table . '.' . $id_field, $entity->id(), '=')
      ->orderBy($revision_table . '.' . $revision_id_field, 'ASC')
      ->range(0, 1)
      ->execute()
      ?->fetchField();

    // Default to the System user if no user could be found.
    return User::load($uid ?: 2);
  }

  /**
   * Form the differences between 2 revisions.
   *
   * @param \Drupal\reliefweb_revisions\EntityRevisionedInterface $revision
   *   The current revision.
   * @param array $diff
   *   The differences between the revision and the previous one.
   *
   * @return \Drupal\Component\Render\MarkupInteface
   *   The formatted differences.
   */
  protected function formatRevisionDiff(EntityRevisionedInterface $revision, array $diff) {
    $fields = [];
    foreach ($revision->getFieldDefinitions() as $field_name => $field_definition) {
      if (isset($diff[$field_name])) {
        $markup = $this->formatFieldDiff($field_definition, $diff[$field_name]);
        if (!empty($markup)) {
          $fields[$field_name] = [
            'label' => $field_definition->getLabel(),
            'value' => $markup,
          ];
        }
      }
    }
    return $fields;
  }

  /**
   * Format the difference between 2 fields.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition for the field to format.
   * @param array $diff
   *   The differences between 2 revisions of the field.
   *
   * @return \Drupal\Component\Render\MarkupInteface
   *   The formatted differences.
   */
  protected function formatFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    switch ($field_definition->getType()) {
      case 'text':
      case 'text_long':
      case 'text_with_summary':
      case 'string':
      case 'string_long':
        return $this->formatTextFieldDiff($field_definition, $diff);

      case 'entity_reference':
        return $this->formatEntityReferenceFieldDiff($field_definition, $diff);

      case 'list_integer':
      case 'list_string':
        return $this->formatListFieldDiff($field_definition, $diff);

      case 'datetime':
        return $this->formatDatetimeFieldDiff($field_definition, $diff);

      case 'daterange':
        return $this->formatDaterangeFieldDiff($field_definition, $diff);

      case 'integer':
        return $this->formatScalarFieldDiff($field_definition, $diff);

      case 'boolean':
        return $this->formatBooleanFieldDiff($field_definition, $diff);

      case 'link':
        return $this->formatComplexFieldDiff($field_definition, $diff);

      /*case 'reliefweb_links':
        break;

      case 'reliefweb_section_links':
        break;

      case 'reliefweb_user_posting_rights':
        break;*/

      default:
        return $this->formatComplexFieldDiff($field_definition, $diff);
    }
    return NULL;
  }

  /**
   * Format text field differences.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   * @param array $diff
   *   Field value differences.
   *
   * @return \Drupal\Core\Render\MarkupInterface|null
   *   The HTML showing the difference.
   *
   * @todo review if there are text type fields that accept multiple values.
   */
  protected function formatTextFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    if (empty($diff['added']) && empty($diff['removed'])) {
      return NULL;
    }

    $from_text = $diff['removed'][0]['value'] ?? '';
    $to_text = $diff['added'][0]['value'] ?? '';

    // Get the differences between the 2 texts.
    $diff_text = TextHelper::getTextDiff($from_text, $to_text);
    if (empty($diff_text)) {
      return NULL;
    }

    // If the text is too long (ex: body), then we wrap it in a `<details>`.
    if (mb_strlen($diff_text) > 400) {
      $markup = '<details class="rw-revision-text-content"><summary>View changes</summary>' . $diff_text . '</details>';
    }
    else {
      $markup = $diff_text;
    }

    return Markup::create($markup);
  }

  /**
   * Format entity reference field differences.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   * @param array $diff
   *   Field value differences.
   *
   * @return \Drupal\Core\Render\MarkupInterface|null
   *   The HTML showing the difference.
   *
   * @todo Check how that is and use an accumulator to store the terms ids
   * and load all their labels at once.
   */
  protected function formatEntityReferenceFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    if (empty($diff['added']) && empty($diff['removed'])) {
      return NULL;
    }

    $entity_type_id = $field_definition
      ->getFieldStorageDefinition()
      ->getSetting('target_type');

    $added = [];
    foreach ($diff['added'] ?? [] as $value) {
      if (isset($value['target_id'])) {
        $added[$value['target_id']] = $value['target_id'];
      }
    }

    $removed = [];
    foreach ($diff['removed'] ?? [] as $value) {
      if (isset($value['target_id'])) {
        $removed[$value['target_id']] = $value['target_id'];
      }
    }

    // For non media entity references, we simply return a list of the entity
    // labels.
    if ($entity_type_id !== 'media') {
      $labels = $this->loadEntityLabels($entity_type_id, $added + $removed);
      $values = [];
      foreach ($added as $id) {
        if (isset($labels[$id])) {
          $values[] = '<ins>' . Xss::filter($labels[$id]) . '</ins>';
        }
      }
      foreach ($removed as $id) {
        if (isset($labels[$id])) {
          $values[] = '<del>' . Xss::filter($labels[$id]) . '</del>';
        }
      }
      return Markup::create(implode(', ', $values));
    }
    // For media, we load the entities to be able to show the thumbnail.
    else {
      $media_entities = $this->getEntityTypeManager()
        ->getStorage($entity_type_id)
        ->loadMultiple($added + $removed);

      $values = [];
      foreach ($media_entities as $id => $media_entity) {
        // Link to the edit form because media don't have a page.
        $value = $media_entity->toLink(NULL, 'edit-form')->toString();
        $thumbnail = $this->getMediaThumbnail($media_entity);
        if (!empty($thumbnail)) {
          $value = $thumbnail . ' ' . $value;
        }
        if (isset($added[$id])) {
          $values['added'][] = $value;
        }
        elseif (isset($removed[$id])) {
          $values['removed'][] = $value;
        }
      }

      $markup = [];
      foreach ($values as $key => $items) {
        $markup[] = '<dt class="' . $key . '">' . $key . '</dt>';
        foreach ($items as $item) {
          $markup[] = '<dd>' . $item . '</dd>';
        }
      }

      return Markup::create('<dl>' . implode('', $markup) . '</dl>');
    }
  }

  /**
   * Get a media thumbnail rendered markup.
   *
   * @param \Drupal\media\MediaInterface $media
   *   Media.
   *
   * @return \Drupal\Core\Render\MarkupInterface|null
   *   Thumbnail markup.
   */
  protected function getMediaThumbnail(MediaInterface $media) {
    $image = MediaHelper::getImageFromMediaEntity($media);
    if (!empty($image)) {
      $build = [
        '#theme' => 'image_style',
        '#style_name' => 'thumbnail',
        '#uri' => $image['uri'],
        '#width' => $image['width'],
        '#height' => $image['height'],
      ];
      return \Drupal::service('renderer')->render($build);
    }
    return NULL;
  }

  /**
   * Format list field differences.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   * @param array $diff
   *   Field value differences.
   *
   * @return \Drupal\Core\Render\MarkupInterface|null
   *   The HTML showing the difference.
   */
  protected function formatListFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    if (empty($diff['added']) && empty($diff['removed'])) {
      return NULL;
    }

    $allowed_values = $field_definition->getSetting('allowed_values') ?? [];

    $values = [];
    foreach ($diff['added'] ?? [] as $value) {
      if (isset($allowed_values[$value['value']])) {
        $values[] = '<ins>' . Xss::filter($allowed_values[$value['value']], []) . '</ins>';
      }
    }
    foreach ($diff['removed'] ?? [] as $value) {
      if (isset($allowed_values[$value['value']])) {
        $values[] = '<del>' . Xss::filter($allowed_values[$value['value']], []) . '</del>';
      }
    }

    return !empty($values) ? Markup::create(implode(', ', $values)) : NULL;
  }

  /**
   * Format boolean field differences.
   *
   * Note: we are ignoring the cardinality because RW doesn't use boolean
   * fields with multiple values.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   * @param array $diff
   *   Field value differences.
   *
   * @return \Drupal\Core\Render\MarkupInterface|null
   *   The HTML showing the difference.
   */
  protected function formatBooleanFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    $markup = [];
    if (isset($diff['removed'][0]['value'])) {
      if (empty($diff['removed'][0]['value'])) {
        $markup[] = '<del class="unchecked">unchecked</del>';
      }
      else {
        $markup[] = '<del class="checked">checked</del>';
      }
    }
    if (isset($diff['added'][0]['value'])) {
      if (empty($diff['added'][0]['value'])) {
        $markup[] = '<ins class="unchecked">unchecked</ins>';
      }
      else {
        $markup[] = '<ins class="checked">checked</ins>';
      }
    }

    return !empty($markup) ? Markup::create(implode(' ', $markup)) : NULL;
  }

  /**
   * Format datetime field differences.
   *
   * Note: we are ignoring the cardinality because RW doesn't use datetime
   * fields with multiple values.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   * @param array $diff
   *   Field value differences.
   *
   * @return \Drupal\Core\Render\MarkupInterface|null
   *   The HTML showing the difference.
   */
  protected function formatDatetimeFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    if (empty($diff['added']) && empty($diff['removed'])) {
      return NULL;
    }

    $values = [];
    if (!empty($diff['removed'][0]['value'])) {
      $date = DateHelper::getDateTimeStamp($diff['removed'][0]['value']);
      $date = $this->dateFormatter->format($date, 'custom', 'd M Y H:i:s e', 'UTC');
      $values[] = '<del>' . $date . '</del>';
    }
    if (!empty($diff['added'][0]['value'])) {
      $date = DateHelper::getDateTimeStamp($diff['added'][0]['value']);
      $date = $this->dateFormatter->format($date, 'custom', 'd M Y H:i:s e', 'UTC');
      $values[] = '<ins>' . $date . '</ins>';
    }

    return !empty($values) ? Markup::create(implode(', ', $values)) : NULL;
  }

  /**
   * Format daterange field differences.
   *
   * Note: we are ignoring the cardinality because RW doesn't use datetime
   * fields with multiple values.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   * @param array $diff
   *   Field value differences.
   *
   * @return \Drupal\Core\Render\MarkupInterface|null
   *   The HTML showing the difference.
   */
  protected function formatDaterangeFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    if (empty($diff['added']) && empty($diff['removed'])) {
      return NULL;
    }

    $fields = ['value' => 'start', 'end_value' => 'end'];

    $markup = [];
    foreach ($fields as $key => $name) {
      $values = [];
      if (!empty($diff['removed'][0][$key])) {
        $date = DateHelper::getDateTimeStamp($diff['removed'][0][$key]);
        $date = $this->dateFormatter->format($date, 'custom', 'd M Y H:i:s e', 'UTC');
        $values['start'] = '<del>' . $date . '</del>';
      }
      if (!empty($diff['added'][0][$key])) {
        $date = DateHelper::getDateTimeStamp($diff['added'][0][$key]);
        $date = $this->dateFormatter->format($date, 'custom', 'd M Y H:i:s e', 'UTC');
        $values[] = '<ins>' . $date . '</ins>';
      }
      if (!empty($values)) {
        $markup[] = '<dt>' . $name . '</dt>';
        $markup[] = '<dd>' . implode(' ', $values) . '</dd>';
      }
    }

    return !empty($markup) ? Markup::create('<dl>' . implode('', $markup) . '</dl>') : NULL;
  }

  /**
   * Format a scalar field differences.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   * @param array $diff
   *   Field value differences.
   *
   * @return \Drupal\Core\Render\MarkupInterface|null
   *   The HTML showing the difference.
   */
  protected function formatScalarFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    if (empty($diff['added']) && empty($diff['removed'])) {
      return NULL;
    }

    $main_property = $field_definition
      ->getFieldStorageDefinition()
      ->getMainPropertyName();

    return $this->formatArrayDiff($diff, $main_property);
  }

  /**
   * Format a complex field differences.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   * @param array $diff
   *   Field value differences.
   *
   * @return \Drupal\Core\Render\MarkupInterface|null
   *   The HTML showing the difference.
   */

  protected function formatComplexFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    if (empty($diff['added']) && empty($diff['removed'])) {
      return NULL;
    }

    $storage_definition = $field_definition->getFieldStorageDefinition();
    $main_property = $storage_definition->getMainPropertyName();
    $properties = $storage_definition->getPropertyDefinitions();

    /*if (!$storage_definition->isMultiple()) {
      $diff = [
        'added' => $diff['added'][0] ?? NULL,
        'removed' => $diff['removed'][0] ?? NULL,
      ];
    }*/

    dpm($properties);
    dpm($field_definition);

    if (count($properties) <= 1) {
      return $this->formatArrayDiff($diff, $main_property);
    }

    $values = [];
    foreach (['removed', 'added'] as $key) {
      foreach ($diff[$key] as $item) {
        dpm($item);
        $markup = [];
        foreach ($properties as $property => $definition) {
          if ($definition->isReadOnly() || !isset($item[$property]) || !is_scalar($item[$property])) {
            continue;
          }
          $value = $item[$property];
          if (is_string($value)) {
            if ($value === '') {
              $value = '<em>empty</em>';
            }
            else {
              $value = Xss::filter($value, []);
            }
          }
          $markup[] = '<dt>' . $definition->getLabel() . '</dt>';
          $markup[] = '<dd>' . $value . '</dd>';
        }
        if (!empty($markup)) {
          $values[$key][] = '<li><dl>' . implode('', $markup) . '</dl></li>';
        }
      }
      if (!empty($values[$key])) {
        $values[$key] = '<dt>' . $key . '</dt><dd><ul>' . implode('', $values[$key]) . '</ul></dd>';
      }
    }

    return !empty($values) ? Markup::create('<dl>' . implode('', $values) . '</dl>') : NULL;
  }

  protected function formatArrayDiff(array $diff, $property = NULL) {
    $values = [];
    $direct = empty($property);

    foreach (['removed' => 'del', 'added' => 'ins'] as $key => $tag) {
      if (empty($diff[$key])) {
        continue;
      }

      foreach ($diff[$key] as $item) {
        $value = NULL;
        if ($direct) {
          $value = $item;
        }
        elseif (is_array($item) && isset($item[$property])) {
          $value = $item[$property];
        }

        if (is_null($value)) {
          continue;
        }
        elseif (is_string($value)) {
          $value = Xss::filter($value, []);
        }

        $values[] = '<' . $tag . '>' . $value . '</' . $tag . '>';
      }
    }

    return !empty($values) ? Markup::create(implode(', ', $values)) : NULL;
  }

  /**
   * Load the entity label for the given ids.
   *
   * @param string $entity_type_id
   *   Entity type id.
   * @param array $ids
   *   Entity ids.
   *
   * @return array
   *   Associative array keyed by the entity ids and with their labels as
   *   values.
   */
  protected function loadEntityLabels($entity_type_id, array $ids) {
    static $cache = [];

    if (!isset($cache[$entity_type_id])) {
      $cache[$entity_type_id] = [];
    }

    $cached = &$cache[$entity_type_id];
    $labels = [];
    $to_load = [];

    foreach ($ids as $id) {
      if (!isset($cached[$id])) {
        $to_load[$id] = $id;
      }
      else {
        $labels[$id] = $cached[$id];
      }
    }

    if (!empty($to_load)) {
      $table = $this->getEntityTypeDataTable($entity_type_id);
      $id_field = $this->getEntityTypeIdField($entity_type_id);
      $label_field = $this->getEntityTypeLabelField($entity_type_id);

      $records = $this->getDatabase()
        ->select($table, $table)
        ->fields($table, [$id_field, $label_field])
        ->condition($table . '.' . $id_field, $to_load, 'IN')
        ->execute();

      foreach ($records as $record) {
        $cached[$record->{$id_field}] = $record->{$label_field};
        $labels[$record->{$id_field}] = $record->{$label_field};
      }
    }

    return $labels;
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

    // Base fields for which diffs can be computed.
    $allowed = [
      $this->getEntityTypeLabelField($revision->getEntityTypeId()) => TRUE,
      'description' => TRUE,
    ];

    $diff = [];
    foreach ($field_definitions as $field_name => $field_definition) {
      //dpm($field_definition->getName() . ' - ' . $field_definition->getType());

      $storage_definition = $field_definition->getFieldStorageDefinition();
      $is_revisionable = $storage_definition->isRevisionable();
      $is_base_field = $storage_definition->isBaseField();

      // We basically only handle fields added through the UI and the entity
      // label and eventual description field (terms).
      if (!$is_revisionable || ($is_base_field && !isset($allowed[$field_name]))) {
        continue;
      }

      $current = $revision->get($field_name)->getValue();
      if (isset($previous_revision)) {
        $previous = $previous_revision->get($field_name)->getValue();
      }
      else {
        $previous = [];
      }

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

    // Oldest first so we can compute the proper differences.
    return array_reverse($ids);
  }

  /**
   * Add the entity history to a form.
   *
   * @param array $form
   *   Entity form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public static function addHistoryToForm(array &$form, FormStateInterface $form_state) {
    $entity = $form_state?->getFormObject()?->getEntity();
    if (!empty($entity) && $entity instanceof EntityRevisionedInterface) {
      $history = \Drupal::service('reliefweb_revisions.entity.history')
        ->getEntityHistory($entity);

      if (!empty($history)) {
        $form['revision_history'] = [
          '#type' => 'container',
          '#group' => 'revision_information',
          '#weight' => 100,
          'history' => $history,
        ];
      }
    }
  }

}
