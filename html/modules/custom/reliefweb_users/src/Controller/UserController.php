<?php

namespace Drupal\reliefweb_users\Controller;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Link;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
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
   * {@inheritdoc}
   */
  public function __construct(Connection $database, FormBuilderInterface $form_builder, DateFormatterInterface $date_formatter, PagerManagerInterface $pager_manager) {
    $this->database = $database;
    $this->formBuilder = $form_builder;
    $this->dateFormatter = $date_formatter;
    $this->pagerManager = $pager_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('form_builder'),
      $container->get('date.formatter'),
      $container->get('pager.manager')
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
      $query->innerJoin('taxonomy_term__field_user_posting_rights', 'fpr', '%alias.field_user_posting_rights_id = u.uid');
      if (isset($filters['job_rights'])) {
        $query->condition('fpr.field_user_posting_rights_job', $rights[$filters['job_rights']], '=');
      }
      if (isset($filters['training_rights'])) {
        $query->condition('fpr.field_user_posting_rights_training', $rights[$filters['training_rights']], '=');
      }
      if (isset($filters['report_rights'])) {
        $query->condition('fpr.field_user_posting_rights_report', $rights[$filters['report_rights']], '=');
      }
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
    $users = $query->execute()->fetchAllAssoc('uid', \PDO::FETCH_OBJ);

    // Prepare the table rows.
    $rows = [];
    if (!empty($users)) {
      $users_roles = $this->database->select('user__roles', 'ur')
        ->fields('ur', ['entity_id', 'roles_target_id'])
        ->condition('ur.entity_id', array_keys($users), 'IN')
        ->execute()
        ->fetchAll(\PDO::FETCH_COLUMN | \PDO::FETCH_GROUP);

      // Get the sources and posting rights.
      $this->getUserSources($users);

      foreach ($users as $user) {
        $user_roles = [];
        if (!empty($users_roles[$user->uid])) {
          $user_roles = array_intersect_key($roles, array_flip($users_roles[$user->uid]));
        }

        $rows[$user->uid] = [
          'uid' => $user->uid,
          'name' => Link::fromTextAndUrl($user->name, URL::fromUserInput('/user/' . $user->uid)),
          'mail' => Link::fromTextAndUrl($user->mail, URL::fromUserInput('/user/' . $user->uid)),
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
    // Get the list of sources with posting rights for the given user ids.
    $query = $this->database->select('taxonomy_term__field_user_posting_rights', 'f');
    $query->innerJoin('taxonomy_term_field_data', 'td', 'td.tid = f.entity_id');
    $query->leftJoin('taxonomy_term__field_shortname', 'fs', "fs.entity_id = f.entity_id");
    $query->addField('f', 'field_user_posting_rights_id', 'uid');
    $query->addField('f', 'field_user_posting_rights_job', 'job');
    $query->addField('f', 'field_user_posting_rights_training', 'training');
    $query->addField('f', 'field_user_posting_rights_report', 'report');
    $query->addField('f', 'entity_id', 'tid');
    $query->addExpression('COALESCE(fs.field_shortname_value, td.name)', 'name');
    $query->condition('f.field_user_posting_rights_id', array_keys($users), 'IN');
    $query->condition('f.bundle', 'source');

    $sources = [];
    foreach ($query->execute() as $record) {
      $job = $record->job;
      $training = $record->training;
      $report = $record->report;

      $link = Link::fromTextAndUrl($record->name, URL::fromUserInput('/taxonomy/term/' . $record->tid . '/user-posting-rights', [
        'attributes' => ['target' => '_blank'],
      ]));
      $row = '<li data-job="' . $job . '" data-training="' . $training . '" data-report="' . $report . '">' . $link->toString() . '</li>';
      $sources[$record->uid][$record->tid] = $row;
    }

    // Add the formatted list of sources to the user objects.
    foreach ($sources as $uid => $rows) {
      $users[$uid]->sources = '<ol>' . implode('', $rows) . '</ol>';
    }
  }

}
