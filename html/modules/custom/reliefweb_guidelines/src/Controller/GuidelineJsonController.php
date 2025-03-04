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
 * Class GuidelineJsonController.
 *
 *  Returns responses for Guideline routes.
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
   * Constructs a new entity.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   This is pointing to the object of enitytype manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   Renderer service.
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
   * @return array
   *   An array suitable for drupal_render().
   */
  public function getFormGuidelines(string $entity_type, string $bundle): JsonResponse {
    $descriptions = [];

    $guideline_list_ids = $this->getAccessibleGuidelineListIds($this->currentUser());
    if (empty($guideline_list_ids)) {
      return new JsonResponse($descriptions);
    }

    $storage = $this->entityTypeManager()->getStorage('guideline');

    // Retrieve the guideline lists accessible to the current user.
    $ids = $storage
      ->getQuery()
      ->condition('status', 1, '=')
      ->condition('type', 'field_guideline', '=')
      ->condition('parent', $guideline_list_ids, 'IN')
      ->condition('field_field', $entity_type . '.' . $bundle . '.', 'STARTS_WITH')
      ->accessCheck(TRUE)
      ->execute();
    if (empty($ids)) {
      return new JsonResponse($descriptions);
    }

    /** @var Drupal\guidelines\Entity\Guideline[] $guidelines */
    $guidelines = $storage->loadMultiple($ids);

    foreach ($guidelines as $guideline) {
      foreach ($guideline->field_field as $field) {
        [, $field_bundle, $field_name] = explode('.', $field->value);
        if (isset($descriptions[$field_name])) {
          continue;
        }
        if (!empty($bundle) && $bundle === $field_bundle) {
          $view_builder = $this->entityTypeManager()->getViewBuilder('guideline');
          $pre_render = $view_builder->view($guideline, 'default');
          $render_output = $this->renderer->render($pre_render);

          if (!empty($guideline->field_title->value)) {
            $title = $guideline->field_title->value;
          }
          else {
            $title = $guideline->label();
          }

          $description = [
            'label' => $field_name,
            'title' => $title,
            'content' => $render_output,
            'link' => $guideline->toUrl()->toString(),
          ];

          // Allow other modules to add extra fields.
          $module_handler = $this->moduleHandler();
          $context = [
            'entity_type' => $entity_type,
            'bundle' => $bundle,
          ];
          $module_handler->alter('guideline_json_fields', $description, $guideline, $context);

          if (isset($description['label']) && !empty($description['label'])) {
            $descriptions[$field_name] = $description;
          }
        }
      }
    }

    return new JsonResponse(array_values($descriptions));
  }

}
