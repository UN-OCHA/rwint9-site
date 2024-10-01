<?php

namespace Drupal\reliefweb_fields\EditorXssFilter;

use Drupal\editor\EditorXssFilter\Standard;
use Drupal\filter\FilterFormatInterface;
use Drupal\reliefweb_utility\Helpers\HtmlSanitizer;
use Drupal\reliefweb_utility\Helpers\TextHelper;
use Drupal\reliefweb_utility\HtmlToMarkdown\Converters\TextConverter;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Defines the standard text editor XSS filter.
 */
class ReliefWebMarkdownXssFilter extends Standard {

  /**
   * {@inheritdoc}
   *
   * In addition to the XSS filtering, we convert the editor content (`$html)
   * from HTML to markdown or the opposite when the text format is changed via
   * the UI.
   *
   * Drupal doesn't provide many hooks to be able to do handle text format
   * changes made via the UI. The UI notably shows a confirmation popup to
   * possibly cancel the text format change.
   *
   * As a result we cannot use drupal form ajax API because we cannot detect the
   * cancellation.
   *
   * The only way to act on the content when the text format actually changes is
   * to leverage the call to the `editor.filter_xss` route which is used to
   * sanitize the text before passing it to the new text format's editor.
   *
   * For that, we check the current route and if it's the XSS filtering one from
   * the editor then we convert the editor's content if appropriate.
   *
   * It's important to filter on the route because the XSS filtering is also
   * done when saving the entity form in which case we would possibly get an
   * extra unwanted conversion.
   *
   * @see editor_filter_xss()
   * @see editor/drupal.editor
   */
  public static function filterXss($html, FilterFormatInterface $format, ?FilterFormatInterface $original_format = NULL) {
    $destination_editor = editor_load($format->id())?->getEditor();
    $from_ui = \Drupal::request()->attributes->get('_route') === 'editor.filter_xss';

    // No conversion if the this is called outside of a form or there is only
    // text format provided.
    if (!$from_ui || is_null($original_format) || $format->id() === $original_format->id()) {
      // The `reliefweb_markdown_editor` handles only plain text so is XSS safe.
      if ($destination_editor === 'reliefweb_markdown_editor') {
        return $html;
      }
      else {
        return parent::filterXss($html, $format, $original_format);
      }
    }

    $source_editor = editor_load($original_format->id())?->getEditor();

    // The `reliefweb_markdown_editor` editor is a dummy editor (not change to
    // the textarea) that accepts markdown/plain text as content.
    $source_is_markdown = $source_editor === 'reliefweb_markdown_editor';
    $destination_is_markdown = $destination_editor === 'reliefweb_markdown_editor';

    // The `reliefweb_formatted_text` filter is used to flag a text format so
    // that the HTML content from its editor (ex: CKEditor 5) is converted to
    // markdown when saved.
    $source_is_html = !empty($original_format->filters('reliefweb_formatted_text')?->status);
    $destination_is_html = !empty($format->filters('reliefweb_formatted_text')?->status);

    // Convert HTML to markdown.
    if ($source_is_html && $destination_is_markdown) {
      // Filter XSS.
      $html = parent::filterXss($html, $format, $original_format);

      // Sanitize the HTML string.
      // @todo how to retrieve the heading offset from the widget?
      $html = HtmlSanitizer::sanitize($html, FALSE, 1);

      // Remove embedded content.
      // @todo how to retrieve whether to strip or not embedded content
      // from the widget?
      $html = TextHelper::stripEmbeddedContent($html);

      // Convert to markdown.
      $converter = new HtmlConverter();
      $converter->getConfig()->setOption('strip_tags', TRUE);
      $converter->getConfig()->setOption('use_autolinks', FALSE);
      $converter->getConfig()->setOption('header_style', 'atx');
      $converter->getConfig()->setOption('strip_placeholder_links', TRUE);

      // Use our own text converter to avoid unwanted character escaping.
      $converter->getEnvironment()->addConverter(new TextConverter());

      $html = trim($converter->convert($html));
    }
    // Convert markdown to HTML.
    elseif ($source_is_markdown && $destination_is_html) {
      // XSS filtering is included in the conversion.
      $html = check_markup($html, $format->id());
    }

    return $html;
  }

}
