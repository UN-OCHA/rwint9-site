<?php

declare(strict_types=1);

namespace Drupal\reliefweb_entities\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\reliefweb_api\Services\ReliefWebApiClient;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\reliefweb_utility\Helpers\TitlePatternHelper;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds tiered API queries for related report content.
 */
class RelatedContentService implements RelatedContentServiceInterface {

  /**
   * Default related content settings.
   */
  private const array DEFAULT_SETTINGS = [
    'candidate_limit' => 20,
    'recency_short_months' => 3,
    'recency_long_months' => 12,
    'recency_decay_exponent' => 1.5,
    'translation_date_window_days' => 2,
    'title_pattern_token_counts' => [10, 8, 6, 4],
    'theme_gate_max_count' => 3,
    'boosts' => [
      'translation' => 100,
      'disaster_country_recent' => 55,
      'disaster_recent' => 45,
      'disaster_medium' => 35,
      'disaster_primary_country' => 30,
      'disaster_country' => 28,
      'disaster' => 15,
      'title_disaster' => 40,
      'title_primary_country' => 30,
      'country_source' => 25,
      'country_theme' => 30,
    ],
  ];

  /**
   * Constructs a RelatedContentService object.
   */
  public function __construct(
    #[Autowire(service: 'reliefweb_api.client')]
    protected readonly ReliefWebApiClient $apiClient,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly TranslationInterface $stringTranslation,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getRelatedContent(EntityInterface $entity, int $limit = 4): array {
    if (!$entity instanceof FieldableEntityInterface) {
      return $this->buildRenderArray([], $this->t('Related Content'));
    }

    $title = $this->t('Related Content');
    $settings = $this->getSettings();
    $payload = $this->buildApiPayload($entity, (int) $settings['candidate_limit']);
    $query_clauses = $this->buildQueryClauses($entity);
    $entities = [];

    if ($query_clauses !== []) {
      $payload['query']['value'] = implode(' OR ', $query_clauses);
      $payload['sort'] = ['score:desc', 'date.original:desc'];

      $data = $this->apiClient->request('reports', $payload);
      $items = $data['data'] ?? $data['items'] ?? [];
      if ($items !== []) {
        $items = $this->rankCandidates($items, $entity, $settings, $limit);
        if (isset($data['data'])) {
          $data['data'] = $items;
        }
        else {
          $data['items'] = $items;
        }
      }
      $entities = RiverServiceBase::getRiverData('report', $data);
    }

    if ($entities === []) {
      $title = $this->t('Latest Updates');
      unset($payload['query']);
      $payload['limit'] = $limit;
      $data = $this->apiClient->request('reports', $payload);
      $entities = RiverServiceBase::getRiverData('report', $data);
    }

    return $this->buildRenderArray($entities, $title);
  }

  /**
   * Build the API payload for related content requests.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The document entity.
   * @param int $limit
   *   Result limit.
   *
   * @return array
   *   API payload.
   */
  protected function buildApiPayload(FieldableEntityInterface $entity, int $limit): array {
    $payload = $this->getReportRiverApiPayload();
    $payload['fields']['exclude'][] = 'body-html';
    $payload['fields']['exclude'][] = 'file';
    $payload['limit'] = $limit;
    $payload['fields']['include'][] = 'disaster.id';
    $payload['fields']['include'][] = 'theme.id';
    $payload['fields']['include'][] = 'format.id';
    $payload['fields']['include'][] = 'disaster_type.id';

    if ($entity->getEntityTypeId() === 'node' && $entity->bundle() === 'report' && !empty($entity->id())) {
      $payload['filter'] = [
        'field' => 'id',
        'value' => $entity->id(),
        'negate' => TRUE,
      ];
    }

    return $payload;
  }

  /**
   * Get the base report river API payload.
   *
   * @return array
   *   Base API payload from the report river service.
   */
  protected function getReportRiverApiPayload(): array {
    return RiverServiceBase::getRiverApiPayload('report');
  }

  /**
   * Build boosted query clauses for related content.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The document entity.
   *
   * @return string[]
   *   Query clauses with optional boost suffixes.
   */
  protected function buildQueryClauses(FieldableEntityInterface $entity): array {
    $settings = $this->getSettings();
    $boosts = $settings['boosts'];
    $clauses = [];

    $disaster_ids = $this->getReferencedEntityIds($entity, 'field_disaster');
    $country_ids = $this->getCountryIds($entity);
    $source_ids = $this->getReferencedEntityIds($entity, 'field_source');
    $is_report = $entity->getEntityTypeId() === 'node' && $entity->bundle() === 'report';

    if ($is_report) {
      $translation = $this->buildTranslationClause($entity, $settings);
      if ($translation !== '') {
        $clauses[] = $translation . '^' . $boosts['translation'];
      }

      $clauses = array_merge($clauses, $this->buildDisasterClauses($disaster_ids, $country_ids, $settings));
      $clauses = array_merge($clauses, $this->buildTitleClauses($entity, $disaster_ids, $country_ids, $settings));
    }
    else {
      $theme_ids = $this->getReferencedEntityIds($entity, 'field_theme');
      $clauses = array_merge(
        $clauses,
        $this->buildCountryThemeClauses($country_ids, $theme_ids, $settings),
      );
    }

    if ($country_ids !== [] && $source_ids !== []) {
      $clauses[] = sprintf(
        '(%s AND %s)^%d',
        $this->formatIdOrExpression('primary_country.id', $country_ids),
        $this->formatIdOrExpression('source.id', $source_ids),
        $boosts['country_source'],
      );
    }

    return array_values(array_filter($clauses));
  }

  /**
   * Build disaster-related query clauses with recency boosts.
   *
   * @param int[] $disaster_ids
   *   Disaster term IDs.
   * @param int[] $country_ids
   *   Country term IDs.
   * @param array $settings
   *   Related content settings.
   *
   * @return string[]
   *   Query clauses.
   */
  protected function buildDisasterClauses(array $disaster_ids, array $country_ids, array $settings): array {
    if ($disaster_ids === []) {
      return [];
    }

    $boosts = $settings['boosts'];
    $disaster = $this->formatIdOrExpression('disaster.id', $disaster_ids);
    $clauses = [];
    $now = time();
    $short_from = $this->monthsAgoTimestamp($settings['recency_short_months'], $now);
    $long_from = $this->monthsAgoTimestamp($settings['recency_long_months'], $now);

    $clauses[] = sprintf(
      '(%s AND %s)^%d',
      $disaster,
      $this->formatDateOriginalRange($short_from, $now),
      $boosts['disaster_recent'],
    );
    $clauses[] = sprintf(
      '(%s AND %s)^%d',
      $disaster,
      $this->formatDateOriginalRange($long_from, $now),
      $boosts['disaster_medium'],
    );

    if ($country_ids !== []) {
      $country = $this->formatIdOrExpression('primary_country.id', $country_ids);
      $clauses[] = sprintf(
        '(%s AND %s AND %s)^%d',
        $disaster,
        $country,
        $this->formatDateOriginalRange($short_from, $now),
        $boosts['disaster_country_recent'],
      );
      $clauses[] = sprintf(
        '(%s AND %s AND %s)^%d',
        $disaster,
        $country,
        $this->formatDateOriginalRange($long_from, $now),
        $boosts['disaster_primary_country'],
      );
      $clauses[] = sprintf(
        '(%s AND %s)^%d',
        $disaster,
        $this->formatIdOrExpression('country.id', $country_ids),
        $boosts['disaster_country'],
      );
    }
    else {
      $clauses[] = $disaster . '^' . $boosts['disaster'];
    }

    return $clauses;
  }

  /**
   * Build country + theme query clauses for non-report bundles.
   *
   * Themes are only used when the entity has at most theme_gate_max_count
   * themes, to avoid overly broad matching on heavily tagged content.
   *
   * @param int[] $country_ids
   *   Country term IDs.
   * @param int[] $theme_ids
   *   Theme term IDs.
   * @param array $settings
   *   Related content settings.
   *
   * @return string[]
   *   Query clauses.
   */
  protected function buildCountryThemeClauses(array $country_ids, array $theme_ids, array $settings): array {
    $max_themes = (int) ($settings['theme_gate_max_count'] ?? 3);
    if ($country_ids === [] || $theme_ids === [] || count($theme_ids) > $max_themes) {
      return [];
    }

    return [
      sprintf(
        '(%s AND %s)^%d',
        $this->formatIdOrExpression('primary_country.id', $country_ids),
        $this->formatIdOrExpression('theme.id', $theme_ids),
        $settings['boosts']['country_theme'],
      ),
    ];
  }

  /**
   * Build title-based series query clauses.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The document entity.
   * @param int[] $disaster_ids
   *   Disaster term IDs.
   * @param int[] $country_ids
   *   Country term IDs.
   * @param array $settings
   *   Related content settings.
   *
   * @return string[]
   *   Query clauses.
   */
  protected function buildTitleClauses(
    FieldableEntityInterface $entity,
    array $disaster_ids,
    array $country_ids,
    array $settings,
  ): array {
    $title = $entity->label();
    if ($title === NULL || $title === '') {
      return [];
    }

    $patterns = TitlePatternHelper::titleToLikePatterns($title, $settings['title_pattern_token_counts']);
    if ($patterns === []) {
      return [];
    }

    $title_query = TitlePatternHelper::likePatternToTitleQuery($patterns[0]);
    if ($title_query === '') {
      return [];
    }

    $boosts = $settings['boosts'];
    $clauses = [];
    $now = time();
    $long_from = $this->monthsAgoTimestamp($settings['recency_long_months'], $now);
    $date_gate = $this->formatDateOriginalRange($long_from, $now);

    if ($disaster_ids !== []) {
      $clauses[] = sprintf(
        '(%s AND %s AND %s)^%d',
        $title_query,
        $this->formatIdOrExpression('disaster.id', $disaster_ids),
        $date_gate,
        $boosts['title_disaster'],
      );
    }

    if ($country_ids !== []) {
      $clauses[] = sprintf(
        '(%s AND %s AND %s)^%d',
        $title_query,
        $this->formatIdOrExpression('primary_country.id', $country_ids),
        $date_gate,
        $boosts['title_primary_country'],
      );
    }

    return $clauses;
  }

  /**
   * Build the translation-matching query clause for reports.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The report entity.
   * @param array $settings
   *   Related content settings.
   *
   * @return string
   *   Parenthesized AND query or empty string.
   */
  protected function buildTranslationClause(FieldableEntityInterface $entity, array $settings): string {
    $query = [];

    $fields = [
      'primary_country' => 'primary_country',
      'country' => 'country',
      'source' => 'source',
      'content_format' => 'format',
      'theme' => 'theme',
      'disaster' => 'disaster',
      'disaster_type' => 'disaster_type',
    ];
    foreach ($fields as $field => $name) {
      $ids = $this->getReferencedEntityIds($entity, 'field_' . $field);
      if ($ids !== []) {
        $query[] = $name . '.id:(' . implode(' AND ', $ids) . ')';
      }
    }

    $languages = $this->getReferencedEntityIds($entity, 'field_language');
    if ($languages !== []) {
      $query[] = 'NOT language.id:(' . implode(' AND ', $languages) . ')';
    }

    $timestamp = $this->getOriginalPublicationTimestamp($entity);
    if ($timestamp !== NULL) {
      $window = (int) $settings['translation_date_window_days'] * 24 * 60 * 60;
      $query[] = $this->formatDateOriginalRange($timestamp - $window, $timestamp + $window);
    }

    if ($query === []) {
      return '';
    }

    return '(' . implode(' AND ', $query) . ')';
  }

  /**
   * Get the original publication timestamp for an entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   *
   * @return int|null
   *   Unix timestamp in UTC, or NULL if unavailable.
   */
  protected function getOriginalPublicationTimestamp(FieldableEntityInterface $entity): ?int {
    if (!$entity->hasField('field_original_publication_date') || $entity->get('field_original_publication_date')->isEmpty()) {
      return NULL;
    }

    $value = $entity->get('field_original_publication_date')->value;
    if ($value === NULL || $value === '') {
      return NULL;
    }

    try {
      $date = new \DateTime((string) $value, new \DateTimeZone('UTC'));
    }
    catch (\Exception) {
      return NULL;
    }

    return $date->getTimestamp();
  }

  /**
   * Get country IDs for related content matching.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The document entity.
   *
   * @return int[]
   *   Country term IDs.
   */
  protected function getCountryIds(FieldableEntityInterface $entity): array {
    if (!$entity->hasField('field_country') || $entity->get('field_country')->isEmpty()) {
      return [];
    }

    $is_report = $entity->getEntityTypeId() === 'node' && $entity->bundle() === 'report';
    if ($is_report) {
      return $this->getReferencedEntityIds($entity, 'field_country');
    }

    $country_ids = [];
    foreach ($entity->get('field_country')->referencedEntities() as $country) {
      if ($country instanceof EntityModeratedInterface && $country->getModerationStatus() === 'ongoing') {
        $country_ids[] = (int) $country->id();
      }
    }

    return $country_ids;
  }

  /**
   * Get referenced entity IDs from a field.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param string $field
   *   Field name.
   *
   * @return int[]
   *   Referenced entity IDs.
   */
  protected function getReferencedEntityIds(FieldableEntityInterface $entity, string $field): array {
    if (!$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return [];
    }

    $ids = [];
    foreach ($entity->get($field) as $item) {
      $target_id = $item->target_id ?? NULL;
      if (!empty($target_id)) {
        $ids[] = (int) $target_id;
      }
    }

    return $ids;
  }

  /**
   * Format an ID field as OR expression.
   *
   * @param string $field
   *   API field name (e.g. disaster.id).
   * @param int[] $ids
   *   Term IDs.
   *
   * @return string
   *   Query fragment.
   */
  protected function formatIdOrExpression(string $field, array $ids): string {
    if ($ids === []) {
      return '';
    }
    if (count($ids) === 1) {
      return $field . ':' . reset($ids);
    }

    return '(' . $field . ':' . implode(' OR ' . $field . ':', $ids) . ')';
  }

  /**
   * Format a date.original range query.
   *
   * @param int $from
   *   Start timestamp (UTC).
   * @param int $to
   *   End timestamp (UTC).
   *
   * @return string
   *   Query fragment.
   */
  protected function formatDateOriginalRange(int $from, int $to): string {
    return 'date.original:[' . gmdate(DATE_ATOM, $from) . ' TO ' . gmdate(DATE_ATOM, $to) . ']';
  }

  /**
   * Compute a timestamp N months before a reference time.
   *
   * @param int $months
   *   Number of months.
   * @param int $reference
   *   Reference timestamp.
   *
   * @return int
   *   Timestamp.
   */
  protected function monthsAgoTimestamp(int $months, int $reference): int {
    $date = (new \DateTime('@' . $reference))->setTimezone(new \DateTimeZone('UTC'));
    $date->modify('-' . $months . ' months');
    return $date->getTimestamp();
  }

  /**
   * Build shared matching context for PHP re-ranking.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The source document entity.
   * @param array $settings
   *   Related content settings.
   *
   * @return array
   *   Match context for tier detection.
   */
  protected function buildMatchContext(FieldableEntityInterface $entity, array $settings): array {
    $is_report = $entity->getEntityTypeId() === 'node' && $entity->bundle() === 'report';
    $title = $entity->label() ?? '';
    $patterns = $is_report && $title !== ''
      ? TitlePatternHelper::titleToLikePatterns($title, $settings['title_pattern_token_counts'])
      : [];
    $now = time();

    return [
      'settings' => $settings,
      'boosts' => $settings['boosts'],
      'is_report' => $is_report,
      'disaster_ids' => $this->getReferencedEntityIds($entity, 'field_disaster'),
      'country_ids' => $this->getCountryIds($entity),
      'primary_country_ids' => $this->getReferencedEntityIds($entity, 'field_primary_country'),
      'source_ids' => $this->getReferencedEntityIds($entity, 'field_source'),
      'theme_ids' => $this->getReferencedEntityIds($entity, 'field_theme'),
      'language_ids' => $this->getReferencedEntityIds($entity, 'field_language'),
      'format_ids' => $this->getReferencedEntityIds($entity, 'field_content_format'),
      'disaster_type_ids' => $this->getReferencedEntityIds($entity, 'field_disaster_type'),
      'title_pattern' => $patterns[0] ?? '',
      'publication_timestamp' => $this->getOriginalPublicationTimestamp($entity),
      'now' => $now,
      'short_from' => $this->monthsAgoTimestamp($settings['recency_short_months'], $now),
      'long_from' => $this->monthsAgoTimestamp($settings['recency_long_months'], $now),
    ];
  }

  /**
   * Re-rank API candidates using max-tier scoring and recency decay.
   *
   * @param array $items
   *   Raw API items.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The source document entity.
   * @param array $settings
   *   Related content settings.
   * @param int $limit
   *   Display limit.
   *
   * @return array
   *   Top-ranked API items.
   */
  protected function rankCandidates(
    array $items,
    FieldableEntityInterface $entity,
    array $settings,
    int $limit,
  ): array {
    $context = $this->buildMatchContext($entity, $settings);
    $scored = [];

    foreach ($items as $item) {
      $scored[] = [
        'item' => $item,
        'score' => $this->scoreCandidate($item, $context),
      ];
    }

    usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

    return array_map(
      static fn(array $row): array => $row['item'],
      array_slice($scored, 0, $limit),
    );
  }

  /**
   * Score a single API candidate.
   *
   * @param array $item
   *   Raw API item.
   * @param array $context
   *   Match context from buildMatchContext().
   *
   * @return float
   *   Final score after tier max and optional recency decay.
   */
  protected function scoreCandidate(array $item, array $context): float {
    $fields = $item['fields'] ?? [];
    $boosts = $context['boosts'];
    $timestamp = $this->getCandidatePublicationTimestamp($fields);
    $now = $context['now'];
    $tier_scores = [];

    if ($context['is_report'] && $this->candidateIsTranslation($fields, $context)) {
      $tier_scores[] = $boosts['translation'];
    }

    $candidate_disasters = $this->extractApiReferenceIds($fields, 'disaster');
    $candidate_primary_countries = $this->extractCandidatePrimaryCountryIds($fields);
    $candidate_countries = $this->extractApiReferenceIds($fields, 'country');

    if ($this->hasIntersection($context['disaster_ids'], $candidate_disasters)) {
      if ($this->isWithinRange($timestamp, $context['short_from'], $now)) {
        $tier_scores[] = $boosts['disaster_recent'];
      }
      if ($this->isWithinRange($timestamp, $context['long_from'], $now)) {
        $tier_scores[] = $boosts['disaster_medium'];
      }
      if ($context['country_ids'] !== [] && $this->hasIntersection($context['country_ids'], $candidate_primary_countries)) {
        if ($this->isWithinRange($timestamp, $context['short_from'], $now)) {
          $tier_scores[] = $boosts['disaster_country_recent'];
        }
        if ($this->isWithinRange($timestamp, $context['long_from'], $now)) {
          $tier_scores[] = $boosts['disaster_primary_country'];
        }
      }
      if ($context['country_ids'] !== [] && $this->hasIntersection($context['country_ids'], $candidate_countries)) {
        $tier_scores[] = $boosts['disaster_country'];
      }
      if ($context['country_ids'] === []) {
        $tier_scores[] = $boosts['disaster'];
      }
    }

    $title = (string) ($fields['title'] ?? '');
    if ($context['title_pattern'] !== '' && TitlePatternHelper::titleMatchesLikePattern($title, $context['title_pattern'])) {
      if ($this->hasIntersection($context['disaster_ids'], $candidate_disasters)
        && $this->isWithinRange($timestamp, $context['long_from'], $now)) {
        $tier_scores[] = $boosts['title_disaster'];
      }
      if ($this->hasIntersection($context['country_ids'], $candidate_primary_countries)
        && $this->isWithinRange($timestamp, $context['long_from'], $now)) {
        $tier_scores[] = $boosts['title_primary_country'];
      }
    }

    $candidate_sources = $this->extractApiReferenceIds($fields, 'source');
    if ($context['country_ids'] !== [] && $context['source_ids'] !== []
      && $this->hasIntersection($context['country_ids'], $candidate_primary_countries)
      && $this->hasIntersection($context['source_ids'], $candidate_sources)) {
      $tier_scores[] = $boosts['country_source'];
    }

    if (!$context['is_report']) {
      $candidate_themes = $this->extractApiReferenceIds($fields, 'theme');
      $max_themes = (int) ($context['settings']['theme_gate_max_count'] ?? 3);
      if ($context['country_ids'] !== [] && $context['theme_ids'] !== []
        && count($context['theme_ids']) <= $max_themes
        && $this->hasIntersection($context['country_ids'], $candidate_primary_countries)
        && $this->hasIntersection($context['theme_ids'], $candidate_themes)) {
        $tier_scores[] = $boosts['country_theme'];
      }
    }

    if ($tier_scores === []) {
      return 0.0;
    }

    $tier_score = max($tier_scores);
    if ($tier_score >= $boosts['translation']) {
      return (float) $tier_score;
    }

    $days = max(1, $timestamp !== NULL ? (int) ceil(($now - $timestamp) / 86400) : 365);
    $recency = 1 / pow($days, (float) $context['settings']['recency_decay_exponent']);

    return $tier_score * $recency;
  }

  /**
   * Determine whether a candidate is a translation of the source report.
   *
   * @param array $fields
   *   Candidate API fields.
   * @param array $context
   *   Match context.
   *
   * @return bool
   *   TRUE if the candidate matches translation criteria.
   */
  protected function candidateIsTranslation(array $fields, array $context): bool {
    $checks = [
      [$context['primary_country_ids'], $this->extractCandidatePrimaryCountryIds($fields)],
      [$context['country_ids'], $this->extractApiReferenceIds($fields, 'country')],
      [$context['source_ids'], $this->extractApiReferenceIds($fields, 'source')],
      [$context['format_ids'], $this->extractApiReferenceIds($fields, 'format')],
      [$context['theme_ids'], $this->extractApiReferenceIds($fields, 'theme')],
      [$context['disaster_ids'], $this->extractApiReferenceIds($fields, 'disaster')],
      [$context['disaster_type_ids'], $this->extractApiReferenceIds($fields, 'disaster_type')],
    ];

    foreach ($checks as [$expected, $actual]) {
      if ($expected === []) {
        continue;
      }
      sort($expected);
      if ($expected !== $actual) {
        return FALSE;
      }
    }

    $candidate_languages = $this->extractApiReferenceIds($fields, 'language');
    if ($context['language_ids'] !== [] && $this->hasIntersection($context['language_ids'], $candidate_languages)) {
      return FALSE;
    }

    if ($context['publication_timestamp'] === NULL) {
      return FALSE;
    }

    $candidate_timestamp = $this->getCandidatePublicationTimestamp($fields);
    if ($candidate_timestamp === NULL) {
      return FALSE;
    }

    $window = (int) $context['settings']['translation_date_window_days'] * 86400;
    return $candidate_timestamp >= ($context['publication_timestamp'] - $window)
      && $candidate_timestamp <= ($context['publication_timestamp'] + $window);
  }

  /**
   * Extract reference term IDs from an API field.
   *
   * @param array $fields
   *   API item fields.
   * @param string $key
   *   Field key.
   *
   * @return int[]
   *   Sorted term IDs.
   */
  protected function extractApiReferenceIds(array $fields, string $key): array {
    if (!isset($fields[$key])) {
      return [];
    }

    $items = $fields[$key];
    if (isset($items['id'])) {
      $items = [$items];
    }

    $ids = [];
    foreach ($items as $item) {
      if (isset($item['id'])) {
        $ids[] = (int) $item['id'];
      }
    }

    sort($ids);
    return $ids;
  }

  /**
   * Extract primary country IDs from candidate API fields.
   *
   * @param array $fields
   *   API item fields.
   *
   * @return int[]
   *   Sorted primary country IDs.
   */
  protected function extractCandidatePrimaryCountryIds(array $fields): array {
    $ids = [];
    foreach ($fields['country'] ?? [] as $country) {
      if (!empty($country['primary']) && isset($country['id'])) {
        $ids[] = (int) $country['id'];
      }
    }

    sort($ids);
    return $ids;
  }

  /**
   * Get the original publication timestamp from API fields.
   *
   * @param array $fields
   *   API item fields.
   *
   * @return int|null
   *   Unix timestamp in UTC, or NULL if unavailable.
   */
  protected function getCandidatePublicationTimestamp(array $fields): ?int {
    $value = $fields['date']['original'] ?? NULL;
    if ($value === NULL || $value === '') {
      return NULL;
    }

    try {
      $date = new \DateTime((string) $value, new \DateTimeZone('UTC'));
    }
    catch (\Exception) {
      return NULL;
    }

    return $date->getTimestamp();
  }

  /**
   * Check whether two ID lists share at least one value.
   *
   * @param int[] $left
   *   First ID list.
   * @param int[] $right
   *   Second ID list.
   *
   * @return bool
   *   TRUE when the lists intersect.
   */
  protected function hasIntersection(array $left, array $right): bool {
    return array_intersect($left, $right) !== [];
  }

  /**
   * Check whether a timestamp falls within a range.
   *
   * @param int|null $timestamp
   *   Timestamp to test.
   * @param int $from
   *   Range start.
   * @param int $to
   *   Range end.
   *
   * @return bool
   *   TRUE when the timestamp is within the range.
   */
  protected function isWithinRange(?int $timestamp, int $from, int $to): bool {
    return $timestamp !== NULL && $timestamp >= $from && $timestamp <= $to;
  }

  /**
   * Load related content settings merged with defaults.
   *
   * @return array
   *   Settings array.
   */
  protected function getSettings(): array {
    $config = $this->configFactory->get('reliefweb_entities.settings')->get('related_content') ?? [];
    $settings = array_replace_recursive(self::DEFAULT_SETTINGS, $config);
    $settings['boosts'] = array_replace(self::DEFAULT_SETTINGS['boosts'], $settings['boosts'] ?? []);
    return $settings;
  }

  /**
   * Build the related content render array.
   *
   * @param array $entities
   *   River entities.
   * @param string $title
   *   Block title.
   *
   * @return array
   *   Render array.
   */
  protected function buildRenderArray(array $entities, string $title): array {
    return [
      '#theme' => 'reliefweb_rivers_river',
      '#id' => 'related',
      '#title' => $title,
      '#resource' => 'reports',
      '#entities' => $entities,
      '#cache' => [
        'tags' => [
          'node_list:report',
          'taxonomy_term_list:country',
          'taxonomy_term_list:source',
        ],
      ],
    ];
  }

  /**
   * Translate a string.
   *
   * @param string $string
   *   String to translate.
   * @param array $args
   *   Replacement arguments.
   *
   * @return string
   *   Translated string.
   */
  protected function t(string $string, array $args = []): string {
    return (string) $this->stringTranslation->translate($string, $args);
  }

}
