<?php

namespace Drupal\reliefweb_bookmarks\Controller;

/**
 * @file
 * Toggles bookmark.
 */

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * A Bookmarks toggle controller.
 */
class BookmarksToggleController extends ControllerBase {
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
   * Request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * Destination.
   *
   * @var \Drupal\Core\Routing\RedirectDestinationInterface
   */
  protected $destination;

  /**
   * {@inheritdoc}
   */
  public function __construct(AccountInterface $account, Connection $database, Request $request, RedirectDestinationInterface $destination) {
    $this->account = $account;
    $this->database = $database;
    $this->request = $request;
    $this->destination = $destination;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('database'),
      $container->get('request_stack')->getCurrentRequest(),
      $container->get('redirect.destination'),
    );
  }

  /**
   * Add or remove node from bookmarks.
   */
  public function addNode(NodeInterface $node) {
    $check_entry = reliefweb_bookmarks_toggle_bookmark('node', $node->id(), $this->account->id());

    if ($check_entry) {
      $message = $this->t('Item removed from your bookmarks.');
    }
    else {
      $message = $this->t('Item added to your bookmarks.');
    }

    Cache::invalidateTags([
      'reliefweb_bookmarks:user:' . $this->account->id(),
      'reliefweb_bookmarks:node:' . $node->id(),
    ]);

    if ($this->request->isXmlHttpRequest()) {
      $link_data = reliefweb_bookmarks_build_link('node', $node->id(), $this->account->id());

      $url = $link_data['url'];
      $link_options = [
        'attributes' => $link_data['attributes'],
      ];
      $url->setOptions($link_options);
      $link = Link::fromTextAndUrl($link_data['title'], $url);

      $response = new AjaxResponse();
      $response->addCommand(new MessageCommand($message));
      $response->addCommand(new ReplaceCommand('.bookmark--link', $link->toString()));

      return $response;
    }
    else {
      $this->messenger()->addStatus($message);
      return new RedirectResponse($this->destination->get());
    }
  }

  /**
   * Add or remove term from bookmarks.
   */
  public function addTerm(TermInterface $taxonomy_term) {
    $check_entry = reliefweb_bookmarks_toggle_bookmark('taxonomy_term', $taxonomy_term->id(), $this->account->id());

    if ($check_entry) {
      $message = $this->t('Item removed from your bookmarks.');
    }
    else {
      $message = $this->t('Item added to your bookmarks.');
    }

    Cache::invalidateTags([
      'reliefweb_bookmarks:user:' . $this->account->id(),
      'reliefweb_bookmarks:taxonomy_term:' . $taxonomy_term->id(),
    ]);

    if ($this->request->isXmlHttpRequest()) {
      $link_data = reliefweb_bookmarks_build_link('taxonomy_term', $taxonomy_term->id(), $this->account->id());

      $url = $link_data['url'];
      $link_options = [
        'attributes' => $link_data['attributes'],
      ];
      $url->setOptions($link_options);
      $link = Link::fromTextAndUrl($link_data['title'], $url);

      $response = new AjaxResponse();
      $response->addCommand(new MessageCommand($message));
      $response->addCommand(new ReplaceCommand('.bookmark--link', $link->toString()));

      return $response;
    }
    else {
      $this->messenger()->addStatus($message);
      return new RedirectResponse($this->destination->get());
    }
  }

}
