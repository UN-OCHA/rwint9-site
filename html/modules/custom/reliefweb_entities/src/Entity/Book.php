<?php

namespace Drupal\reliefweb_entities\Entity;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\reliefweb_entities\BundleEntityInterface;
use Drupal\reliefweb_entities\DocumentInterface;
use Drupal\reliefweb_entities\DocumentTrait;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\EntityModeratedTrait;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_revisions\EntityRevisionedTrait;

/**
 * Bundle class for book nodes.
 */
class Book extends Node implements BundleEntityInterface, EntityModeratedInterface, EntityRevisionedInterface, DocumentInterface {

  use DocumentTrait;
  use EntityModeratedTrait;
  use EntityRevisionedTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getApiResource() {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public static function addFieldConstraints(&$fields) {
    // No specific constraints.
  }

  /**
   * Get the book outline.
   *
   * @return array
   *   Render array with the bookoutline.
   */
  public function getBookOutline() {
    $book_manager = \Drupal::service('book.manager');
    $books = $book_manager->getAllBooks();

    // Sort the books by weight to respect the hierarchy.
    uasort($books, function ($a, $b) {
      return $a['weight'] <=> $b['weight'];
    });

    // Extract the book menu links from the books.
    $links = [];
    foreach ($books as $book) {
      $data = $book_manager->bookTreeAllData($book['bid'], $book);
      $menu = $book_manager->bookTreeOutput($data);
      $links += $menu['#items'];
    }

    if (empty($links)) {
      return [];
    }

    // Mark the active link if any.
    $this->markBookMenuActiveLink($links);

    $build = [
      '#theme' => 'reliefweb_entities_book_menu',
      '#title' => $this->t('More about ReliefWeb'),
      '#links' => $links,
      '#cache' => [
        // @todo maybe we need some extra cache info that we could extract
        // from the trees above.
        '#tags' => ['node_list:book'],
      ],
    ];

    return $build;
  }

  /**
   * Mark the active link in a book menu.
   *
   * @param array $links
   *   Menu links.
   *
   * @return bool
   *   TRUE of the active link was found and marked.
   */
  protected function markBookMenuActiveLink(array &$links) {
    // We assume the current book is the active one when `getBookOutline` is
    // called so we'll check the menu links for a link matching this book.
    $id = $this->id();

    $found = FALSE;
    foreach ($links as &$link) {
      $url = $link['url'] ?? NULL;

      if ($url instanceof Url && $url->isRouted() && $url->getRouteName() === 'entity.node.canonical') {
        $route_parameters = $url->getRouteParameters();
        if (isset($route_parameters['node']) && $route_parameters['node'] == $id) {
          $link['active'] = TRUE;
          return TRUE;
        }
      }

      // Check the menu children.
      if (!empty($link['below'])) {
        $found = $this->markBookMenuActiveLink($link['below']);
      }

      // No need to proceed further if we found the active link.
      if ($found) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
