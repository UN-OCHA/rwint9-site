<?php

declare(strict_types=1);

namespace Drupal\reliefweb_content_analyzer\Services;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Url;
use Drupal\reliefweb_content_analyzer\ContentEmbeddings\Dto\EmbeddingNearestQuery;
use Drupal\reliefweb_content_analyzer\Helpers\EmbeddingVectorSimilarity;
use Drupal\reliefweb_content_analyzer\Helpers\PlainTextNormalizer;
use Drupal\reliefweb_content_analyzer\Helpers\Stopwords;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatch;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchCandidate;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\DuplicateMatchResult;
use Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchSettings;
use Drupal\reliefweb_utility\Helpers\TitlePatternHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Finds near-duplicate reports among window/source and embedding candidates.
 *
 * By default window candidates must share at least one field_source
 * (configurable). Embedding nearest neighbors expand recall without a source
 * filter. Hard gate: word 3-gram Jaccard. Soft gate: TF-IDF then local
 * embedding cosine (stored vectors or /embed). Series siblings are discarded.
 */
class ReportDuplicateMatcher implements ReportDuplicateMatcherInterface {

  /**
   * Constructs a ReportDuplicateMatcher.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   * @param \Drupal\Core\Database\Connection $database
   *   Database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Time service.
   * @param \Drupal\reliefweb_content_analyzer\Services\EmbeddingsStorageInterface $embeddingsStorage
   *   Embeddings storage.
   * @param \Drupal\reliefweb_content_analyzer\Services\EmbeddingGenerator $embeddingGenerator
   *   Embedding generator (/embed).
   * @param \Drupal\reliefweb_content_analyzer\Services\EmbeddingTextBuilder $embeddingTextBuilder
   *   Text builder for hash/profile when upserting.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger channel.
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly Connection $database,
    protected readonly TimeInterface $time,
    protected readonly EmbeddingsStorageInterface $embeddingsStorage,
    protected readonly EmbeddingGenerator $embeddingGenerator,
    protected readonly EmbeddingTextBuilder $embeddingTextBuilder,
    #[Autowire(service: 'logger.channel.reliefweb_content_analyzer')]
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function findDuplicates(ContentEntityInterface $entity): DuplicateMatchResult {
    if ($entity->getEntityTypeId() !== 'node' || $entity->bundle() !== 'report') {
      return new DuplicateMatchResult(reason: 'not_report');
    }

    if (!$this->hasBody($entity)) {
      return new DuplicateMatchResult(reason: 'no_body');
    }

    $settings = $this->settings();
    if ($settings->skipWithAttachments && $this->hasAttachment($entity)) {
      return new DuplicateMatchResult(reason: 'has_attachment');
    }

    $source_ids = $this->getSourceIds($entity);
    if ($settings->filterBySource && $source_ids === []) {
      return new DuplicateMatchResult(reason: 'no_source');
    }

    $normalized = PlainTextNormalizer::normalize($this->getBodyValue($entity));
    if (mb_strlen($normalized, 'UTF-8') < $settings->minimumBodyLength) {
      return new DuplicateMatchResult(reason: 'body_too_short');
    }

    $query_vec = $this->resolveQueryVector($entity, $normalized);
    $window_rows = $this->loadCandidates($entity, $source_ids, $settings);
    $emb_rows = $query_vec !== NULL
      ? $this->loadEmbeddingCandidates($entity, $query_vec, $settings)
      : [];
    $rows = $this->unionCandidateRows($window_rows, $emb_rows);
    if ($rows === []) {
      return new DuplicateMatchResult(reason: 'no_candidates');
    }

    $probe_nid = (int) $entity->id();
    $title_nids = array_map(static fn(object $row): int => (int) $row->nid, $rows);
    if ($probe_nid > 0) {
      $title_nids[] = $probe_nid;
    }
    $first_titles = $this->loadFirstRevisionTitles($title_nids);
    $probe_title = $first_titles[$probe_nid]
      ?? ($entity->label() !== NULL ? trim((string) $entity->label()) : '');

    $scored = [];
    $pending_indices = [];
    $source_hash = hash('sha256', $normalized);
    $language_codes = $this->getLanguageCodes($entity);
    foreach ($rows as $row) {
      $nid = (int) $row->nid;
      $candidate_normalized = PlainTextNormalizer::normalize($row->body_value);
      $candidate = DuplicateMatchCandidate::score(
        nid: $nid,
        title: (string) $row->title,
        url: Url::fromRoute('entity.node.canonical', ['node' => $nid])->toString(),
        created: (int) $row->created,
        normalized: $normalized,
        candidateNormalized: $candidate_normalized,
        sourceHash: $source_hash,
        settings: $settings,
        language_codes: $language_codes,
        candidateSource: (string) ($row->candidate_source ?? DuplicateMatchCandidate::SOURCE_WINDOW),
      );

      if (
        $candidate->isDuplicate
        && $candidate->method === DuplicateMatch::METHOD_JACCARD
        && $this->isSeriesSibling(
          $probe_title,
          $first_titles[$nid] ?? (string) $row->title,
        )
      ) {
        $candidate = $candidate->withSeriesSiblingDiscard();
      }

      if ($candidate->needsEmbeddingConfirmation($settings)) {
        $pending_indices[] = count($scored);
      }
      $scored[] = $candidate;
    }

    if ($pending_indices !== [] && $query_vec !== NULL) {
      $pending_nids = [];
      foreach ($pending_indices as $scored_index) {
        $pending_nids[] = $scored[$scored_index]->nid;
      }
      $candidate_vectors = $this->resolveCandidateVectors($pending_nids, $rows, $settings);
      foreach ($pending_indices as $scored_index) {
        $nid = $scored[$scored_index]->nid;
        $cand_vec = $candidate_vectors[$nid] ?? NULL;
        $score = $cand_vec !== NULL
          ? EmbeddingVectorSimilarity::cosine($query_vec, $cand_vec)
          : NULL;
        $scored[$scored_index] = $scored[$scored_index]->withEmbeddingConfirmation(
          $score,
          $settings,
        );
        if (
          $scored[$scored_index]->isDuplicate
          && $scored[$scored_index]->method === DuplicateMatch::METHOD_EMBEDDING
          && $this->isSeriesSibling(
            $probe_title,
            $first_titles[$nid] ?? $scored[$scored_index]->title,
          )
        ) {
          $scored[$scored_index] = $scored[$scored_index]->withSeriesSiblingDiscard();
        }
      }
    }

    $matches = [];
    foreach ($scored as $candidate) {
      $match = $candidate->toMatch();
      if ($match !== NULL) {
        $matches[] = $match;
      }
    }

    usort($scored, static function (DuplicateMatchCandidate $a, DuplicateMatchCandidate $b): int {
      $dup_a = $a->isDuplicate ? 1 : 0;
      $dup_b = $b->isDuplicate ? 1 : 0;
      if ($dup_a !== $dup_b) {
        return $dup_b <=> $dup_a;
      }
      $hard_a = $a->method === DuplicateMatch::METHOD_JACCARD ? 1 : 0;
      $hard_b = $b->method === DuplicateMatch::METHOD_JACCARD ? 1 : 0;
      if ($hard_a !== $hard_b) {
        return $hard_b <=> $hard_a;
      }
      $score_a = max($a->jaccardScore ?? 0.0, $a->tfidfScore ?? 0.0, $a->embeddingScore ?? 0.0);
      $score_b = max($b->jaccardScore ?? 0.0, $b->tfidfScore ?? 0.0, $b->embeddingScore ?? 0.0);
      return $score_b <=> $score_a;
    });

    usort($matches, static function (DuplicateMatch $a, DuplicateMatch $b): int {
      $hard_a = $a->isHardMatch() ? 1 : 0;
      $hard_b = $b->isHardMatch() ? 1 : 0;
      if ($hard_a !== $hard_b) {
        return $hard_b <=> $hard_a;
      }
      return $b->score <=> $a->score;
    });

    return new DuplicateMatchResult(
      matches: $matches,
      reason: $matches === [] ? 'no_matches' : 'matched',
      candidates: $scored,
    );
  }

  /**
   * Resolve the probe embedding vector (stored or freshly embedded).
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Probe entity.
   * @param string $normalized
   *   Normalized body text.
   *
   * @return float[]|null
   *   Query vector, or NULL when unavailable.
   */
  protected function resolveQueryVector(ContentEntityInterface $entity, string $normalized): ?array {
    $nid = (int) $entity->id();
    if ($nid > 0) {
      $stored = $this->embeddingsStorage->loadVector('node', $nid);
      if ($stored !== NULL) {
        return $stored;
      }
    }

    try {
      $vectors = $this->embeddingGenerator->embedTexts([$normalized]);
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Report duplicate probe embed failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }

    $vector = $vectors[0] ?? NULL;
    if (!is_array($vector) || $vector === []) {
      return NULL;
    }

    if ($nid > 0) {
      $this->upsertEmbedding('node', $nid, 'report', $vector, $normalized);
    }

    return $vector;
  }

