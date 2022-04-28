<?php

namespace Drupal\reliefweb_revisions\Services;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\media\MediaInterface;
use Drupal\reliefweb_moderation\Helpers\UserPostingRightsHelper;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_utility\Helpers\DateHelper;
use Drupal\reliefweb_utility\Helpers\MediaHelper;
use Drupal\reliefweb_utility\Helpers\TextHelper;
use Drupal\reliefweb_utility\Helpers\UrlHelper;
use Drupal\reliefweb_utility\Traits\EntityDatabaseInfoTrait;
use Drupal\user\EntityOwnerInterface;

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
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The translation manager service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    AccountProxyInterface $current_user,
    Connection $database,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeManagerInterface $entity_type_manager,
    TranslationInterface $string_translation
  ) {
    $this->config = $config_factory->get('reliefweb_revisions.settings');
    $this->currentUser = $current_user;
    $this->database = $database;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->stringTranslation = $string_translation;
  }

  /**
   * Get the revision history of an entity.
   *
   * Note: the revision history content is loaded asynchronously.
   *
   * @param \Drupal\reliefweb_revisions\EntityRevisionedInterface $entity
   *   Entity.
   *
   * @return array
   *   Render array with the revision history.
   */
  public function getEntityHistory(EntityRevisionedInterface $entity) {
    // Skip if the user doesn't have permission to view the history or its an
    // entity being created.
    if (!$this->currentUser->hasPermission('view entity history') || $entity->id() === NULL || !empty($entity->in_preview)) {
      return [];
    }

    // History render array.
    return [
      '#theme' => 'reliefweb_revisions_history',
      '#id' => Html::getUniqueId('rw-revisions-history-' . $entity->id()),
      '#entity' => $entity,
      '#url' => Url::fromRoute('reliefweb_revisions.entity.history', [
        'entity_type_id' => $entity->getEntityTypeId(),
        'entity' => $entity->id(),
      ])->toString(),
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => $entity->getCacheTags(),
      ],
    ];
  }

  /**
   * Get an entity's revision history's content.
   *
   * @param \Drupal\reliefweb_revisions\EntityRevisionedInterface $entity
   *   Entity.
   *
   * @return array
   *   Render array with the revision history content.
   */
  public function getEntityHistoryContent(EntityRevisionedInterface $entity) {
    // Skip if the user doesn't have permission to view the history or its an
    // entity being created.
    if (!$this->currentUser->hasPermission('view entity history') || $entity->id() === NULL || !empty($entity->in_preview)) {
      return [];
    }

    $entity_type_id = $entity->getEntityTypeId();
    $revision_user_field = $this->getEntityTypeRevisionUserField($entity_type_id);
    $revision_created_field = $this->getEntityTypeRevisionCreatedField($entity_type_id);

    // Get the list of revisions for the entity.
    $revision_ids = $this->getRevisionIds($entity);
    $total_revision_ids = count($revision_ids);

    // Retrieve the author of the entity.
    $author = $this->getEntityAuthor($entity);

    // Keep track of the previous revision.
    $previous_revision = NULL;

    // Extract the first revision.
    $first_revision = array_pop($revision_ids);

    // Limit the number of revisions to the most recent and add back the first
    // revision.
    $revision_ids = array_slice($revision_ids, 0, $this->config->get('limit') ?? 10);
    $revision_ids[] = $first_revision;

    // Oldest first so we can compute the proper differences.
    $revision_ids = array_reverse($revision_ids);

    // Compute the history.
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
      '#theme' => 'reliefweb_revisions_history_content',
      // Show the most recent history first.
      '#history' => array_reverse($history),
      // Number of ignored revisions.
      '#ignored' => $total_revision_ids - count($revision_ids),
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => $entity->getCacheTags(),
      ],
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
    return $this->getEntityTypeManager()
      ->getStorage('user')
      ->load($uid ?: 2);
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
   * @return array|null
   *   The render array for the difference or NULL if there is no difference.
   */
  protected function formatFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    if (!empty($diff['re-ordered'])) {
      return ['#theme' => 'reliefweb_revisions_diff_reordered'];
    }

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

      case 'boolean':
        return $this->formatBooleanFieldDiff($field_definition, $diff);

      case 'integer':
        return $this->formatFieldDiffDefault($field_definition, $diff);

      case 'geofield':
        return $this->formatFieldDiffDefault($field_definition, $diff, [], [
          'lat',
          'lon',
        ]);

      case 'link':
        return $this->formatLinkFieldDiff($field_definition, $diff);

      case 'reliefweb_links':
        return $this->formatReliefWebLinksFieldDiff($field_definition, $diff);

      case 'reliefweb_section_links':
        return $this->formatReliefWebSectionLinksFieldDiff($field_definition, $diff);

      case 'reliefweb_user_posting_rights':
        return $this->formatReliefWebUserPostingRightsFieldDiff($field_definition, $diff);

      case 'reliefweb_file':
        return $this->formatReliefWebFileFieldDiff($field_definition, $diff);

      default:
        return $this->formatFieldDiffDefault($field_definition, $diff);
    }
    return NULL;
  }

  /**
   * Format text field differences.
   *
   * Note: there are no text fields on ReliefWeb which accept multiple values
   * so we only deal with the first value.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   * @param array $diff
   *   Field value differences.
   *
   * @return array|null
   *   The render array for the difference or NULL if there is no difference.
   */
  protected function formatTextFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    if (empty($diff['added']) && empty($diff['removed'])) {
      return NULL;
    }

    $from_text = $diff['removed'][0]['value'] ?? '';
    $to_text = $diff['added'][0]['value'] ?? '';

    // Get the differences between the 2 texts.
    $diff_text = TextHelper::getTextDiff($from_text, $to_text);

    return empty($diff_text) ? NULL : [
      '#theme' => 'reliefweb_revisions_diff_text',
      '#text' => Markup::create($diff_text),
    ];
  }

  /**
   * Format entity reference field differences.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   * @param array $diff
   *   Field value differences.
   *
   * @return array|null
   *   The render array for the difference or NULL if there is no difference.
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

      $output = [];
      foreach ($added as $id) {
        if (isset($labels[$id])) {
          $output['#added'][] = $labels[$id];
        }
      }
      foreach ($removed as $id) {
        if (isset($labels[$id])) {
          $output['#removed'][] = $labels[$id];
        }
      }

      return empty($output) ? NULL : [
        '#theme' => 'reliefweb_revisions_diff_list',
      ] + $output;
    }
    // For media, we load the entities to be able to show the thumbnail.
    else {
      $media_entities = $this->getEntityTypeManager()
        ->getStorage($entity_type_id)
        ->loadMultiple($added + $removed);

      $output = [];
      foreach ($media_entities as $id => $media_entity) {
        // Link to the edit form because media don't have a page.
        $item = [
          'link' => $media_entity->toLink(NULL, 'edit-form')->toString(),
          'thumbnail' => $this->getMediaThumbnail($media_entity),
        ];
        if (isset($added[$id])) {
          $output['#added'][] = $item;
        }
        elseif (isset($removed[$id])) {
          $output['#removed'][] = $item;
        }
      }

      return empty($output) ? NULL : [
        '#theme' => 'reliefweb_revisions_diff_media',
      ] + $output;
    }
  }

  /**
   * Get a media thumbnail rendered markup.
   *
   * @param \Drupal\media\MediaInterface $media
   *   Media.
   *
   * @return array|null
   *   Thumbnail build array or NULL if no image could be found.
   */
  protected function getMediaThumbnail(MediaInterface $media) {
    $image = MediaHelper::getImageFromMediaEntity($media);
    if (!empty($image)) {
      return [
        '#theme' => 'image_style',
        '#style_name' => 'thumbnail',
        '#uri' => $image['uri'],
        '#width' => $image['width'],
        '#height' => $image['height'],
      ];
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
   * @return array|null
   *   The render array for the difference or NULL if there is no difference.
   */
  protected function formatListFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    if (empty($diff['added']) && empty($diff['removed'])) {
      return NULL;
    }

    $allowed_values = $field_definition->getSetting('allowed_values') ?? [];

    $output = [];
    foreach (['added', 'removed'] as $key) {
      foreach ($diff[$key] ?? [] as $value) {
        if (isset($allowed_values[$value['value']])) {
          $output['#' . $key][] = $allowed_values[$value['value']];
        }
      }
    }

    return empty($output) ? NULL : [
      '#theme' => 'reliefweb_revisions_diff_list',
    ] + $output;
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
   * @return array|null
   *   The render array for the difference or NULL if there is no difference.
   */
  protected function formatBooleanFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    $output = [];
    foreach (['added', 'removed'] as $key) {
      if (isset($diff[$key][0]['value'])) {
        // TRUE if checked, FALSE if unchecked.
        $output['#' . $key] = !empty($diff[$key][0]['value']);
      }
    }

    return empty($output) ? NULL : [
      '#theme' => 'reliefweb_revisions_diff_boolean',
    ] + $output;
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
   * @return array|null
   *   The render array for the difference or NULL if there is no difference.
   */
  protected function formatDatetimeFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    if (empty($diff['added']) && empty($diff['removed'])) {
      return NULL;
    }

    $output = [];
    foreach (['added', 'removed'] as $key) {
      if (!empty($diff[$key][0]['value'])) {
        $output['#' . $key][] = DateHelper::format($diff[$key][0]['value'], 'custom', 'd M Y H:i:s e', 'UTC');
      }
    }

    return empty($output) ? NULL : [
      '#theme' => 'reliefweb_revisions_diff_list',
    ] + $output;
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
   * @return array|null
   *   The render array for the difference or NULL if there is no difference.
   */
  protected function formatDaterangeFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    if (empty($diff['added']) && empty($diff['removed'])) {
      return NULL;
    }

    $fields = ['value' => 'start', 'end_value' => 'end'];

    $output = [];
    foreach ($fields as $field => $name) {
      foreach (['added', 'removed'] as $key) {
        if (!empty($diff[$key][0][$field])) {
          $output['#dates'][$name][$key] = DateHelper::format($diff[$key][0][$field], 'custom', 'd M Y H:i:s e', 'UTC');
        }
      }
    }

    return empty($output) ? NULL : [
      '#theme' => 'reliefweb_revisions_diff_daterange',
    ] + $output;
  }

  /**
   * Format link field differences.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   * @param array $diff
   *   Field value differences.
   *
   * @return array|null
   *   The render array for the difference or NULL if there is no difference.
   */
  protected function formatLinkFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    if (empty($diff['added']) && empty($diff['removed'])) {
      return NULL;
    }

    $title_setting = $field_definition->getSetting('title');
    if (!empty($title_setting)) {
      return $this->formatFieldDiffDefault($field_definition, $diff);
    }

    // Whether the field accepts mulitple values or not.
    $multiple = $field_definition->getFieldStorageDefinition()->isMultiple();

    $output = $this->formatArrayDiff($diff, 'uri');
    if (!empty($output) && $multiple) {
      $output['#theme'] = 'reliefweb_revisions_diff_nested';
    }
    return $output;
  }

  /**
   * Format link field differences.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   * @param array $diff
   *   Field value differences.
   *
   * @return array|null
   *   The render array for the difference or NULL if there is no difference.
   */
  protected function formatReliefWebLinksFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    // Keep track of any active content re-ordering.
    $current_active = [];
    $previous_active = [];

    // Previous revision links.
    $previous = [];
    foreach ($diff['previous'] as $item) {
      $previous[$item['url']] = $item;
      if ($item['active'] == 1) {
        $previous_active[] = $item['url'];
      }
    }

    // Current revision links.
    $current = [];
    foreach ($diff['current'] as $item) {
      $current[$item['url']] = $item;
      if ($item['active'] == 1) {
        $current_active[] = $item['url'];
      }
    }

    $categories = [
      // Filter the links to only show the added active links as there is not
      // much interest in showing the list of archived links in the revisions
      // and it can be really heavy for some entities.
      'added' => array_filter(array_diff_key($current, $previous), function ($item) {
        return $item['active'] == 1;
      }),
      'removed' => array_diff_key($previous, $current),
      'modified-title' => [],
      'modified-image' => [],
      'archived' => [],
      'unarchived' => [],
    ];

    $labels = [
      'added' => $this->t('Added'),
      'removed' => $this->t('Removed'),
      'modified-title' => $this->t('Modified Title'),
      'modified-image' => $this->t('Modified Image'),
      'archived' => $this->t('Archived'),
      'unarchived' => $this->t('Unarchived'),
    ];

    // Check if something changed for the links that are
    // in both current and previous revisions.
    foreach (array_intersect_key($current, $previous) as $key => $item) {
      $previous_item = $previous[$key];
      $current_item = $current[$key];
      if ($previous_item['active'] == 0 && $current_item['active'] == 1) {
        $categories['unarchived'][] = $item;
      }
      elseif ($previous_item['active'] == 1 && $current_item['active'] == 0) {
        $categories['archived'][] = $item;
      }
      elseif ($previous_item['title'] !== $current_item['title']) {
        $item['title'] = $previous_item['title'] . ' => ' . $current_item['title'];
        $categories['modified-title'][] = $item;
      }
      elseif ($previous_item['image'] !== $current_item['image']) {
        $categories['modified-image'][] = $previous_item;
      }
    }

    // Keep track of the number of changes to hide them if too many.
    $change_count = 0;

    // URL options.
    $url_options = [
      'attributes' => [
        'target' => '_blank',
      ],
    ];

    // Format the links.
    $output = [];
    foreach ($categories as $category => $items) {
      if (!empty($items)) {
        $changes = [];
        foreach ($items as $item) {
          $title = !empty($item['title']) ? $item['title'] : $item['url'];
          if (mb_strpos($item['url'], '/') === 0) {
            $url = Url::fromUserInput($item['url'], $url_options);
          }
          else {
            $url = Url::fromUri($item['url'], $url_options);
          }
          $changes[] = Link::fromTextAndUrl($title, $url);
          $change_count++;
        }

        $output['#categories'][$category] = [
          'label' => $labels[$category],
          'changes' => $changes,
        ];
      }
    }

    // If no other changes, check if the active links have been reordered.
    if (empty($output)) {
      if ($current_active !== $previous_active) {
        return [
          '#theme' => 'reliefweb_revisions_diff_reordered',
          '#message' => $this->t('Active links reordered'),
        ];
      }
      return NULL;
    }

    return [
      '#theme' => 'reliefweb_revisions_diff_categories',
      '#change_count' => $change_count,
    ] + $output;
  }

  /**
   * Format link field differences.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   * @param array $diff
   *   Field value differences.
   *
   * @return array|null
   *   The render array for the difference or NULL if there is no difference.
   */
  protected function formatReliefWebSectionLinksFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    if (empty($diff['added']) && empty($diff['removed'])) {
      return NULL;
    }

    $use_title = $field_definition->getSetting('use_title');
    $use_override = $field_definition->getSetting('use_override');

    $exclude = [];
    if (empty($use_title)) {
      $exclude[] = 'title';
    }
    if (empty($use_override)) {
      $exclude[] = 'override';
    }

    if (!empty($use_title) || !empty($use_override)) {
      return $this->formatFieldDiffDefault($field_definition, $diff, $exclude);
    }

    // Whether the field accepts mulitple values or not.
    $multiple = $field_definition->getFieldStorageDefinition()->isMultiple();

    $output = $this->formatArrayDiff($diff, 'url');
    if (!empty($output) && $multiple) {
      $output['#theme'] = 'reliefweb_revisions_diff_nested';
    }
    return $output;
  }

  /**
   * Format user posting rights field differences.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   * @param array $diff
   *   Field value differences.
   *
   * @return array|null
   *   The render array for the difference or NULL if there is no difference.
   */
  protected function formatReliefWebUserPostingRightsFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    if (empty($diff['previous']) && empty($diff['current'])) {
      return NULL;
    }

    // Previous revision user info.
    $previous = [];
    foreach ($diff['previous'] as $item) {
      $previous[$item['id']] = $item;
    }

    // Current revision user info.
    $current = [];
    foreach ($diff['current'] as $item) {
      $current[$item['id']] = $item;
    }

    $categories = [
      'added' => array_diff_key($current, $previous),
      'removed' => array_diff_key($previous, $current),
      'modified-training' => [],
      'modified-job' => [],
      'modified-notes' => [],
    ];

    $labels = [
      'added' => $this->t('Added'),
      'removed' => $this->t('Removed'),
      'modified-training' => $this->t('Modified Training'),
      'modified-job' => $this->t('Modified Job'),
      'modified-notes' => $this->t('Modified Notes'),
    ];

    $rights = [
      0 => 'unverified',
      1 => 'blocked',
      2 => 'allowed',
      3 => 'trusted',
    ];

    // Check if something changed for the users that are in both current and
    // previous revisions.
    foreach (array_intersect_key($current, $previous) as $key => $item) {
      $previous_item = $previous[$key];
      $current_item = $current[$key];
      // Rights change.
      foreach (['job', 'training'] as $type) {
        if ($previous_item[$type] !== $current_item[$type]) {
          $item['change'] = new FormattableMarkup('@before &rarr; @after', [
            '@before' => UserPostingRightsHelper::renderRight($rights[$previous_item[$type]]),
            '@after' => UserPostingRightsHelper::renderRight($rights[$current_item[$type]]),
          ]);
          $categories['modified-' . $type][] = $item;
        }
      }
      // Notes change.
      if ($previous_item['notes'] !== $current_item['notes']) {
        $text_diff = TextHelper::getTextDiff($previous_item['notes'], $current_item['notes']);
        $item['change'] = Markup::create($text_diff);
        $categories['modified-notes'][] = $item;
      }
    }

    // Keep track of the number of changes to hide them if too many.
    $change_count = 0;

    // URL attributes.
    $url_options = [
      'attributes' => [
        'target' => '_blank',
      ],
    ];

    // Prepare the changes.
    $output = [];
    foreach ($categories as $category => $items) {
      if (!empty($items)) {
        $changes = [];

        foreach ($items as $item) {
          $replacements = [];
          $markup = [];

          // Label. Link to the user.
          $markup[] = 'User: @link';
          $url = Url::fromUserInput('/user/' . $item['id'], $url_options);
          $replacements['@link'] = Link::fromTextAndUrl($item['id'], $url)->toString();

          // Add the rights when a user is added.
          if ($category === 'added') {
            $markup[] = '(job: @job, training: @training)';
            $replacements['@job'] = UserPostingRightsHelper::renderRight($rights[$item['job']]);
            $replacements['@training'] = UserPostingRightsHelper::renderRight($rights[$item['training']]);
          }

          // Add the rights changes.
          if (isset($item['change'])) {
            $markup[] = '(@change)';
            $replacements['@change'] = $item['change'];
          }

          $changes[] = new FormattableMarkup(implode(' ', $markup), $replacements);
          $change_count++;
        }

        $output['#categories'][$category] = [
          'label' => $labels[$category],
          'changes' => $changes,
        ];
      }
    }

    return empty($output) ? NULL : [
      '#theme' => 'reliefweb_revisions_diff_categories',
      '#change_count' => $change_count,
    ] + $output;
  }

  /**
   * Format reliefweb file field differences.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   * @param array $diff
   *   Field value differences.
   *
   * @return array|null
   *   The render array for the difference or NULL if there is no difference.
   */
  protected function formatReliefWebFileFieldDiff(FieldDefinitionInterface $field_definition, array $diff) {
    if (empty($diff['added']) && empty($diff['removed'])) {
      return NULL;
    }

    // Keep track of the file UUIDs so we can load their file names and URIs.
    $file_uuids = [];

    // Previous files.
    $previous = [];
    foreach ($diff['previous'] as $item) {
      $previous[$item['uuid']] = $item;
      if (!empty($item['file_uuid'])) {
        $file_uuids[$item['file_uuid']] = $item['file_uuid'];
      }
    }

    // Current files.
    $current = [];
    foreach ($diff['current'] as $item) {
      $current[$item['uuid']] = $item;
      if (!empty($item['file_uuid'])) {
        $file_uuids[$item['file_uuid']] = $item['file_uuid'];
      }
    }

    // Revision categories.
    $categories = [
      'added' => array_diff_key($current, $previous),
      'removed' => array_diff_key($previous, $current),
      'modified' => [],
      'replaced' => [],
    ];

    $labels = [
      'added' => $this->t('Added'),
      'removed' => $this->t('Removed'),
      'modified' => $this->t('Modified'),
      'replaced' => $this->t('Replaced'),
    ];

    // Load the file information.
    if (!empty($file_uuids)) {
      $files = $this->getDatabase()->select('file_managed', 'fm')
        ->fields('fm', ['uuid', 'uri', 'filename', 'filesize'])
        ->condition('uuid', $file_uuids, 'IN')
        ->execute()
        ?->fetchAllAssoc('uuid', \PDO::FETCH_ASSOC) ?? [];
    }

    // Properties to display in the revisions.
    $properties = [
      'description' => 'description',
      'language' => 'version',
      'preview_page' => 'preview page',
      'preview_rotation' => 'preview rotation',
    ];
    $property_values = [
      'language' => reliefweb_files_get_languages(),
      'preview_rotation' => ['90' => 'right', '-90' => 'left'],
    ];

    // Check if the file description changed.
    foreach (array_intersect_key($current, $previous) as $key => $item) {
      $previous_item = $previous[$key];
      $current_item = $current[$key];
      $modified = FALSE;

      // Replaced file.
      if ($previous_item['file_uuid'] !== $current_item['file_uuid']) {
        $item['replaced'] = $previous_item;
        $categories['replaced'][$item['uuid']] = $item;
      }
      // Modified properties.
      else {
        $details = [];
        foreach ($properties as $property => $property_label) {
          if ($current_item[$property] !== $previous_item[$property]) {
            if (isset($property_values[$property][$previous_item[$property]])) {
              $previous_item[$property] = $property_values[$property][$previous_item[$property]];
            }
            if (isset($property_values[$property][$current_item[$property]])) {
              $current_item[$property] = $property_values[$property][$current_item[$property]];
            }

            $details[$property] = new FormattableMarkup('@label: @before => @after', [
              '@label' => $property_label,
              '@before' => $previous_item[$property] ?: Markup::create('<em>none</em>'),
              '@after' => $current_item[$property] ?: Markup::create('<em>none</em>'),
            ]);
            $modified = TRUE;
          }
        }

        if ($modified) {
          $item['details'] = $details;
          $categories['modified'][$item['uuid']] = $item;
        }
      }
    }

    $change_count = 0;
    $output = [];
    foreach ($categories as $category => $items) {
      $changes = [];
      foreach ($items as $item) {
        if (isset($files[$item['file_uuid']])) {
          $file = $files[$item['file_uuid']];
          $label = new FormattableMarkup('@file_name (@file_size)', [
            '@file_name' => $file['filename'],
            '@file_size' => format_size($file['filesize']),
          ]);

          $uri = UrlHelper::getAbsoluteFileUri($file['uri']);
          if (!empty($uri)) {
            $label = Link::fromTextAndUrl($label, Url::fromUri($uri))->toString();
          }
        }
        else {
          $label = Markup::create($item['file_name']);
        }

        if ($category !== 'removed') {
          // For new or replaced files, extract the properties.
          if ($category !== 'modified') {
            foreach ($properties as $property => $property_label) {
              $item['details'][$property] = new FormattableMarkup('@label: @change', [
                '@label' => $property_label,
                '@change' => $item[$property] ?: Markup::create('<em>none</em>'),
              ]);
            }
          }

          // Add the property details.
          if (!empty($item['details'])) {
            $label = new FormattableMarkup('@label (@details)', [
              '@label' => $label,
              '@details' => Markup::create(implode(', ', $item['details'])),
            ]);
          }

          // Add a link to the previous file for replaced files.
          if ($category === 'replaced' && isset($item['replaced'])) {
            $original_item = $item['replaced'];

            if (isset($files[$original_item['file_uuid']])) {
              $original_file = $files[$original_item['file_uuid']];
              $original_label = new FormattableMarkup('@file_name (@file_size)', [
                '@file_name' => $original_file['filename'],
                '@file_size' => format_size($original_file['filesize']),
              ]);

              $original_uri = UrlHelper::getAbsoluteFileUri($original_file['uri']);
              if (!empty($original_uri)) {
                $original_label = Link::fromTextAndUrl($original_label, Url::fromUri($original_uri))->toString();
              }
            }
            else {
              $original_label = Markup::create($original_item['file_name']);
            }

            $label = new FormattableMarkup('@current &mdash; replaced: @previous', [
              '@previous' => $original_label,
              '@current' => $label,
            ]);
          }
        }
        $changes[] = $label;
        $change_count++;
      }

      if (!empty($changes)) {
        $output['#categories'][$category] = [
          'label' => $labels[$category],
          'changes' => $changes,
        ];
      }
    }

    // If no other changes, check if the active links have been reordered.
    if (empty($output)) {
      if (!empty($diff['re-ordered'])) {
        return [
          '#theme' => 'reliefweb_revisions_diff_reordered',
          '#message' => $this->t('Files re-ordered'),
        ];
      }
      return NULL;
    }

    return [
      '#theme' => 'reliefweb_revisions_diff_categories',
      '#change_count' => $change_count,
    ] + $output;
  }

  /**
   * Default field differences formatting function.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Field definition.
   * @param array $diff
   *   Field value differences.
   * @param array $exclude
   *   Properties to exclude.
   * @param array $include
   *   Properties to include.
   *
   * @return array|null
   *   The render array for the difference or NULL if there is no difference.
   */
  protected function formatFieldDiffDefault(FieldDefinitionInterface $field_definition, array $diff, array $exclude = [], array $include = []) {
    if (empty($diff['added']) && empty($diff['removed'])) {
      return NULL;
    }

    $storage_definition = $field_definition->getFieldStorageDefinition();
    $main_property = $storage_definition->getMainPropertyName();
    $properties = $storage_definition->getPropertyDefinitions();

    // Limit the properties to consider.
    if (!empty($exclude)) {
      $properties = array_diff_key($properties, array_flip($exclude));
    }
    if (!empty($include)) {
      $properties = array_intersect_key($properties, array_flip($include));
    }

    // If there is a single property (or none, if the field value is a direct
    // value), then use a simple array diff formatting.
    if (count($properties) <= 1) {
      return $this->formatArrayDiff($diff, $main_property);
    }

    // If the field only accepts one value, we only show the difference of the
    // changed properties.
    if (!$storage_definition->isMultiple()) {
      $added = $diff['added'][0] ?? [];
      $removed = $diff['removed'][0] ?? [];
      $diff['added'] = [array_diff($added, $removed)];
      $diff['removed'] = [array_diff_assoc($removed, $added)];
    }

    $output = [];
    foreach (['added', 'removed'] as $key) {
      foreach ($diff[$key] as $item) {
        $values = [];
        foreach ($properties as $property => $definition) {
          if ($definition->isReadOnly() || !isset($item[$property]) || !is_scalar($item[$property])) {
            continue;
          }
          $values[] = [
            'label' => $definition->getLabel(),
            'value' => $item[$property],
          ];
        }
        if (!empty($values)) {
          $output['#' . $key][] = $values;
        }
      }
    }

    return empty($output) ? NULL : [
      '#theme' => 'reliefweb_revisions_diff_nested',
    ] + $output;
  }

  /**
   * Format the differences for an array of values.
   *
   * @param array $diff
   *   Associative array containing the added and removed values.
   * @param string|null $property
   *   If defined, it's the main property of the values that will be used
   *   to format the differences.
   *
   * @return array|null
   *   The render array for the difference or NULL if there is no difference.
   */
  protected function formatArrayDiff(array $diff, $property = NULL) {
    $direct = empty($property);

    $output = [];
    foreach (['added', 'removed'] as $key) {
      foreach ($diff[$key] as $item) {
        $value = NULL;
        if ($direct) {
          $value = $item;
        }
        elseif (is_array($item) && isset($item[$property])) {
          $value = $item[$property];
        }

        if (!is_null($value)) {
          $output['#' . $key][] = $value;
        }
      }
    }

    return empty($output) ? NULL : [
      '#theme' => 'reliefweb_revisions_diff_list',
    ] + $output;
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
      'parent' => TRUE,
    ];

    $diff = [];
    foreach ($field_definitions as $field_name => $field_definition) {
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
          'current' => $current,
          'previous' => $previous,
          'added' => is_array($added) ? $added : [],
          'removed' => is_array($removed) ? $removed : [],
          're-ordered' => $added === TRUE && $removed === TRUE,
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
   * @return array|bool
   *   Values from the first array not present in the second array. If there
   *   is no difference, return whether the second array has a different order
   *   than the first one.
   */
  protected function getArrayDiff(array $array1, array $array2) {
    if (empty($array2)) {
      return $array1;
    }

    $reordered = FALSE;
    $diff = [];
    foreach ($array1 as $key1 => $value1) {
      $key2 = array_search($value1, $array2);
      if ($key2 === FALSE) {
        $diff[] = $value1;
      }
      elseif ($key2 !== $key1) {
        $reordered = TRUE;
      }
    }
    return !empty($diff) ? $diff : $reordered;
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

    return array_keys($results);
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
      $history = $entity->getHistory();

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
