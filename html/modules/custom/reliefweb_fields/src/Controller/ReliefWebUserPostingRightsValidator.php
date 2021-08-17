<?php

namespace Drupal\reliefweb_fields\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\reliefweb_fields\Plugin\Field\FieldWidget\ReliefWebUserPostingRights;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller to validate data of ReliefWeb User Posting Rights.
 */
class ReliefWebUserPostingRightsValidator extends ControllerBase {

  /**
   * Validate a user's posting rights.
   *
   * @param string $entity_type_id
   *   Entity type id.
   * @param string $bundle
   *   Entity bundle.
   * @param string $field_name
   *   Field name.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the user posting rights data or an error if invalid.
   */
  public function validateUser($entity_type_id, $bundle, $field_name) {
    $content = ReliefWebUserPostingRights::validateUser($entity_type_id, $bundle, $field_name);
    return new JsonResponse($content);
  }

}
