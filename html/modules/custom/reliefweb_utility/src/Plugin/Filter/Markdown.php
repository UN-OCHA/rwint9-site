<?php

namespace Drupal\reliefweb_utility\Plugin\Filter;

use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use League\CommonMark\Environment;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Provides a filter to display any HTML as plain text.
 *
 * @Filter(
 *   id = "filter_markdown",
 *   title = @Translation("Convert a markdown text to HTML"),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_MARKUP_LANGUAGE,
 *   weight = -20
 * )
 */
class Markdown extends FilterBase {

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    // Add a space before the heading '#' which is fine as ReliefWeb doesn't use
    // hash tags.
    // @see https://talk.commonmark.org/t/heading-not-working/819/42
    $text = preg_replace('/^(#+)([^# ])/m', '$1 $2', $text);

    // Obtain a pre-configured Environment with all the CommonMark
    // parsers/renderers ready-to-go.
    $environment = Environment::createCommonMarkEnvironment();

    // Add the extension to convert ID attributes.
    $environment->addExtension(new AttributesExtension());

    // Create the converter with the extension(s).
    $converter = new MarkdownConverter($environment);

    // Convert to HTML.
    $html = $converter->convertToHtml($text);

    return new FilterProcessResult($html);
  }

}
