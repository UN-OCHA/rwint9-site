<?php

declare(strict_types=1);

namespace Drupal\reliefweb_utility\Helpers;

/**
 * Layout-aware series title matching for PDF structured spans.
 *
 * PHP port of ocha_ai_helper series_title.match_series_title_pages().
 */
final class SeriesTitleMatchHelper {

  private const TOP_PAGE_FRACTION = 0.4;
  private const MATCH_THRESHOLD = 70;
  private const MAX_TITLE_REGION_LINES = 5;
  private const MAX_GAP_LINES = 2;
  private const MAX_CANDIDATES = 5;
  private const LINE_Y_TOLERANCE_FRACTION = 0.6;

  /**
   * Match each page independently; return ranked candidates and matched titles.
   *
   * @param list<list<array{text: string, x: float, y: float, w: float, h: float, size: float}>> $pages
   *   Per-page MuPDF spans.
   * @param string[] $titles
   *   Series member titles (recency order).
   * @param int $max_matched_titles
   *   Cap on matched_titles from the winning fingerprint group.
   *
   * @return array{
   *   matched_titles: string[],
   *   candidates: list<array{
   *     page: int,
   *     title_region_text: string,
   *     nearby_date: ?string,
   *     nearby_issue: ?string,
   *     nearby_week: ?string,
   *     confidence: float
   *   }>
   *   }
   *   Matcher response shape consumed by ReportSeriesMatcher.
   */
  public static function matchPages(
    array $pages,
    array $titles,
    int $max_matched_titles = 5,
  ): array {
    $empty = [
      'matched_titles' => [],
      'candidates' => [],
    ];
    if ($pages === [] || $titles === []) {
      return $empty;
    }

    $schema = self::titlesMarkerSchema($titles);
    $scored = [];
    foreach ($pages as $page_index => $spans) {
      if (!is_array($spans) || $spans === []) {
        continue;
      }
      $result = self::matchPage(
        $spans,
        $titles,
        $max_matched_titles,
      );
      if (($result['matched_titles'] ?? []) === []) {
        continue;
      }
      $key = [
        self::wantedMarkerCount($result, $schema),
        (float) $result['confidence'],
        -$page_index,
      ];
      $scored[] = [
        'key' => $key,
        'page_index' => $page_index,
        'result' => $result,
      ];
    }

    if ($scored === []) {
      return $empty;
    }

    usort(
      $scored,
      static function (array $a, array $b): int {
        return $b['key'] <=> $a['key'];
      },
    );

    $candidates = [];
    $seen = [];
    $best_full = NULL;
    foreach ($scored as $row) {
      $result = $row['result'];
      $dedupe = self::candidateDedupeKey($result);
      if (isset($seen[$dedupe])) {
        continue;
      }
      $seen[$dedupe] = TRUE;
      if ($best_full === NULL) {
        $best_full = $result;
      }
      $candidates[] = [
        'page' => $row['page_index'] + 1,
        'title_region_text' => (string) $result['title_region_text'],
        'nearby_date' => $result['nearby_date'],
        'nearby_issue' => $result['nearby_issue'],
        'nearby_week' => $result['nearby_week'],
        'confidence' => (float) $result['confidence'],
      ];
      if (count($candidates) >= self::MAX_CANDIDATES) {
        break;
      }
    }

    return [
      'matched_titles' => $best_full['matched_titles'] ?? [],
      'candidates' => $candidates,
    ];
  }

