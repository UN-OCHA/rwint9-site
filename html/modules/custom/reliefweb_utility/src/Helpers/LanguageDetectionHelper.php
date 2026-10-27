<?php

declare(strict_types=1);

namespace Drupal\reliefweb_utility\Helpers;

use Nitotm\Eld\EldDataFile;
use Nitotm\Eld\EldFormat;
use Nitotm\Eld\LanguageDetector;
use Nitotm\Eld\LanguageResult;

/**
 * Helper for language detection from text.
 *
 * Builds detection samples from MuPDF stext line spans (or plain text lines)
 * by keeping long lines and clustering them into layout blocks. Short map
 * labels and legends are filtered by line length, not by language-specific
 * word morphology.
 */
class LanguageDetectionHelper {

  /**
   * Maximum layout blocks to concatenate for language detection.
   */
  public const MAX_SAMPLES = 3;

  /**
   * Default minimum characters for a line to count as body copy.
   */
  public const DEFAULT_MIN_LINE_CHARS = 40;

  /**
   * Default minimum sample characters before detection runs.
   */
  public const DEFAULT_MIN_CHARS = 200;

  /**
   * Max vertical gap between consecutive lines as a multiple of line height.
   */
  protected const MAX_GAP_HEIGHT_RATIO = 1.8;

  /**
   * Max relative font-size difference for lines in the same block.
   */
  protected const MAX_SIZE_RATIO = 0.2;

  /**
   * Cached ELD detectors keyed by ngram database size.
   *
   * @var array<string, \Nitotm\Eld\LanguageDetector>
   */
  protected static array $languageDetectors = [];

  /**
   * Default Eld ngram database size.
   */
  public const DEFAULT_DATABASE = EldDataFile::MEDIUM;

  /**
   * Allowed Eld ngram database size values.
   *
   * @return list<string>
   *   Database size identifiers.
   */
  public static function databaseSizes(): array {
    return EldDataFile::values();
  }

  /**
   * Run ELD once and return the raw result (caller checks isReliable).
   *
   * @param string $text
   *   Text to analyze.
   * @param string $database
   *   Eld ngram database size (small, medium, large, extralarge).
   *   Invalid values fall back to medium.
   *
   * @return \Nitotm\Eld\LanguageResult
   *   Detection result. Empty text yields language "und".
   */
  public static function detect(
    string $text,
    string $database = self::DEFAULT_DATABASE,
  ): LanguageResult {
    $text = static::cleanText($text);
    if ($text === '') {
      return new LanguageResult();
    }
    return static::getLanguageDetector($database)->detect($text);
  }