  /**
   * Load and optionally embed candidate vectors for soft confirmation.
   *
   * @param int[] $nids
   *   Pending candidate nids in confirmation order.
   * @param list<object{nid: int, body_value: string}> $rows
   *   Union candidate rows (for body text when embedding).
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchSettings $settings
   *   Settings (unused dimensions come from content embeddings).
   *
   * @return array<int, float[]>
   *   Vectors keyed by nid.
   */
  protected function resolveCandidateVectors(array $nids, array $rows, DuplicateMatchSettings $settings): array {
    $bodies = [];
    foreach ($rows as $row) {
      $bodies[(int) $row->nid] = (string) $row->body_value;
    }

    $vectors = [];
    $missing_nids = [];
    $missing_texts = [];
    foreach ($nids as $nid) {
      $stored = $this->embeddingsStorage->loadVector('node', $nid);
      if ($stored !== NULL) {
        $vectors[$nid] = $stored;
        continue;
      }
      $text = PlainTextNormalizer::normalize($bodies[$nid] ?? '');
      if ($text === '') {
        continue;
      }
      $missing_nids[] = $nid;
      $missing_texts[] = $text;
    }

    if ($missing_texts === []) {
      return $vectors;
    }

    try {
      $embedded = $this->embeddingGenerator->embedTexts($missing_texts);
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Report duplicate candidate embed failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return $vectors;
    }

    foreach ($missing_nids as $i => $nid) {
      $vector = $embedded[$i] ?? NULL;
      if (!is_array($vector) || $vector === []) {
        continue;
      }
      $vectors[$nid] = $vector;
      $this->upsertEmbedding('node', $nid, 'report', $vector, $missing_texts[$i]);
    }

    return $vectors;
  }

