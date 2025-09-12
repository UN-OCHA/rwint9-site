<?php

declare(strict_types=1);

namespace Drupal\reliefweb_sync_orgs\Service;

use Fuse\Fuse;

/**
 * Service to do fuzzy search.
 */
class FuzzySearchService {

  /**
   * Default options.
   */
  protected const DEFAULT_OPTIONS = [
    'keys' => ['name'],
    'isCaseSensitive' => FALSE,
    'ignoreDiacritics' => TRUE,
    'ignoreLocation' => TRUE,
    'threshold' => 0.1,
    'includeScore' => TRUE,
    'minMatchCharLength' => 3,
  ];

  /**
   * Fuse search instance.
   *
   * @var \Fuse\Fuse
   */
  protected $fuse;

  /**
   * Constructs a new FuzzySearchService instance.
   */
  public function __construct(array $collection, array $options = []) {
    if (empty($options)) {
      $options = self::DEFAULT_OPTIONS;
    }

    $this->fuse = new Fuse($collection, $options);
  }

  /**
   * Perform a search.
   */
  public function search(string $search): array {
    // Remove everything between brackets from search.
    $search = preg_replace('/\s*\(.*?\)\s*/', '', $search);

    $matches = $this->fuse->search($search);
    if (empty($matches)) {
      return [];
    }

    $match = reset($matches);
    $tid = $match['item']['tid'];
    $name = $match['item']['name'];
    $score = $match['score'];

    if ($score < 0.00001) {
      return [
        'status' => 'exact',
        'tid' => $tid,
        'name' => $name,
        'score' => $score,
      ];
    }
    elseif ($score < 0.3) {
      return [
        'status' => 'partial',
        'tid' => $tid,
        'name' => $name,
        'score' => $score,
      ];
    }
    elseif ($score < 0.6) {
      return [
        'status' => 'mismatch',
        'tid' => $tid,
        'name' => $name,
        'score' => $score,
      ];
    }
    else {
      return [
        'status' => 'skipped',
        'tid' => $tid,
        'name' => $name,
        'score' => $score,
      ];
    }

  }

}
