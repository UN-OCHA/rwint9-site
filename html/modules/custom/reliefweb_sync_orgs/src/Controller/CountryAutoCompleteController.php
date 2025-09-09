<?php

namespace Drupal\reliefweb_sync_orgs\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Database\Connection;

/**
 * Defines a route controller for watches autocomplete form elements.
 */
class CountryAutoCompleteController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * Handler for autocomplete request.
   */
  public function handleAutocomplete(Request $request) {
    $results = [];
    $input = $request->query->get('q');

    // Get the typed string from the URL, if it exists.
    if (!$input) {
      return new JsonResponse($results);
    }

    $input = Xss::filter($input);

    $query = $this->database->select('taxonomy_term_field_data', 't');
    $query->addJoin('LEFT', 'taxonomy_term__field_iso3', 's', 's.entity_id = t.tid');

    $group = $query->orConditionGroup();
    $group->condition('name', '%' . $query->escapeLike($input) . '%', 'LIKE');
    $group->condition('field_iso3_value', '%' . $query->escapeLike($input) . '%', 'LIKE');

    $terms = $query->fields('t', ['tid', 'name', 'status'])
      ->fields('s', ['field_iso3_value'])
      ->condition('vid', 'country')
      ->condition($group)
      ->orderBy('name')
      ->range(0, 30)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $data = [];
    foreach ($terms as $term) {
      $label = [
        $term['name'],
      ];

      if (isset($term['field_iso3_value'])) {
        $label[] = '[' . $term['field_iso3_value'] . ']';
      }

      $label[] = '<small>(' . $term['tid'] . ')</small>';
      $label[] = $term['status'] ? '✅' : '🚫';

      $data[] = [
        'value' => $term['name'] . ' (' . $term['tid'] . ')',
        'label' => implode(' ', $label),
      ];
    }

    return new JsonResponse($data);
  }

}
