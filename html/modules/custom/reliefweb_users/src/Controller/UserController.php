<?php

namespace Drupal\reliefweb_users\Controller;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Link;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * User posts controller.
 */
class UserController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * Date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The pager manager.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected $pagerManager;

  /**
   * The user posting rights manager service.
   *
   * @var \Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface
   */
  protected $userPostingRightsManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $database, FormBuilderInterface $form_builder, DateFormatterInterface $date_formatter, PagerManagerInterface $pager_manager, UserPostingRightsManagerInterface $user_posting_rights_manager) {
    $this->database = $database;
    $this->formBuilder = $form_builder;
    $this->dateFormatter = $date_formatter;
    $this->pagerManager = $pager_manager;
    $this->userPostingRightsManager = $user_posting_rights_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('form_builder'),
      $container->get('date.formatter'),
      $container->get('pager.manager'),
      $container->get('reliefweb_moderation.user_posting_rights')
    );
  }

  /**
   * Get the page content.
   *
   * @return array
   *   Render array.
   */
  public function getContent(Request $request) {
    // We want the editors to be able to bookmark a moderation page with
    // a selection of filters so we set the method as GET.
    $form_state = new FormState();
    $form_state->setMethod('GET');
    $form_state->setProgrammed(TRUE);
    $form_state->setProcessInput(TRUE);
    $form_state->disableCache();

    // Build the filters form.
    $form = $this->formBuilder
      ->buildForm('\Drupal\reliefweb_users\Form\UserPageFilterForm', $form_state);

    // Remove unneeded parts.
    unset($form['op']);
    unset($form['form_build_id']);
    unset($form['form_id']);

    $build = [
      '#theme' => 'reliefweb_users_page',
    ];

    // Filters.
    $build['#filters'] = $form;

    // List of users.
    $storage = $form_state->getStorage();
    $build['#list'] = $this->usersAdminList($storage, $request->query->all());

    return $build;
  }

  /**
   * Build the user table for the user admin page.
   */
  protected function usersAdminList($storage, $query_parameters) {
    $items_per_page = 30;
    $build = [];

    // Get filter values and options.
    $filters = $storage['filters'] ?? [];
    $roles = $storage['roles'];
    $statuses = $storage['statuses'];
    $rights = array_flip(array_keys($storage['rights']));

    // Table headers.
    $header = [
      'uid' => ['data' => $this->t('ID'), 'field' => 'u.uid', 'sort' => 'desc'],
      'name' => ['data' => $this->t('Name'), 'field' => 'u.name'],
      'mail' => ['data' => $this->t('Mail'), 'field' => 'u.mail'],
      'status' => ['data' => $this->t('Status'), 'field' => 'u.status'],
      'role' => $this->t('Roles'),
      'sources' => $this->t('Sources (Job, Training, Reports)'),
      'created' => ['data' => $this->t('Member for'), 'field' => 'u.created'],
      'access' => ['data' => $this->t('Last access'), 'field' => 'u.access'],
      'edit' => $this->t('Edit'),
    ];

    $fields = ['uid', 'name', 'mail', 'status', 'created', 'access'];

    // Get the list of users with pagination.
    $query = $this->database->select('users_field_data', 'u');
    $query->extend('Drupal\Core\Database\Query\PagerSelectExtender');

    // Header sorting.
    $query->extend('Drupal\Core\Database\Query\TableSortExtender')->orderByHeader($header);

    // Fields to retrieve.
    $query->fields('u', $fields);

    // Exclude admin and non-authenticated users.
    $query->condition('u.uid', 1, '>');

    // Filter the query.
    if (isset($filters['role'])) {
      $query->innerJoin('user__roles', 'ur', 'ur.entity_id = u.uid');
      $query->condition('ur.roles_target_id', array_keys($filters['role']), 'IN');
    }
    if (isset($filters['status'])) {
      $query->condition('u.status', $filters['status'] === 'active' ? 1 : 0, '=');
    }
    if (!empty($filters['name'])) {
      $query->condition('u.name', '%' . $this->database->escapeLike($filters['name']) . '%', 'LIKE');
    }
    if (!empty($filters['mail'])) {
      $query->condition('u.mail', '%' . $this->database->escapeLike($filters['mail']) . '%', 'LIKE');
    }

    // Content posted filter.
    if (!empty($filters['posted'])) {
      $query->innerJoin('node_field_data', 'n', '%alias.uid = u.uid');
      $query->condition('n.type', array_keys($filters['posted']), 'IN');
    }

    // Posting rights filter.
    if (isset($filters['job_rights']) || isset($filters['training_rights']) || isset($filters['report_rights'])) {
      // Create a subquery to find users with the requested posting rights
      // from either user posting rights or domain posting rights.
      $subquery = $this->database->select('users_field_data', 'u2');
      $subquery->addField('u2', 'uid');
      $subquery->condition('u2.uid', 1, '>');

      // Join user posting rights.
      $user_rights_alias = $subquery->leftJoin('taxonomy_term__field_user_posting_rights', 'fpr', '%alias.field_user_posting_rights_id = u2.uid');

      // Join domain posting rights.
      $domain_rights_alias = $subquery->leftJoin('taxonomy_term__field_domain_posting_rights', 'fdr', '%alias.field_domain_posting_rights_domain = SUBSTRING_INDEX(u2.mail, \'@\', -1)');

      // Create conditions for each right type.
      $conditions = [];
      if (isset($filters['job_rights'])) {
        $right_value = $rights[$filters['job_rights']];
        $conditions[] = $subquery->conditionGroupFactory('OR')
          ->condition($user_rights_alias . '.field_user_posting_rights_job', $right_value, '=')
          ->condition($domain_rights_alias . '.field_domain_posting_rights_job', $right_value, '=');
      }
      if (isset($filters['training_rights'])) {
        $right_value = $rights[$filters['training_rights']];
        $conditions[] = $subquery->conditionGroupFactory('OR')
          ->condition($user_rights_alias . '.field_user_posting_rights_training', $right_value, '=')
          ->condition($domain_rights_alias . '.field_domain_posting_rights_training', $right_value, '=');
      }
      if (isset($filters['report_rights'])) {
        $right_value = $rights[$filters['report_rights']];
        $conditions[] = $subquery->conditionGroupFactory('OR')
          ->condition($user_rights_alias . '.field_user_posting_rights_report', $right_value, '=')
          ->condition($domain_rights_alias . '.field_domain_posting_rights_report', $right_value, '=');
      }

      // Add all conditions with AND logic.
      if (!empty($conditions)) {
        $condition_group = $subquery->conditionGroupFactory('AND');
        foreach ($conditions as $condition) {
          $condition_group->condition($condition);
        }
        $subquery->condition($condition_group);
      }

      // Filter main query to only include users found in subquery.
      $query->condition('u.uid', $subquery, 'IN');
    }

    // Set group by.
    $group_by = &$query->getGroupBy();
    $group_by = array_map(function ($field) {
      return 'u.' . $field;
    }, $fields);

    // Get the number of users for the query.
    $count_query = $query->countQuery();

    // Get the number of users for the query.
    $count = $count_query->execute()->fetchField();

    // Get the users.
    $currentPage = $this->pagerManager->createPager($count, $items_per_page)->getCurrentPage();
    $query->range($currentPage * $items_per_page, $items_per_page);
    $users = $query->execute()->fetchAllAssoc('uid', FetchAs::Object);

    // Prepare the table rows.
    $rows = [];
    if (!empty($users)) {
      $results = $this->database->select('user__roles', 'ur')
        ->fields('ur', ['entity_id', 'roles_target_id'])
        ->condition('ur.entity_id', array_keys($users), 'IN')
        ->execute()
        ?->fetchAll() ?? [];

      $users_roles = [];
      foreach ($results as $row) {
        $users_roles[$row->entity_id][] = $row->roles_target_id;
      }

      // Get the sources and posting rights.
      $this->getUserSources($users);

      foreach ($users as $user) {
        $user_roles = [];
        if (!empty($users_roles[$user->uid])) {
          $user_roles = array_intersect_key($roles, array_flip($users_roles[$user->uid]));
        }

        $rows[$user->uid] = [
          'uid' => $user->uid,
          'name' => !empty($user->name) ? Link::fromTextAndUrl($user->name, URL::fromUserInput('/user/' . $user->uid)) : 'Unknown',
          'mail' => !empty($user->mail) ? Link::fromTextAndUrl($user->mail, URL::fromUserInput('/user/' . $user->uid)) : 'Unknown',
          'status' => $statuses[empty($user->status) ? 'blocked' : 'active'],
          'role' => !empty($user_roles) ? new FormattableMarkup('<ol><li>' . implode('</li><li>', $user_roles) . '</li></ol>', []) : '',
          'sources' => isset($user->sources) ? new FormattableMarkup($user->sources, []) : '',
          'created' => $this->dateFormatter->formatInterval(time() - $user->created),
          'access' => !empty($user->access) ? $this->t('@time ago', [
            '@time' => $this->dateFormatter->formatInterval(time() - $user->access),
          ]) : $this->t('never'),
          'edit' => Link::fromTextAndUrl($this->t('edit'), URL::fromUserInput('/user/' . $user->uid . '/edit')),
        ];
      }
    }

    // List of users.
    $build['table'] = [
      '#theme' => 'table',
      '#header' => $header,
      '#caption' => $this->t('@count users found.', ['@count' => number_format($count)]),
      '#rows' => $rows,
      '#empty' => $this->t('No users found.'),
      '#attributes' => [
        'class' => ['users-list'],
      ],
    ];

    // Pager for the list of users.
    $build['pager'] = [
      '#theme' => 'pager',
      '#element' => 0,
      '#parameters' => $query_parameters,
      '#route_name' => 'entity.user.collection',
      '#tags' => [],
      '#quantity' => 5,
    ];

    return $build;
  }

  /**
   * Get the sources and posting rights records for the given users.
   *
   * @param array $users
   *   List of users to be displayed in the users list. This is an associative
   *   array keyed by the user ids and with user objects as values. The sources
   *   will be attached to the objects as html lists.
   */
  public function getUserSources(array &$users) {
    $sources = [];

    // Get user posting rights for all users.
    $user_query = $this->database->select('taxonomy_term__field_user_posting_rights', 'f');
    $user_query->innerJoin('taxonomy_term_field_data', 'td', 'td.tid = f.entity_id');
    $user_query->leftJoin('taxonomy_term__field_shortname', 'fs', "fs.entity_id = f.entity_id");
    $user_query->addField('f', 'field_user_posting_rights_id', 'uid');
    $user_query->addField('f', 'field_user_posting_rights_job', 'job');
    $user_query->addField('f', 'field_user_posting_rights_training', 'training');
    $user_query->addField('f', 'field_user_posting_rights_report', 'report');
    $user_query->addField('f', 'entity_id', 'tid');
    $user_query->addExpression('COALESCE(fs.field_shortname_value, td.name)', 'name');
    $user_query->addExpression('\'user\'', 'rights_type');
    $user_query->condition('f.field_user_posting_rights_id', array_keys($users), 'IN');
    $user_query->condition('f.bundle', 'source');

    foreach ($user_query->execute() as $record) {
      $sources[$record->uid][$record->tid] = $this->formatSourceRow($record, 'user');
    }

    // Get domain posting rights for all users.
    // First, collect all users' email domains.
    $user_domains = [];
    foreach ($users as $uid => $user) {
      if (!empty($user->mail)) {
        $domain = $this->userPostingRightsManager->extractDomainFromEmail($user->mail) ?? '';
        if ($domain) {
          $user_domains[$domain][] = $uid;
        }
      }
    }

    if (!empty($user_domains)) {
      $domain_query = $this->database->select('taxonomy_term__field_domain_posting_rights', 'f');
      $domain_query->innerJoin('taxonomy_term_field_data', 'td', 'td.tid = f.entity_id');
      $domain_query->leftJoin('taxonomy_term__field_shortname', 'fs', "fs.entity_id = f.entity_id");
      $domain_query->addField('f', 'field_domain_posting_rights_domain', 'domain');
      $domain_query->addField('f', 'field_domain_posting_rights_job', 'job');
      $domain_query->addField('f', 'field_domain_posting_rights_training', 'training');
      $domain_query->addField('f', 'field_domain_posting_rights_report', 'report');
      $domain_query->addField('f', 'entity_id', 'tid');
      $domain_query->addExpression('COALESCE(fs.field_shortname_value, td.name)', 'name');
      $domain_query->addExpression('\'domain\'', 'rights_type');
      $domain_query->condition('f.field_domain_posting_rights_domain', array_keys($user_domains), 'IN');
      $domain_query->condition('f.bundle', 'source');

      foreach ($domain_query->execute() as $record) {
        $domain = strtolower($record->domain);
        if (isset($user_domains[$domain])) {
          foreach ($user_domains[$domain] as $uid) {
            // Only add domain rights if user doesn't already have user rights
            // for this source.
            if (!isset($sources[$uid][$record->tid])) {
              $sources[$uid][$record->tid] = $this->formatSourceRow($record, 'domain');
            }
          }
        }
      }
    }

    // Add the formatted list of sources to the user objects.
    foreach ($sources as $uid => $rows) {
      $users[$uid]->sources = '<ol>' . implode('', $rows) . '</ol>';
    }
  }

  /**
   * Format a source row for display.
   *
   * @param object $record
   *   The database record.
   * @param string $rights_type
   *   Either 'user' or 'domain'.
   *
   * @return string
   *   Formatted HTML row.
   */
  protected function formatSourceRow($record, $rights_type) {
    $job = $record->job;
    $training = $record->training;
    $report = $record->report;

    $link = Link::fromTextAndUrl($record->name, URL::fromUserInput('/taxonomy/term/' . $record->tid . '/user-posting-rights', [
      'attributes' => ['target' => '_blank'],
    ]));

    $rights_type_class = $rights_type === 'domain' ? ' posting-rights--domain' : '';

    $row = [
      '<li data-job="' . $job . '" data-training="' . $training . '" data-report="' . $report . '" data-rights-type="' . $rights_type . '">',
      $link->toString(),
      '<span class="posting-rights--wrapper">',
      '<span data-posting-right="' . $job . '" class="posting-rights posting-rights--job' . $rights_type_class . '" title="' . $this->getRightsLabel('job', $job) . '">' . $this->getRightsLabel('job', $job) . '</span>',
      '<span data-posting-right="' . $training . '" class="posting-rights posting-rights--training' . $rights_type_class . '" title="' . $this->getRightsLabel('training', $training) . '">' . $this->getRightsLabel('training', $training) . '</span>',
      '<span data-posting-right="' . $report . '" class="posting-rights posting-rights--report' . $rights_type_class . '" title="' . $this->getRightsLabel('report', $report) . '">' . $this->getRightsLabel('report', $report) . '</span>',
      '</span>',
      '</li>',
    ];

    return implode(' ', $row);
  }

  /**
   * Get human readable rights.
   */
  protected function getRightsLabel(string $type, string $right) {
    $types = [
      'job' => $this->t('Job'),
      'training' => $this->t('Training'),
      'report' => $this->t('Report'),
    ];
    $rights = [
      0 => $this->t('Unverified'),
      1 => $this->t('Blocked'),
      2 => $this->t('Allowed'),
      3 => $this->t('Trusted'),
    ];

    $label = [
      $types[$type],
      $rights[$right],
    ];

    return implode(' - ', $label);
  }

}