  /**
   * Upsert an embedding row for a node.
   *
   * @param string $entity_type_id
   *   Entity type.
   * @param int $entity_id
   *   Entity ID.
   * @param string $bundle
   *   Bundle.
   * @param float[] $vector
   *   Embedding.
   * @param string $text
   *   Text used for hashing.
   */
  protected function upsertEmbedding(
    string $entity_type_id,
    int $entity_id,
    string $bundle,
    array $vector,
    string $text,
  ): void {
    try {
      if (!$this->embeddingsStorage->isAvailable()) {
        return;
      }
      $content = $this->embeddingGenerator->settings();
      $this->embeddingsStorage->ensureReady($content->dimensions);
      $hash = $this->embeddingTextBuilder->hash(['body'], $text);
      $this->embeddingsStorage->upsert(
        $entity_type_id,
        $entity_id,
        $bundle,
        $vector,
        $hash,
        'en',
        $content->dimensions,
      );
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Report duplicate embedding upsert failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }
  }

  /**
   * Load embedding nearest-neighbor candidates (no source filter).
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Probe entity.
   * @param float[] $query_vec
   *   Probe embedding.
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchSettings $settings
   *   Settings.
   *
   * @return list<object{nid: int, title: string, created: int, body_value: string, candidate_source: string}>
   *   Candidate rows tagged SOURCE_EMBEDDING.
   */
  protected function loadEmbeddingCandidates(
    ContentEntityInterface $entity,
    array $query_vec,
    DuplicateMatchSettings $settings,
  ): array {
    if (!$this->embeddingsStorage->isAvailable() || $settings->candidateModerationStatuses === []) {
      return [];
    }

    $range = $this->idRangeForEmbeddingLookback($entity, $settings);
    if ($range === NULL) {
      return [];
    }
    [$id_min, $id_max] = $range;
    $exclude = (int) $entity->id();
    if ($exclude <= 0) {
      $exclude = NULL;
    }

    try {
      $hits = $this->embeddingsStorage->findNearest(new EmbeddingNearestQuery(
        entityTypeId: 'node',
        query: $query_vec,
        limit: $settings->embeddingTopk,
        bundle: 'report',
        excludeEntityId: $exclude,
        entityIdMin: $id_min,
        entityIdMax: $id_max,
        minSimilarity: $settings->embeddingSimilarityThreshold,
      ));
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Report duplicate embedding nearest search failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return [];
    }

    if ($hits === []) {
      return [];
    }

    $nids = array_map(static fn($hit): int => $hit->entityId, $hits);
    return $this->loadCandidateRowsByNids($nids, $settings, DuplicateMatchCandidate::SOURCE_EMBEDDING);
  }

