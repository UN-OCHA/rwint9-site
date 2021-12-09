<?php

namespace Drupal\guidelines\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\guidelines\Entity\Guideline;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Class GuidelineJsonController.
 *
 *  Returns responses for Guideline routes.
 */
class GuidelineJsonController extends ControllerBase {

  /**
   * Return guidelines for a form.
   *
   * @param string $entity_type
   *   The entity type.
   *
   * @param string $bundle
   *   The entity bundle.
   *
   * @return array
   *   An array suitable for drupal_render().
   */
  public function getFormGuidelines($entity_type, $bundle) {
    $descriptions = [];

    /** @var Drupal\guidelines\Entity\Guideline[] $guidelines */
    $guidelines = Guideline::loadByEntity($entity_type);

    foreach ($guidelines as $guideline) {
      foreach ($guideline->field_field as $field) {
        list($e, $b, $f) = explode('.', $field->value);
        if (!empty($bundle) && $bundle === $b) {
          $view_builder = \Drupal::entityTypeManager()->getViewBuilder('guideline');
          $pre_render = $view_builder->view($guideline, 'default');
          $render_output = render($pre_render);

          $description = [
            'label' => $f,
            'title' => $guideline->field_title->value,
            'content' => $render_output,
            'link' => $guideline->toUrl()->toString(),
          ];

          // Allow other modules to add extra fields.
          $module_handler = \Drupal::moduleHandler();
          $context = [
            'entity_type' => $entity_type,
            'bundle' => $bundle,
          ];
          $module_handler->alter('guideline_json_fields', $description, $guideline, $context);

          if (isset($description['label']) && !empty($description['label'])) {
            $descriptions[] = $description;
          }
        }
      }
    }

    return new JsonResponse($descriptions);
  }

}
