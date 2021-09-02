<?php

namespace Drupal\reliefweb_topics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller to validate data of ReliefWeb Links.
 */
class CommunityTopicsController extends ControllerBase {

  /**
   * Validate a link.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the link data or an error if invalid.
   */
  public function validate() {
    // Limit to 10,000 bytes (should never be reached).
    $data = json_decode(file_get_contents('php://input', FALSE, NULL, 0, 10000), TRUE);

    // Validate the URL.
    if (empty($link['url'])) {
      $invalid = $this->t('Missing link url.');
    }
    // Validate the title.
    elseif (empty($link['title'])) {
      $invalid = $this->t('The link title is mandatory.');
    }
    // Path has to link to updates.
    elseif (strpos($data['url'], '/updates') === FALSE) {
      $invalid = $this->t('Invalid URL: use a link to a river.');
    }

    if (empty($invalid)) {
      return new JsonResponse($data);
    }

    return new JsonResponse([
      'error' => $invalid,
    ]);
  }

}