  /**
   * Resolve min/max report nids in the embedding created-date lookback.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Probe entity.
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchSettings $settings
   *   Settings.
   *
   * @return array{0: int, 1: int}|null
   *   [min_nid, max_nid], or NULL when empty.
   */
  protected function idRangeForEmbeddingLookback(
    ContentEntityInterface $entity,
    DuplicateMatchSettings $settings,
  ): ?array {
    $now = $this->time->getRequestTime();
    $anchor = $this->resolveAnchorTimestamp($entity);
    $window_start = $anchor - ($settings->embeddingLookbackDays * 86400);
    $window_end = $now;

    $row = $this->database->query(
      'SELECT MIN(n.nid) AS min_nid, MAX(n.nid) AS max_nid
       FROM {node_field_data} n
       WHERE n.type = :bundle
         AND n.created >= :window_start
         AND n.created <= :window_end',
      [
        ':bundle' => 'report',
        ':window_start' => $window_start,
        ':window_end' => $window_end,
      ],
    )->fetchAssoc();

    if (!$row || $row['min_nid'] === NULL || $row['max_nid'] === NULL) {
      return NULL;
    }
    return [(int) $row['min_nid'], (int) $row['max_nid']];
  }

  /**
   * Load candidate metadata/bodies for nids with post-filters.
   *
   * @param int[] $nids
   *   Candidate IDs.
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchSettings $settings
   *   Settings.
   * @param string $candidate_source
   *   SOURCE_* tag.
   *
   * @return list<object{nid: int, title: string, created: int, body_value: string, candidate_source: string}>
   *   Filtered rows.
   */
  protected function loadCandidateRowsByNids(
    array $nids,
    DuplicateMatchSettings $settings,
    string $candidate_source,
  ): array {
    $nids = array_values(array_unique(array_filter(array_map('intval', $nids))));
    if ($nids === [] || $settings->candidateModerationStatuses === []) {
      return [];
    }

    $query = $this->database->select('node_field_data', 'nfd');
    $query->fields('nfd', ['nid', 'title', 'created']);
    $query->condition('nfd.type', 'report');
    $query->condition('nfd.nid', $nids, 'IN');
    $query->condition('nfd.moderation_status', $settings->candidateModerationStatuses, 'IN');

    if ($settings->skipWithAttachments) {
      $query->leftJoin(
        'node__field_file',
        'ff',
        'ff.entity_id = nfd.nid AND ff.deleted = 0',
      );
      $query->isNull('ff.entity_id');
    }

    $meta = [];
    foreach ($query->execute() as $row) {
      $meta[(int) $row->nid] = $row;
    }

    $bodies = $this->loadCandidateBodies(array_keys($meta));
    $result = [];
    // Preserve NN / input order.
    foreach ($nids as $nid) {
      if (!isset($meta[$nid], $bodies[$nid])) {
        continue;
      }
      $normalized = PlainTextNormalizer::normalize($bodies[$nid]);
      if (mb_strlen($normalized, 'UTF-8') < $settings->minimumBodyLength) {
        continue;
      }
      $row = $meta[$nid];
      $row->nid = $nid;
      $row->created = (int) $row->created;
      $row->body_value = $bodies[$nid];
      $row->candidate_source = $candidate_source;
      $result[] = $row;
    }
    return $result;
  }

