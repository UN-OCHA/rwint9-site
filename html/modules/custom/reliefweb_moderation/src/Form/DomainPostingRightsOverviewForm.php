<?php

declare(strict_types=1);

namespace Drupal\reliefweb_moderation\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Pager\PagerParametersInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface;
use Drupal\reliefweb_moderation\Controller\SourceAutocompleteController;
use Drupal\reliefweb_utility\Helpers\DomainHelper;
use Drupal\reliefweb_utility\Helpers\LocalizationHelper;
use Drupal\reliefweb_utility\Traits\EntityDatabaseInfoTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for managing domain posting rights.
 */
class DomainPostingRightsOverviewForm extends FormBase {

  use EntityDatabaseInfoTrait;

  /**
   * Number of domains to display per page.
   */
  protected int $domainsPerPage = 50;

  /**
   * Constructs a DomainPostingRightsOverviewForm object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Pager\PagerManagerInterface $pagerManager
   *   The pager manager service.
   * @param \Drupal\Core\Pager\PagerParametersInterface $pagerParameters
   *   The pager parameters service.
   * @param \Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface $userPostingRightsManager
   *   The user posting rights manager service.
   */
  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected PagerManagerInterface $pagerManager,
    protected PagerParametersInterface $pagerParameters,
    protected UserPostingRightsManagerInterface $userPostingRightsManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('pager.manager'),
      $container->get('pager.parameters'),
      $container->get('reliefweb_moderation.user_posting_rights')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reliefweb_moderation_domain_posting_rights_overview_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Get filter values from query parameters or form state.
    $domain_filter = $this->getRequest()->query->get('domain', '');
    $source_filter = $this->getRequest()->query->get('source', '');

    // Normalize domain filter.
    if (!empty($domain_filter)) {
      $domain_filter = DomainHelper::normalizeDomain($domain_filter);
    }

