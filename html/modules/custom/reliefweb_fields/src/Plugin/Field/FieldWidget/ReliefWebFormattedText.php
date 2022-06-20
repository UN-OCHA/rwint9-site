<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_utility\Helpers\HtmlSanitizer;
use Drupal\reliefweb_utility\Helpers\TextHelper;
use Drupal\reliefweb_utility\HtmlToMarkdown\Converters\TextConverter;
use Drupal\text\Plugin\Field\FieldWidget\TextareaWidget;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Plugin implementation of the 'reliefweb_formatted_text' widget.
 *
 * @FieldWidget(
 *   id = "reliefweb_formatted_text",
 *   label = @Translation("ReliefWeb Formatted Text"),
 *   field_types = {
 *     "text_long",
 *     "text_with_summary"
 *   }
 * )
 */
class ReliefWebFormattedText extends TextareaWidget {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'max_heading_level' => 2,
      'strip_embedded_content' => TRUE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::settingsForm($form, $form_state);
    $element['max_heading_level'] = [
      '#type' => 'select',
      '#title' => $this->t('Maximum heading level'),
      '#options' => array_combine(range(1, 6), range(1, 6)),
      '#default_value' => $this->getSetting('max_heading_level'),
    ];
    $element['strip_embedded_content'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Strip embedded content'),
      '#default_value' => !empty($this->getSetting('strip_embedded_content')),
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $summary[] = $this->t('Maximum heading level: @max_heading_level', [
      '@max_heading_level' => $this->getSetting('max_heading_level'),
    ]);
    $summary[] = $this->t('Strip embedded content: @strip_embedded_content', [
      '@strip_embedded_content' => !empty($this->getSetting('strip_embedded_content')) ? $this->t('Yes') : $this->t('No'),
    ]);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    // Convert the value to HTML to work with the editor.
    $item = $items[$delta];
    if (!empty($item->format) && !empty($item->value) && static::shouldConversionApply($item->format)) {
      $html = check_markup($item->value, $item->format);
      $element['#default_value'] = $this->sanitizeHtml($html);
    }

    // Add the attribute with the maximum heading level so the CKeditor
    // plugin can update the available text styles and add heading conversions.
    $element['#attributes']['data-max-heading-level'] = $this->getSetting('max_heading_level');

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    // Converted the values from HTML to markdown.
    foreach ($values as $delta => $value) {
      if (isset($value['format']) && static::shouldConversionApply($value['format']) && empty($value['_converted']) && !empty($value['value'])) {
        // Sanitize the HTML string.
        $html = $this->sanitizeHtml(trim($value['value']));

        // Convert to markdown.
        $converter = new HtmlConverter();
        $converter->getConfig()->setOption('strip_tags', TRUE);
        $converter->getConfig()->setOption('use_autolinks', FALSE);
        $converter->getConfig()->setOption('header_style', 'atx');
        $converter->getConfig()->setOption('strip_placeholder_links', TRUE);

        // Use our own text converter to avoid unwanted character escaping.
        $converter->getEnvironment()->addConverter(new TextConverter());

        $value['value'] = trim($converter->convert($html));
        $value['_converted'] = TRUE;
        $values[$delta] = $value;
      }
    }
    return $values;
  }

  /**
   * Sanitize a HTML string.
   *
   * @param string $html
   *   HTML string.
   *
   * @return string
   *   Sanitized HTML string.
   */
  public function sanitizeHtml($html) {
    // Sanitize the HTML string.
    $heading_offset = $this->getSetting('max_heading_level') - 1;
    $html = HtmlSanitizer::sanitize($html, FALSE, $heading_offset);

    // Remove embedded content.
    if (!empty($this->getSetting('strip_embedded_content'))) {
      $html = TextHelper::stripEmbeddedContent($html);
    }
    return $html;
  }

  /**
   * Check if the markdown/HTML conversion should apply.
   *
   * @param string $format_id
   *   Text format id.
   *
   * @return bool
   *   TRUE if we should apply the conversion.
   */
  public static function shouldConversionApply($format_id) {
    $formats = filter_formats(\Drupal::currentUser());
    return isset($formats[$format_id]) && !empty($formats[$format_id]->filters('reliefweb_formatted_text')->status);
  }

}
