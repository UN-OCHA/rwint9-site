<?php

declare(strict_types=1);

namespace Drupal\reliefweb_utility\Helpers;

use Drupal\Core\Render\Markup;

/**
 * Helpers for revision log merge, deduplication, and display formatting.
 */
class RevisionLogHelper {

  /**
   * Format a revision log message for display.
   *
   * @param string $message
   *   Revision log message.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   Formatted revision log message wrapped in a MarkupInterface so it's not
   *   escaped a second time when rendered in a template.
   */
  public static function formatMessage(string $message) {
    if ($message !== '') {
      $message = MarkdownHelper::convertInlinesOnly($message);
      $message = HtmlSanitizer::sanitize($message);
    }
    else {
      $message = '';
    }
    return Markup::create($message);
  }

  /**
   * Update a revision log string with a new message.
   *
   * @param string $log
   *   Existing revision log text.
   * @param string $message
   *   The message to add. Empty string is allowed only for replace (clears the
   *   log).
   * @param string $action
   *   One of prepend, append, or replace.
   * @param bool $skip_if_present
   *   Whether to skip if the message is already present as a full clause
   *   (case-insensitive, whitespace-normalized equality — not a substring
   *   search), or when every atomic clause of a multi-sentence message is
   *   already present. Pass FALSE when setting a full replacement on a new
   *   revision.
   * @param string $separator
   *   Separator used between existing log and message for prepend/append when
   *   both sides are non-empty. Ignored for replace.
   *
   * @return string
   *   The updated revision log text.
   */
  public static function updateMessage(
    string $log,
    string $message,
    string $action = 'append',
    bool $skip_if_present = TRUE,
    string $separator = ' ',
  ): string {
    $message = trim($message);
    // Allow empty message only for replace so callers can clear the log
    // (e.g. restore a blank original before re-annotating a new revision).
    if ($message === '' && $action !== 'replace') {
      return trim($log);
    }

    $log = trim($log);
    if ($message !== '' && $skip_if_present && $log !== '' && static::containsMessage($log, $message)) {
      return $log;
    }

    $log = match ($action) {
      'prepend' => $log === '' ? $message : $message . $separator . $log,
      'append' => $log === '' ? $message : $log . $separator . $message,
      'replace' => $message,
      default => $log,
    };

    return trim($log);
  }

  /**
   * Whether the revision log already contains the given message as a clause.
   *
   * Matching is case-insensitive and whitespace-normalized. The message must
   * equal a full clause (or the entire log), not merely appear as a substring.
   * A multi-clause message is also treated as present when every atomic clause
   * of the message already appears as a clause in the log.
   *
   * @param string $log
   *   Existing revision log text.
   * @param string $message
   *   Candidate message to look for.
   *
   * @return bool
   *   TRUE if a matching clause is present.
   */
  public static function containsMessage(string $log, string $message): bool {
    $normalized_message = static::normalizeFragment($message);
    if ($normalized_message === '') {
      return FALSE;
    }

    $log_clauses = static::splitClauses($log);
    $normalized_log_clauses = [];
    foreach ($log_clauses as $clause) {
      $normalized_log_clauses[] = static::normalizeFragment($clause);
    }

    if (in_array($normalized_message, $normalized_log_clauses, TRUE)) {
      return TRUE;
    }

    // Multi-sentence messages: present if every atomic clause is already in
    // the log (splitClauses also appends the full text; drop that for this
    // check so we compare sentence-level parts only).
    $message_clauses = static::splitClauses($message);
    if (count($message_clauses) > 1) {
      $last = $message_clauses[array_key_last($message_clauses)];
      if (trim($last) === trim($message)) {
        array_pop($message_clauses);
      }
      if ($message_clauses === []) {
        return FALSE;
      }
      foreach ($message_clauses as $clause) {
        $normalized_clause = static::normalizeFragment($clause);
        if ($normalized_clause === '' || !in_array($normalized_clause, $normalized_log_clauses, TRUE)) {
          return FALSE;
        }
      }
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Normalize a revision log fragment for comparison.
   *
   * @param string $text
   *   Raw fragment.
   *
   * @return string
   *   Lowercased text with whitespace collapsed.
   */
  public static function normalizeFragment(string $text): string {
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return mb_strtolower(trim($text));
  }

  /**
   * Split a revision log into clauses for presence matching.
   *
   * Splits on newlines, " - " separators, and sentence boundaries. The full
   * log is always included so single-message logs match as one clause.
   *
   * @param string $log
   *   Existing revision log text.
   *
   * @return string[]
   *   Non-empty clause strings.
   */
  public static function splitClauses(string $log): array {
    $log = trim($log);
    if ($log === '') {
      return [];
    }

    $parts = preg_split('/\n+| - +|(?<=[.!?])\s+/u', $log) ?: [];
    $clauses = array_values(array_filter(array_map('trim', $parts), static fn(string $part): bool => $part !== ''));

    // Always include the full log so a single multi-sentence message still
    // matches when re-added as a whole.
    if (!in_array($log, $clauses, TRUE)) {
      $clauses[] = $log;
    }

    return $clauses;
  }

}