  /**
   * Match series titles against one page of spans.
   *
   * @param list<array{text: string, x: float, y: float, w: float, h: float, size: float}> $spans
   *   Page spans.
   * @param string[] $titles
   *   Series titles.
   * @param int $max_matched_titles
   *   Cap on matched titles.
   *
   * @return array{
   *   title_region_text: string,
   *   nearby_date: ?string,
   *   nearby_issue: ?string,
   *   nearby_week: ?string,
   *   matched_titles: string[],
   *   confidence: float
   *   }
   *   Single-page match result.
   */
  private static function matchPage(
    array $spans,
    array $titles,
    int $max_matched_titles,
  ): array {
    $empty = [
      'title_region_text' => '',
      'nearby_date' => NULL,
      'nearby_issue' => NULL,
      'nearby_week' => NULL,
      'matched_titles' => [],
      'confidence' => 0.0,
    ];

    $normalized = self::normalizeSpans($spans);
    if ($normalized === []) {
      return $empty;
    }

    [$top_spans, $y_min, $extent] = self::filterTopPageSpans(
      $normalized,
      self::TOP_PAGE_FRACTION,
    );
    $lines = self::clusterSpansIntoLines($top_spans);
    if ($lines === []) {
      return $empty;
    }

    $page_font_sizes = array_map(
      static fn(array $span): float => (float) $span['size'],
      $normalized,
    );
    $page_median_font = self::median($page_font_sizes);

    $title_scores = [];
    foreach ($titles as $title) {
      if (!is_string($title) || trim($title) === '') {
        continue;
      }
      $scored = self::scoreTitleAgainstLines($title, $lines);
      if ($scored !== NULL) {
        $title_scores[] = $scored;
      }
    }
    if ($title_scores === []) {
      return $empty;
    }

    $groups = [];
    foreach ($title_scores as $item) {
      $groups[$item['fingerprint']][] = $item;
    }

    $winning_fp = NULL;
    $winning_key = NULL;
    foreach ($groups as $fp => $items) {
      $key = self::groupKey($items);
      if ($winning_key === NULL || $key > $winning_key) {
        $winning_key = $key;
        $winning_fp = $fp;
      }
    }
    $winning = $groups[$winning_fp] ?? [];
    if ($winning === []) {
      return $empty;
    }

    $title_order = array_flip(array_values($titles));
    usort(
      $winning,
      static function (array $a, array $b) use ($title_order): int {
        $cmp = $b['score'] <=> $a['score'];
        if ($cmp !== 0) {
          return $cmp;
        }
        $cmp = $b['n_components_hit'] <=> $a['n_components_hit'];
        if ($cmp !== 0) {
          return $cmp;
        }
        return ($title_order[$a['title']] ?? 0) <=> ($title_order[$b['title']] ?? 0);
      },
    );
    $matched_titles = array_map(
      static fn(array $item): string => $item['title'],
      array_slice($winning, 0, max(1, $max_matched_titles)),
    );

    $best_member = $winning[0];
    foreach ($winning as $item) {
      $left = [
        $item['n_components_hit'],
        $item['coverage'],
        $item['score'],
        $item['compactness'],
        -$item['region_geom']['cy'],
      ];
      $right = [
        $best_member['n_components_hit'],
        $best_member['coverage'],
        $best_member['score'],
        $best_member['compactness'],
        -$best_member['region_geom']['cy'],
      ];
      if ($left > $right) {
        $best_member = $item;
      }
    }

    $title_region_text = $best_member['region_text'];
    $region_geom = $best_member['region_geom'];
    $best_score = (float) $best_member['score'];
    [$nearby_date, $nearby_issue, $nearby_week] = self::findNearbyMarkers(
      $normalized,
      $title_region_text,
      $region_geom,
      $lines,
    );

    $n_with_hits = count($title_scores);
    $confidence = (0.5 * ($best_score / 100.0))
      + (0.3 * (count($winning) / max(1, $n_with_hits)));
    $region_in_top = $region_geom['cy'] <= ($y_min + self::TOP_PAGE_FRACTION * $extent);
    $region_font_ok = $region_geom['median_size'] >= $page_median_font;
    if ($region_in_top && $region_font_ok) {
      $confidence += 0.2;
    }
    $confidence = max(0.0, min(1.0, $confidence));

    return [
      'title_region_text' => $title_region_text,
      'nearby_date' => $nearby_date,
      'nearby_issue' => $nearby_issue,
      'nearby_week' => $nearby_week,
      'matched_titles' => $matched_titles,
      'confidence' => round($confidence, 4),
    ];
  }

