<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a Content Processor attribute object.
 *
 * Plugin Namespace: Plugin\reliefweb_post_api\ContentProcessor.
 *
 * @Annotation
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ContentProcessor extends Plugin {

  /**
   * Constructs a Content Processor attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   The label of the plugin.
   * @param string $entityType
   *   The entity type the plugin can apply to.
   * @param string $entityBundle
   *   The entity bundle the plugin can apply to.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label = NULL,
    public readonly string $entityType,
    public readonly string $entityBundle
  ) {}

}
