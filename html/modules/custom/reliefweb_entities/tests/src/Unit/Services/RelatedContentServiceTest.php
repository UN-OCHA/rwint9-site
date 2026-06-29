<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_entities\Unit\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\reliefweb_api\Services\ReliefWebApiClient;
use Drupal\reliefweb_entities\Services\RelatedContentService;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests for the RelatedContentService.
 */
#[CoversClass(RelatedContentService::class)]
#[Group('reliefweb_entities')]
class RelatedContentServiceTest extends UnitTestCase {

  /**
   * The service under test.
   */
  protected TestableRelatedContentService $service;

  /**
   * The mocked API client.
   */
  protected ReliefWebApiClient&MockObject $apiClient;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('related_content')->willReturn([]);

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('reliefweb_entities.settings')->willReturn($config);

    $string_translation = $this->createMock(TranslationInterface::class);
    $string_translation->method('translate')->willReturnCallback(
      static fn(string $string, array $args = []): string => strtr($string, $args),
    );

    $this->apiClient = $this->createMock(ReliefWebApiClient::class);

    $this->service = new TestableRelatedContentService(
      $this->apiClient,
      $config_factory,
      $string_translation,
    );
  }

  /**
   * Tests translation date range uses original publication date, not epoch.
   */
  public function testTranslationClauseUsesOriginalPublicationDate(): void {
    $entity = $this->createReportEntity([
      'field_primary_country' => [71],
      'field_country' => [71],
      'field_source' => [1503],
      'field_content_format' => [12570],
      'field_theme' => [4589],
      'field_disaster' => [52461],
      'field_disaster_type' => [4611],
      'field_language' => [267],
      'field_original_publication_date' => '2013-12-24',
    ]);

    $clause = $this->service->exposeBuildTranslationClause($entity);

    $this->assertStringContainsString('date.original:[2013-12-22T00:00:00+00:00 TO 2013-12-26T00:00:00+00:00]', $clause);
    $this->assertStringNotContainsString('1969-12-30', $clause);
    $this->assertStringNotContainsString('1970-01-03', $clause);
  }

  /**
   * Tests translation clause retains full tagging and language exclusion.
   */
  public function testTranslationClauseRetainsTaggingAndLanguageExclusion(): void {
    $entity = $this->createReportEntity([
      'field_primary_country' => [71],
      'field_country' => [71],
      'field_source' => [1503],
      'field_content_format' => [12570],
      'field_theme' => [4589, 4590],
      'field_disaster' => [52461],
      'field_disaster_type' => [4611, 4618],
      'field_language' => [267],
      'field_original_publication_date' => '2013-12-24',
    ]);

    $clause = $this->service->exposeBuildTranslationClause($entity);

    $this->assertStringContainsString('primary_country.id:(71)', $clause);
    $this->assertStringContainsString('country.id:(71)', $clause);
    $this->assertStringContainsString('source.id:(1503)', $clause);
    $this->assertStringContainsString('format.id:(12570)', $clause);
    $this->assertStringContainsString('theme.id:(4589 AND 4590)', $clause);
    $this->assertStringContainsString('disaster.id:(52461)', $clause);
    $this->assertStringContainsString('disaster_type.id:(4611 AND 4618)', $clause);
    $this->assertStringContainsString('NOT language.id:(267)', $clause);
  }

  /**
   * Tests related query does not include bare theme OR clauses.
   */
  public function testBuildQueryClausesExcludesBareThemeOr(): void {
    $entity = $this->createReportEntity([
      'field_disaster' => [52461],
      'field_country' => [71],
      'field_theme' => [4589, 4590, 4592],
      'field_source' => [1503],
      'title' => '100 Day Plan for Priority Humanitarian Action',
    ], 4212380);

    $clauses = $this->service->exposeBuildQueryClauses($entity);
    $query = implode(' OR ', $clauses);

    $this->assertDoesNotMatchRegularExpression('/(?<!\()theme\.id:\d+ OR theme\.id:/', $query);
    $this->assertStringNotContainsString('theme.id:4589 OR theme.id:4590', $query);
  }

  /**
   * Tests disaster clauses include recency date ranges.
   */
  public function testDisasterClausesIncludeRecencyDateRanges(): void {
    $clauses = $this->service->exposeBuildDisasterClauses(
      [52461],
      [71],
      $this->service->exposeGetSettings(),
    );
    $query = implode(' ', $clauses);

    $this->assertMatchesRegularExpression('/date\.original:\[[^\]]+ TO [^\]]+\]/', $query);
    $this->assertStringContainsString('disaster.id:52461', $query);
  }

  /**
   * Tests only ongoing countries are used for job entities.
   */
  public function testJobEntityUsesOnlyOngoingCountries(): void {
    $ongoing = $this->createModeratedCountry(71, 'ongoing');
    $archived = $this->createModeratedCountry(99, 'archived');

    $entity = $this->createJobEntity([
      'field_country' => [$ongoing, $archived],
    ]);

    $country_ids = $this->service->exposeGetCountryIds($entity);

    $this->assertSame([71], $country_ids);
  }

  /**
   * Tests job entities use country + theme when theme count is within the gate.
   */
  public function testJobEntityUsesCountryThemeClause(): void {
    $ongoing = $this->createModeratedCountry(71, 'ongoing');

    $entity = $this->createJobEntity([
      'field_country' => [$ongoing],
      'field_theme' => [4589],
    ]);

    $query = implode(' OR ', $this->service->exposeBuildQueryClauses($entity));

    $this->assertStringContainsString('primary_country.id:71', $query);
    $this->assertStringContainsString('theme.id:4589', $query);
    $this->assertStringContainsString('^30', $query);
  }

  /**
   * Tests job entities skip country + theme when too many themes are tagged.
   */
  public function testJobEntitySkipsCountryThemeWhenTooManyThemes(): void {
    $ongoing = $this->createModeratedCountry(71, 'ongoing');

    $entity = $this->createJobEntity([
      'field_country' => [$ongoing],
      'field_theme' => [4589, 4590, 4592, 4593],
    ]);

    $query = implode(' OR ', $this->service->exposeBuildQueryClauses($entity));

    $this->assertStringNotContainsString('theme.id:', $query);
  }

  /**
   * Tests report payload excludes the current entity.
   */
  public function testBuildApiPayloadForReportExcludesSelf(): void {
    $entity = $this->createReportEntity([
      'field_disaster' => [52461],
      'field_country' => [71],
    ], 100);

    $payload = $this->service->exposeBuildApiPayload($entity, 4);

    $this->assertSame('id', $payload['filter']['field']);
    $this->assertSame(100, $payload['filter']['value']);
    $this->assertTrue($payload['filter']['negate']);
  }

  /**
   * Tests entities without anchors produce no query clauses.
   */
  public function testEmptyEntityProducesNoQueryClauses(): void {
    $entity = $this->createReportEntity([], 1);

    $this->assertSame([], $this->service->exposeBuildQueryClauses($entity));
  }

  /**
   * Tests disaster + primary country recent tier is present in query.
   */
  public function testDisasterCountryRecentClauseInQuery(): void {
    $entity = $this->createReportEntity([
      'field_disaster' => [52461],
      'field_country' => [71],
      'field_primary_country' => [71],
    ]);

    $query = implode(' OR ', $this->service->exposeBuildQueryClauses($entity));

    $this->assertStringContainsString('disaster.id:52461', $query);
    $this->assertStringContainsString('primary_country.id:71', $query);
    $this->assertStringContainsString('date.original:[', $query);
    $this->assertStringContainsString('^55', $query);
  }

  /**
   * Tests bare disaster fallback is omitted when country is tagged.
   */
  public function testUndatedDisasterFallbackOmittedWhenCountryPresent(): void {
    $clauses = $this->service->exposeBuildDisasterClauses(
      [52461],
      [71],
      $this->service->exposeGetSettings(),
    );

    $this->assertNotContains('disaster.id:52461^15', $clauses);
  }

  /**
   * Tests title tiers include a 12-month date gate.
   */
  public function testTitleTiersIncludeDateGate(): void {
    $entity = $this->createReportEntity([
      'field_disaster' => [52461],
      'field_country' => [71],
      'field_primary_country' => [71],
      'title' => 'Cuba Monthly Monitoring United Nations Plan of Action',
    ]);

    $clauses = $this->service->exposeBuildTitleClauses(
      $entity,
      [52461],
      [71],
      $this->service->exposeGetSettings(),
    );
    $query = implode(' ', $clauses);

    $this->assertNotSame([], $clauses);
    $this->assertMatchesRegularExpression('/date\.original:\[[^\]]+ TO [^\]]+\]/', $query);
    $this->assertStringContainsString('title:', $query);
  }

  /**
   * Tests re-ranking prefers recent crisis match over old series installment.
   */
  public function testRankCandidatesPrefersRecentCrisisOverOldSeries(): void {
    $entity = $this->createReportEntity([
      'field_disaster' => [52461],
      'field_country' => [71],
      'field_primary_country' => [71],
      'title' => 'Cuba Monthly Monitoring United Nations Plan of Action October 2025',
    ], 9001);

    $old_series = $this->createApiItem(100, [
      'title' => 'Cuba Monthly Monitoring United Nations Plan of Action October 2025',
      'date' => ['original' => '2025-10-15T00:00:00+00:00'],
      'disaster' => [['id' => 52461]],
      'country' => [['id' => 71, 'primary' => TRUE]],
    ]);
    $recent_crisis = $this->createApiItem(200, [
      'title' => 'Cuba: Flash Update on Hurricane Response',
      'date' => ['original' => '2026-04-01T00:00:00+00:00'],
      'disaster' => [['id' => 52461]],
      'country' => [['id' => 71, 'primary' => TRUE]],
    ]);

    $ranked = $this->service->exposeRankCandidates(
      [$old_series, $recent_crisis],
      $entity,
      2,
    );

    $this->assertSame(200, $ranked[0]['id']);
  }

  /**
   * Tests translations bypass recency decay.
   */
  public function testRankCandidatesTranslationBypassesDecay(): void {
    $entity = $this->createReportEntity([
      'field_primary_country' => [71],
      'field_country' => [71],
      'field_source' => [1503],
      'field_content_format' => [12570],
      'field_theme' => [4589],
      'field_disaster' => [52461],
      'field_disaster_type' => [4611],
      'field_language' => [267],
      'field_original_publication_date' => '2013-12-24',
      'title' => 'Original English Report',
    ], 9002);

    $old_translation = $this->createApiItem(300, [
      'title' => 'Informe en español',
      'date' => ['original' => '2013-12-24T00:00:00+00:00'],
      'country' => [['id' => 71, 'primary' => TRUE]],
      'source' => [['id' => 1503]],
      'format' => [['id' => 12570]],
      'theme' => [['id' => 4589]],
      'disaster' => [['id' => 52461]],
      'disaster_type' => [['id' => 4611]],
      'language' => [['id' => 268]],
    ]);
    $recent_lower_tier = $this->createApiItem(400, [
      'title' => 'Recent lower-tier match',
      'date' => ['original' => gmdate('c')],
      'disaster' => [['id' => 52461]],
      'country' => [['id' => 71, 'primary' => TRUE]],
    ]);

    $ranked = $this->service->exposeRankCandidates(
      [$recent_lower_tier, $old_translation],
      $entity,
      2,
    );

    $this->assertSame(300, $ranked[0]['id']);
  }

  /**
   * Tests re-ranking uses max tier score, not sum of tiers.
   */
  public function testRankCandidatesUsesMaxTierNotSum(): void {
    $entity = $this->createReportEntity([
      'field_disaster' => [52461],
      'field_country' => [71],
      'field_primary_country' => [71],
      'title' => 'Situation Report',
    ], 9003);

    $six_months_ago = gmdate('c', strtotime('-6 months'));
    $multi_low_tier = $this->createApiItem(500, [
      'title' => 'Situation Report April 2026',
      'date' => ['original' => $six_months_ago],
      'disaster' => [['id' => 52461]],
      'country' => [['id' => 71, 'primary' => TRUE]],
    ]);
    $single_high_tier = $this->createApiItem(600, [
      'title' => 'Flash Update',
      'date' => ['original' => gmdate('c')],
      'disaster' => [['id' => 52461]],
      'country' => [['id' => 71, 'primary' => TRUE]],
    ]);

    $multi_score = $this->service->exposeScoreCandidate($multi_low_tier, $entity);
    $high_score = $this->service->exposeScoreCandidate($single_high_tier, $entity);

    $this->assertGreaterThan($multi_score, $high_score);
  }

  /**
   * Create a minimal API item fixture.
   *
   * @param int $id
   *   Item ID.
   * @param array<string, mixed> $fields
   *   API fields.
   *
   * @return array<string, mixed>
   *   API item.
   */
  protected function createApiItem(int $id, array $fields): array {
    return [
      'id' => $id,
      'fields' => $fields,
    ];
  }

  /**
   * Create a mock report entity.
   *
   * @param array<string, mixed> $fields
   *   Field values keyed by field name.
   * @param int|null $id
   *   Entity ID.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface&\PHPUnit\Framework\MockObject\MockObject
   *   Mock report entity.
   */
  protected function createReportEntity(array $fields, ?int $id = 1): object {
    return $this->createFieldableEntity('report', $fields, $id);
  }

  /**
   * Create a mock job entity.
   *
   * @param array<string, mixed> $fields
   *   Field values keyed by field name.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface&\PHPUnit\Framework\MockObject\MockObject
   *   Mock job entity.
   */
  protected function createJobEntity(array $fields): object {
    return $this->createFieldableEntity('job', $fields, 200);
  }

  /**
   * Create a mock fieldable node entity.
   *
   * @param string $bundle
   *   Bundle name.
   * @param array<string, mixed> $fields
   *   Field values keyed by field name.
   * @param int|null $id
   *   Entity ID.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface&\PHPUnit\Framework\MockObject\MockObject
   *   Mock entity.
   */
  protected function createFieldableEntity(string $bundle, array $fields, ?int $id): FieldableEntityInterface&MockObject {
    $title = $fields['title'] ?? 'Test report';
    unset($fields['title']);

    $entity = $this->createMock(FieldableEntityInterface::class);

    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('bundle')->willReturn($bundle);
    $entity->method('id')->willReturn($id);
    $entity->method('label')->willReturn($title);

    $field_map = [];
    foreach ($fields as $field_name => $value) {
      $field_map[$field_name] = $this->createFieldItemList($field_name, $value);
    }

    $entity->method('hasField')->willReturnCallback(
      static fn(string $field): bool => isset($field_map[$field]),
    );
    $entity->method('get')->willReturnCallback(
      function (string $field) use ($field_map) {
        return $field_map[$field] ?? new MockFieldItemList(empty: TRUE);
      },
    );

    return $entity;
  }

  /**
   * Create a mock moderated country term.
   *
   * @param int $id
   *   Term ID.
   * @param string $status
   *   Moderation status.
   *
   * @return \Drupal\reliefweb_moderation\EntityModeratedInterface&\PHPUnit\Framework\MockObject\MockObject
   *   Mock country term.
   */
  protected function createModeratedCountry(int $id, string $status): EntityModeratedInterface&MockObject {
    $country = $this->createMockForIntersectionOfInterfaces([
      EntityModeratedInterface::class,
      EntityInterface::class,
    ]);
    $country->method('id')->willReturn((string) $id);
    $country->method('getModerationStatus')->willReturn($status);

    return $country;
  }

  /**
   * Create a field item list mock.
   *
   * @param string $field_name
   *   Field name.
   * @param mixed $value
   *   Field value(s).
   *
   * @return \Drupal\Tests\reliefweb_entities\Unit\Services\MockFieldItemList
   *   Field item list mock.
   */
  protected function createFieldItemList(string $field_name, mixed $value): MockFieldItemList {
    if ($field_name === 'field_original_publication_date') {
      return new MockFieldItemList(
        empty: $value === NULL || $value === '',
        value: $value,
      );
    }

    if ($field_name === 'field_country' && is_array($value) && isset($value[0]) && is_object($value[0])) {
      return new MockFieldItemList(
        empty: FALSE,
        referenced_entities: $value,
      );
    }

    $ids = is_array($value) ? $value : [$value];
    $items = [];
    foreach ($ids as $target_id) {
      $item = new \stdClass();
      $item->target_id = $target_id;
      $items[] = $item;
    }

    return new MockFieldItemList(
      empty: $items === [],
      items: $items,
    );
  }

}

