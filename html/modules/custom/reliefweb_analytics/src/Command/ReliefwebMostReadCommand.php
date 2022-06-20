<?php

namespace Drupal\reliefweb_analytics\Command;

use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Consolidation\SiteProcess\ProcessManagerAwareTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
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
 * ReliefWeb Import Drush commandfile.
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
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Most read for homepage.
   *
   * @command reliefweb_analytics:homepage
   * @usage reliefweb_analytics:homepage
   *   Send emails.
   * @validate-module-enabled reliefweb_analytics
   * @aliases reliefweb-mostread-homepage
   */
  public function homepage() {
    $results = [];

    // Fetch data from GA4.
    $this->logger()->notice('Processing home page');
    $parameters = $this->getHomePagePayload();
    $data = $this->fetchGa4Data($parameters);
    if (!empty($data)) {
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
   *   Send emails.
   * @validate-module-enabled reliefweb_analytics
   * @aliases reliefweb-mostread-countries
   */
  public function countries() {
    $results = [];

    // Load all terms.
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();
    $query->condition('vid', 'country');
    $tids = $query->execute();
    $countries = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($tids);

    // Fetch data from GA4.
    foreach ($countries as $country) {
      $this->logger()->notice('Processing ' . $country->label());
      $parameters = $this->getCountryPayload($country->label());
      $data = $this->fetchGa4Data($parameters);
      if (!empty($data)) {
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
   *   Send emails.
   * @validate-module-enabled reliefweb_analytics
   * @aliases reliefweb-mostread-disasters
   */
  public function disasters() {
    // Load terms having a job URL.
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();
    $query->condition('vid', 'disaster');
    $tids = $query->execute();
    $disasters = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($tids);
    foreach ($disasters as $disaster) {
      $this->logger()->notice('Processing ' . $disaster->label());
      $parameters = $this->getDisasterPayload($disaster->label());
      $data = $this->fetchGa4Data($parameters);
      if (!empty($data)) {
        $results[$disaster->id()] = [
          $disaster->id(),
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
  protected function updateCsv($results) {
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
      $results[] = $row->getDimensionValues()[0]->getValue();
    }

    return $results;
  }

  /**
   * Fetch and process GA4 data.
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
        if (!isset($results[$row->getDimensionValues()[1]->getValue()])) {
          $results[$row->getDimensionValues()[1]->getValue()] = [];
        }
        if (count($results[$row->getDimensionValues()[1]->getValue()]) < 5) {
          $results[$row->getDimensionValues()[1]->getValue()][] = $row->getDimensionValues()[0]->getValue();
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
      new FilterExpression([
        'filter' => new Filter([
          'field_name' => 'pagePath',
          'string_filter' => new StringFilter([
            'match_type' => MatchType::BEGINS_WITH,
            'value' => '/report',
          ]),
        ]),
      ]),
    ];

    if ($filter) {
      $expressions[] = $filter;
    }

    return [
      'property' => 'properties/291027553',
      'limit' => 5,
      'returnPropertyQuota' => TRUE,
      'dateRanges' => [
        new DateRange([
          'start_date' => '30daysAgo',
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
    return $this->buildPayload();
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
          'value' => '.',
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
          'value' => '.',
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
