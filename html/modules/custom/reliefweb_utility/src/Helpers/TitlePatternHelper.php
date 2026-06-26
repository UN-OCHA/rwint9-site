<?php

declare(strict_types=1);

namespace Drupal\reliefweb_utility\Helpers;

/**
 * Helper for report title series pattern generation and conversion.
 */
class TitlePatternHelper {

  /**
   * Convert a title to SQL LIKE prefix patterns.
   *
   * @param string $title
   *   Document title.
   * @param int[] $token_counts
   *   Token counts for prefix patterns.
   *
   * @return string[]
   *   LIKE patterns, most specific first.
   */
  public static function titleToLikePatterns(string $title, array $token_counts = [10, 8, 6, 4]): array {
    return self::generatePatternList($title, $token_counts);
  }

  /**
   * Convert a title to regex prefix patterns.
   *
   * @param string $title
   *   Document title.
   * @param int[] $token_counts
   *   Token counts for prefix patterns.
   *
   * @return string[]
   *   Regex patterns, most specific first.
   */
  public static function titleToRegexPatterns(string $title, array $token_counts = [10, 8, 6, 4]): array {
    return self::generateRegexPatternList($title, $token_counts);
  }

  /**
   * Generate SQL LIKE prefix patterns from a string.
   *
   * Normalizes the input via stringToLikePattern() before building prefix
   * patterns at the requested token counts.
   *
   * @param string $string
   *   The string to generate patterns for.
   * @param int[] $counts
   *   The number of tokens to include in the pattern.
   * @param string $prefix
   *   Prefix to prepend to the patterns. Defaults to ''.
   * @param string $wildcard
   *   The wildcard to use. Defaults to '%'.
   *
   * @return string[]
   *   The patterns ordered by length in descending order.
   */
  public static function generatePatternList(
    string $string,
    array $counts = [10, 8, 6, 4],
    string $prefix = '',
    string $wildcard = '%',
  ): array {
    $string = self::stringToLikePattern($string, $wildcard);
    return self::buildPatternListFromNormalized($string, $counts, $prefix, $wildcard, FALSE);
  }

  /**
   * Generate regex prefix patterns from a string.
   *
   * @param string $string
   *   Input string.
   * @param int[] $counts
   *   Token counts for prefix patterns.
   * @param string $prefix
   *   Optional prefix.
   *
   * @return string[]
   *   Patterns ordered by length descending.
   */
  public static function generateRegexPatternList(
    string $string,
    array $counts = [10, 8, 6, 4],
    string $prefix = '',
  ): array {
    $wildcard = '.*';
    $string = self::stringToRegexPattern($string);
    return self::buildPatternListFromNormalized($string, $counts, $prefix, $wildcard, TRUE);
  }

  /**
   * Convert a string to a SQL LIKE pattern.
   *
   * Escapes the string for SQL LIKE and replaces variable date and number parts
   * with SQL LIKE wildcards.
   *
   * @param string $string
   *   The string to convert.
   * @param string $wildcard
   *   The wildcard to use. Defaults to '%'.
   *
   * @return string
   *   The SQL LIKE pattern.
   */
  public static function stringToLikePattern(string $string, string $wildcard = '%'): string {
    // Escape the string for SQL LIKE.
    return trim(self::applyDateStripping(self::escapeLike($string), $wildcard));
  }

  /**
   * Convert a string to a regex pattern with date/number wildcards.
   *
   * @param string $string
   *   Input string.
   *
   * @return string
   *   Regex pattern body.
   */
  public static function stringToRegexPattern(string $string): string {
    $wildcard = '.*';
    $stripped = self::applyDateStripping($string, $wildcard);
    $parts = explode($wildcard, $stripped);
    $quoted = array_map(
      static fn(string $part): string => preg_quote($part, '/'),
      $parts,
    );

    return trim(implode($wildcard, $quoted));
  }

