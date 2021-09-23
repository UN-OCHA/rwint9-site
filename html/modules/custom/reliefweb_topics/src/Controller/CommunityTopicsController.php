<?php

namespace Drupal\reliefweb_topics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\reliefweb_utility\Helpers\UrlHelper;
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
    if (empty($data['url'])) {
      $invalid = $this->t('Missing link url.');
    }
    // Validate the title.
    elseif (empty($data['title'])) {
      $invalid = $this->t('The link title is mandatory.');
    }
    // Ensure the URL is a valid aboslute URL.
    elseif (!UrlHelper::isValid($data['url'], TRUE)) {
      $invalid = $this->t("Invalid URL. It must a full URL starting with https or http.");
    }

    if (empty($invalid)) {
      return new JsonResponse($data);
    }

    return new JsonResponse([
      'error' => $invalid,
    ]);
  }

}