  /**
   * Normalize span text and geometry fields for layout matching.
   *
   * @param list<array{text: string, x: float, y: float, w: float, h: float, size: float}> $spans
   *   Raw spans.
   *
   * @return list<array{text: string, x: float, y: float, w: float, h: float, size: float, cy: float}>
   *   Normalized spans.
   */
  private static function normalizeSpans(array $spans): array {
    $normalized = [];
    foreach ($spans as $span) {
      if (!is_array($span)) {
        continue;
      }
      $text = self::normalizeSpanText((string) ($span['text'] ?? ''));
      if ($text === '') {
        continue;
      }
      $y = (float) ($span['y'] ?? 0.0);
      $h = (float) ($span['h'] ?? 0.0);
      $normalized[] = [
        'text' => $text,
        'x' => (float) ($span['x'] ?? 0.0),
        'y' => $y,
        'w' => (float) ($span['w'] ?? 0.0),
        'h' => $h,
        'size' => (float) ($span['size'] ?? 0.0),
        'cy' => $y + ($h / 2.0),
      ];
    }
    return $normalized;
  }

  /**
   * Keep only spans in the top fraction of the page.
   *
   * @param list<array{text: string, x: float, y: float, w: float, h: float, size: float, cy: float}> $spans
   *   Normalized spans.
   * @param float $top_page_fraction
   *   Top band fraction.
   *
   * @return array{0: list<array{text: string, x: float, y: float, w: float, h: float, size: float, cy: float}>, 1: float, 2: float}
   *   Top spans, y_min, extent.
   */
  private static function filterTopPageSpans(array $spans, float $top_page_fraction): array {
    if ($spans === []) {
      return [[], 0.0, 0.0];
    }
    $y_min = min(array_map(static fn(array $s): float => $s['y'], $spans));
    $y_max = max(array_map(static fn(array $s): float => $s['y'] + $s['h'], $spans));
    $extent = max($y_max - $y_min, 1e-6);
    $cutoff = $y_min + ($top_page_fraction * $extent);
    $top = array_values(array_filter(
      $spans,
      static fn(array $s): bool => $s['cy'] <= $cutoff,
    ));
    if ($top === []) {
      $top = $spans;
    }
    return [$top, $y_min, $extent];
  }

  /**
   * Cluster spans into reading-order lines by vertical proximity.
   *
   * @param list<array{text: string, x: float, y: float, w: float, h: float, size: float, cy: float}> $spans
   *   Spans to cluster.
   *
   * @return list<array{text: string, y: float, cy: float, h: float, size: float, x0: float, x1: float}>
   *   Reading-order lines.
   */
  private static function clusterSpansIntoLines(array $spans): array {
    if ($spans === []) {
      return [];
    }
    $heights = array_map(static fn(array $s): float => max($s['h'], 1.0), $spans);
    $y_tol = self::median($heights) * self::LINE_Y_TOLERANCE_FRACTION;

    usort(
      $spans,
      static fn(array $a, array $b): int => [$a['cy'], $a['x']] <=> [$b['cy'], $b['x']],
    );

    $lines = [];
    $line_centers = [];
    foreach ($spans as $span) {
      $placed = FALSE;
      foreach ($line_centers as $index => $center) {
        if (abs($span['cy'] - $center) <= $y_tol) {
          $lines[$index][] = $span;
          $line_centers[$index] = array_sum(array_column($lines[$index], 'cy')) / count($lines[$index]);
          $placed = TRUE;
          break;
        }
      }
      if (!$placed) {
        $lines[] = [$span];
        $line_centers[] = $span['cy'];
      }
    }

    $result = [];
    foreach ($lines as $members) {
      usort($members, static fn(array $a, array $b): int => $a['x'] <=> $b['x']);
      $text = self::normalizeSpanText(implode(' ', array_column($members, 'text')));
      if ($text === '') {
        continue;
      }
      $result[] = [
        'text' => $text,
        'y' => min(array_column($members, 'y')),
        'cy' => array_sum(array_column($members, 'cy')) / count($members),
        'h' => max(array_column($members, 'h')),
        'size' => max(array_column($members, 'size')),
        'x0' => min(array_column($members, 'x')),
        'x1' => max(array_map(static fn(array $m): float => $m['x'] + $m['w'], $members)),
      ];
    }
    usort(
      $result,
      static fn(array $a, array $b): int => [$a['cy'], $a['x0']] <=> [$b['cy'], $b['x0']],
    );
    return $result;
  }

