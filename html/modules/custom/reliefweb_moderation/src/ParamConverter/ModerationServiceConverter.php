<?php

namespace Drupal\reliefweb_moderation\ParamConverter;

use Drupal\Core\ParamConverter\ParamConverterInterface;
use Drupal\reliefweb_moderation\ModerationServiceBase;
use Symfony\Component\Routing\Route;

/**
 * Convert an entity bundle into the corresponding moderation service.
 */
class ModerationServiceConverter implements ParamConverterInterface {

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults) {
    if (!empty($value)) {
      return ModerationServiceBase::getModerationService($value);
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route) {
    return !empty($definition['type']) && $definition['type'] == 'reliefweb_moderation_service';
  }

}
