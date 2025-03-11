<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'reliefweb_text_with_summary' formatter.
 *
 * @FieldFormatter(
 *   id = "reliefweb_text_with_summary",
 *   label = @Translation("ReliefWeb Text with Summary"),
 *   field_types = {
 *     "text_with_summary",
 *   }
 * )
 */
class ReliefWebTextWithSummary extends FormatterBase {

  /**
   * Constructs a FormatterBase object.
   *
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    $label,
    $view_mode,
    array $third_party_settings,
    protected StateInterface $state,
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $label,
      $view_mode,
      $third_party_settings
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    $ai_summary_disclaimer = $this->state->get('reliefweb_ai_summary_disclaimer');

    // The ProcessedText element already handles cache context & tag bubbling.
    // @see \Drupal\filter\Element\ProcessedText::preRenderText()
    foreach ($items as $delta => $item) {
      if (!empty($item->value)) {
        $value = $item->value;
        $format = $item->format;
        $disclaimer = '';
      }
      // We assume for now (as of 2025/03/10) that if there is a summary it's
      // an AI generated summary.
      // @todo differentiate the source of the summary.
      elseif (!empty($item->summary)) {
        $value = $item->summary;
        $format = 'markdown';
        $disclaimer = $ai_summary_disclaimer;
      }
      else {
        $value = '';
        $format = $item->format;
        $disclaimer = '';
      }

      $elements[$delta] = [
        '#type' => 'processed_text',
        '#text' => $value,
        '#format' => $format,
        '#langcode' => $item->getLangcode(),
        '#disclaimer' => $disclaimer,
      ];
    }
    return $elements;
  }

}
