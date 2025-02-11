<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a classifier plugin attribute object.
 *
 * Plugin Namespace: Plugin\ReliefWebImporter.
 *
 * @see \Drupal\reliefweb_import\Plugin\ReliefWebImporterPluginBase
 * @see \Drupal\reliefweb_import\Plugin\ReliefWebImporterPluginInterface
 * @see \Drupal\reliefweb_import\Plugin\ReliefWebImporterPluginManager
 * @see plugin_api
 *
 * @Attribute
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ReliefWebImporter extends Plugin {

  /**
   * Constructor.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The label of the plugin.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $description
   *   The description of the plugin.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly TranslatableMarkup $description,
  ) {}

}