  /**
   * Score a series title against page lines via component fuzzy hits.
   *
   * @param string $title
   *   Candidate series title.
   * @param list<array{text: string, y: float, cy: float, h: float, size: float, x0: float, x1: float}> $lines
   *   Page lines.
   *
   * @return array<string, mixed>|null
   *   Score payload or NULL.
   */
  private static function scoreTitleAgainstLines(string $title, array $lines): ?array {
    $clean_title = self::normalizeSpanText($title);
    if ($clean_title === '') {
      return NULL;
    }
    $fingerprint = TitlePatternHelper::fingerprintTitle($clean_title);
    $components = TitlePatternHelper::splitTitleComponents($clean_title);
    if ($components === []) {
      return NULL;
    }

    $hit_scores_by_line = [];
    $components_hit_by_line = [];
    $components_hit = [];
    $best_score = 0.0;

    foreach ($components as $comp_index => $component) {
      foreach ($lines as $line_index => $line) {
        if (TitlePatternHelper::isMarkerOnlyLine($line['text'])) {
          continue;
        }
        $score = FuzzyTextHelper::fuzzyBestScore($component, $line['text']);
        if ($score >= self::MATCH_THRESHOLD) {
          $hit_scores_by_line[$line_index] = max(
            $hit_scores_by_line[$line_index] ?? 0.0,
            $score,
          );
          $components_hit_by_line[$line_index][$comp_index] = TRUE;
          $components_hit[$comp_index] = TRUE;
          $best_score = max($best_score, $score);
        }
      }
    }

    if ($hit_scores_by_line === []) {
      return NULL;
    }

    $clusters = self::clusterHitIndices(array_keys($hit_scores_by_line), self::MAX_GAP_LINES);
    $best_cluster = self::pickBestCluster(
      $clusters,
      $hit_scores_by_line,
      $components_hit_by_line,
      $lines,
    );
    [$region_text, $region_geom] = self::regionFromCluster(
      $best_cluster,
      $lines,
      self::MAX_TITLE_REGION_LINES,
    );
    if ($region_text === '') {
      return NULL;
    }

    $n_comps = count($components);
    $n_hit = count($components_hit);
    $span = $best_cluster[array_key_last($best_cluster)] - $best_cluster[0] + 1;

    return [
      'title' => $title,
      'fingerprint' => $fingerprint,
      'score' => $best_score,
      'n_components_hit' => $n_hit,
      'coverage' => $n_hit / max($n_comps, 1),
      'compactness' => 1.0 / max($span, 1),
      'cluster' => $best_cluster,
      'region_text' => $region_text,
      'region_geom' => $region_geom,
    ];
  }

  /**
   * Split hit line indices into clusters by intervening gap size.
   *
   * @param int[] $hit_indices
   *   Line indices with hits.
   * @param int $max_gap_lines
   *   Max intervening non-hit lines.
   *
   * @return list<list<int>>
   *   Clusters of indices.
   */
  private static function clusterHitIndices(array $hit_indices, int $max_gap_lines): array {
    if ($hit_indices === []) {
      return [];
    }
    sort($hit_indices, \SORT_NUMERIC);
    $hit_indices = array_values(array_unique($hit_indices));
    $clusters = [[$hit_indices[0]]];
    for ($i = 1; $i < count($hit_indices); $i++) {
      $index = $hit_indices[$i];
      $last = $clusters[array_key_last($clusters)];
      $intervening = $index - $last[array_key_last($last)] - 1;
      if ($intervening > $max_gap_lines) {
        $clusters[] = [$index];
      }
      else {
        $clusters[array_key_last($clusters)][] = $index;
      }
    }
    return $clusters;
  }

  /**
   * Choose the best hit cluster by score coverage and geometry.
   *
   * @param list<list<int>> $clusters
   *   Hit clusters.
   * @param array<int, float> $hit_scores_by_line
   *   Scores by line.
   * @param array<int, array<int, true>> $components_hit_by_line
   *   Components hit per line.
   * @param list<array{cy: float}> $lines
   *   Lines.
   *
   * @return list<int>
   *   Best cluster indices.
   */
  private static function pickBestCluster(
    array $clusters,
    array $hit_scores_by_line,
    array $components_hit_by_line,
    array $lines,
  ): array {
    $best = $clusters[0];
    $best_key = NULL;
    foreach ($clusters as $cluster) {
      $mean = array_sum(array_map(
        static fn(int $i): float => $hit_scores_by_line[$i],
        $cluster,
      )) / count($cluster);
      $comps = [];
      foreach ($cluster as $i) {
        foreach (array_keys($components_hit_by_line[$i] ?? []) as $comp) {
          $comps[$comp] = TRUE;
        }
      }
      $topness = -min(array_map(static fn(int $i): float => $lines[$i]['cy'], $cluster));
      $compactness = 1.0 / max($cluster[array_key_last($cluster)] - $cluster[0] + 1, 1);
      $key = [$mean, count($comps), $compactness, $topness];
      if ($best_key === NULL || $key > $best_key) {
        $best_key = $key;
        $best = $cluster;
      }
    }
    return $best;
  }

