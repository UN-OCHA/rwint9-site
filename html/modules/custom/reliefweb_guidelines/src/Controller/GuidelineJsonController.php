<?php

namespace Drupal\reliefweb_guidelines\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\reliefweb_guidelines\GuidelineLoadTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns JSON responses for form guideline popups.
 */
class GuidelineJsonController extends ControllerBase {

  use GuidelineLoadTrait;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected RendererInterface $renderer;

  /**
   * Constructs a GuidelineJsonController.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    RendererInterface $renderer,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('renderer')
    );
  }

  /**
   * Return guidelines for a form.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $bundle
   *   The entity bundle.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with guideline descriptions keyed by field name.
   */
  public function getFormGuidelines(string $entity_type, string $bundle): JsonResponse {
    $descriptions = [];

    $guideline_list_ids = $this->getAccessibleGuidelineListIds($this->currentUser());
    if (empty($guideline_list_ids)) {
      return new JsonResponse($descriptions);
    }

    $storage = $this->entityTypeManager()->getStorage('node');

    // Retrieve published guideline nodes for lists accessible to the current
    // user.
    $ids = $storage
      ->getQuery()
      ->condition('status', 1, '=')
      ->condition('type', 'guideline', '=')
      ->condition('field_guideline_list', $guideline_list_ids, 'IN')
      ->condition('field_field', $entity_type . '.' . $bundle . '.', 'STARTS_WITH')
      ->accessCheck(TRUE)
      ->execute();
    if (empty($ids)) {
      return new JsonResponse($descriptions);
    }

    /** @var \Drupal\reliefweb_guidelines\Entity\Node\Guideline[] $guidelines */
    $guidelines = $storage->loadMultiple($ids);

    foreach ($guidelines as $guideline) {
      foreach ($guideline->field_field as $field) {
        [, $field_bundle, $field_name] = explode('.', $field->value);
        if (isset($descriptions[$field_name])) {
          continue;
        }
        if (!empty($bundle) && $bundle === $field_bundle) {
          $view_builder = $this->entityTypeManager()->getViewBuilder('node');
          $pre_render = $view_builder->view($guideline, 'default');
          $render_output = $this->renderer->render($pre_render);

          $title = $guideline->label();

          $description = [
            'label' => $field_name,
            'title' => $title,
            'content' => $render_output,
            'link' => $guideline->toUrl()->toString(),
          ];

          // Allow other modules to add extra fields.
          $context = [
            'entity_type' => $entity_type,
            'bundle' => $bundle,
          ];
          $this->moduleHandler()->alter('guideline_json_fields', $description, $guideline, $context);

          if (!empty($description['label'])) {
            $descriptions[$field_name] = $description;
          }
        }
      }
    }

    return new JsonResponse(array_values($descriptions));
  }

}
