<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\process;

use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\Variable;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;

/**
 * Unserialize a string.
 *
 * @see \Drupal\migrate\Plugin\MigrateProcessInterface
 *
 * @MigrateProcessPlugin(
 *   id = "unserialize",
 *   handle_multiples = TRUE
 * )
 */
class Unserialize extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (!is_string($value)) {
      throw new MigrateException(sprintf("Input should be a string, instead it was of type '%s'", gettype($value)));
    }
    $data = unserialize($value);
    $new_value = NestedArray::getValue($data, $this->configuration['index'], $key_exists);

    if (!$key_exists) {
      if (array_key_exists('default', $this->configuration)) {
        $new_value = $this->configuration['default'];
      }
      else {
        throw new MigrateException(sprintf("Array index missing, extraction failed for '%s'. Consider adding a `default` key to the configuration.", Variable::export($value)));
      }
    }
    return $new_value;
  }

}