    // Build filters section.
    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filters'),
      '#weight' => 0,
      '#attributes' => [
        'class' => ['rw-domain-posting-rights-filters'],
      ],
      '#tree' => TRUE,
      '#open' => !empty($domain_filter) || !empty($source_filter),
    ];

    $form['filters']['domain'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Domain'),
      '#description' => $this->t('Filter by domain (e.g., example.com).'),
      '#default_value' => $domain_filter,
      '#required' => FALSE,
      '#attributes' => [
        'placeholder' => $this->t('Enter domain (e.g., unicef.org)'),
      ],
    ];

    $form['filters']['source'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Source'),
      '#description' => $this->t('Filter by source organization.'),
      '#default_value' => $source_filter,
      '#required' => FALSE,
      '#autocomplete_route_name' => 'reliefweb_moderation.source_autocomplete',
      '#autocomplete_route_parameters' => [],
      '#attributes' => [
        'placeholder' => $this->t('Start typing to search for a source'),
      ],
    ];

    $form['filters']['actions'] = [
      '#type' => 'actions',
      '#attributes' => [
        'class' => ['rw-domain-filters-actions'],
      ],
    ];

    $form['filters']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filter'),
      '#name' => 'filter',
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
      '#limit_validation_errors' => [['filters']],
    ];

    $form['filters']['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Reset'),
      '#url' => Url::fromRoute('reliefweb_moderation.domain_posting_rights.overview'),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    // Destination URL for edit links.
    $destination = $this->getDestination($domain_filter, $source_filter);

    // Query and build the table.
    $domains = $this->getDomainPostingRightsData($domain_filter, $source_filter);
    $form['pagination_summary'] = $this->buildPaginationSummary($domains);
    $form['table'] = $this->buildTable($domains, $destination);
    $form['pager'] = ['#type' => 'pager'];

    $form['#attached']['library'][] = 'common_design_subtheme/rw-user-posting-right';
    $form['#attached']['library'][] = 'common_design_subtheme/rw-domain-posting-rights';
    $form['#attributes']['class'][] = 'rw-domain-posting-rights-form';

    return $form;
  }

  /**
   * Get domain posting rights data grouped by domain.
   *
   * @param string $domain_filter
   *   Domain filter value.
   * @param string $source_filter
   *   Source filter value (autocomplete format).
   *
   * @return array
   *   Array of domain posting rights data grouped by domain.
   */
  protected function getDomainPostingRightsData(string $domain_filter, string $source_filter): array {
    // Get domain posting rights table and field names.
    $table = $this->getFieldTableName('taxonomy_term', 'field_domain_posting_rights');
    $domain_field = $this->getFieldColumnName('taxonomy_term', 'field_domain_posting_rights', 'domain');
    $job_field = $this->getFieldColumnName('taxonomy_term', 'field_domain_posting_rights', 'job');
    $training_field = $this->getFieldColumnName('taxonomy_term', 'field_domain_posting_rights', 'training');
    $report_field = $this->getFieldColumnName('taxonomy_term', 'field_domain_posting_rights', 'report');

    // First use a paginated query on the domains to get the domains to display
    // in the table.
    $domains_query = $this->database->select($table, $table);
    $domains_query->addField($table, $domain_field, 'domain');
    $domains_query->condition($table . '.bundle', 'source', '=');
    $domains_query->orderBy($table . '.' . $domain_field, 'ASC');
    $domains_query->distinct();

    $domains_query = $domains_query->extend(PagerSelectExtender::class);
    $domains_query->limit($this->getDomainsPerPage());

    // Apply domain filter.
    if (!empty($domain_filter)) {
      $domains_query->condition($table . '.' . $domain_field, $domain_filter, '=');
    }

    // Apply source filter.
    if (!empty($source_filter)) {
      $source_id = SourceAutocompleteController::extractSourceIdFromInput($source_filter);
      if ($source_id) {
        $domains_query->condition($table . '.entity_id', $source_id, '=');
      }
    }

    $domains = $domains_query->execute()?->fetchCol() ?? [];
    if (empty($domains)) {
      return [];
    }

    // Query all domain posting rights.
    $rights_query = $this->database->select($table, $table);
    $rights_query->addField($table, 'entity_id', 'source_id');
    $rights_query->addField($table, $domain_field, 'domain');
    $rights_query->addField($table, $job_field, 'job');
    $rights_query->addField($table, $training_field, 'training');
    $rights_query->addField($table, $report_field, 'report');
    $rights_query->condition($table . '.bundle', 'source', '=');
    $rights_query->condition($table . '.' . $domain_field, $domains, 'IN');
    $rights_query->orderBy($table . '.' . $domain_field, 'ASC');

    $results = $rights_query->execute()?->fetchAll(FetchAs::Associative) ?? [];

    // Group by domain.
    $grouped = [];
    foreach ($results as $row) {
      $domain = DomainHelper::normalizeDomain($row['domain']);
      if (empty($domain)) {
        continue;
      }

      if (!isset($grouped[$domain])) {
        $grouped[$domain] = [];
      }

      $grouped[$domain][] = [
        'source_id' => (int) $row['source_id'],
        'domain' => $domain,
        'job' => (int) ($row['job'] ?? 0),
        'training' => (int) ($row['training'] ?? 0),
        'report' => (int) ($row['report'] ?? 0),
      ];
    }

    return $grouped;
  }

  /**
   * Build summary of the pagination (domain range displayed).
   *
   * @param array $domains
   *   Array of domain posting rights data grouped by domain.
   *
   * @return array
   *   Summary render array.
   */
  protected function buildPaginationSummary(array $domains): array {
    if (empty($domains)) {
      return [];
    }

    $pager = $this->pagerManager->getPager();
    if (!isset($pager)) {
      return [];
    }

    $current_page = $pager->getCurrentPage();
    $items_per_page = $pager->getLimit();
    $total_items = $pager->getTotalItems();

    $start = ($current_page * $items_per_page) + 1;
    $end = min(($current_page + 1) * $items_per_page, $total_items);

    return [
      '#type' => 'inline_template',
      '#template' => <<<TEMPLATE
        <div class="rw-domain-posting-rights-pagination-summary">
        {% trans %}
        Showing <span>{{ start }}</span> - <span>{{ end }}</span> of <span>{{ total }}</span> domains
        {% endtrans %}
        </div>
        TEMPLATE,
      '#context' => [
        'start' => $start,
        'end' => $end,
        'total' => $total_items,
      ],
    ];
  }

  /**
   * Build the table with grouped domain posting rights.
   *
   * @param array $domains
   *   Array of domain posting rights data grouped by domain.
   * @param string $destination
   *   Destination URL for edit links.
   *
   * @return array
   *   Table render array.
   */
  protected function buildTable(array $domains, string $destination): array {
    if (empty($domains)) {
      return [
        '#markup' => '<div class="rw-domain-no-results"><p>' . $this->t('No domain posting rights found.') . '</p></div>',
      ];
    }

    // Gather all source IDs.
    $all_source_ids = [];
    foreach ($domains as $domain_data) {
      foreach ($domain_data as $row) {
        $all_source_ids[] = $row['source_id'];
      }
    }
    $all_source_ids = array_unique($all_source_ids);

    // Load all sources at once.
    $sources = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadMultiple($all_source_ids);
    if (empty($sources)) {
      return [
        '#markup' => '<div class="rw-domain-no-results"><p>' . $this->t('No sources found.') . '</p></div>',
      ];
    }

    // Build table header.
    $header = [
      $this->t('Domain'),
      [
        'data' => $this->t('Source'),
        'colspan' => 2,
      ],
      $this->t('Report'),
      $this->t('Job'),
      $this->t('Training'),
      $this->t('Edit'),
    ];

    // Get the collator for sorting.
    $collator = LocalizationHelper::getCollator();

    // Build table rows with group property for grouping.
    $structured_rows = [];
    foreach ($domains as $domain => $domain_data) {
      $row_count = count($domain_data);
      $row_index = 0;

      // Get the rows for the domain that match a source and sort them by source
      // label alphabetically.
      $domain_rows = [];
      foreach ($domain_data as $row) {
        if (isset($sources[$row['source_id']])) {
          $domain_rows[] = $row + ['source' => $sources[$row['source_id']]];
        }
      }
      uasort($domain_rows, function (array $a, array $b) use ($collator) {
        return $collator->compare($a['source']->label(), $b['source']->label());
      });

      // Build the rows for the domain.
      foreach ($domain_rows as $row) {
        $source = $row['source'];

        // Get source name and shortname separately.
        $source_name = $source->label();
        $shortname = '';
        if ($source->hasField('field_shortname') && !$source->field_shortname->isEmpty()) {
          $shortname = $source->field_shortname->value;
        }

        // Create source name link.
        $source_link = [
          '#type' => 'link',
          '#title' => $source_name,
          '#url' => $source->toUrl(),
          '#attributes' => [
            'target' => '_blank',
          ],
        ];

        // Build edit link (only for first source in domain group).
        $edit_link = NULL;
        if ($row_index === 0) {
          $edit_link = [
            '#type' => 'link',
            '#title' => $this->t('Edit'),
            '#url' => Url::fromRoute(
              'reliefweb_moderation.domain_posting_rights.edit',
              ['domain' => $domain],
              ['query' => ['destination' => $destination]]
            ),
            '#attributes' => [
              'class' => ['button', 'button--small'],
            ],
          ];
        }

        // Build row cells.
        $row_cells = [];

        // Domain cell (with rowspan for first row of each domain group).
        if ($row_index === 0) {
          $row_cells[] = [
            'data' => $domain,
            'rowspan' => $row_count,
            'class' => ['rw-domain-cell'],
          ];
        }

        // Source name cell.
        $row_cells[] = ['data' => $source_link];

        // Source shortname cell.
        $row_cells[] = ['data' => $shortname ?: '-'];

        // Posting rights cells.
        $row_cells[] = ['data' => $this->formatPostingRights($row['report'])];
        $row_cells[] = ['data' => $this->formatPostingRights($row['job'])];
        $row_cells[] = ['data' => $this->formatPostingRights($row['training'])];

        // Edit link cell (with rowspan for first row of each domain group).
        if ($row_index === 0) {
          $row_cells[] = [
            'data' => $edit_link,
            'rowspan' => $row_count,
            'class' => ['rw-edit-cell'],
          ];
        }

        // Add row with group property.
        $structured_rows[] = [
          'data' => $row_cells,
          'group' => $domain,
          'no_striping' => TRUE,
        ];

        $row_index++;
      }
    }

    return [
      '#theme' => 'table__grouped',
      '#header' => $header,
      '#rows' => $structured_rows,
      '#attributes' => [
        'class' => ['rw-domain-posting-rights-table'],
      ],
      '#pager' => [
        '#type' => 'pager',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $triggering_element = $form_state->getTriggeringElement();

    // Skip validation for filter button.
    if (isset($triggering_element['#name']) && $triggering_element['#name'] === 'filter') {
      return;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $triggering_element = $form_state->getTriggeringElement();

    // Handle filter button.
    if (isset($triggering_element['#name']) && $triggering_element['#name'] === 'filter') {
      $domain = $form_state->getValue(['filters', 'domain'], '');
      $source = $form_state->getValue(['filters', 'source'], '');

      // Normalize domain.
      if (!empty($domain)) {
        $domain = DomainHelper::normalizeDomain($domain);
      }

      // Build query parameters.
      $query = [];
      if (!empty($domain)) {
        $query['domain'] = $domain;
      }
      if (!empty($source)) {
        $query['source'] = $source;
      }

      // Redirect with filters.
      $form_state->setRedirect('reliefweb_moderation.domain_posting_rights.overview', [], [
        'query' => $query,
      ]);
      return;
    }
  }

  /**
   * Formats the posting rights value for display.
   *
   * @param int $right_code
   *   The numeric value of the posting right.
   *
   * @return array
   *   A render array for the formatted posting right.
   */
  protected function formatPostingRights(int $right_code): array {
    $right = match ($right_code) {
      0 => 'unverified',
      1 => 'blocked',
      2 => 'allowed',
      3 => 'trusted',
      default => 'unknown',
    };

    $label = match ($right_code) {
      0 => $this->t('Unverified'),
      1 => $this->t('Blocked'),
      2 => $this->t('Allowed'),
      3 => $this->t('Trusted'),
      default => $this->t('Unknown'),
    };

    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $label,
      '#attributes' => [
        'class' => [
          'rw-user-posting-right',
          'rw-user-posting-right--large',
        ],
        'data-user-posting-right' => $right,
      ],
    ];
  }

  /**
   * Get destination URL for edit links.
   *
   * @param string $domain_filter
   *   Domain filter value.
   * @param string $source_filter
   *   Source filter value (autocomplete format).
   *
   * @return string
   *   Destination URL for edit links.
   */
  protected function getDestination(string $domain_filter, string $source_filter): string {
    $query = [];
    if (!empty($domain_filter)) {
      $query['domain'] = $domain_filter;
    }
    if (!empty($source_filter)) {
      $query['source'] = $source_filter;
    }
    return Url::fromRoute('reliefweb_moderation.domain_posting_rights.overview', [], ['query' => $query])->toString();
  }

  /**
   * Get number of domains to display per page.
   *
   * @return int
   *   The number of domains to display per page.
   */
  public function getDomainsPerPage(): int {
    return $this->domainsPerPage;
  }

  /**
   * Set number of domains to display per page.
   *
   * @param int $count
   *   The number of domains to display per page.
   */
  public function setDomainsPerPage(int $count): void {
    $this->domainsPerPage = $count;
  }

}
