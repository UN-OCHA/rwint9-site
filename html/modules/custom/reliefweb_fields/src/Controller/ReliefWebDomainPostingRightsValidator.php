<?php

namespace Drupal\reliefweb_fields\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\reliefweb_fields\Plugin\Field\FieldWidget\ReliefWebDomainPostingRights;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller to validate data of ReliefWeb Domain Posting Rights.
 */
class ReliefWebDomainPostingRightsValidator extends ControllerBase {

  /**
   * Validate a domain's posting rights.
   *
   * @param string $entity_type_id
   *   Entity type id.
   * @param string $bundle
   *   Entity bundle.
   * @param string $field_name
   *   Field name.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the domain posting rights data or an error if invalid.
   */
  public function validateDomain($entity_type_id, $bundle, $field_name) {
    $content = ReliefWebDomainPostingRights::validateDomain($entity_type_id, $bundle, $field_name);
    return new JsonResponse($content);
  }

}
