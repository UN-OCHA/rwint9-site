<?php

namespace Drupal\reliefweb_utility\Entity;

use Drupal\Core\File\Exception\FileException;
use Drupal\imageapi_optimize\Entity\ImageStyleWithPipeline as BaseImageStyleWithPipeline;

/**
 * Override of the ImageStyleWithPipeline to also delete webp images.
 */
class ImageStyleWithPipeline extends BaseImageStyleWithPipeline {

  /**
   * {@inheritdoc}
   */
  public function flush($path = NULL) {
    $result = parent::flush($path);

    // Also delete the webp version of the image if it exists.
    if (isset($path)) {
      /** @var \Drupal\Core\File\FileSystemInterface $file_system */
      $file_system = \Drupal::service('file_system');
      $derivative_uri = $this->buildUri($path);
      $derivative_uri_webp = $derivative_uri . '.webp';

      if (file_exists($derivative_uri_webp)) {
        try {
          $file_system->delete($derivative_uri_webp);
        }
        catch (FileException $exception) {
          // Ignore failed deletion.
        }
      }
    }

    return $result;
  }

}