  /**
   * Normalize extracted text before sampling and language detection.
   *
   * Removes replacement/control characters and collapses whitespace so map
   * OCR noise is less likely to confuse detection.
   *
   * @param string $text
   *   Raw extracted text.
   *
   * @return string
   *   Cleaned text.
   */
  public static function cleanText(string $text): string {
    if ($text === '') {
      return '';
    }

    // Drop Unicode replacement characters and C0/C1 controls except
    // newline/tab.
    $text = preg_replace('/\x{FFFD}|[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    // Normalize newlines and collapse horizontal whitespace.
    $text = preg_replace("/[^\S\n\r]+/u", ' ', $text) ?? '';
    $text = preg_replace("/[ \t]*\R[ \t]*/u", "\n", $text) ?? '';
    $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? '';
    return trim($text);
  }

  /**
   * Whether a text sample has enough characters for language detection.
   *
   * @param string $text
   *   Text to analyze.
   * @param int $min_chars
   *   Minimum character length after cleaning.
   *
   * @return bool
   *   TRUE if the cleaned text meets the threshold.
   */
  public static function hasEnoughText(
    string $text,
    int $min_chars = self::DEFAULT_MIN_CHARS,
  ): bool {
    return mb_strlen(static::cleanText($text)) >= max(0, $min_chars);
  }

  /**
   * Build a detection sample from MuPDF stext page spans.
   *
   * @param list<list<array{text: string, x?: float, y?: float, w?: float, h?: float, size?: float}>> $pages
   *   Per-page span lists from FileHelper::extractStructuredTextSpans().
   * @param int $min_line_chars
   *   Minimum characters for a line to be kept.
   * @param int $min_chars
   *   Unused for selection; callers use hasEnoughText on the result. Kept for
   *   a stable API alongside buildSampleFromText.
   * @param int $max_samples
   *   Maximum number of blocks to concatenate.
   *
   * @return string
   *   Concatenated long-line blocks, or empty string when none qualify.
   */
  public static function buildSampleFromSpans(
    array $pages,
    int $min_line_chars = self::DEFAULT_MIN_LINE_CHARS,
    int $min_chars = self::DEFAULT_MIN_CHARS,
    int $max_samples = self::MAX_SAMPLES,
  ): string {
    // $min_chars is applied by callers via hasEnoughText() on the result.
    $lines = [];
    $order = 0;
    foreach ($pages as $page_index => $spans) {
      if (!is_array($spans)) {
        continue;
      }
      foreach ($spans as $span) {
        if (!is_array($span)) {
          continue;
        }
        $text = static::cleanText((string) ($span['text'] ?? ''));
        if ($text === '' || mb_strlen($text) < $min_line_chars) {
          continue;
        }
        $lines[] = [
          'text' => $text,
          'x' => (float) ($span['x'] ?? 0.0),
          'y' => (float) ($span['y'] ?? 0.0),
          'w' => (float) ($span['w'] ?? 0.0),
          'h' => (float) ($span['h'] ?? 0.0),
          'size' => (float) ($span['size'] ?? 0.0),
          'page' => (int) $page_index,
          'order' => $order++,
        ];
      }
    }

    return static::selectBlocks($lines, TRUE, $max_samples);
  }

  /**
   * Build a detection sample from plain text using long lines.
   *
   * @param string $text
   *   Source text (e.g. stripped body HTML).
   * @param int $min_line_chars
   *   Minimum characters for a line to be kept.
   * @param int $min_chars
   *   Unused for selection; callers use hasEnoughText on the result.
   * @param int $max_samples
   *   Maximum number of blocks to concatenate.
   *
   * @return string
   *   Concatenated long-line blocks, or cleaned full text when no long lines.
   */
  public static function buildSampleFromText(
    string $text,
    int $min_line_chars = self::DEFAULT_MIN_LINE_CHARS,
    int $min_chars = self::DEFAULT_MIN_CHARS,
    int $max_samples = self::MAX_SAMPLES,
  ): string {
    // $min_chars is applied by callers via hasEnoughText() on the result.
    $text = static::cleanText($text);
    if ($text === '') {
      return '';
    }

    $raw_lines = preg_split("/\n+/u", $text) ?: [];
    $lines = [];
    $order = 0;
    foreach ($raw_lines as $raw) {
      $line = trim($raw);
      if ($line === '' || mb_strlen($line) < $min_line_chars) {
        continue;
      }
      $lines[] = [
        'text' => $line,
        'x' => 0.0,
        'y' => (float) $order,
        'w' => 0.0,
        'h' => 1.0,
        'size' => 0.0,
        'page' => 0,
        'order' => $order++,
      ];
    }

    if ($lines === []) {
      // No long lines: return cleaned text so callers can still judge length.
      return $text;
    }

    return static::selectBlocks($lines, FALSE, $max_samples);
  }

  /**
   * Pick the longest layout blocks and concatenate them for ELD.
   *
   * Flow: cluster consecutive long lines into paragraphs → keep the
   * $max_samples longest by character count → re-sort those into document
   * order so the sample reads top-to-bottom rather than longest-first.
   *
   * @param list<array{text: string, x: float, y: float, w: float, h: float, size: float, page: int, order: int}> $lines
   *   Filtered long lines in document order.
   * @param bool $use_geometry
   *   Whether to use vertical gap and font size when clustering.
   * @param int $max_samples
   *   Maximum blocks to keep.
   *
   * @return string
   *   Concatenated block text.
   */
  protected static function selectBlocks(
    array $lines,
    bool $use_geometry,
    int $max_samples,
  ): string {
    if ($lines === [] || $max_samples <= 0) {
      return '';
    }

    $blocks = static::clusterLines($lines, $use_geometry);
    if ($blocks === []) {
      return '';
    }

    // Prefer denser prose blocks (more chars) over short captions.
    usort($blocks, static function (array $a, array $b): int {
      return $b['chars'] <=> $a['chars'] ?: $a['order'] <=> $b['order'];
    });
    $selected = array_slice($blocks, 0, $max_samples);
    // Restore reading order for the chosen subset.
    usort($selected, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);

    return implode("\n\n", array_column($selected, 'text'));
  }

  /**
   * Group consecutive long lines into paragraph-like layout blocks.
   *
   * Walks lines in document order. A new block starts when geometry says the
   * next line does not belong with the previous one (or always stays in one
   * run when $use_geometry is FALSE, e.g. plain body text with synthetic
   * coordinates). Each block stores joined text, length, and the first line's
   * order index for later sorting.
   *
   * @param list<array{text: string, x: float, y: float, w: float, h: float, size: float, page: int, order: int}> $lines
   *   Filtered long lines.
   * @param bool $use_geometry
   *   Whether gap/size rules apply.
   *
   * @return list<array{text: string, chars: int, order: int}>
   *   Blocks with joined text and character counts.
   */
  protected static function clusterLines(array $lines, bool $use_geometry): array {
    $blocks = [];
    $current = [];

    // Close the current open block and record its text + length.
    $flush = static function () use (&$blocks, &$current): void {
      if ($current === []) {
        return;
      }
      $texts = array_column($current, 'text');
      $joined = implode("\n", $texts);
      $blocks[] = [
        'text' => $joined,
        'chars' => mb_strlen($joined),
        'order' => $current[0]['order'],
      ];
      $current = [];
    };

    $previous = NULL;
    foreach ($lines as $line) {
      if ($previous === NULL) {
        $current = [$line];
        $previous = $line;
        continue;
      }

      // Without geometry (body text), every long line stays in one contiguous
      // run until flush at the end. ::selectBlocks() then picks by length.
      $same_block = TRUE;
      if ($use_geometry) {
        // PDF spans: split when page changes, vertical gap looks like a new
        // section, or font size jumps (heading vs body).
        if ($line['page'] !== $previous['page']) {
          $same_block = FALSE;
        }
        else {
          $height = max($previous['h'], $line['h'], 1.0);
          $gap = $line['y'] - ($previous['y'] + $previous['h']);
          // Allow small overlap / same-line noise; break on large vertical
          // jumps.
          if ($gap > $height * self::MAX_GAP_HEIGHT_RATIO) {
            $same_block = FALSE;
          }
          // Font size proxy: prefer size, fall back to line height.
          $previous_size = $previous['size'] > 0.0 ? $previous['size'] : $previous['h'];
          $line_size = $line['size'] > 0.0 ? $line['size'] : $line['h'];
          if ($previous_size > 0.0 && $line_size > 0.0) {
            $ratio = abs($line_size - $previous_size) / max($previous_size, $line_size);
            if ($ratio > self::MAX_SIZE_RATIO) {
              $same_block = FALSE;
            }
          }
        }
      }

      if (!$same_block) {
        $flush();
      }
      $current[] = $line;
      $previous = $line;
    }
    $flush();

    return $blocks;
  }

  /**
   * Get a cached language detector for the given Eld database size.
   *
   * @param string $database
   *   Eld ngram database size. Invalid values fall back to medium.
   *
   * @return \Nitotm\Eld\LanguageDetector
   *   The language detector.
   */
  protected static function getLanguageDetector(string $database): LanguageDetector {
    if (!in_array($database, EldDataFile::values(), TRUE)) {
      $database = self::DEFAULT_DATABASE;
    }
    if (!isset(static::$languageDetectors[$database])) {
      static::$languageDetectors[$database] = new LanguageDetector(
        $database,
        EldFormat::ISO639_1,
      );
    }
    return static::$languageDetectors[$database];
  }

}
