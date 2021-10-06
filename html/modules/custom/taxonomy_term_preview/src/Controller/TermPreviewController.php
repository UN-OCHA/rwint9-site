<?php

namespace Drupal\taxonomy_term_preview\Controller;

use Drupal\Core\Entity\Controller\EntityViewController;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a controller to render a single taxonomy term in preview.
 */
class TermPreviewController extends EntityViewController {

  /**
   * The entity repository service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * Creates a TermViewController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer, EntityRepositoryInterface $entity_repository) {
    parent::__construct($entity_type_manager, $renderer);
    $this->entityRepository = $entity_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('entity.repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function view(EntityInterface $term_preview, $view_mode_id = 'full', $langcode = NULL) {
    $term_preview->preview_view_mode = $view_mode_id;
    $build = parent::view($term_preview, $view_mode_id);

    $build['#attached']['library'][] = 'taxonomy_term_preview/drupal.term.preview';

    // Don't render cache previews.
    unset($build['#cache']);

    return $build;
  }

  /**
   * The _title_callback for the page that renders a single term in preview.
   *
   * @param \Drupal\Core\Entity\EntityInterface $term_preview
   *   The current taxonomy term.
   *
   * @return string
   *   The page title.
   */
  public function title(EntityInterface $term_preview) {
    return $this->entityRepository->getTranslationFromContext($term_preview)->label();
  }

}
