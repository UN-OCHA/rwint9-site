<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\reliefweb_utility\Helpers\HtmlSanitizer;
use Drupal\reliefweb_utility\Helpers\TextHelper;
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
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    // Convert the value to HTML to work with the editor.
    $item = $items[$delta];
    if ($item->format === 'markdown_editor') {
      $element['#default_value'] = check_markup($item->value, $item->format);
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    // Converted the values from HTML to markdown.
    foreach ($values as $delta => $value) {
      if (isset($value['format']) && $value['format'] === 'markdown_editor' && empty($value['_converted']) && !empty($value['value'])) {
        // Sanitize the HTML string.
        $sanitizer = new HtmlSanitizer();
        $text = $sanitizer->sanitizeHtml($value['value']);

        // Remove embedded content.
        $text = TextHelper::stripEmbeddedContent($text);

        // Convert to markdown.
        $converter = new HtmlConverter();
        $converter->getConfig()->setOption('strip_tags', TRUE);
        $converter->getConfig()->setOption('use_autolinks', FALSE);
        $converter->getConfig()->setOption('header_style', 'atx');
        $converter->getConfig()->setOption('strip_placeholder_links', TRUE);

        $value['value'] = trim($converter->convert($text));
        $value['_converted'] = TRUE;
        $values[$delta] = $value;
      }
    }
    return $values;
  }

}
