<?php

namespace Drupal\reliefweb_bookmarks\Controller;

/**
 * @file
 * Contains \Drupal\reliefweb_bookmarks\Controller\EntityBookmarksReadLaterController.
 */

use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\AnnounceCommand;

/**
 * A EntityBookmarksReadLater controller.
 */
class EntityBookmarksReadLaterController extends ControllerBase {
  protected $account;
  protected $connection;

  /**
   * {@inheritdoc}
   */
  public function __construct(AccountInterface $account, Connection $connection) {
    $this->account = $account;
    $this->database = $connection;
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
  public function content(NodeInterface $node) {
    $uid = $this->account->id();
    $nid = $node->id();
    $db = $this->database;
    $query = $db->select('reliefweb_bookmarks', 'ew');
    $query->fields('ew');
    $query->condition('entity_id', $nid);
    $query->condition('uid', $uid);
    $check_entry = $query->execute()->FetchField();
    if ($check_entry) {
      $query = $this->database->delete('reliefweb_bookmarks');
      $query->condition('entity_id', $nid);
      $query->condition('uid', $uid);
      $query->execute();
      $message = $this->t('Item removed from your bookmarks.');
    }
    else {
      $this->database->insert('reliefweb_bookmarks')
        ->fields(
          [
            'entity_type' => 'node',
            'entity_id' => $nid,
            'uid' => $uid,
          ]
      )->execute();
      $message = $this->t('Item added to your bookmarks.');
    }

    $request = \Drupal::request();
    if ($request->isXmlHttpRequest()) {
      $check_entry = !$check_entry;

      $request = \Drupal::destination();
      $path = '/node/' . $nid . '/add-to-bookmarks';
      $url = Url::fromUri('internal:' . $path);

      $link_options = [
        'attributes' => [
          'class' => [
            'use-ajax',
            'bookmark--link',
            $check_entry ? 'bookmark--link--remove' : 'bookmark--link--add',
          ],
        ],
      ];
      $url->setOptions($link_options);

      if ($check_entry) {
        $link = Link::fromTextAndUrl($this->t('Remove from bookmarks'), $url);
      }
      else {
        $link = Link::fromTextAndUrl($this->t('Add to bookmarks'), $url);
      }

      $response = new AjaxResponse();
      $response->addCommand(new AnnounceCommand($message, 'assertive'));
      $response->addCommand(new ReplaceCommand('.bookmark--link', $link->toString()));

      return $response;
    }
    else {
      $this->messenger()->addStatus($message);
      return new RedirectResponse(\Drupal::destination()->get());
    }
  }

}