  /**
   * Union window and embedding rows; tag SOURCE_BOTH on overlap.
   *
   * @param list<object> $window_rows
   *   Window candidates.
   * @param list<object> $emb_rows
   *   Embedding candidates.
   *
   * @return list<object{nid: int, title: string, created: int, body_value: string, candidate_source: string}>
   *   Merged rows.
   */
  protected function unionCandidateRows(array $window_rows, array $emb_rows): array {
    $by_nid = [];
    foreach ($window_rows as $row) {
      $nid = (int) $row->nid;
      $row->candidate_source = DuplicateMatchCandidate::SOURCE_WINDOW;
      $by_nid[$nid] = $row;
    }
    foreach ($emb_rows as $row) {
      $nid = (int) $row->nid;
      if (isset($by_nid[$nid])) {
        $by_nid[$nid]->candidate_source = DuplicateMatchCandidate::SOURCE_BOTH;
        continue;
      }
      $row->candidate_source = DuplicateMatchCandidate::SOURCE_EMBEDDING;
      $by_nid[$nid] = $row;
    }
    return array_values($by_nid);
  }

  /**
   * Whether two titles are series siblings via TitlePatternHelper markers.
   *
   * @param string $title_a
   *   First title.
   * @param string $title_b
   *   Second title.
   *
   * @return bool
   *   TRUE when COMPARE_SERIES_SIBLING.
   */
  protected function isSeriesSibling(string $title_a, string $title_b): bool {
    $title_a = trim($title_a);
    $title_b = trim($title_b);
    if ($title_a === '' || $title_b === '') {
      return FALSE;
    }
    return TitlePatternHelper::compareSeriesMarkers(
      TitlePatternHelper::extractSeriesMarkers($title_a),
      TitlePatternHelper::extractSeriesMarkers($title_b),
      $this->getTitlePatternSimilarityThreshold(),
    ) === TitlePatternHelper::COMPARE_SERIES_SIBLING;
  }

  /**
   * Stem similarity threshold shared with the series matcher.
   *
   * @return float
   *   Configured title_pattern_similarity_threshold, or the helper default.
   */
  protected function getTitlePatternSimilarityThreshold(): float {
    $value = $this->configFactory
      ->get('reliefweb_content_analyzer.settings')
      ->get('report_series_matching.matcher.title_pattern_similarity_threshold');
    if (!is_numeric($value)) {
      return TitlePatternHelper::DEFAULT_SERIES_STEM_SIMILARITY;
    }
    return (float) $value;
  }

  /**
   * Load first-revision titles for nids.
   *
   * @param int[] $nids
   *   Node IDs.
   *
   * @return array<int, string>
   *   Title keyed by nid.
   */
  protected function loadFirstRevisionTitles(array $nids): array {
    $nids = array_values(array_unique(array_filter(array_map('intval', $nids))));
    if ($nids === []) {
      return [];
    }

    $subquery = $this->database->select('node_field_revision', 'nfr2');
    $subquery->addExpression('MIN(vid)', 'min_vid');
    $subquery->fields('nfr2', ['nid']);
    $subquery->condition('nid', $nids, 'IN');
    $subquery->groupBy('nfr2.nid');

    $query = $this->database->select('node_field_revision', 'nfr');
    $query->fields('nfr', ['nid', 'title']);
    $query->join($subquery, 'min_vids', 'nfr.nid = min_vids.nid AND nfr.vid = min_vids.min_vid');

    $titles = [];
    foreach ($query->execute() as $record) {
      $titles[(int) $record->nid] = trim((string) $record->title);
    }
    return $titles;
  }

  /**
   * Load typed settings from config.
   *
   * @return \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchSettings
   *   Settings.
   */
  protected function settings(): DuplicateMatchSettings {
    $config = $this->configFactory->get('reliefweb_content_analyzer.settings');
    return DuplicateMatchSettings::fromConfigArray($config->get('report_duplicate_matching'));
  }