  /**
   * Build title-region text and geometry from a hit cluster.
   *
   * @param list<int> $cluster
   *   Line indices.
   * @param list<array{text: string, y: float, cy: float, h: float, size: float}> $lines
   *   Lines.
   * @param int $max_title_region_lines
   *   Cap.
   *
   * @return array{0: string, 1: array{y: float, h: float, cy: float, median_size: float, line_indices: list<int>}}
   *   Region text and geometry.
   */
  private static function regionFromCluster(
    array $cluster,
    array $lines,
    int $max_title_region_lines,
  ): array {
    $start = $cluster[0];
    $end = $cluster[array_key_last($cluster)];
    $indices = range($start, $end);
    if (count($indices) > $max_title_region_lines) {
      $indices = array_slice($indices, 0, $max_title_region_lines);
    }

    $text_lines = [];
    foreach ($indices as $i) {
      if (!TitlePatternHelper::isMarkerOnlyLine($lines[$i]['text'])) {
        $text_lines[] = $lines[$i]['text'];
      }
    }
    $region_text = implode("\n", $text_lines);
    $geom_lines = array_map(static fn(int $i): array => $lines[$i], $indices);
    if ($geom_lines === []) {
      $geom_lines = [$lines[$cluster[0]]];
    }

    return [
      $region_text,
      [
        'y' => min(array_column($geom_lines, 'y')),
        'h' => max(array_map(
          static fn(array $line): float => $line['y'] + $line['h'],
          $geom_lines,
        )) - min(array_column($geom_lines, 'y')),
        'cy' => array_sum(array_column($geom_lines, 'cy')) / count($geom_lines),
        'median_size' => self::median(array_column($geom_lines, 'size')),
        'line_indices' => $indices,
      ],
    ];
  }