  /**
   * Convert a SQL LIKE pattern to a regex body.
   *
   * @param string $likePattern
   *   SQL LIKE pattern using % wildcards.
   *
   * @return string
   *   Regex pattern body without delimiters.
   */
  public static function likePatternToRegex(string $likePattern): string {
    $likePattern = trim($likePattern);
    if ($likePattern === '') {
      return '';
    }

    $parts = self::splitLikePattern($likePattern);
    $regex = '';
    foreach ($parts as $index => $part) {
      if ($part !== '') {
        $regex .= preg_quote($part, '/');
      }
      if ($index < count($parts) - 1) {
        $regex .= '.*';
      }
    }

    if (str_starts_with($likePattern, '%')) {
      $regex = '.*' . $regex;
    }
    if (str_ends_with($likePattern, '%')) {
      $regex .= '.*';
    }

    return $regex;
  }

  /**
   * Check whether a title matches a SQL LIKE pattern.
   *
   * @param string $title
   *   Candidate title.
   * @param string $likePattern
   *   SQL LIKE pattern using % wildcards.
   *
   * @return bool
   *   TRUE if the title matches the pattern.
   */
  public static function titleMatchesLikePattern(string $title, string $likePattern): bool {
    $regex = self::likePatternToRegex($likePattern);
    if ($regex === '') {
      return FALSE;
    }

    return (bool) preg_match('/^' . $regex . '$/iu', $title);
  }

  /**
   * Convert a SQL LIKE pattern to an Elasticsearch title query.
   *
   * @param string $pattern
   *   SQL LIKE pattern using % wildcards.
   *
   * @return string
   *   Title query fragment or empty string.
   */
  public static function likePatternToTitleQuery(string $pattern): string {
    $pattern = trim($pattern);
    if ($pattern === '') {
      return '';
    }

    $parts = self::splitLikePattern($pattern);
    $escaped = [];
    foreach ($parts as $index => $part) {
      if ($part === '') {
        continue;
      }
      $escaped[] = self::escapeLuceneTerm($part);
      if ($index < count($parts) - 1) {
        $escaped[] = '*';
      }
    }

    if ($parts[0] === '') {
      array_unshift($escaped, '*');
    }
    if (str_ends_with($pattern, '%')) {
      $escaped[] = '*';
    }

    $value = implode('', $escaped);
    if ($value === '' || $value === '*') {
      return '';
    }

    return 'title:' . $value;
  }

  /**
   * Tokenize a string into an array of tokens with their byte offsets.
   *
   * Extracts sequences of Unicode word characters (letters and digits),
   * skipping everything else (spaces, punctuation, slashes, hyphens, etc.).
   *
   * @param string $string
   *   The UTF-8 string to tokenize.
   *
   * @return array<int, array{token: string, offset: int}>
   *   Ordered list of tokens. Each entry contains:
   *   - 'token': the token text.
   *   - 'offset': the byte offset at which the token starts in $string.
   *   To find where a token ends (in bytes): offset + strlen(token).
   *   Use substr(), not mb_substr(), when slicing $string with these offsets.
   */
  public static function tokenizeString(string $string): array {
    if ($string === '') {
      return [];
    }

    // Match sequences of Unicode "word" characters (letters, digits).
    // \p{L} = any Unicode letter, \p{N} = any Unicode number.
    if (preg_match_all('/[\p{L}\p{N}]+/u', $string, $matches, PREG_OFFSET_CAPTURE) === FALSE) {
      return [];
    }

    $tokens = [];
    foreach ($matches[0] as [$token, $offset]) {
      $tokens[] = ['token' => $token, 'offset' => $offset];
    }

    return $tokens;
  }

  /**
   * Escape a string for SQL LIKE patterns.
   *
   * @param string $string
   *   Input string.
   *
   * @return string
   *   Escaped string.
   */
  public static function escapeLike(string $string): string {
    return addcslashes($string, '\\%_');
  }

