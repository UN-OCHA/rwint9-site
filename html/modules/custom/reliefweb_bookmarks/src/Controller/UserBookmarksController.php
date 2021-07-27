<?php

namespace Drupal\reliefweb_bookmarks\Controller;

/**
 * @file
 * Contains \Drupal\reliefweb_bookmarks\Controller\UserBookmarksController.
 */


use Drupal\Core\Controller\ControllerBase;
use Drupal\user\UserInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Database\Connection;
use Drupal\reliefweb_rivers\RiverServiceBase;

/**
 * A UserBookmarksController controller.
 */
class UserBookmarksController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  public function __construct(AccountInterface $account, Connection $database) {
    $this->account = $account;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function content(UserInterface $user) {
    $query = $this->database->select('reliefweb_bookmarks', 'ew');
    $query->fields('ew');
    $query->condition('uid', $user->id());
    $query->condition('entity_type', 'node');
    $records = $query->execute()->fetchAllAssoc('entity_id');

    // Load all at once.
    $storage = $this->entityTypeManager()->getStorage('node');
    $nodes = $storage->loadMultiple(array_keys($records));

    $query = $this->database->select('reliefweb_bookmarks', 'ew');
    $query->fields('ew');
    $query->condition('uid', $user->id());
    $query->condition('entity_type', 'taxonomy_term');
    $records = $query->execute()->fetchAllAssoc('entity_id');

    // Load all at once.
    $storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $taxonomy_terms = $storage->loadMultiple(array_keys($records));

    $grouped = [
      'report' => [
        'resource' => 'reports',
        'title' => $this->t('Latest Headlines'),
        'view' => 'headlines',
        'more' => [
          'url' => RiverServiceBase::getRiverUrl('report', [
            'view' => 'headlines',
          ]),
          'label' => $this->t('View all headlines'),
        ],
        'data' => [],
      ],
      'disaster' => [
        'resource' => 'disasters',
        'title' => $this->t('Recent Disasters'),
        'more' => [
          'url' => RiverServiceBase::getRiverUrl('disaster'),
          'label' => $this->t('View all disasters'),
        ],
        'data' => [],
      ],
      'blog_post' => [
        'resource' => 'blog',
        'title' => $this->t('Latest Blog'),
        'more' => [
          'url' => RiverServiceBase::getRiverUrl('blog_post'),
          'label' => $this->t('View all blog posts'),
        ],
        'data' => [],
      ],
      'job' => [
        'resource' => 'jobs',
        'title' => $this->t('Open jobs'),
        'more' => [
          'url' => RiverServiceBase::getRiverUrl('job'),
          'label' => $this->t('View all jobs'),
        ],
        'data' => [],
      ],
      'training' => [
        'resource' => 'training',
        'title' => $this->t('Training programs'),
        'more' => [
          'url' => RiverServiceBase::getRiverUrl('training'),
          'label' => $this->t('View all training programs'),
        ],
        'data' => [],
      ],
    ];

    // Group by type.
    foreach ($nodes as $node) {
      $grouped[$node->getType()]['data'][] = $node;
    }

    // Add terms.
    $grouped['disaster']['data'] = $taxonomy_terms;

    // Build output.
    $sections = [];
    foreach ($grouped as $section_key => $group) {
      if (empty($group['data'])) {
        continue;
      }

      // Generate the section.
      switch ($section_key) {
        case 'report':
        case 'disaster':
        case 'blog_post':
          $sections[$section_key] = [
            '#theme' => 'reliefweb_rivers_river',
            '#id' => $section_key,
            '#title' => $group['title'],
            '#resource' => $group['resource'],
            '#entities' => $group['data'],
            '#more' => $group['more'] ?? NULL,
          ];
          break;

        case 'job':
        case 'training':
          if (!isset($sections['opportunities'])) {
            $sections['opportunities'] = [
              '#theme' => 'reliefweb_homepage_opportunities',
              '#id' => 'opportunities',
            ];
          }
          $sections['opportunities']['#opportunities'][] = [
            'type' => $section_key,
            'title' => $group['title'],
            'url' => $group['more']['url'],
            'total' => count($group['data']),
          ];
          break;
      }
    }

    return [
      '#theme' => 'reliefweb_homepage',
      '#title' => $this->t('ReliefWeb'),
      '#sections' => $sections,
    ];
  }

}