  /**
   * Whether the entity has a non-empty body.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity.
   *
   * @return bool
   *   TRUE when body text is present.
   */
  protected function hasBody(ContentEntityInterface $entity): bool {
    return $this->getBodyValue($entity) !== '';
  }

  /**
   * Raw body value trimmed of tags for emptiness checks.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity.
   *
   * @return string
   *   Body value or empty string.
   */
  protected function getBodyValue(ContentEntityInterface $entity): string {
    if (!$entity->hasField('body') || $entity->get('body')->isEmpty()) {
      return '';
    }
    $raw = (string) ($entity->get('body')->value ?? '');
    return trim(strip_tags($raw)) === '' ? '' : $raw;
  }

  /**
   * Whether the entity has any file attachment.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity.
   *
   * @return bool
   *   TRUE when field_file is non-empty.
   */
  protected function hasAttachment(ContentEntityInterface $entity): bool {
    return $entity->hasField('field_file') && !$entity->get('field_file')->isEmpty();
  }

  /**
   * Source term IDs on the entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity.
   *
   * @return int[]
   *   Unique source IDs.
   */
  protected function getSourceIds(ContentEntityInterface $entity): array {
    if (!$entity->hasField('field_source') || $entity->get('field_source')->isEmpty()) {
      return [];
    }
    $ids = [];
    foreach ($entity->get('field_source') as $item) {
      $target_id = $item->target_id ?? NULL;
      if ($target_id !== NULL && $target_id !== '') {
        $ids[(int) $target_id] = (int) $target_id;
      }
    }
    return array_values($ids);
  }

  /**
   * Language ISO codes from field_language taxonomy terms.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Entity.
   *
   * @return string[]
   *   Unique ISO 639-1 codes supported by stopword lists (may be empty).
   */
  protected function getLanguageCodes(ContentEntityInterface $entity): array {
    if (!$entity->hasField('field_language') || $entity->get('field_language')->isEmpty()) {
      return [];
    }

    $codes = [];
    foreach ($entity->get('field_language')->referencedEntities() as $language) {
      if (!$language->hasField('field_language_code') || $language->get('field_language_code')->isEmpty()) {
        continue;
      }
      $code = strtolower((string) $language->get('field_language_code')->value);
      if ($code === '' || $code === 'ot') {
        continue;
      }
      if (in_array($code, Stopwords::SUPPORTED, TRUE)) {
        $codes[$code] = $code;
      }
    }
    return array_values($codes);
  }

