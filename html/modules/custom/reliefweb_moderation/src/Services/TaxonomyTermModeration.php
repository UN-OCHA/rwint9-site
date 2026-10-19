<?php

namespace Drupal\reliefweb_moderation\Services;

use Drupal\reliefweb_moderation\ModerationServiceBase;

/**
 * Shared moderation service for simple taxonomy term vocabularies.
 *
 * Used for vocabularies that only need draft/published statuses and do not
 * have a dedicated moderation service (e.g. career_category, theme).
 */
class TaxonomyTermModeration extends ModerationServiceBase {

  /**
   * The taxonomy vocabulary bundle.
   *
   * @var string
   */
  protected string $bundle;

  /**
   * The moderation page title.
   *
   * @var string
   */
  protected string $title;

  /**
   * Constructs a TaxonomyTermModeration service.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Pager\PagerManagerInterface $pager_manager
   *   The pager manager.
   * @param \Drupal\Core\Pager\PagerParametersInterface $pager_parameters
   *   The pager parameters.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface $user_posting_rights_manager
   *   The user posting rights manager.
   * @param string $bundle
   *   The taxonomy vocabulary machine name.
   * @param string $title
   *   The moderation page title.
   */
  public function __construct(
    $current_user,
    $database,
    $date_formatter,
    $entity_field_manager,
    $entity_type_manager,
    $pager_manager,
    $pager_parameters,
    $request_stack,
    $string_translation,
    $user_posting_rights_manager,
    string $bundle,
    string $title,
  ) {
    parent::__construct(
      $current_user,
      $database,
      $date_formatter,
      $entity_field_manager,
      $entity_type_manager,
      $pager_manager,
      $pager_parameters,
      $request_stack,
      $string_translation,
      $user_posting_rights_manager,
    );
    $this->bundle = $bundle;
    $this->title = $title;
  }

  /**
   * {@inheritdoc}
   */
  public function getBundle() {
    return $this->bundle;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeId() {
    return 'taxonomy_term';
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle() {
    return $this->title;
  }

  /**
   * {@inheritdoc}
   */
  public function getHeaders() {
    return [
      'edit' => [
        'label' => '',
      ],
      'data' => [
        'label' => $this->t('Name'),
        'type' => 'property',
        'specifier' => 'name',
        'sortable' => TRUE,
      ],
      'date' => [
        'label' => $this->t('Updated'),
        'type' => 'property',
        'specifier' => 'changed',
        'sortable' => TRUE,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRows(array $results) {
    if (empty($results['entities'])) {
      return [];
    }

    /** @var \Drupal\reliefweb_moderation\EntityModeratedInterface[] $entities */
    $entities = $results['entities'];

    // Prepare the table rows' data from the entities.
    $rows = [];
    foreach ($entities as $entity) {
      $cells = [];

      // Edit link + status cell.
      $cells['edit'] = $this->getEntityEditAndStatusData($entity);

      // Entity data cell.
      $data = [];

      // Title.
      $data['title'] = $entity->toLink()->toString();

      // Revision information.
      $data['revision'] = $this->getEntityRevisionData($entity);

      // Filter out empty data.
      $cells['data'] = array_filter($data);

      // Date cell.
      $cells['date'] = [
        'date' => $entity->getChangedTime(),
      ];

      $rows[] = $cells;
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  protected function initFilterDefinitions(array $filters = []) {
    return parent::initFilterDefinitions([
      'status',
      'name',
      'changed',
    ]);
  }

}