/**
 * Testable subclass exposing protected methods.
 */
final class TestableRelatedContentService extends RelatedContentService {

  /**
   * {@inheritdoc}
   */
  protected function getReportRiverApiPayload(): array {
    return [
      'fields' => [
        'include' => [],
        'exclude' => [],
      ],
    ];
  }

  /**
   * Expose buildTranslationClause().
   */
  public function exposeBuildTranslationClause(object $entity): string {
    return $this->buildTranslationClause($entity, $this->getSettings());
  }

  /**
   * Expose buildQueryClauses().
   */
  public function exposeBuildQueryClauses(object $entity): array {
    return $this->buildQueryClauses($entity);
  }

  /**
   * Expose buildDisasterClauses().
   */
  public function exposeBuildDisasterClauses(array $disaster_ids, array $country_ids, array $settings): array {
    return $this->buildDisasterClauses($disaster_ids, $country_ids, $settings);
  }

  /**
   * Expose getCountryIds().
   */
  public function exposeGetCountryIds(object $entity): array {
    return $this->getCountryIds($entity);
  }

  /**
   * Expose buildCountryThemeClauses().
   */
  public function exposeBuildCountryThemeClauses(array $country_ids, array $theme_ids, array $settings): array {
    return $this->buildCountryThemeClauses($country_ids, $theme_ids, $settings);
  }

  /**
   * Expose buildApiPayload().
   */
  public function exposeBuildApiPayload(object $entity, int $limit): array {
    return $this->buildApiPayload($entity, $limit);
  }

  /**
   * Expose getSettings().
   */
  public function exposeGetSettings(): array {
    return $this->getSettings();
  }

  /**
   * Expose buildTitleClauses().
   */
  public function exposeBuildTitleClauses(
    object $entity,
    array $disaster_ids,
    array $country_ids,
    array $settings,
  ): array {
    return $this->buildTitleClauses($entity, $disaster_ids, $country_ids, $settings);
  }

  /**
   * Expose rankCandidates().
   */
  public function exposeRankCandidates(array $items, object $entity, int $limit): array {
    return $this->rankCandidates($items, $entity, $this->getSettings(), $limit);
  }

  /**
   * Expose scoreCandidate().
   */
  public function exposeScoreCandidate(array $item, object $entity): float {
    $context = $this->buildMatchContext($entity, $this->getSettings());
    return $this->scoreCandidate($item, $context);
  }

}
