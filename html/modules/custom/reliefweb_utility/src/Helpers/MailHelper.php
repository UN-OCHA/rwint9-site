<?php

namespace Drupal\reliefweb_utility\Helpers;

use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Core\Site\Settings;

/**
 * Helper to manipulate emails.
 */
class MailHelper {


  /**
   * Allowed tags in the HTML body part of the email.
   *
   * @var array
   */
  protected static $allowedTags = [
    'html',
    'head',
    'meta',
    'body',
    'div',
    'span',
    'br',
    'a',
    'em',
    'i',
    'strong',
    'b',
    'cite',
    'code',
    'strike',
    'ul',
    'ol',
    'li',
    'dl',
    'dt',
    'dd',
    'blockquote',
    'p',
    'pre',
    'h1',
    'h2',
    'h3',
    'h4',
    'h5',
    'h6',
    'table',
    'caption',
    'thead',
    'tbody',
    'th',
    'td',
    'tr',
    'sup',
    'sub',
    'img',
  ];

  /**
   * Get the plain text version of an email's body.
   *
   * @param array|string $body
   *   The mail body.
   *
   * @return string
   *   The plain text body.
   */
  public static function getPlainText($body) {
    if (empty($body)) {
      return '';
    }

    // Get the line ending characters.
    $eol = Settings::get('mail_line_endings', PHP_EOL);

    // Join the body array into one string if necessary.
    if (is_array($body)) {
      $body = implode($eol . $eol, $body);
    }

    // Skip if there is nothing to convert.
    $body = trim($body);
    if (empty($body)) {
      return '';
    }

    // If there is no apparent HTML elements we assume it's already plain text.
    if (preg_match('#</[^>]+>#', $body) !== 1) {
      $body_text = $body;
    }
    else {
      // Remove the preheader for plain text mail.
      $body = preg_replace('#<[^ ]+ id="preheader"[^<]*</[^>]+>#', '', $body);

      // Convert any HTML to plain-text.
      $body_text = MailFormatHelper::htmlToText($body, self::$allowedTags);

      // Decode HTML entities in links.
      $body_text = preg_replace_callback('#^\[\d+\] https?://\S+#m', function ($match) {
        return html_entity_decode($match[0], ENT_QUOTES, 'UTF-8');
      }, $body_text);
    }

    // Remove unnecessary consecutive line breaks.
    $body_text = preg_replace('#(' . $eol . '){3,}#', $eol . $eol, $body_text);

    // Ensure there is a blank line before second level titles.
    $body_text = preg_replace('/([^\n\r])(' . $eol . '--------)/', '$1' . $eol . '$2', $body_text);

    return $body_text;
  }

}
