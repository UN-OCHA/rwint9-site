<?php

namespace Drupal\reliefweb_fields\Plugin\CKEditorPlugin;

use Drupal\ckeditor\CKEditorPluginBase;
use Drupal\ckeditor\CKEditorPluginContextualInterface;
use Drupal\editor\Entity\Editor;

/**
 * Defines the "reliefweb_formatted_text" plugin.
 *
 * @CKEditorPlugin(
 *   id = "reliefweb_formatted_text",
 *   label = @Translation("ReliefWeb Formatted Text CKEditor plugin")
 * )
 */
class ReliefWebFormattedText extends CKEditorPluginBase implements CKEditorPluginContextualInterface {

  /**
   * The extension path resolver.
   *
   * @var \Drupal\Core\Extension\ExtensionPathResolver
   */
  protected $extensionPathResolver;

  /**
   * {@inheritdoc}
   */
  public function isInternal() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function isEnabled(Editor $editor) {
    if (!$editor->hasAssociatedFilterFormat()) {
      return FALSE;
    }

    /** @var \Drupal\filter\FilterFormatInterface|null $format */
    $format = $editor->getFilterFormat();
    return !empty($format) && !empty($format->filters('reliefweb_formatted_text')->status);
  }

  /**
   * {@inheritdoc}
   */
  public function getButtons() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getFile() {
    return $this->getExtensionPathResolver()->getPath('module', 'reliefweb_fields') . '/js/plugins/reliefweb_formatted_text/plugin.js';
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(Editor $editor) {
    return [];
  }

  /**
   * Get the extension path resolver.
   *
   * @return \Drupal\Core\Extension\ExtensionPathResolver
   *   The extension path resolver.
   */
  protected function getExtensionPathResolver() {
    if (!isset($this->extensionPathResolver)) {
      $this->extensionPathResolver = \Drupal::service('extension.path.resolver');
    }
    return $this->extensionPathResolver;
  }

}