  /**
   * Build prefix patterns from a normalized pattern string.
   *
   * @param string $string
   *   Normalized pattern string.
   * @param int[] $counts
   *   Token counts for prefix patterns.
   * @param string $prefix
   *   Optional prefix.
   * @param string $wildcard
   *   Wildcard string.
   * @param bool $regex_prefix
   *   Whether to preg_quote the prefix instead of SQL LIKE escaping.
   *
   * @return string[]
   *   Patterns ordered by length descending.
   */
  private static function buildPatternListFromNormalized(
    string $string,
    array $counts,
    string $prefix,
    string $wildcard,
    bool $regex_prefix,
  ): array {
    // Tokenize the string.
    $tokens = self::tokenizeString($string);
    if ($tokens === []) {
      return [];
    }

    // Escape the prefix for SQL LIKE (or preg_quote for regex patterns).
    if ($prefix !== '') {
      $prefix = $regex_prefix ? preg_quote($prefix, '/') : self::escapeLike($prefix);
    }

    // Start with the full string.
    $patterns = [$prefix . $string => TRUE];

    // Sort the token counts in descending order.
    rsort($counts, SORT_NUMERIC);

    // Get the smallest count.
    $minimum = min($counts);

    // Generate patterns for the specified counts.
    foreach ($counts as $count) {
      $pattern_tokens = array_slice($tokens, 0, $count);
      if (count($pattern_tokens) < $minimum) {
        break;
      }

      $last_token = end($pattern_tokens);
      $byte_offset = $last_token['offset'] + strlen($last_token['token']);
      $pattern_string = substr($string, 0, $byte_offset);

      // Append a wildcard to the pattern if none is present.
      if (!str_ends_with($pattern_string, $wildcard)) {
        $pattern_string .= $wildcard;
      }

      // Use the pattern as the key to avoid duplicates.
      $patterns[$prefix . $pattern_string] = TRUE;
    }

    // Return the patterns as an array of strings.
    return array_keys($patterns);
  }

  /**
   * Apply multilingual date and number stripping to a string.
   *
   * @param string $string
   *   Input string.
   * @param string $wildcard
   *   Wildcard replacement string.
   *
   * @return string
   *   String with dates and numbers replaced by wildcards.
   */
  private static function applyDateStripping(string $string, string $wildcard): string {
    $months = self::getDateLikePatternMonthAlternation();
    $optional_de = '(?:de\s+)?';
    $optional_le = '(?:le\s+)?';
    $optional_fi = '(?:في\s+)?';
    $day = '(?:1er|1ère|1e|\d{1,2})';

    // Date and number replacements.
    $replacements = [
      // Date patterns (most specific first).
      // Chinese: 2026年4月27日 (no leading \b — CJK text may appear directly
      // before the year).
      '/(?<![0-9])\d{4}年\d{1,2}月\d{1,2}日/u' => $wildcard,

      // Chinese: 2026年4月 or 2026年十二月.
      '/(?<![0-9])\d{4}年(?:' . $months . '|\d{1,2}月)/u' => $wildcard,

      // Numeric day range + month + year: "02 - 06 May 2026".
      '/\b\d{1,2}\s*[-–—]\s*\d{1,2}\s+' . $optional_de . '(?:' . $months . ')\s+' . $optional_de . '\d{4}\b/iu' => $wildcard,

      // Day + month name + year: "27 April 2026", "le 1er avril 2026".
      '/\b' . $optional_le . $optional_fi . $day . '\s+' . $optional_de . '(?:' . $months . ')\s+' . $optional_de . '\d{4}\b/iu' => $wildcard,

      // Month name + year: "December 2025", "diciembre de 2025".
      '/\b(?:' . $months . ')\s+' . $optional_de . '\d{4}\b/iu' => $wildcard,

      // Numeric dates: 2026-04-27, 27/04/2026, 27.04.2026.
      '/\b\d{1,4}[-\/\.]\d{1,2}[-\/\.]\d{2,4}\b/u' => $wildcard,

      // Number patterns.
      // Numeric ranges (not dates): "12-13", "12 - 13", "2024-2025".
      '/\b\d{1,4}\s*[-–—]\s*\d{1,4}\b/u' => $wildcard,

      // Hash-prefixed: #193, #60.
      '/#\d+\w*/u' => $wildcard,

      // Decimal numbers: 93.1, 4.0.
      '/\b\d+\.\d+\b/u' => $wildcard,

      // Label + number: "Week 60", "Update 12", "Tool 5".
      '/\b([A-Za-z]+)\s+\d+\b/u' => '$1 ' . $wildcard,

      // Number + label: "5 Districts", "3 Regions", "2nd Phase".
      '/\b\d+(?:st|nd|rd|th)?\s+([A-Za-z]+)\b/u' => $wildcard . ' $1',

      // Standalone remaining integers.
      '/\b\d+\b/u' => $wildcard,
    ];

    // @todo we should replace common mistaken characters like dashes, mdashes,
    // etc. with a `?` wildcard to be more lenient.
    $result = preg_replace(
      array_keys($replacements),
      array_values($replacements),
      $string,
    );

    // Collapse multiple consecutive wildcards into one.
    $escaped = preg_quote($wildcard, '/');
    $result = preg_replace('/(?:' . $escaped . '\s*){2,}/u', $wildcard . ' ', $result);

    return trim($result ?? '');
  }

