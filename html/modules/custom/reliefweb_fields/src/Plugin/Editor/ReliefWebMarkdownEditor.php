<?php

declare(strict_types=1);

namespace Drupal\reliefweb_fields\Plugin\Editor;

use Drupal\editor\Entity\Editor;
use Drupal\editor\Plugin\EditorBase;

/**
 * Defines a markdown text editor for Drupal.
 *
 * @Editor(
 *   id = "reliefweb_markdown_editor",
 *   label = @Translation("ReliefWeb markdown editor"),
 *   supports_content_filtering = TRUE,
 *   supports_inline_editing = FALSE,
 *   is_xss_safe = FALSE,
 *   supported_element_types = {
 *     "textarea"
 *   }
 * )
 *
 * @internal
 *   Plugin classes are internal.
 */
class ReliefWebMarkdownEditor extends EditorBase {

  /**
   * {@inheritdoc}
   *
   * phpcs:disable Drupal.NamingConventions.ValidFunctionName
   */
  public function getJSSettings(Editor $editor) {
    // phpcs:enable Drupal.NamingConventions.ValidFunctionName
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getLibraries(Editor $editor) {
    return [
      'reliefweb_fields/reliefweb.editor.markdown',
    ];
  }

}
