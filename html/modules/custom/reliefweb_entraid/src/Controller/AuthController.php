<?php

namespace Drupal\reliefweb_entraid\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\openid_connect\OpenIDConnectClaims;
use Drupal\openid_connect\OpenIDConnectSessionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for OpenID Connect Windows AAD module routes.
 */
class AuthController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The OpenID Connect claims.
   *
   * @var \Drupal\openid_connect\OpenIDConnectClaims
   */
  protected $openIdConnectClaims;

  /**
   * The OpenID Connect session service.
   *
   * @var \Drupal\openid_connect\OpenIDConnectSessionInterface
   */
  protected $openIdConnectSession;

  /**
   * Constructs a new AuthController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\openid_connect\OpenIDConnectClaims $open_id_connect_claims
   *   The OpenID Connect claims.
   * @param \Drupal\openid_connect\OpenIDConnectSessionInterface $open_id_connect_session
   *   The OpenID Connect session service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    OpenIDConnectClaims $open_id_connect_claims,
    OpenIDConnectSessionInterface $open_id_connect_session,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->openIdConnectClaims = $open_id_connect_claims;
    $this->openIdConnectSession = $open_id_connect_session;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('openid_connect.claims'),
      $container->get('openid_connect.session')
    );
  }

  /**
   * Redirect to the user login callback.
   */
  public function redirectLogin() {
    try {
      $client_entities = $this->entityTypeManager()
        ->getStorage('openid_connect_client')
        ->loadByProperties(['id' => 'entraid']);

      if (!isset($client_entities['entraid'])) {
        throw new \Exception();
      }

      $client = $client_entities['entraid'];
      $plugin = $client->getPlugin();
      $scopes = $this->openIdConnectClaims->getScopes($plugin);
      $this->openIdConnectSession->saveOp('login');
      $response = $plugin->authorize($scopes);

      return $response;
    }
    catch (\Exception $exception) {
      $config = $this->config('openid_connect.client.entraid');
      $cacheable_metadata = new CacheableMetadata();
      $cacheable_metadata->addCacheableDependency($config);
      throw new CacheableNotFoundHttpException($cacheable_metadata);
    }
  }

}
