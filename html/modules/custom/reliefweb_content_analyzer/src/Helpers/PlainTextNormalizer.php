<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\Helpers;

use Drupal\Component\Utility\Html;
use Drupal\reliefweb_utility\Helpers\TextHelper;

/**
 * Strips HTML/markdown to plain text for near-duplicate comparison.
 *
 * Markup variants (e.g. * vs _) are removed rather than canonicalized so
 * lexical similarity is not affected by formatting differences.
 */
final class PlainTextNormalizer {

  /**
   * Normalize body text to comparable plain text.
   *
   * @param string $text
   *   Raw body value (markdown and/or HTML).
   *
   * @return string
   *   Lowercased, whitespace-sanitized plain text.
   */
  public static function normalize(string $text): string {
    $text = trim($text);
    if ($text === '') {
      return '';
    }

    $text = Html::decodeEntities($text);

    // Drop HTML tags when present (bodies may be HTML or markdown-with-HTML).
    if (str_contains($text, '<')) {
      $text = strip_tags($text);
      $text = Html::decodeEntities($text);
    }

    $text = self::stripMarkdown($text);

    return mb_strtolower(TextHelper::sanitizeText($text), 'UTF-8');
  }

  /**
   * Strip common markdown constructs, keeping readable text where useful.
   *
   * @param string $text
   *   Text that may contain markdown.
   *
   * @return string
   *   Text with markdown markup removed.
   */
  protected static function stripMarkdown(string $text): string {
    // Fenced code blocks: keep inner content.
    $text = preg_replace('/```[\w]*\R?([\s\S]*?)```/u', '$1', $text) ?? $text;

    // Images: drop entirely.
    $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $text) ?? $text;

    // Links: keep link text.
    $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $text) ?? $text;

    // Reference-style links: keep text, drop reference.
    $text = preg_replace('/\[([^\]]+)\]\[[^\]]*\]/u', '$1', $text) ?? $text;
    $text = preg_replace('/^\s*\[[^\]]+\]:\s+\S+.*$/mu', '', $text) ?? $text;

    // Inline code: keep content.
    $text = preg_replace('/`([^`]+)`/u', '$1', $text) ?? $text;

    // Headings.
    $text = preg_replace('/^\s{0,3}#{1,6}\s+/mu', '', $text) ?? $text;

    // Blockquotes.
    $text = preg_replace('/^\s{0,3}>\s?/mu', '', $text) ?? $text;

    // Unordered / ordered list markers.
    $text = preg_replace('/^\s{0,3}([*+-]|\d+[.)])\s+/mu', '', $text) ?? $text;

    // Horizontal rules.
    $text = preg_replace('/^\s{0,3}([-*_]){3,}\s*$/mu', '', $text) ?? $text;

    // Strong then emphasis (order matters so ** is removed before *).
    $text = preg_replace('/(\*\*|__)(.*?)\1/us', '$2', $text) ?? $text;
    $text = preg_replace('/(\*|_)([^*_]+)\1/us', '$2', $text) ?? $text;

    // Strikethrough.
    $text = preg_replace('/~~(.*?)~~/us', '$1', $text) ?? $text;

    return $text;
  }

}