  /**
   * Split a SQL LIKE pattern on % wildcards.
   *
   * @param string $likePattern
   *   SQL LIKE pattern.
   *
   * @return string[]
   *   Literal segments.
   */
  private static function splitLikePattern(string $likePattern): array {
    return explode('%', $likePattern);
  }

  /**
   * Escape a term for Lucene query syntax.
   *
   * @param string $term
   *   Raw term.
   *
   * @return string
   *   Escaped term.
   */
  private static function escapeLuceneTerm(string $term): string {
    return preg_replace(
      '/([+\-!(){}\[\]^"~*?:\\\\\/|&]|&&|\|\|)/',
      '\\\\$1',
      $term,
    ) ?? $term;
  }

  /**
   * Returns a regex-safe alternation of month names.
   *
   * Includes full and abbreviated names in English, French, Spanish, Russian,
   * Chinese, and Arabic. Built once per request.
   *
   * @return string
   *   A regex-safe alternation of month names.
   */
  private static function getDateLikePatternMonthAlternation(): string {
    static $alternation = NULL;
    if ($alternation !== NULL) {
      return $alternation;
    }

    $names = [
      // English full.
      'January', 'February', 'March', 'April', 'May', 'June',
      'July', 'August', 'September', 'October', 'November', 'December',
      // English abbreviated.
      'Jan', 'Feb', 'Mar', 'Apr', 'Jun', 'Jul', 'Aug',
      'Sep', 'Sept', 'Oct', 'Nov', 'Dec',
      // French full.
      'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
      'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
      // French abbreviated.
      'janv.', 'févr.', 'avr.', 'juil.', 'sept.', 'oct.', 'nov.', 'déc.',
      // Spanish full.
      'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
      'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
      // Spanish abbreviated.
      'ene.', 'feb.', 'mar.', 'abr.', 'may.', 'jun.',
      'jul.', 'ago.', 'dic.',
      // Russian nominative.
      'январь', 'февраль', 'март', 'апрель', 'май', 'июнь',
      'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь',
      // Russian genitive (e.g. "27 апреля 2026").
      'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
      'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
      // Chinese full and numeric month.
      '一月', '二月', '三月', '四月', '五月', '六月',
      '七月', '八月', '九月', '十月', '十一月', '十二月',
      '1月', '2月', '3月', '4月', '5月', '6月',
      '7月', '8月', '9月', '10月', '11月', '12月',
      // Arabic.
      'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
      'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر',
    ];

    $names = array_values(array_unique($names));
    usort($names, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

    $alternation = implode('|', array_map(
      static fn(string $name): string => preg_quote($name, '/'),
      $names,
    ));

    return $alternation;
  }

}
