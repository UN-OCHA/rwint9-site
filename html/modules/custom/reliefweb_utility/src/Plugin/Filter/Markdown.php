<?php

namespace Drupal\reliefweb_utility\Plugin\Filter;

use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use League\CommonMark\Environment;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\Table\TableExtension;
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

    // Configuration to add attributes to external links.
    $external_link_config = [
      'external_link' => [
        'internal_hosts' => [
          \Drupal::request()->getHost(),
          'reliefweb.int',
        ],
        'open_in_new_window' => TRUE,
      ],
    ];
    $environment->mergeConfig($external_link_config);

    // Add the extension to convert external links.
    $environment->addExtension(new ExternalLinkExtension());

    // Add the extension to convert ID attributes.
    $environment->addExtension(new AttributesExtension());

    // Add the extension to convert the tables.
    $environment->addExtension(new TableExtension());

    // Create the converter with the extension(s).
    $converter = new MarkdownConverter($environment);

    // Convert to HTML.
    $html = $converter->convertToHtml($text);

    return new FilterProcessResult($html);
  }

}
