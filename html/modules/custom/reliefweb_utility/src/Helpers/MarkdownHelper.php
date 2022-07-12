<?php

namespace Drupal\reliefweb_utility\Helpers;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\InlinesOnly\InlinesOnlyExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Helper to convert from and to markdown.
 */
class MarkdownHelper {

  /**
   * List of HTML block level elements.
   *
   * @var array
   */
  public static $htmlBlockElements = [
    "address",
    "article",
    "aside",
    "base",
    "basefont",
    "blockquote",
    "body",
    "caption",
    "center",
    "col",
    "colgroup",
    "dd",
    "details",
    "dialog",
    "dir",
    "div",
    "dl",
    "dt",
    "fieldset",
    "figcaption",
    "figure",
    "footer",
    "form",
    "frame",
    "frameset",
    "h1",
    "h2",
    "h3",
    "h4",
    "h5",
    "h6",
    "head",
    "header",
    "hr",
    "html",
    "iframe",
    "legend",
    "li",
    "link",
    "main",
    "menu",
    "menuitem",
    "nav",
    "noframes",
    "ol",
    "optgroup",
    "option",
    "p",
    "param",
    "section",
    "source",
    "summary",
    "table",
    "tbody",
    "td",
    "tfoot",
    "th",
    "thead",
    "title",
    "tr",
    "track",
    "ul",
    // Extra elements that need to be followed by a blank line for the following
    // text to be converted to markdown.
    "pre",
    "script",
    "style",
    "textarea",
  ];

  /**
   * Convert a markdown text to HTML.
   *
   * @param string $text
   *   Markdown text to convert.
   * @param array $internal_hosts
   *   List of internal hosts to determine if a link is external or not.
   *
   * @return string
   *   HTML.
   *
   * @todo this is the same code as \RWApiIndexer\Processor::convertToHtml().
   *   See if we can consolidate that somehow while keeping this and the API
   *   indexer independent. In the meantime any change here should probably
   *   reflected in the API indexer.
   *
   * @see \RWApiIndexer\Processor::convertToHtml()
   */
  public static function convertToHtml($text, array $internal_hosts = ['reliefweb.int']) {
    static $pattern;

    // CommonMark specs consider text following an HTML block element without
    // a blank line to seperate them, as part of the HTML block. This is a
    // breaking change compared to what Michel Fortin's PHP markdown or
    // hoedown libraries were doing so use the following regex to ensure there
    // is a blank line.
    // @see https://spec.commonmark.org/0.30/#html-blocks
    // @see https://talk.commonmark.org/t/beyond-markdown/2787/4
    if (!isset($pattern)) {
      $pattern = '#(</' . implode('>|</', static::$htmlBlockElements) . '>)\s*#m';
    }
    $text = preg_replace($pattern, "$1\n\n", $text);

    // Add a space before the heading '#' which is fine as ReliefWeb doesn't use
    // hash tags.
    // @see https://talk.commonmark.org/t/heading-not-working/819/42
    $text = preg_replace('/^(#+)([^# ])/m', '$1 $2', $text);

    // No need for extra blanks.
    $text = trim($text);

    // Environment configuration.
    $config = [
       // Settings to add attributes to external links.
      'external_link' => [
        'internal_hosts' => $internal_hosts,
        'open_in_new_window' => TRUE,
      ],
    ];

    // Create an Environment with all the CommonMark parsers and renderers.
    $environment = new Environment($config);
    $environment->addExtension(new CommonMarkCoreExtension());

    // Add the extension to convert external links.
    $environment->addExtension(new ExternalLinkExtension());

    // Add the extension to convert ID attributes.
    $environment->addExtension(new AttributesExtension());

    // Add the extension to convert links.
    $environment->addExtension(new AutolinkExtension());

    // Add the extension to convert the tables.
    $environment->addExtension(new TableExtension());

    // Create the converter with the extension(s).
    $converter = new MarkdownConverter($environment);

    // Convert to HTML.
    return (string) $converter->convert($text);
  }

  /**
   * Convert a markdown text to HTML (only inline elements).
   *
   * @param string $text
   *   Markdown text to convert.
   * @param array $internal_hosts
   *   List of internal hosts to determine if a link is external or not.
   *
   * @return string
   *   HTML.
   */
  public static function convertInlinesOnly($text, array $internal_hosts = ['reliefweb.int']) {
    // Environment configuration.
    $config = [
       // Settings to add attributes to external links.
      'external_link' => [
        'internal_hosts' => $internal_hosts,
        'open_in_new_window' => TRUE,
      ],
    ];

    // Create an Environment with all the CommonMark parsers and renderers.
    $environment = new Environment($config);
    $environment->addExtension(new InlinesOnlyExtension());

    // Add the extension to convert external links.
    $environment->addExtension(new ExternalLinkExtension());

    // Add the extension to convert links.
    $environment->addExtension(new AutolinkExtension());

    // Add the extension to convert strikethrough.
    $environment->addExtension(new StrikethroughExtension());

    // Create the converter with the extension(s).
    $converter = new MarkdownConverter($environment);

    // Convert to HTML.
    return (string) $converter->convert($text);
  }

}
