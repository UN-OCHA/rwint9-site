<?php

namespace Drupal\reliefweb_moderation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for source autocomplete functionality.
 */
class SourceAutocompleteController extends ControllerBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * Autocomplete callback for source taxonomy terms.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the autocomplete suggestions.
   */
  public function autocomplete(Request $request): JsonResponse {
    $string = $request->query->get('q', '');

    if (strlen($string) < 2) {
      return new JsonResponse([]);
    }

    $suggestions = [];

    try {
      // Query taxonomy terms in the 'source' vocabulary.
      $query = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->getQuery()
        ->condition('vid', 'source')
        ->condition('status', 1)
        ->accessCheck(TRUE);

      // Create an OR condition group for name, shortname, and ID.
      $or_group = $query->orConditionGroup();

      // Search by name (label).
      $or_group->condition('name', $string, 'CONTAINS');

      // Search by shortname field if it exists.
      $or_group->condition('field_shortname', $string, 'CONTAINS');

      // Search by ID if the string is numeric.
      if (is_numeric($string)) {
        $or_group->condition('tid', $string);
      }

      $query->condition($or_group);
      $query->range(0, 20);
      $query->sort('name');

      $tids = $query->execute();

      if (!empty($tids)) {
        $terms = $this->entityTypeManager
          ->getStorage('taxonomy_term')
          ->loadMultiple($tids);

        foreach ($terms as $term) {
          // Build the display text with shortname if available.
          $label = $term->label();
          $shortname = '';

          if ($term->hasField('field_shortname') && !$term->field_shortname->isEmpty()) {
            $shortname = $term->field_shortname->value;
            if (!empty($shortname) && $shortname !== $label) {
              $label .= ' (' . $shortname . ')';
            }
          }

          $label .= ' [id:' . $term->id() . ']';

          $suggestions[] = [
            'value' => $label,
            'label' => $label,
            'id' => $term->id(),
          ];
        }
      }
    }
    catch (\Exception $exception) {
      // Log the error but don't expose it to the user.
      $this->getLogger('reliefweb_moderation')->error('Error in source autocomplete: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }

    return new JsonResponse($suggestions);
  }

  /**
   * Extract source ID from autocomplete input.
   *
   * @param string $input
   *   The autocomplete input value.
   *
   * @return int|null
   *   The source taxonomy term ID or null if not found.
   */
  public static function extractSourceIdFromInput(string $input): ?int {
    if (empty($input)) {
      return NULL;
    }

    // Check if the input contains [id:XXX] pattern.
    if (preg_match('/\[id:(\d+)\]$/', $input, $matches)) {
      return (int) $matches[1];
    }

    return NULL;
  }

}
