<?php

namespace Drupal\reliefweb_revisions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller to display an entity's history.
 */
class EntityHistory extends ControllerBase {


  /**
   * The drupal renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * {@inheritdoc}
   */
  public function __construct(RendererInterface $renderer) {
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('renderer')
    );
  }

  /**
   * Get an entity's revision history.
   *
   * @param string $entity_type_id
   *   Entity type id.
   * @param \Drupal\reliefweb_revisions\EntityRevisionedInterface $entity
   *   Entity for which to retrieve the history.
   *
   * @return \Drupal\Core\Cache\CacheableResponse
   *   The rendered entity history.
   */
  public function view($entity_type_id, EntityRevisionedInterface $entity) {
    $build = $entity->getHistoryContent();
    return new Response($this->renderer->render($build));
  }

}
