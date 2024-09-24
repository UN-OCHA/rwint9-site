<?php

namespace Drupal\reliefweb_entraid\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Returns responses for OpenID Connect Windows AAD module routes.
 */
class AuthController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The OpenID Connect claims.
   *
   * @var \Drupal\openid_connect\OpenIDConnectClaims
   */
  protected $claims;

  /**
   * The OpenID Connect session service.
   *
   * @var \Drupal\openid_connect\OpenIDConnectSessionInterface
   */
  protected $session;

  /**
   * Redirect the user login callback.
   */
  public function redirectLogin() {
    // @codingStandardsIgnoreLine
    $container = \Drupal::getContainer();

    $this->entityTypeManager = $container->get('entity_type.manager');
    $this->claims = $container->get('openid_connect.claims');
    $this->session = $container->get('openid_connect.session');

    $client = $this->entityTypeManager->getStorage('openid_connect_client')->loadByProperties(['id' => 'entraid'])['entraid'];
    $plugin = $client->getPlugin();
    $scopes = $this->claims->getScopes($plugin);
    $this->session->saveOp('login');
    $response = $plugin->authorize($scopes);

    return $response->send();
  }

}
