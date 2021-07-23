<?php

namespace Drupal\reliefweb_bookmarks\Controller;

/**
 * @file
 * Contains \Drupal\reliefweb_bookmarks\Controller\UserBookmarksController.
 */


use Drupal\Core\Controller\ControllerBase;
use Drupal\user\UserInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Database\Connection;

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
    $pager = $query->extend('Drupal\Core\Database\Query\PagerSelectExtender')->limit(10);
    $records = $pager->execute()->fetchAll();

    $rows = [];
    $tags = [
      'user:' . $user->id(),
      'reliefweb_bookmarks:user:' . $user->id(),
    ];

    foreach ($records as $key => $data) {
      $storage = $this->entityTypeManager()->getStorage($data->entity_type);
      $node = $storage->load($data->entity_id);
      $node_title = $node->label();
      $path = $node->toUrl()->toString();
      $url = Url::fromUri('internal:' . $path);
      $link = Link::fromTextAndUrl($node_title, $url);
      $bookmark_link = [
        '#theme' => 'links',
        '#links' => [
          reliefweb_bookmarks_build_link($data->entity_type, $data->entity_id, $user->id()),
        ],
        '#attached' => [
          'library' => [
            'core/drupal.ajax',
          ],
        ],
      ];
      $rows[] = [
        'data' => [
          'name' => $key + 1,
          'content' => $link,
          'link' => render($bookmark_link),
        ],
      ];
      $tags[] = 'reliefweb_bookmarks:entity_id:' . $node->id();
    }

    $header = [
      ['name' => $this->t('No.')],
      ['content' => $this->t('Title')],
      ['link' => $this->t('Bookmark')],
    ];

    $build['config_table'] = [
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No bookmarks found.'),
      '#cache' => [
        'contexts' => [
          'user',
        ],
        'tags' => $tags,
      ],
    ];

    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

}