  /**
   * Extract nearby date/issue/week markers for a title region.
   *
   * @param list<array{text: string, cy: float}> $all_spans
   *   All page spans.
   * @param string $region_text
   *   Title region text.
   * @param array{y: float, h: float, cy: float}|null $region_geom
   *   Region geometry.
   * @param list<array{h: float}> $lines
   *   Lines.
   *
   * @return array{0: ?string, 1: ?string, 2: ?string}
   *   nearby_date, nearby_issue, nearby_week.
   */
  private static function findNearbyMarkers(
    array $all_spans,
    string $region_text,
    ?array $region_geom,
    array $lines,
  ): array {
    if ($region_geom === NULL || $region_text === '') {
      return [NULL, NULL, NULL];
    }
    $line_heights = array_map(
      static fn(array $line): float => max($line['h'], 1.0),
      $lines,
    ) ?: [12.0];
    $median_h = self::median($line_heights);
    $window = 2.0 * $median_h;
    $y0 = $region_geom['y'] - $window;
    $y1 = $region_geom['y'] + $region_geom['h'] + $window;
    $region_cy = $region_geom['cy'];

    $candidates = [[0.0, $region_text]];
    foreach ($all_spans as $span) {
      if ($span['cy'] >= $y0 && $span['cy'] <= $y1) {
        $candidates[] = [abs($span['cy'] - $region_cy), $span['text']];
      }
    }
    usort($candidates, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

    $nearby_date = NULL;
    $nearby_issue = NULL;
    $nearby_week = NULL;
    foreach ($candidates as [, $text]) {
      $nearby_date ??= TitlePatternHelper::extractNearbyDate($text);
      $nearby_issue ??= TitlePatternHelper::extractNearbyIssue($text);
      $nearby_week ??= TitlePatternHelper::extractNearbyWeek($text);
      if ($nearby_date !== NULL && $nearby_issue !== NULL && $nearby_week !== NULL) {
        break;
      }
    }
    return [$nearby_date, $nearby_issue, $nearby_week];
  }

  /**
   * Infer which marker kinds the series title set usually includes.
   *
   * @param string[] $titles
   *   Series titles.
   *
   * @return array{wants_date: bool, wants_issue: bool, wants_week: bool}
   *   Marker schema.
   */
  private static function titlesMarkerSchema(array $titles): array {
    if ($titles === []) {
      return ['wants_date' => FALSE, 'wants_issue' => FALSE, 'wants_week' => FALSE];
    }
    $n = count($titles);
    $majority = $n / 2.0;
    $dates = 0;
    $issues = 0;
    $weeks = 0;
    foreach ($titles as $title) {
      if (!is_string($title)) {
        continue;
      }
      if (TitlePatternHelper::extractNearbyDate($title) !== NULL) {
        $dates++;
      }
      if (TitlePatternHelper::extractNearbyIssue($title) !== NULL) {
        $issues++;
      }
      if (TitlePatternHelper::extractNearbyWeek($title) !== NULL) {
        $weeks++;
      }
    }
    return [
      'wants_date' => $dates > $majority,
      'wants_issue' => $issues > $majority,
      'wants_week' => $weeks > $majority,
    ];
  }

  /**
   * Count schema-wanted markers present on a page result.
   *
   * @param array<string, mixed> $result
   *   Page result.
   * @param array{wants_date: bool, wants_issue: bool, wants_week: bool} $schema
   *   Schema.
   *
   * @return int
   *   Count of wanted markers present.
   */
  private static function wantedMarkerCount(array $result, array $schema): int {
    $count = 0;
    if ($schema['wants_date'] && !empty($result['nearby_date'])) {
      $count++;
    }
    if ($schema['wants_issue'] && !empty($result['nearby_issue'])) {
      $count++;
    }
    if ($schema['wants_week'] && !empty($result['nearby_week'])) {
      $count++;
    }
    return $count;
  }

  /**
   * Build a dedupe key for a ranked page candidate.
   *
   * @param array<string, mixed> $result
   *   Page result.
   *
   * @return string
   *   Dedupe key.
   */
  private static function candidateDedupeKey(array $result): string {
    return implode("\0", [
      mb_strtolower(self::normalizeSpanText((string) ($result['title_region_text'] ?? ''))),
      (string) ($result['nearby_date'] ?? ''),
      (string) ($result['nearby_issue'] ?? ''),
      (string) ($result['nearby_week'] ?? ''),
    ]);
  }

  /**
   * Sort key for fingerprint groups (score, coverage, recency).
   *
   * @param list<array<string, mixed>> $items
   *   Fingerprint group members.
   *
   * @return array{0: float, 1: float, 2: float, 3: int, 4: float, 5: int}
   *   Sort key.
   */
  private static function groupKey(array $items): array {
    $best = $items[0];
    foreach ($items as $item) {
      $left = [
        $item['score'],
        -$item['region_geom']['cy'],
        $item['compactness'],
        $item['coverage'],
        $item['n_components_hit'],
      ];
      $right = [
        $best['score'],
        -$best['region_geom']['cy'],
        $best['compactness'],
        $best['coverage'],
        $best['n_components_hit'],
      ];
      if ($left > $right) {
        $best = $item;
      }
    }
    return [
      $best['score'],
      -$best['region_geom']['cy'],
      $best['compactness'],
      count($items),
      $best['coverage'],
      $best['n_components_hit'],
    ];
  }

  /**
   * Return the median of a non-empty float list.
   *
   * @param float[] $values
   *   Numeric values.
   *
   * @return float
   *   Median.
   */
  private static function median(array $values): float {
    if ($values === []) {
      return 0.0;
    }
    sort($values, \SORT_NUMERIC);
    $mid = intdiv(count($values), 2);
    if (count($values) % 2 === 1) {
      return (float) $values[$mid];
    }
    return ((float) $values[$mid - 1] + (float) $values[$mid]) / 2.0;
  }

  /**
   * Collapse internal whitespace.
   */
  private static function normalizeSpanText(string $text): string {
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
  }

}
