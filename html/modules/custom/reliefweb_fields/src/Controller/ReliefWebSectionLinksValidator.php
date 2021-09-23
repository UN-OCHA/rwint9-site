<?php

namespace Drupal\reliefweb_fields\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\reliefweb_fields\Plugin\Field\FieldWidget\ReliefWebSectionLinks;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller to validate data of ReliefWeb Section Links.
 */
class ReliefWebSectionLinksValidator extends ControllerBase {

  /**
   * Validate a section link.
   *
   * @param string $entity_type_id
   *   Entity type id.
   * @param string $bundle
   *   Entity bundle.
   * @param string $field_name
   *   Field name.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the link data or an error if invalid.
   */
  public function validateSectionLink($entity_type_id, $bundle, $field_name) {
    $content = ReliefWebSectionLinks::validateLink($entity_type_id, $bundle, $field_name);
    return new JsonResponse($content);
  }

}
