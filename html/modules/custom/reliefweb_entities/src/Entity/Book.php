<?php

namespace Drupal\reliefweb_entities\Entity;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\reliefweb_entities\BundleEntityInterface;
use Drupal\reliefweb_entities\DocumentInterface;
use Drupal\reliefweb_entities\DocumentTrait;
use Drupal\reliefweb_entities\EntityModeratedInterface;
use Drupal\reliefweb_entities\EntityModeratedTrait;
use Drupal\node\Entity\Node;

/**
 * Bundle class for book nodes.
 */
class Book extends Node implements BundleEntityInterface, EntityModeratedInterface, DocumentInterface {

  use DocumentTrait;
  use EntityModeratedTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getApiResource() {
    return '';
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
    // This book's url which we assume is the active link when `getBookOutline`
    // is called.
    $url = 'entity:node/' . $this->id();

    $found = FALSE;
    foreach ($links as &$link) {
      if (isset($link['url']) && $link['url'] === $url) {
        $found = $link['active'] = TRUE;
      }
      elseif ($link['below']) {
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
