<?php

namespace Drupal\reliefweb_utility\Helpers;

/**
 * Helper to manipulate classes.
 */
class ClassHelper {

  /**
   * Check if a class exists, converting the given class name to camel case.
   *
   * @param string $namespace
   *   Namespace.
   * @param string $classname
   *   Class name.
   *
   * @return string|false
   *   Namespaced class name or FALSE if it was not found.
   */
  public static function classExists($namespace, $classname) {
    $class = rtrim($namespace, '\\') . '\\' . static::toCamelCase($classname);
    return class_exists($class) ? $class : FALSE;
  }

  /**
   * Convert the give string to camel case.
   */
  public static function toCamelCase($string) {
    return str_replace(['_', '-', ' '], '', ucwords($string, '_- '));
  }

}
