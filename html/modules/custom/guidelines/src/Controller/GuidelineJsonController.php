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

          $descriptions[] = [
            'label' => $f,
            'content' => $render_output,
            'link' => $guideline->toUrl()->toString(),
          ];
        }
      }
    }

    return new JsonResponse($descriptions);
  }

}