  /**
   * Load candidate report rows with body text.
   *
   * When source filtering is enabled, candidates must share at least one of the
   * given source IDs and are ordered by shared-source count (desc), then
   * created (desc). When disabled, all sources in the date window are eligible
   * and results are ordered by created (desc) only. Bodies are loaded in a
   * second query so GROUP BY stays compatible with ONLY_FULL_GROUP_BY.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The report being checked.
   * @param int[] $source_ids
   *   Source IDs; used only when source filtering is enabled.
   * @param \Drupal\reliefweb_content_analyzer\ReportDuplicateMatch\Dto\DuplicateMatchSettings $settings
   *   Settings.
   *
   * @return list<object{nid: int|string, title: string, created: int, body_value: string, candidate_source?: string}>
   *   Candidate rows.
   */
  protected function loadCandidates(
    ContentEntityInterface $entity,
    array $source_ids,
    DuplicateMatchSettings $settings,
  ): array {
    if ($settings->candidateModerationStatuses === []) {
      return [];
    }
    if ($settings->filterBySource && $source_ids === []) {
      return [];
    }

    $now = $this->time->getRequestTime();
    $anchor = $this->resolveAnchorTimestamp($entity);
    $window_start = $anchor - ($settings->lookbackDays * 86400);
    // Forward window from the entity created date; do not search into the
    // future relative to the current request.
    $window_end = min($now, $anchor + ($settings->lookforwardDays * 86400));

    $query = $this->database->select('node_field_data', 'nfd');
    $query->fields('nfd', ['nid', 'title', 'created']);
    $query->condition('nfd.type', 'report');
    $query->condition('nfd.created', $window_start, '>=');
    $query->condition('nfd.created', $window_end, '<=');
    $query->condition('nfd.moderation_status', $settings->candidateModerationStatuses, 'IN');

    $entity_id = (int) $entity->id();
    if ($entity_id > 0) {
      $query->condition('nfd.nid', $entity_id, '<>');
    }

    if ($settings->filterBySource) {
      $query->innerJoin(
        'node__field_source',
        'fs',
        'fs.entity_id = nfd.nid AND fs.deleted = 0',
      );
      $query->condition('fs.field_source_target_id', $source_ids, 'IN');
      $query->addExpression('COUNT(DISTINCT fs.field_source_target_id)', 'shared_source_count');
      $query->groupBy('nfd.nid');
      $query->groupBy('nfd.title');
      $query->groupBy('nfd.created');
      // Require a non-empty body without selecting longtext into GROUP BY.
      $query->where('EXISTS (SELECT 1 FROM {node__body} b WHERE b.entity_id = nfd.nid AND b.deleted = 0 AND b.delta = 0 AND TRIM(b.body_value) <> :empty_body)', [
        ':empty_body' => '',
      ]);
    }
    else {
      $query->innerJoin(
        'node__body',
        'b',
        'b.entity_id = nfd.nid AND b.deleted = 0 AND b.delta = 0',
      );
      $query->where("TRIM(b.body_value) <> ''");
    }

    if ($settings->skipWithAttachments) {
      // Exclude reports that have attachments.
      $query->leftJoin(
        'node__field_file',
        'ff',
        'ff.entity_id = nfd.nid AND ff.deleted = 0',
      );
      $query->isNull('ff.entity_id');
    }

    if ($settings->filterBySource) {
      $query->orderBy('shared_source_count', 'DESC');
    }
    $query->orderBy('nfd.created', 'DESC');
    $query->range(0, $settings->candidateLimit);

    $rows = array_values($query->execute()->fetchAll());
    if ($rows === []) {
      return [];
    }

    $nids = [];
    foreach ($rows as $row) {
      $nids[] = (int) $row->nid;
    }
    $bodies = $this->loadCandidateBodies($nids);

    $result = [];
    foreach ($rows as $row) {
      $nid = (int) $row->nid;
      $body = $bodies[$nid] ?? '';
      if ($body === '') {
        continue;
      }
      $row->nid = $nid;
      $row->created = (int) $row->created;
      $row->body_value = $body;
      $row->candidate_source = DuplicateMatchCandidate::SOURCE_WINDOW;
      unset($row->shared_source_count);
      $result[] = $row;
    }

    return $result;
  }

  /**
   * Load body values for candidate nids.
   *
   * @param int[] $nids
   *   Node IDs in display/preference order.
   *
   * @return array<int, string>
   *   Map of nid to body_value for non-empty bodies.
   */
  protected function loadCandidateBodies(array $nids): array {
    if ($nids === []) {
      return [];
    }

    $query = $this->database->select('node__body', 'b');
    $query->fields('b', ['entity_id', 'body_value']);
    $query->condition('b.entity_id', $nids, 'IN');
    $query->condition('b.deleted', 0);
    $query->condition('b.delta', 0);
    $query->where("TRIM(b.body_value) <> ''");

    $bodies = [];
    foreach ($query->execute() as $row) {
      $bodies[(int) $row->entity_id] = (string) $row->body_value;
    }
    return $bodies;
  }

  /**
   * Resolves the timestamp that anchors the candidate created-date window.
   *
   * Prefers the entity created timestamp; falls back to request time for
   * unsaved entities without a created value yet.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The report being checked.
   *
   * @return int
   *   Unix timestamp used as the center of the lookback window.
   */
  protected function resolveAnchorTimestamp(ContentEntityInterface $entity): int {
    if ($entity->hasField('created') && !$entity->get('created')->isEmpty()) {
      $created = (int) $entity->get('created')->value;
      if ($created > 0) {
        return $created;
      }
    }

    return $this->time->getRequestTime();
  }

}
