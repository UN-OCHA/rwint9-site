<?php

declare(strict_types=1);

namespace Drupal\reliefweb_utility\Helpers;

/**
 * Helper for report title series pattern generation and conversion.
 *
 * @phpstan-type SeriesMarkers array{
 *   stem: string,
 *   issues: int[],
 *   weeks: int[],
 *   periods: list<array{start: string, end: string}>
 * }
 */
class TitlePatternHelper {

  /**
   * Compare series markers: stems are not compatible.
   */
  public const COMPARE_UNRELATED = 'unrelated';

  /**
   * Compare series markers: same series line, different installment.
   */
  public const COMPARE_SERIES_SIBLING = 'series_sibling';

  /**
   * Compare series markers: stems compatible but no disagreeing markers.
   */
  public const COMPARE_INCONCLUSIVE = 'inconclusive';

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
    return trim(self::stripDatesAndNumbers(self::escapeLike($string), $wildcard));
  }

  /**
   * Normalize a title stem for series comparison (no SQL escaping).
   *
   * @param string $title
   *   Document title.
   *
   * @return string
   *   Stem with dates/numbers replaced by % wildcards.
   */
  public static function normalizeSeriesStem(string $title): string {
    return trim(self::stripDatesAndNumbers($title, '%'));
  }

  /**
   * Normalize a patternized stem for similarity scoring.
   *
   * Lowercases, replaces % wildcards with spaces, strips non-alphanumeric
   * characters to spaces, and collapses whitespace so punctuation variants
   * compare equally.
   *
   * @param string $pattern
   *   Patternized title stem (typically from normalizeSeriesStem()).
   *
   * @return string
   *   Normalized comparison string.
   */
  public static function normalizePatternForSimilarity(string $pattern): string {
    $normalized = mb_strtolower($pattern);
    $normalized = str_replace('%', ' ', $normalized);
    $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $normalized) ?? $normalized;
    $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    return trim($normalized);
  }

  /**
   * Scores similarity between two already-patternized title stems.
   *
   * Uses 0.9 * similar_text on normalizePatternForSimilarity() forms plus
   * 0.1 * similar_text on the raw patternized stems so punctuation-preserving
   * near-matches get a small bonus.
   *
   * @param string $a
   *   First patternized stem.
   * @param string $b
   *   Second patternized stem.
   *
   * @return float
   *   Similarity in [0, 1].
   */
  public static function scorePatternSimilarity(string $a, string $b): float {
    if ($a === '' && $b === '') {
      return 1.0;
    }
    if ($a === '' || $b === '') {
      return 0.0;
    }

    $norm_a = self::normalizePatternForSimilarity($a);
    $norm_b = self::normalizePatternForSimilarity($b);
    if ($norm_a === '' && $norm_b === '') {
      return 1.0;
    }
    if ($norm_a === '' || $norm_b === '') {
      return 0.0;
    }

    similar_text($norm_a, $norm_b, $norm_percent);
    similar_text($a, $b, $raw_percent);

    return (0.9 * ((float) $norm_percent / 100.0)) + (0.1 * ((float) $raw_percent / 100.0));
  }

  /**
   * Scores similarity between two document titles after patternizing.
   *
   * @param string $title_a
   *   First title.
   * @param string $title_b
   *   Second title.
   *
   * @return float
   *   Similarity in [0, 1].
   */
  public static function scoreTitleSimilarity(string $title_a, string $title_b): float {
    return self::scorePatternSimilarity(
      self::normalizeSeriesStem($title_a),
      self::normalizeSeriesStem($title_b),
    );
  }

  /**
   * Fingerprint a title for series-title grouping (strip markers, lowercase).
   *
   * @param string $title
   *   Document title.
   *
   * @return string
   *   Lowercased stem with markers removed and whitespace collapsed.
   */
  public static function fingerprintTitle(string $title): string {
    return mb_strtolower(self::stripSeriesMarkers($title, FALSE), 'UTF-8');
  }

  /**
   * Split a title into fuzzy-matchable components on : | dash separators.
   *
   * @param string $title
   *   Document title.
   *
   * @return string[]
   *   Non-empty components (prefers multi-token parts when available).
   */
  public static function splitTitleComponents(string $title): array {
    $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    if ($title === '') {
      return [];
    }

    $stripped = self::stripSeriesMarkers($title, TRUE);
    $parts = preg_split('/\s*[:|]\s*|\s+[-–—]\s+/u', $stripped) ?: [];
    $parts = array_values(array_filter(
      array_map(
        static fn(string $part): string => trim(preg_replace('/\s+/u', ' ', $part) ?? $part),
        $parts,
      ),
      static fn(string $part): bool => mb_strlen($part) >= 4,
    ));

    $multi = array_values(array_filter(
      $parts,
      static fn(string $part): bool => count(preg_split('/\s+/u', $part) ?: []) >= 2,
    ));
    if ($multi !== []) {
      return $multi;
    }
    if ($parts !== []) {
      return $parts;
    }

    $clean = self::stripSeriesMarkers($title, FALSE);
    return $clean !== '' ? [$clean] : [];
  }

  /**
   * First surface date/range substring in text, or NULL.
   *
   * @param string $text
   *   Text that may contain a date.
   *
   * @return string|null
   *   Matched date substring as written.
   */
  public static function extractNearbyDate(string $text): ?string {
    foreach (self::getNearbyDatePatterns() as $pattern) {
      if (preg_match($pattern, $text, $matches) === 1) {
        return trim($matches[0]);
      }
    }
    return NULL;
  }

  /**
   * First surface issue marker substring in text, or NULL.
   *
   * @param string $text
   *   Text that may contain an issue marker.
   *
   * @return string|null
   *   Matched issue substring as written.
   */
  public static function extractNearbyIssue(string $text): ?string {
    $labels = self::getIssueLabelAlternation();
    $patterns = [
      '/#\s*\d+/u',
      '/№\s*\d+/u',
      '/第\s*\d+\s*期/u',
      '/(?<![\p{L}\p{N}])(?:' . $labels . ')\s*#?\s*\d+(?![\p{L}\p{N}])/iu',
    ];
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $text, $matches) === 1) {
        return trim($matches[0]);
      }
    }
    return NULL;
  }

  /**
   * First surface week marker substring in text, or NULL.
   *
   * @param string $text
   *   Text that may contain a week marker.
   *
   * @return string|null
   *   Matched week substring as written.
   */
  public static function extractNearbyWeek(string $text): ?string {
    $labels = self::getWeekLabelAlternation();
    $patterns = [
      '/(?<![\p{L}\p{N}])(?:' . $labels . ')\s+\d+(?![\p{L}\p{N}])/iu',
      '/第\s*\d+\s*周/u',
    ];
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $text, $matches) === 1) {
        return trim($matches[0]);
      }
    }
    return NULL;
  }

  /**
   * Whether a line is only date/issue/week marker content.
   *
   * @param string $text
   *   Line text.
   *
   * @return bool
   *   TRUE when the line collapses after stripping markers.
   */
  public static function isMarkerOnlyLine(string $text): bool {
    $cleaned = trim($text);
    if ($cleaned === '') {
      return FALSE;
    }
    if (self::extractNearbyDate($cleaned) === $cleaned
      || self::extractNearbyIssue($cleaned) === $cleaned
      || self::extractNearbyWeek($cleaned) === $cleaned) {
      return TRUE;
    }
    $remainder = $cleaned;
    if ($date = self::extractNearbyDate($remainder)) {
      $remainder = str_replace($date, ' ', $remainder);
    }
    if ($issue = self::extractNearbyIssue($remainder)) {
      $remainder = str_replace($issue, ' ', $remainder);
    }
    if ($week = self::extractNearbyWeek($remainder)) {
      $remainder = str_replace($week, ' ', $remainder);
    }
    $remainder = preg_replace('/[\s,;|\/_\-–—]+/u', '', $remainder) ?? $remainder;
    return $remainder === '';
  }

  /**
   * Strip dates, issue/week markers, and leftover numbers from a title.
   *
   * @param string $title
   *   Title text.
   * @param bool $keep_separators
   *   When TRUE, keep : | dash separators for component splitting.
   *
   * @return string
   *   Stripped text with whitespace collapsed.
   */
  public static function stripSeriesMarkers(string $title, bool $keep_separators = FALSE): string {
    // Remove issue/week surfaces before number stripping so forms like "# 14"
    // and "№ 5" are not reduced to leftover punctuation (Python order).
    $stripped = $title;
    if ($issue = self::extractNearbyIssue($stripped)) {
      $stripped = str_replace($issue, ' ', $stripped);
    }
    if ($week = self::extractNearbyWeek($stripped)) {
      $stripped = str_replace($week, ' ', $stripped);
    }
    $stripped = self::stripDatesAndNumbers($stripped, ' ');

    if (!$keep_separators) {
      $stripped = preg_replace('/[()|]+/u', ' ', $stripped) ?? $stripped;
      $stripped = preg_replace('/[,;_\/]+/u', ' ', $stripped) ?? $stripped;
      $stripped = preg_replace('/\s*[-–—]+\s*/u', ' ', $stripped) ?? $stripped;
      $stripped = preg_replace('/\s*[:\-–—]\s*$/u', '', $stripped) ?? $stripped;
    }
    else {
      $stripped = preg_replace('/[()]+/u', ' ', $stripped) ?? $stripped;
      $stripped = preg_replace('/,+/u', ' ', $stripped) ?? $stripped;
    }

    $stripped = preg_replace('/\s+/u', ' ', $stripped) ?? $stripped;
    return trim($stripped);
  }

  /**
   * Date patterns used for nearby surface extraction (most specific first).
   *
   * @return string[]
   *   PCRE patterns with delimiters.
   */
  private static function getNearbyDatePatterns(): array {
    $replacements = self::getStripReplacements('%');
    $patterns = [];
    foreach (array_keys($replacements) as $pattern) {
      // Stop before generic number-stripping patterns.
      if (str_contains($pattern, '#\\d+') || str_contains($pattern, '\\b\\d+\\b')) {
        break;
      }
      if (str_contains($pattern, '([A-Za-z]+)\\s+\\d+') || str_contains($pattern, '\\d+(?:st|nd|rd|th)?\\s+([A-Za-z]+)')) {
        break;
      }
      $patterns[] = $pattern;
    }
    return $patterns;
  }

  /**
   * Extract canonical series markers from a title.
   *
   * @param string $title
   *   Document title.
   *
   * @return array{
   *   stem: string,
   *   issues: int[],
   *   weeks: int[],
   *   periods: list<array{start: string, end: string}>
   *   }
   *   Stem plus labeled issues/weeks and inclusive Y-m-d periods.
   */
  public static function extractSeriesMarkers(string $title): array {
    return [
      'stem' => self::normalizeSeriesStem($title),
      'issues' => self::extractIssues($title),
      'weeks' => self::extractWeeks($title),
      'periods' => self::extractPeriods($title),
    ];
  }

  /**
   * Compare two marker payloads from extractSeriesMarkers().
   *
   * @param SeriesMarkers $a
   *   First markers.
   * @param SeriesMarkers $b
   *   Second markers.
   *
   * @return string
   *   One of COMPARE_* constants.
   */
  public static function compareSeriesMarkers(array $a, array $b): string {
    $stem_a = (string) ($a['stem'] ?? '');
    $stem_b = (string) ($b['stem'] ?? '');
    // Differen stems: unrelated.
    if (!self::stemsCompatible($stem_a, $stem_b)) {
      return self::COMPARE_UNRELATED;
    }

    // Normalize the markers.
    $issues_a = self::normalizeIntList($a['issues'] ?? []);
    $issues_b = self::normalizeIntList($b['issues'] ?? []);
    $weeks_a = self::normalizeIntList($a['weeks'] ?? []);
    $weeks_b = self::normalizeIntList($b['weeks'] ?? []);
    $periods_a = self::normalizePeriodList($a['periods'] ?? []);
    $periods_b = self::normalizePeriodList($b['periods'] ?? []);

    // Same stem, different issue numbers: likely series.
    if (self::intListsDisagree($issues_a, $issues_b)) {
      return self::COMPARE_SERIES_SIBLING;
    }
    // Same stem, different week numbers: likely series.
    if (self::intListsDisagree($weeks_a, $weeks_b)) {
      return self::COMPARE_SERIES_SIBLING;
    }
    // Same stem, different periods: likely series.
    if ($periods_a !== [] && $periods_b !== [] && !self::periodsHaveOverlap($periods_a, $periods_b)) {
      return self::COMPARE_SERIES_SIBLING;
    }

    // Unsure about the series relationship.
    return self::COMPARE_INCONCLUSIVE;
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
    $stripped = self::stripDatesAndNumbers($string, $wildcard);
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
   * Strip dates and other volatile title tokens (numbers, hashes, ranges).
   *
   * Used for series LIKE stems and marker scrubbing: dates first, then
   * numeric ranges, issue hashes, decimals, label+number pairs, and bare
   * integers — replaced by the given wildcard.
   *
   * @param string $string
   *   Input string.
   * @param string $wildcard
   *   Wildcard replacement string (e.g. "%" for LIKE stems, " " for
   *   fingerprints).
   *
   * @return string
   *   String with dates and volatile numbers replaced by wildcards.
   */
  private static function stripDatesAndNumbers(string $string, string $wildcard): string {
    $replacements = self::getStripReplacements($wildcard);

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
   * Build strip regex => replacement map (shared source for LIKE stems).
   *
   * @param string $wildcard
   *   Wildcard replacement string.
   *
   * @return array<string, string>
   *   Pattern map, most specific first.
   */
  private static function getStripReplacements(string $wildcard): array {
    $context = self::getDatePatternContext();
    $months = $context['months'];
    $optional_de = $context['optional_de'];
    $optional_le = $context['optional_le'];
    $optional_fi = $context['optional_fi'];
    $day = $context['day'];
    $dash = $context['dash'];

    return [
      // Date patterns (most specific first).
      // Chinese: 2026年4月27日 (no leading \b — CJK text may appear directly
      // before the year).
      '/(?<![0-9])\d{4}年\d{1,2}月\d{1,2}日/u' => $wildcard,

      // Chinese: 2026年4月 or 2026年十二月.
      '/(?<![0-9])\d{4}年(?:' . $months . '|\d{1,2}月)/u' => $wildcard,

      // US month-first range: "May 7, 2026 - May 13, 2026".
      '/\b(?:' . $months . ')\s+' . $day . '\s*,?\s*\d{4}\s*' . $dash . '\s*(?:' . $months . ')\s+' . $day . '\s*,?\s*\d{4}\b/iu' => $wildcard,

      // US same-month shorthand: "May 7-13, 2026".
      '/\b(?:' . $months . ')\s+' . $day . '\s*' . $dash . '\s*' . $day . '\s*,?\s*\d{4}\b/iu' => $wildcard,

      // US month-first single: "May 7, 2026".
      '/\b(?:' . $months . ')\s+' . $day . '\s*,?\s*\d{4}\b/iu' => $wildcard,

      // FR cross-month: "du 30 avril au 6 mai 2026".
      '/\bdu\s+' . $day . '\s+(?:' . $months . ')\s+au\s+' . $day . '\s+(?:' . $months . ')\s+\d{4}\b/iu' => $wildcard,

      // FR same-month: "du 7 au 13 mai 2026".
      '/\bdu\s+' . $day . '\s+au\s+' . $day . '\s+(?:' . $months . ')\s+\d{4}\b/iu' => $wildcard,

      // ES cross-month: "del 30 de abril al 6 de mayo de 2026".
      '/\bdel\s+' . $day . '\s+de\s+(?:' . $months . ')\s+al\s+' . $day . '\s+de\s+(?:' . $months . ')\s+de\s+\d{4}\b/iu' => $wildcard,

      // ES same-month: "del 7 al 13 de mayo de 2026".
      '/\bdel\s+' . $day . '\s+al\s+' . $day . '\s+de\s+(?:' . $months . ')\s+de\s+\d{4}\b/iu' => $wildcard,

      // PT cross-month: "de 30 de abril a 6 de maio de 2026".
      '/\bde\s+' . $day . '\s+de\s+(?:' . $months . ')\s+a\s+' . $day . '\s+de\s+(?:' . $months . ')\s+de\s+\d{4}\b/iu' => $wildcard,

      // PT same-month: "de 7 a 13 de maio de 2026".
      '/\bde\s+' . $day . '\s+a\s+' . $day . '\s+de\s+(?:' . $months . ')\s+de\s+\d{4}\b/iu' => $wildcard,

      // Dual full day+month+year range: "7 May 2026 - 13 May 2026".
      '/\b' . $optional_le . $optional_fi . $day . '\s+' . $optional_de . '(?:' . $months . ')\s+' . $optional_de . '\d{4}\s*' . $dash . '\s*' . $optional_le . $optional_fi . $day . '\s+' . $optional_de . '(?:' . $months . ')\s+' . $optional_de . '\d{4}\b/iu' => $wildcard,

      // Cross-month day range, year once: "30 April - 6 May 2026".
      '/\b' . $day . '\s+' . $optional_de . '(?:' . $months . ')\s*' . $dash . '\s*' . $day . '\s+' . $optional_de . '(?:' . $months . ')\s+' . $optional_de . '\d{4}\b/iu' => $wildcard,

      // Numeric day range + month + year: "02 - 06 May 2026".
      '/\b\d{1,2}\s*' . $dash . '\s*\d{1,2}\s+' . $optional_de . '(?:' . $months . ')\s+' . $optional_de . '\d{4}\b/iu' => $wildcard,

      // Day + month name + year: "27 April 2026", "le 1er avril 2026".
      '/\b' . $optional_le . $optional_fi . $day . '\s+' . $optional_de . '(?:' . $months . ')\s+' . $optional_de . '\d{4}\b/iu' => $wildcard,

      // Month range + year: "Jan-Mar 2026", "October - December 2025".
      '/\b(?:' . $months . ')\s*' . $dash . '\s*(?:' . $months . ')\s+' . $optional_de . '\d{4}\b/iu' => $wildcard,

      // Month name + year: "December 2025", "diciembre de 2025".
      '/\b(?:' . $months . ')\s+' . $optional_de . '\d{4}\b/iu' => $wildcard,

      // Numeric dates: 2026-04-27, 27/04/2026, 27.04.2026.
      '/\b\d{1,4}[-\/\.]\d{1,2}[-\/\.]\d{2,4}\b/u' => $wildcard,

      // Number patterns.
      // Numeric ranges (not dates): "12-13", "12 - 13", "2024-2025".
      '/\b\d{1,4}\s*' . $dash . '\s*\d{1,4}\b/u' => $wildcard,

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
  }

  /**
   * Shared month/day regex fragments for strip and extract.
   *
   * @return array{
   *   months: string,
   *   optional_de: string,
   *   optional_le: string,
   *   optional_fi: string,
   *   day: string,
   *   dash: string
   *   }
   *   Regex fragments.
   */
  private static function getDatePatternContext(): array {
    return [
      'months' => self::getDateLikePatternMonthAlternation(),
      'optional_de' => '(?:de\s+)?',
      'optional_le' => '(?:le\s+)?',
      'optional_fi' => '(?:في\s+)?',
      'day' => '(?:1er|1ère|1e|\d{1,2})',
      'dash' => '[-–—]',
    ];
  }

  /**
   * Extract labeled issue numbers from a title.
   *
   * @param string $title
   *   Title.
   *
   * @return int[]
   *   Sorted unique issue numbers.
   */
  private static function extractIssues(string $title): array {
    $issues = [];
    if (preg_match_all('/#\s*(\d+)(?![\p{L}\p{N}])/u', $title, $matches)) {
      foreach ($matches[1] as $value) {
        $issues[] = (int) $value;
      }
    }
    // №189 (common in RU titles).
    if (preg_match_all('/№\s*(\d+)(?![\p{L}\p{N}])/u', $title, $matches)) {
      foreach ($matches[1] as $value) {
        $issues[] = (int) $value;
      }
    }
    // Label + number (EN/FR/ES/DE/PT/RU/AR). Use Unicode boundaries: \b is
    // weak for non-Latin scripts.
    $labels = self::getIssueLabelAlternation();
    $pattern = '/(?<![\p{L}\p{N}])(?:' . $labels . ')\s*#?\s*(\d+)(?![\p{L}\p{N}])/iu';
    if (preg_match_all($pattern, $title, $matches)) {
      foreach ($matches[1] as $value) {
        $issues[] = (int) $value;
      }
    }
    // Chinese: 第12期.
    if (preg_match_all('/第\s*(\d+)\s*期/u', $title, $matches)) {
      foreach ($matches[1] as $value) {
        $issues[] = (int) $value;
      }
    }
    return self::normalizeIntList($issues);
  }

  /**
   * Extract labeled week numbers from a title.
   *
   * @param string $title
   *   Title.
   *
   * @return int[]
   *   Sorted unique week numbers.
   */
  private static function extractWeeks(string $title): array {
    $weeks = [];
    $labels = self::getWeekLabelAlternation();
    $pattern = '/(?<![\p{L}\p{N}])(?:' . $labels . ')\s+(\d+)(?![\p{L}\p{N}])/iu';
    if (preg_match_all($pattern, $title, $matches)) {
      foreach ($matches[1] as $value) {
        $weeks[] = (int) $value;
      }
    }
    // Chinese: 第2周 / 第 2 周.
    if (preg_match_all('/第\s*(\d+)\s*周/u', $title, $matches)) {
      foreach ($matches[1] as $value) {
        $weeks[] = (int) $value;
      }
    }
    return self::normalizeIntList($weeks);
  }

  /**
   * Regex alternation for issue labels (excluding # / № / 第N期).
   *
   * @return string
   *   Alternation suitable for a Unicode-aware pattern.
   */
  private static function getIssueLabelAlternation(): string {
    $labels = [
      // EN. Require a dot on "no." so bare "no" is not an issue label.
      'issue', 'no\.', 'number', 'n°',
      // FR.
      'numéro', 'édition', 'num\.',
      // ES / PT.
      'número', 'núm\.?', 'edición', 'edição',
      // DE.
      'ausgabe', 'nummer', 'nr\.?',
      // RU.
      'выпуск', 'номер',
      // AR.
      'عدد', 'إصدار',
    ];
    return implode('|', $labels);
  }

  /**
   * Regex alternation for week labels (excluding Chinese 第N周).
   *
   * @return string
   *   Alternation suitable for a Unicode-aware pattern.
   */
  private static function getWeekLabelAlternation(): string {
    $labels = [
      // EN.
      'week', 'wk\.?',
      // FR.
      'semaine',
      // ES / PT.
      'semana',
      // DE.
      'woche',
      // RU.
      'неделя', 'нед\.?',
      // AR.
      'أسبوع',
    ];
    return implode('|', $labels);
  }

  /**
   * Extract calendar phrases as inclusive Y-m-d periods.
   *
   * Matches most-specific patterns first and blanks them out so broader
   * month/year patterns do not double-count the same phrase.
   *
   * @param string $title
   *   Title.
   *
   * @return list<array{start: string, end: string}>
   *   Sorted unique periods.
   */
  private static function extractPeriods(string $title): array {
    $context = self::getDatePatternContext();
    $months = $context['months'];
    $optional_de = $context['optional_de'];
    $optional_le = $context['optional_le'];
    $optional_fi = $context['optional_fi'];
    $day = $context['day'];
    $dash = $context['dash'];

    $remaining = $title;
    $periods = [];

    $consume = static function (string $regex, callable $handler) use (&$remaining, &$periods): void {
      if (preg_match_all($regex, $remaining, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === FALSE) {
        return;
      }
      // Blank from the end so offsets stay valid.
      for ($i = count($matches) - 1; $i >= 0; $i--) {
        $match = $matches[$i];
        $full = $match[0][0];
        $offset = $match[0][1];
        $groups = [];
        foreach ($match as $index => $part) {
          if ($index === 0) {
            continue;
          }
          $groups[$index] = $part[0];
        }
        $period = $handler($groups, $full);
        if ($period !== NULL) {
          $periods[] = $period;
          $remaining = substr_replace($remaining, str_repeat(' ', strlen($full)), $offset, strlen($full));
        }
      }
    };

    // Chinese: 2026年4月27日.
    $consume('/(?<![0-9])(\d{4})年(\d{1,2})月(\d{1,2})日/u', static function (array $g): ?array {
      return self::periodFromYmd((int) $g[1], (int) $g[2], (int) $g[3]);
    });

    // Chinese: 2026年4月 or 2026年十二月.
    $consume('/(?<![0-9])(\d{4})年(' . $months . '|\d{1,2}月)/u', static function (array $g): ?array {
      $month = self::parseMonthToken($g[2]);
      return $month === NULL ? NULL : self::periodFromMonthYear((int) $g[1], $month);
    });

    // US month-first range: "May 7, 2026 - May 13, 2026".
    $consume(
      '/\b(' . $months . ')\s+(' . $day . ')\s*,?\s*(\d{4})\s*' . $dash . '\s*(' . $months . ')\s+(' . $day . ')\s*,?\s*(\d{4})\b/iu',
      static function (array $g): ?array {
        $month_start = self::parseMonthToken($g[1]);
        $day_start = self::parseDayToken($g[2]);
        $month_end = self::parseMonthToken($g[4]);
        $day_end = self::parseDayToken($g[5]);
        if ($month_start === NULL || $day_start === NULL || $month_end === NULL || $day_end === NULL) {
          return NULL;
        }
        return self::mergeDayPeriods(
          self::periodFromYmd((int) $g[3], $month_start, $day_start),
          self::periodFromYmd((int) $g[6], $month_end, $day_end),
        );
      },
    );

    // US same-month shorthand: "May 7-13, 2026".
    $consume(
      '/\b(' . $months . ')\s+(' . $day . ')\s*' . $dash . '\s*(' . $day . ')\s*,?\s*(\d{4})\b/iu',
      static function (array $g): ?array {
        $month = self::parseMonthToken($g[1]);
        $day_start = self::parseDayToken($g[2]);
        $day_end = self::parseDayToken($g[3]);
        $year = (int) $g[4];
        if ($month === NULL || $day_start === NULL || $day_end === NULL) {
          return NULL;
        }
        return self::mergeDayPeriods(
          self::periodFromYmd($year, $month, $day_start),
          self::periodFromYmd($year, $month, $day_end),
        );
      },
    );

    // US month-first single: "May 7, 2026".
    $consume(
      '/\b(' . $months . ')\s+(' . $day . ')\s*,?\s*(\d{4})\b/iu',
      static function (array $g): ?array {
        $month = self::parseMonthToken($g[1]);
        $day_num = self::parseDayToken($g[2]);
        if ($month === NULL || $day_num === NULL) {
          return NULL;
        }
        return self::periodFromYmd((int) $g[3], $month, $day_num);
      },
    );

    // FR cross-month: "du 30 avril au 6 mai 2026".
    $consume(
      '/\bdu\s+(' . $day . ')\s+(' . $months . ')\s+au\s+(' . $day . ')\s+(' . $months . ')\s+(\d{4})\b/iu',
      static function (array $g): ?array {
        $day_start = self::parseDayToken($g[1]);
        $month_start = self::parseMonthToken($g[2]);
        $day_end = self::parseDayToken($g[3]);
        $month_end = self::parseMonthToken($g[4]);
        $year = (int) $g[5];
        if ($day_start === NULL || $month_start === NULL || $day_end === NULL || $month_end === NULL) {
          return NULL;
        }
        return self::mergeDayPeriods(
          self::periodFromYmd($year, $month_start, $day_start),
          self::periodFromYmd($year, $month_end, $day_end),
          TRUE,
        );
      },
    );

    // FR same-month: "du 7 au 13 mai 2026".
    $consume(
      '/\bdu\s+(' . $day . ')\s+au\s+(' . $day . ')\s+(' . $months . ')\s+(\d{4})\b/iu',
      static function (array $g): ?array {
        $day_start = self::parseDayToken($g[1]);
        $day_end = self::parseDayToken($g[2]);
        $month = self::parseMonthToken($g[3]);
        $year = (int) $g[4];
        if ($day_start === NULL || $day_end === NULL || $month === NULL) {
          return NULL;
        }
        return self::mergeDayPeriods(
          self::periodFromYmd($year, $month, $day_start),
          self::periodFromYmd($year, $month, $day_end),
        );
      },
    );

    // ES cross-month: "del 30 de abril al 6 de mayo de 2026".
    $consume(
      '/\bdel\s+(' . $day . ')\s+de\s+(' . $months . ')\s+al\s+(' . $day . ')\s+de\s+(' . $months . ')\s+de\s+(\d{4})\b/iu',
      static function (array $g): ?array {
        $day_start = self::parseDayToken($g[1]);
        $month_start = self::parseMonthToken($g[2]);
        $day_end = self::parseDayToken($g[3]);
        $month_end = self::parseMonthToken($g[4]);
        $year = (int) $g[5];
        if ($day_start === NULL || $month_start === NULL || $day_end === NULL || $month_end === NULL) {
          return NULL;
        }
        return self::mergeDayPeriods(
          self::periodFromYmd($year, $month_start, $day_start),
          self::periodFromYmd($year, $month_end, $day_end),
          TRUE,
        );
      },
    );

    // ES same-month: "del 7 al 13 de mayo de 2026".
    $consume(
      '/\bdel\s+(' . $day . ')\s+al\s+(' . $day . ')\s+de\s+(' . $months . ')\s+de\s+(\d{4})\b/iu',
      static function (array $g): ?array {
        $day_start = self::parseDayToken($g[1]);
        $day_end = self::parseDayToken($g[2]);
        $month = self::parseMonthToken($g[3]);
        $year = (int) $g[4];
        if ($day_start === NULL || $day_end === NULL || $month === NULL) {
          return NULL;
        }
        return self::mergeDayPeriods(
          self::periodFromYmd($year, $month, $day_start),
          self::periodFromYmd($year, $month, $day_end),
        );
      },
    );

    // PT cross-month: "de 30 de abril a 6 de maio de 2026".
    $consume(
      '/\bde\s+(' . $day . ')\s+de\s+(' . $months . ')\s+a\s+(' . $day . ')\s+de\s+(' . $months . ')\s+de\s+(\d{4})\b/iu',
      static function (array $g): ?array {
        $day_start = self::parseDayToken($g[1]);
        $month_start = self::parseMonthToken($g[2]);
        $day_end = self::parseDayToken($g[3]);
        $month_end = self::parseMonthToken($g[4]);
        $year = (int) $g[5];
        if ($day_start === NULL || $month_start === NULL || $day_end === NULL || $month_end === NULL) {
          return NULL;
        }
        return self::mergeDayPeriods(
          self::periodFromYmd($year, $month_start, $day_start),
          self::periodFromYmd($year, $month_end, $day_end),
          TRUE,
        );
      },
    );

    // PT same-month: "de 7 a 13 de maio de 2026".
    $consume(
      '/\bde\s+(' . $day . ')\s+a\s+(' . $day . ')\s+de\s+(' . $months . ')\s+de\s+(\d{4})\b/iu',
      static function (array $g): ?array {
        $day_start = self::parseDayToken($g[1]);
        $day_end = self::parseDayToken($g[2]);
        $month = self::parseMonthToken($g[3]);
        $year = (int) $g[4];
        if ($day_start === NULL || $day_end === NULL || $month === NULL) {
          return NULL;
        }
        return self::mergeDayPeriods(
          self::periodFromYmd($year, $month, $day_start),
          self::periodFromYmd($year, $month, $day_end),
        );
      },
    );

    // Dual full day+month+year range: "7 May 2026 - 13 May 2026".
    $consume(
      '/\b' . $optional_le . $optional_fi . '(' . $day . ')\s+' . $optional_de . '(' . $months . ')\s+' . $optional_de . '(\d{4})\s*' . $dash . '\s*' . $optional_le . $optional_fi . '(' . $day . ')\s+' . $optional_de . '(' . $months . ')\s+' . $optional_de . '(\d{4})\b/iu',
      static function (array $g): ?array {
        $day_start = self::parseDayToken($g[1]);
        $month_start = self::parseMonthToken($g[2]);
        $day_end = self::parseDayToken($g[4]);
        $month_end = self::parseMonthToken($g[5]);
        if ($day_start === NULL || $month_start === NULL || $day_end === NULL || $month_end === NULL) {
          return NULL;
        }
        return self::mergeDayPeriods(
          self::periodFromYmd((int) $g[3], $month_start, $day_start),
          self::periodFromYmd((int) $g[6], $month_end, $day_end),
        );
      },
    );

    // Cross-month day range, year once: "30 April - 6 May 2026".
    $consume(
      '/\b(' . $day . ')\s+' . $optional_de . '(' . $months . ')\s*' . $dash . '\s*(' . $day . ')\s+' . $optional_de . '(' . $months . ')\s+' . $optional_de . '(\d{4})\b/iu',
      static function (array $g): ?array {
        $day_start = self::parseDayToken($g[1]);
        $month_start = self::parseMonthToken($g[2]);
        $day_end = self::parseDayToken($g[3]);
        $month_end = self::parseMonthToken($g[4]);
        $year = (int) $g[5];
        if ($day_start === NULL || $month_start === NULL || $day_end === NULL || $month_end === NULL) {
          return NULL;
        }
        return self::mergeDayPeriods(
          self::periodFromYmd($year, $month_start, $day_start),
          self::periodFromYmd($year, $month_end, $day_end),
          TRUE,
        );
      },
    );

    // Numeric day range + month + year: "02 - 06 May 2026".
    $consume(
      '/\b(\d{1,2})\s*' . $dash . '\s*(\d{1,2})\s+' . $optional_de . '(' . $months . ')\s+' . $optional_de . '(\d{4})\b/iu',
      static function (array $g): ?array {
        $month = self::parseMonthToken($g[3]);
        $year = (int) $g[4];
        if ($month === NULL) {
          return NULL;
        }
        return self::mergeDayPeriods(
          self::periodFromYmd($year, $month, (int) $g[1]),
          self::periodFromYmd($year, $month, (int) $g[2]),
        );
      },
    );

    // Day + month name + year: "27 April 2026", "le 1er avril 2026".
    $consume(
      '/\b' . $optional_le . $optional_fi . '(' . $day . ')\s+' . $optional_de . '(' . $months . ')\s+' . $optional_de . '(\d{4})\b/iu',
      static function (array $g): ?array {
        $month = self::parseMonthToken($g[2]);
        $day_num = self::parseDayToken($g[1]);
        if ($month === NULL || $day_num === NULL) {
          return NULL;
        }
        return self::periodFromYmd((int) $g[3], $month, $day_num);
      },
    );

    // Month range + year: "Jan-Mar 2026", "Nov-Feb 2026" (wraps into next
    // year).
    $consume(
      '/\b(' . $months . ')\s*' . $dash . '\s*(' . $months . ')\s+' . $optional_de . '(\d{4})\b/iu',
      static function (array $g): ?array {
        $month_start = self::parseMonthToken($g[1]);
        $month_end = self::parseMonthToken($g[2]);
        $year = (int) $g[3];
        if ($month_start === NULL || $month_end === NULL) {
          return NULL;
        }
        $end_year = $month_start > $month_end ? $year + 1 : $year;
        $start = self::periodFromMonthYear($year, $month_start);
        $end = self::periodFromMonthYear($end_year, $month_end);
        if ($start === NULL || $end === NULL) {
          return NULL;
        }
        return [
          'start' => $start['start'],
          'end' => $end['end'],
        ];
      },
    );

    // Month name + year: "December 2025".
    $consume(
      '/\b(' . $months . ')\s+' . $optional_de . '(\d{4})\b/iu',
      static function (array $g): ?array {
        $month = self::parseMonthToken($g[1]);
        return $month === NULL ? NULL : self::periodFromMonthYear((int) $g[2], $month);
      },
    );

    // Numeric dates: 2026-04-27, 27/04/2026, 27.04.2026.
    $consume('/\b(\d{1,4}[-\/\.]\d{1,2}[-\/\.]\d{2,4})\b/u', static function (array $g): ?array {
      return self::parseNumericDate($g[1]);
    });

    return self::normalizePeriodList($periods);
  }

  /**
   * Merge two single-day periods into an inclusive range.
   *
   * @param array{start: string, end: string}|null $start
   *   Start day period.
   * @param array{start: string, end: string}|null $end
   *   End day period.
   * @param bool $wrap_start_year
   *   When TRUE and start is after end (year shared on the end date only),
   *   move the start back one year (e.g. 30 Dec - 5 Jan 2026).
   *
   * @return array{start: string, end: string}|null
   *   Merged period or NULL.
   */
  private static function mergeDayPeriods(?array $start, ?array $end, bool $wrap_start_year = FALSE): ?array {
    if ($start === NULL || $end === NULL) {
      return NULL;
    }
    if ($wrap_start_year && $start['start'] > $end['end']) {
      [$year, $month, $day] = array_map('intval', explode('-', $start['start']));
      $start = self::periodFromYmd($year - 1, $month, $day);
      if ($start === NULL) {
        return NULL;
      }
    }
    return [
      'start' => min($start['start'], $end['start']),
      'end' => max($start['end'], $end['end']),
    ];
  }

  /**
   * Parse a numeric date string into a single-day period.
   *
   * @param string $raw
   *   Raw date token.
   *
   * @return array{start: string, end: string}|null
   *   Period or NULL.
   */
  private static function parseNumericDate(string $raw): ?array {
    $raw = trim($raw);
    // Prefer ISO / year-first, then d/m/Y (RW-dominant), then m/d/Y.
    $formats = ['Y-m-d', 'Y/m/d', 'Y.m.d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'm/d/Y', 'm-d-Y', 'm.d.Y'];
    foreach ($formats as $format) {
      $date = \DateTimeImmutable::createFromFormat('!' . $format, $raw);
      if ($date instanceof \DateTimeImmutable) {
        $errors = \DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
          continue;
        }
        $ymd = $date->format('Y-m-d');
        return ['start' => $ymd, 'end' => $ymd];
      }
    }
    return NULL;
  }

  /**
   * Build a single-day period from Y-m-d parts.
   *
   * @param int $year
   *   Year.
   * @param int $month
   *   Month 1-12.
   * @param int $day
   *   Day.
   *
   * @return array{start: string, end: string}|null
   *   Period or NULL if invalid.
   */
  private static function periodFromYmd(int $year, int $month, int $day): ?array {
    if (!checkdate($month, $day, $year)) {
      return NULL;
    }
    $ymd = sprintf('%04d-%02d-%02d', $year, $month, $day);
    return ['start' => $ymd, 'end' => $ymd];
  }

  /**
   * Build a full-month period.
   *
   * @param int $year
   *   Year.
   * @param int $month
   *   Month 1-12.
   *
   * @return array{start: string, end: string}|null
   *   Period or NULL if invalid.
   */
  private static function periodFromMonthYear(int $year, int $month): ?array {
    if ($month < 1 || $month > 12 || $year < 1) {
      return NULL;
    }
    $start = sprintf('%04d-%02d-01', $year, $month);
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $start);
    if (!$date instanceof \DateTimeImmutable) {
      return NULL;
    }
    return [
      'start' => $start,
      'end' => $date->modify('last day of this month')->format('Y-m-d'),
    ];
  }

  /**
   * Resolve a month name or numeric token to 1-12.
   *
   * @param string $token
   *   Month token (possibly with 月 suffix).
   *
   * @return int|null
   *   Month number or NULL.
   */
  private static function parseMonthToken(string $token): ?int {
    $token = trim($token);
    if (preg_match('/^(\d{1,2})月$/u', $token, $matches)) {
      $month = (int) $matches[1];
      return ($month >= 1 && $month <= 12) ? $month : NULL;
    }
    if (preg_match('/^\d{1,2}$/', $token)) {
      $month = (int) $token;
      return ($month >= 1 && $month <= 12) ? $month : NULL;
    }
    $map = self::getMonthNumberMap();
    $key = mb_strtolower($token, 'UTF-8');
    return $map[$key] ?? NULL;
  }

  /**
   * Resolve a day token (including French 1er) to 1-31.
   *
   * @param string $token
   *   Day token.
   *
   * @return int|null
   *   Day number or NULL.
   */
  private static function parseDayToken(string $token): ?int {
    $token = trim(mb_strtolower($token, 'UTF-8'));
    if (in_array($token, ['1er', '1ère', '1e'], TRUE)) {
      return 1;
    }
    if (preg_match('/^\d{1,2}$/', $token)) {
      $day = (int) $token;
      return ($day >= 1 && $day <= 31) ? $day : NULL;
    }
    return NULL;
  }

  /**
   * Month name (lowercased) to number map.
   *
   * @return array<string, int>
   *   Map.
   */
  private static function getMonthNumberMap(): array {
    static $map = NULL;
    if ($map !== NULL) {
      return $map;
    }

    $groups = [
      1 => [
        'January', 'Jan', 'janvier', 'janv.', 'enero', 'ene.',
        'Januar', 'Jan.', 'janeiro', 'jan.',
        'январь', 'января', '一月', 'يناير',
      ],
      2 => [
        'February', 'Feb', 'février', 'févr.', 'febrero', 'feb.',
        'Februar', 'Feb.', 'fevereiro', 'fev.',
        'февраль', 'февраля', '二月', 'فبراير',
      ],
      3 => [
        'March', 'Mar', 'mars', 'marzo', 'mar.',
        'März', 'Mrz.', 'março',
        'март', 'марта', '三月', 'مارس',
      ],
      4 => [
        'April', 'Apr', 'avril', 'avr.', 'abril', 'abr.',
        'Apr.',
        'апрель', 'апреля', '四月', 'أبريل',
      ],
      5 => [
        'May', 'mai', 'mayo', 'may.',
        'Mai', 'maio', 'mai.',
        'май', 'мая', '五月', 'مايو',
      ],
      6 => [
        'June', 'Jun', 'juin', 'junio', 'jun.',
        'Juni', 'Jun.', 'junho',
        'июнь', 'июня', '六月', 'يونيو',
      ],
      7 => [
        'July', 'Jul', 'juillet', 'juil.', 'julio', 'jul.',
        'Juli', 'Jul.', 'julho',
        'июль', 'июля', '七月', 'يوليو',
      ],
      8 => [
        'August', 'Aug', 'août', 'agosto', 'ago.',
        'Aug.',
        'август', 'августа', '八月', 'أغسطس',
      ],
      9 => [
        'September', 'Sep', 'Sept', 'septembre', 'sept.', 'septiembre',
        'Sept.', 'setembro', 'set.',
        'сентябрь', 'сентября', '九月', 'سبتمبر',
      ],
      10 => [
        'October', 'Oct', 'octobre', 'oct.', 'octubre',
        'Oktober', 'Okt.', 'outubro', 'out.',
        'октябрь', 'октября', '十月', 'أكتوبر',
      ],
      11 => [
        'November', 'Nov', 'novembre', 'nov.', 'noviembre',
        'Nov.', 'novembro',
        'ноябрь', 'ноября', '十一月', 'نوفمبر',
      ],
      12 => [
        'December', 'Dec', 'décembre', 'déc.', 'diciembre', 'dic.',
        'Dezember', 'Dez.', 'dezembro', 'dez.',
        'декабрь', 'декабря', '十二月', 'ديسمبر',
      ],
    ];

    $map = [];
    foreach ($groups as $number => $names) {
      foreach ($names as $name) {
        $map[mb_strtolower($name, 'UTF-8')] = $number;
      }
    }
    return $map;
  }

  /**
   * Whether two stems look like the same series line.
   */
  private static function stemsCompatible(string $stem_a, string $stem_b): bool {
    if (!self::stemHasLiteralContent($stem_a) || !self::stemHasLiteralContent($stem_b)) {
      return FALSE;
    }
    if ($stem_a === $stem_b) {
      return TRUE;
    }
    // Treat commas as optional so "Summary, %" and "Summary %" stay compatible
    // when source titles omit the comma before a date.
    if (self::stemCompatibilityKey($stem_a) === self::stemCompatibilityKey($stem_b)) {
      return TRUE;
    }
    return self::titleMatchesLikePattern($stem_b, $stem_a)
      || self::titleMatchesLikePattern($stem_a, $stem_b);
  }

  /**
   * Normalize a stem for compatibility checks (ignore commas / extra spaces).
   */
  private static function stemCompatibilityKey(string $stem): string {
    $key = str_replace(',', ' ', $stem);
    $key = preg_replace('/\s+/u', ' ', $key) ?? $key;
    return trim($key);
  }

  /**
   * Whether a stem has non-wildcard literal content.
   */
  private static function stemHasLiteralContent(string $stem): bool {
    $literal = trim(str_replace('%', '', $stem));
    return $literal !== '';
  }

  /**
   * Whether two non-empty int lists disagree.
   *
   * @param int[] $a
   *   First list (normalized).
   * @param int[] $b
   *   Second list (normalized).
   */
  private static function intListsDisagree(array $a, array $b): bool {
    return $a !== [] && $b !== [] && $a !== $b;
  }

  /**
   * Whether any period in A overlaps any period in B.
   *
   * @param list<array{start: string, end: string}> $a
   *   First periods.
   * @param list<array{start: string, end: string}> $b
   *   Second periods.
   */
  private static function periodsHaveOverlap(array $a, array $b): bool {
    foreach ($a as $period_a) {
      foreach ($b as $period_b) {
        if ($period_a['start'] <= $period_b['end'] && $period_b['start'] <= $period_a['end']) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Deduplicate and sort positive integers.
   *
   * @param mixed $values
   *   Raw values.
   *
   * @return int[]
   *   Normalized list.
   */
  private static function normalizeIntList(mixed $values): array {
    if (!is_array($values)) {
      return [];
    }
    $out = [];
    foreach ($values as $value) {
      if (is_int($value) || (is_string($value) && ctype_digit($value))) {
        $int = (int) $value;
        if ($int > 0) {
          $out[$int] = $int;
        }
      }
    }
    $list = array_values($out);
    sort($list, SORT_NUMERIC);
    return $list;
  }

  /**
   * Deduplicate and sort period ranges.
   *
   * @param mixed $values
   *   Raw periods.
   *
   * @return list<array{start: string, end: string}>
   *   Normalized periods.
   */
  private static function normalizePeriodList(mixed $values): array {
    if (!is_array($values)) {
      return [];
    }
    $keyed = [];
    foreach ($values as $value) {
      if (!is_array($value)) {
        continue;
      }
      $start = isset($value['start']) ? (string) $value['start'] : '';
      $end = isset($value['end']) ? (string) $value['end'] : '';
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        continue;
      }
      if ($start > $end) {
        [$start, $end] = [$end, $start];
      }
      $keyed[$start . '|' . $end] = ['start' => $start, 'end' => $end];
    }
    $list = array_values($keyed);
    usort($list, static function (array $left, array $right): int {
      return [$left['start'], $left['end']] <=> [$right['start'], $right['end']];
    });
    return $list;
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
   * Includes full and abbreviated names in English, French, Spanish, German,
   * Portuguese, Russian, Chinese, and Arabic. Built once per request.
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
      // German full.
      'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
      'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
      // German abbreviated.
      'Jan.', 'Feb.', 'Mrz.', 'Apr.', 'Jun.', 'Jul.',
      'Aug.', 'Sept.', 'Okt.', 'Nov.', 'Dez.',
      // Portuguese full.
      'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
      'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro',
      // Portuguese abbreviated.
      'jan.', 'fev.', 'mar.', 'abr.', 'mai.', 'jun.',
      'jul.', 'ago.', 'set.', 'out.', 'nov.', 'dez.',
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
