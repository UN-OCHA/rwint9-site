<?php

namespace Drupal\reliefweb_analytics\Command;

use Drupal\path_alias\AliasRepositoryInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Consolidation\SiteProcess\ProcessManagerAwareTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drush\Commands\DrushCommands;
use Google\Analytics\Data\V1beta\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\Filter;
use Google\Analytics\Data\V1beta\Filter\StringFilter;
use Google\Analytics\Data\V1beta\Filter\StringFilter\MatchType;
use Google\Analytics\Data\V1beta\FilterExpression;
use Google\Analytics\Data\V1beta\FilterExpressionList;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\OrderBy\MetricOrderBy;
use Google\ApiCore\ApiException;

/**
 * ReliefWeb Most Read Drush commandfile.
 */
class ReliefwebMostReadCommand extends DrushCommands implements SiteAliasManagerAwareInterface {

  // Drush traits.
  use ProcessManagerAwareTrait;
  use SiteAliasManagerAwareTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Path alias manager.
   *
   * @var \Drupal\path_alias\AliasRepositoryInterface
   */
  protected $pathAliasRepository;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The state store.
   *
   * @var Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AliasRepositoryInterface $path_alias_repository,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->state = $state;
    $this->pathAliasRepository = $path_alias_repository;
  }

  /**
   * Most read for homepage.
   *
   * @command reliefweb_analytics:homepage
   * @usage reliefweb_analytics:homepage
   *   Retrieve the most read reports for the homepage.
   * @validate-module-enabled reliefweb_analytics
   * @aliases reliefweb-mostread-homepage
   */
  public function homepage() {
    $results = [];

    // Fetch data from GA4.
    $this->logger()->notice('Processing home page');
    $parameters = $this->getHomePagePayload();
    $data = $this->fetchGa4Data($parameters);
    if (!empty($data) && is_array(($data))) {
      $results['front'] = [
        'front',
        implode(',', $data),
      ];
    }

    if (!empty($results)) {
      $this->updateCsv($results);
    }
  }

  /**
   * Most read for countries.
   *
   * @command reliefweb_analytics:countries
   * @usage reliefweb_analytics:countries
   *   Retrieve the most read reports for each country.
   * @validate-module-enabled reliefweb_analytics
   * @aliases reliefweb-mostread-countries
   */
  public function countries() {
    $results = [];

    // Load all terms.
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();
    $query->condition('vid', 'country');
    $query->condition('tid', $this->state->get('reliefweb_analytics_countries_last_tid', 0), '>');
    $query->sort('tid');
    $tids = $query->execute();

    // Reset last tid when empty.
    if (empty($tids)) {
      $this->state->set('reliefweb_analytics_countries_last_tid', 0);
      return $this->countries();
    }

    $countries = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($tids);

    // Fetch data from GA4.
    foreach ($countries as $country) {
      $this->logger()->notice('Processing ' . $country->label());
      $parameters = $this->getCountryPayload($country->label());
      $data = $this->fetchGa4Data($parameters);

      // Check for rate_limit.
      if ($data == 'rate_limit') {
        break;
      }

      if (!empty($data) && is_array($data)) {
        $this->state->set('reliefweb_analytics_countries_last_tid', $country->id());
        $results[$country->id()] = [
          $country->id(),
          implode(',', $data),
        ];
      }
    }

    if (!empty($results)) {
      $this->updateCsv($results);
    }
  }

  /**
   * Most read for countries.
   *
   * @command reliefweb_analytics:countries-all
   * @usage reliefweb_analytics:countries-all
   *   Send emails.
   * @validate-module-enabled reliefweb_analytics
   * @aliases reliefweb-mostread-countries-all
   */
  public function countriesAll() {
    $results = [];
    $parameters = $this->getAllCountriesPayload();
    $combined = $this->fetchGa4DataCombined($parameters);

    if (!empty($combined)) {
      foreach ($combined as $country => $data) {
        $results[$country] = [
          $country,
          implode(',', $data),
        ];
      }
    }

    if (!empty($results)) {
      $this->updateCsv($results);
    }
  }

  /**
   * Most read for disasters.
   *
   * @command reliefweb_analytics:disasters
   * @usage reliefweb_analytics:disasters
   *   Retrieve the most read reports for each disaster.
   * @validate-module-enabled reliefweb_analytics
   * @aliases reliefweb-mostread-disasters
   */
  public function disasters() {
    $ids_to_remove = [];
    $active_disaster_states = $this->state->get('reliefweb_analytics_active_disaster_states', [
      'alert',
      'disaster',
      'past',
    ]);

    // Load all terms.
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();
    $query->condition('vid', 'disaster');
    $query->condition('tid', $this->state->get('reliefweb_analytics_disasters_last_tid', 0), '>');
    $query->sort('tid');
    $tids = $query->execute();

    // Reset last tid when empty.
    if (empty($tids)) {
      $this->state->set('reliefweb_analytics_disasters_last_tid', 0);
      return $this->countries();
    }

    $disasters = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($tids);
    foreach ($disasters as $disaster) {
      // Check moderation_status.
      $moderation_status = $disaster->moderation_status->value ?? 'not set';
      if (!in_array($moderation_status, $active_disaster_states)) {
        $ids_to_remove[] = $disaster->id();
        continue;
      }

      $this->logger()->notice('Processing ' . $disaster->label());
      $parameters = $this->getDisasterPayload($disaster->label());
      $data = $this->fetchGa4Data($parameters);

      // Check for rate_limit.
      if ($data == 'rate_limit') {
        break;
      }

      if (!empty($data) && is_array($data)) {
        $this->state->set('reliefweb_analytics_disasters_last_tid', $disaster->id());
        $results[$disaster->id()] = [
          $disaster->id(),
          implode(',', $data),
        ];
      }
    }

    if (!empty($results)) {
      $this->updateCsv($results, $ids_to_remove);
    }
  }

  /**
   * Most read for disasters using a single request.
   *
   * @command reliefweb_analytics:disasters-all
   * @usage reliefweb_analytics:disasters-all
   *   Send emails.
   * @validate-module-enabled reliefweb_analytics
   * @aliases reliefweb-mostread-disasters-all
   */
  public function disastersAll() {
    $results = [];
    $parameters = $this->getAllDisastersPayload();
    $combined = $this->fetchGa4DataCombined($parameters);

    if (!empty($combined)) {
      foreach ($combined as $disaster => $data) {
        $results[$disaster] = [
          $disaster,
          implode(',', $data),
        ];
      }
    }

    if (!empty($results)) {
      $this->updateCsv($results);
    }
  }

  /**
   * Update csv file.
   */
  protected function updateCsv($results, $ids_to_remove = []) {
    // Load original csv.
    $csv = [];
    $handle = @fopen('public://most-read/most-read.csv', 'r');
    if ($handle) {
      while (($row = fgetcsv($handle, 100)) !== FALSE) {
        $csv[$row[0]] = $row;
      }

      if (is_resource($handle)) {
        @fclose($handle);
      }
    }

    // Merge new data.
    $csv = array_replace($csv, $results);

    // Remove old ids.
    array_diff_key($csv, $ids_to_remove);

    // Write new file.
    @mkdir('public://most-read');
    $handle = @fopen('public://most-read/most-read.csv', 'w');
    if ($handle === FALSE) {
      return [];
    }

    foreach ($csv as $row) {
      fputcsv($handle, $row);
    }

    if (is_resource($handle)) {
      @fclose($handle);
    }
  }

  /**
   * Get GA4 clinet.
   */
  protected function getGa4Client() {
    static $client = NULL;
    if (!$client) {
      $client = new BetaAnalyticsDataClient(['credentials' => '/var/www/credentials.json']);
    }

    return $client;
  }

  /**
   * Fetch and process GA4 data.
   *
   * @param array $parameters
   *   Payload.
   *
   * @see https://developers.google.com/analytics/devguides/reporting/core/v4/limits-quotas#analytics_reporting_api_v4
   */
  public function fetchGa4Data(array $parameters) {
    $results = [];

    try {
      $start = microtime(TRUE);
      $response = $this->getGa4Client()->runReport($parameters);

      // Make sure it takes at least a second.
      $end = microtime(TRUE);
      if ($end - $start < 1) {
        usleep($end - $start);
      }
    }
    catch (ApiException $exception) {
      if ($exception->getStatus() == 'RESOURCE_EXHAUSTED') {
        $this->logger()->warning('Rate limit hit.');
        $this->logger()->warning('Google exception: ' . $exception->getMessage());
        return 'rate_limit';
      }
      else {
        $this->logger()->error('Google exception: ' . $exception->getMessage());
      }
      exit();
    }
    catch (\Exception $exception) {
      $this->logger()->error('Exception: ' . $exception->getMessage());
      exit();
    }

    // Log quota.
    $quota = $response->getPropertyQuota();
    $this->logger()->notice(strtr('Day: @d, hour: @h, errors/hour: @e, threshold: @t', [
      '@d' => $quota->getTokensPerDay()->getConsumed() . '/' . $quota->getTokensPerDay()->getRemaining(),
      '@h' => $quota->getTokensPerHour()->getConsumed() . '/' . $quota->getTokensPerHour()->getRemaining(),
      '@e' => $quota->getServerErrorsPerProjectPerHour()->getConsumed() . '/' . $quota->getServerErrorsPerProjectPerHour()->getRemaining(),
      '@t' => $quota->getPotentiallyThresholdedRequestsPerHour()->getConsumed() . '/' . $quota->getPotentiallyThresholdedRequestsPerHour()->getRemaining(),
    ]));

    foreach ($response->getRows() as $row) {
      if ($lookup = $this->pathAliasRepository->lookupByAlias($row->getDimensionValues()[0]->getValue(), 'en')) {
        $results[] = $lookup['id'];
      }
    }

    return $results;
  }

  /**
   * Fetch top 100.000 page views and calculate top 5.
   *
   * @param array $parameters
   *   Payload.
   *
   * @see https://developers.google.com/analytics/devguides/reporting/core/v4/limits-quotas#analytics_reporting_api_v4
   */
  public function fetchGa4DataCombined(array $parameters) {
    $results = [];

    try {
      $start = microtime(TRUE);
      $response = $this->getGa4Client()->runReport($parameters);

      // Make sure it takes at least a second.
      $end = microtime(TRUE);
      if ($end - $start < 1) {
        usleep($end - $start);
      }
    }
    catch (ApiException $exception) {
      if ($exception->getStatus() == 'RESOURCE_EXHAUSTED') {
        $this->logger()->warning('Rate limit hit.');
        $this->logger()->warning('Google exception: ' . $exception->getMessage());
      }
      else {
        $this->logger()->error('Google exception: ' . $exception->getMessage());
      }
      exit();
    }
    catch (\Exception $exception) {
      $this->logger()->error('Exception: ' . $exception->getMessage());
      exit();
    }

    // Log quota.
    $quota = $response->getPropertyQuota();
    $this->logger()->notice(strtr('Day: @d, hour: @h, errors/hour: @e, threshold: @t', [
      '@d' => $quota->getTokensPerDay()->getConsumed() . '/' . $quota->getTokensPerDay()->getRemaining(),
      '@h' => $quota->getTokensPerHour()->getConsumed() . '/' . $quota->getTokensPerHour()->getRemaining(),
      '@e' => $quota->getServerErrorsPerProjectPerHour()->getConsumed() . '/' . $quota->getServerErrorsPerProjectPerHour()->getRemaining(),
      '@t' => $quota->getPotentiallyThresholdedRequestsPerHour()->getConsumed() . '/' . $quota->getPotentiallyThresholdedRequestsPerHour()->getRemaining(),
    ]));

    foreach ($response->getRows() as $row) {
      if (isset($row->getDimensionValues()[1]) && !empty($row->getDimensionValues()[1]->getValue()) && $row->getDimensionValues()[1]->getValue() !== '(not set)') {
        // Expand $row->getDimensionValues()[1].
        $parts = explode(',', $row->getDimensionValues()[1]->getValue());
        foreach ($parts as $part) {
          $part = trim($part);
          if (!isset($results[$part])) {
            $results[$part] = [];
          }
          if (count($results[$part]) < 5) {
            if ($lookup = $this->pathAliasRepository->lookupByAlias($row->getDimensionValues()[0]->getValue(), 'en')) {
              $results[$part][] = $lookup['id'];
            }
          }
        }
      }
    }

    return $results;
  }

  /**
   * Our logger.
   */
  protected function getLogger(): LoggerChannelInterface {
    return $this->loggerFactory->get('reliefweb_analytics');
  }

  /**
   * Common payload.
   */
  protected function buildPayload($filter = NULL) {
    $expressions = [
      new FilterExpression([
        'filter' => new Filter([
          'field_name' => 'customEvent:content_type',
          'string_filter' => new StringFilter([
            'value' => 'Report',
          ]),
        ]),
      ]),
    ];

    if ($filter) {
      $expressions[] = $filter;
    }

    return [
      'property' => 'properties/' . $this->state->get('reliefweb_analytics_property_id', '291027553'),
      'limit' => $this->state->get('reliefweb_analytics_limit', 5),
      'returnPropertyQuota' => TRUE,
      'dateRanges' => [
        new DateRange([
          'start_date' => $this->state->get('reliefweb_analytics_start_date', '30daysAgo'),
          'end_date' => 'today',
        ]),
      ],
      'dimensions' => [
        new Dimension(['name' => 'pagePath']),
      ],
      'metrics' => [
        new Metric(['name' => 'activeUsers']),
      ],
      'order_bys' => [
        new MetricOrderBy(['metric_name' => 'screenPageViews']),
      ],
      'dimensionFilter' => new FilterExpression([
        'and_group' => new FilterExpressionList([
          'expressions' => $expressions,
        ]),
      ]),
    ];
  }

  /**
   * Homepage payload.
   */
  protected function getHomePagePayload() {
    $payload = $this->buildPayload();

    $payload['dateRanges'] = [
      new DateRange([
        'start_date' => $this->state->get('reliefweb_analytics_homepage_start_date', 'yesterday'),
        'end_date' => 'today',
      ]),
    ];

    return $payload;
  }

  /**
   * Country payload.
   */
  protected function getCountryPayload($country_name) {
    $filter = new FilterExpression([
      'filter' => new Filter([
        'field_name' => 'customEvent:content_report_primary_country',
        'string_filter' => new StringFilter([
          'value' => $country_name,
        ]),
      ]),
    ]);
    $payload = $this->buildPayload($filter);

    return $payload;
  }

  /**
   * Disaster payload.
   */
  protected function getDisasterPayload($disaster_name) {
    $filter = new FilterExpression([
      'filter' => new Filter([
        'field_name' => 'customEvent:content_report_disaster',
        'string_filter' => new StringFilter([
          'value' => $disaster_name,
        ]),
      ]),
    ]);
    $payload = $this->buildPayload($filter);

    return $payload;
  }

  /**
   * Get all countries payload.
   */
  protected function getAllCountriesPayload() {
    $filter = new FilterExpression([
      'filter' => new Filter([
        'field_name' => 'customEvent:content_report_primary_country',
        'string_filter' => new StringFilter([
          'value' => '...',
          'match_type' => MatchType::PARTIAL_REGEXP,
        ]),
      ]),
    ]);
    $payload = $this->buildPayload($filter);

    // Add dimension for disaster.
    $payload['dimensions'][] = new Dimension(['name' => 'customEvent:content_report_primary_country']);

    // Raise limit.
    $payload['limit'] = 100000;

    return $payload;
  }

  /**
   * Get all disasters payload.
   */
  protected function getAllDisastersPayload() {
    $filter = new FilterExpression([
      'filter' => new Filter([
        'field_name' => 'customEvent:content_report_disaster',
        'string_filter' => new StringFilter([
          'value' => '...',
          'match_type' => MatchType::PARTIAL_REGEXP,
        ]),
      ]),
    ]);
    $payload = $this->buildPayload($filter);

    // Add dimension for disaster.
    $payload['dimensions'][] = new Dimension(['name' => 'customEvent:content_report_disaster']);

    // Raise limit.
    $payload['limit'] = 100000;

    return $payload;
  }

}
