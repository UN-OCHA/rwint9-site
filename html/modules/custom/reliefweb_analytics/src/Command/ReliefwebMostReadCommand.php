<?php

namespace Drupal\reliefweb_analytics\Command;

use Drupal\path_alias\AliasRepositoryInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Consolidation\SiteProcess\ProcessManagerAwareTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
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
    else {
      $results['front'] = NULL;
    }

    $this->updateCsv($results);
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

    // Limit fetching data to countries with the following statuses.
    $statuses = array_flip($this->state->get('reliefweb_analytics_most_read_country_statuses', [
      'ongoing',
      'nornmal',
    ]));

    // Load all terms.
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();
    $query->condition('vid', 'country');
    $query->condition('tid', $this->state->get('reliefweb_analytics_most_read_country_last_tid', 0), '>');
    $query->sort('tid');
    $tids = $query->execute();

    // Reset last tid when empty.
    if (empty($tids)) {
      $this->state->set('reliefweb_analytics_most_read_country_last_tid', 0);
      return $this->countries();
    }

    $countries = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($tids);

    // Fetch data from GA4.
    foreach ($countries as $country) {
      $data = NULL;

      // Only process active (published) countries.
      if (isset($statuses[$country->moderation_status->value])) {
        $this->logger()->notice('Processing ' . $country->label());
        $parameters = $this->getDisasterPayload($country->label());
        $data = $this->fetchGa4Data($parameters);

        // Check for rate_limit.
        if ($data == 'rate_limit') {
          break;
        }
      }

      // Add to the result if there is data otherwise mark it as to be removed.
      if (!empty($data) && is_array($data)) {
        $results[$country->id()] = [
          $country->id(),
          implode(',', $data),
        ];
      }
      else {
        $results[$country->id()] = NULL;
      }

      // Update the last ID reference so we don't process this item again
      // until the reset.
      $this->state->set('reliefweb_analytics_most_read_country_last_tid', $country->id());
    }

    $this->updateCsv($results);
  }

  /**
   * Most read for countries using a single request.
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
    $combined = $this->fetchGa4DataCombined($parameters, $this->state->get('reliefweb_analytics_most_read_limit', 5));

    // Limit fetching data to countries with the following statuses.
    $statuses = array_flip($this->state->get('reliefweb_analytics_most_read_country_statuses', [
      'ongoing',
      'nornmal',
    ]));

    // Set the data for all the countries.
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'country',
    ]);
    foreach ($terms as $term) {
      // Skip non active (published) terms.
      if (!isset($statuses[$term->moderation_status->value])) {
        $results[$term->id()] = NULL;
        continue;
      }

      // Get the data using the term ID or label (it depends which dimesion
      // was used to get the data).
      $data = $combined[$term->id()] ?? $combined[$term->label()] ?? NULL;
      if (!empty($data) && is_array($data)) {
        $results[$term->id()] = [
          $term->id(),
          implode(',', $data),
        ];
      }
    }

    $this->updateCsv($results);
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
    $results = [];

    // Limit fetching data to countries with the following statuses.
    $statuses = array_flip($this->state->get('reliefweb_analytics_most_read_disaster_statuses', [
      'alert',
      'ongoing',
      'past',
    ]));

    // Load all terms.
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();
    $query->condition('vid', 'disaster');
    $query->condition('tid', $this->state->get('reliefweb_analytics_most_read_disaster_last_tid', 0), '>');
    $query->sort('tid');
    $tids = $query->execute();

    // Reset last tid when empty.
    if (empty($tids)) {
      $this->state->set('reliefweb_analytics_most_read_disaster_last_tid', 0);
      return $this->disasters();
    }

    $disasters = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($tids);
    foreach ($disasters as $disaster) {
      $data = NULL;

      // Only process active (published) disasters.
      if (isset($statuses[$disaster->moderation_status->value])) {
        $this->logger()->notice('Processing ' . $disaster->label());
        $parameters = $this->getDisasterPayload($disaster->label());
        $data = $this->fetchGa4Data($parameters);

        // Check for rate_limit.
        if ($data == 'rate_limit') {
          break;
        }
      }

      // Add to the result if there is data otherwise mark it as to be removed.
      if (!empty($data) && is_array($data)) {
        $results[$disaster->id()] = [
          $disaster->id(),
          implode(',', $data),
        ];
      }
      else {
        $results[$disaster->id()] = NULL;
      }

      // Update the last ID reference so we don't process this item again
      // until the reset.
      $this->state->set('reliefweb_analytics_most_read_disaster_last_tid', $disaster->id());
    }

    $this->updateCsv($results);
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

    // Fetch the combined data for all the disasters.
    $parameters = $this->getAllDisastersPayload();
    $combined = $this->fetchGa4DataCombined($parameters, $this->state->get('reliefweb_analytics_most_read_limit', 5));

    // Limit fetching data to countries with the following statuses.
    $statuses = array_flip($this->state->get('reliefweb_analytics_most_read_disaster_statuses', [
      'alert',
      'ongoing',
      'past',
    ]));

    // Set the data for all the disasters.
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'disaster',
    ]);
    foreach ($terms as $term) {
      // Skip non active (published) terms.
      if (!isset($statuses[$term->moderation_status->value])) {
        $results[$term->id()] = NULL;
        continue;
      }

      // Get the data using the term ID or label (it depends which dimesion
      // was used to get the data).
      $data = $combined[$term->id()] ?? $combined[trim($term->label())] ?? NULL;
      if (!empty($data) && is_array($data)) {
        $results[$term->id()] = [
          $term->id(),
          implode(',', $data),
        ];
      }
    }

    $this->updateCsv($results);
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
    if (!empty($results)) {
      $csv = array_replace($csv, $results);
    }

    // Remove empty data and sort by term ids (to ease analyze of the results).
    $csv = array_filter($csv);
    uksort($csv, function ($a, $b) {
      return $a === 'front' ? -1 : $a <=> $b;
    });

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
      // The credentials are set via the GOOGLE_APPLICATION_CREDENTIALS env var.
      $client = new BetaAnalyticsDataClient();
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
      // This is the report path or ID depending on the dimension used for
      // filtering.
      $results[] = $this->prepareDocumentIdDimension($row->getDimensionValues()[0]->getValue());
    }

    return $results;
  }

  /**
   * Fetch top 100.000 page views and calculate top 5.
   *
   * @param array $parameters
   *   Payload.
   * @param int $limit
   *   Maximum number of reports to count for each term.
   *
   * @see https://developers.google.com/analytics/devguides/reporting/core/v4/limits-quotas#analytics_reporting_api_v4
   */
  public function fetchGa4DataCombined(array $parameters, $limit) {
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
        // Expand $row->getDimensionValues()[1] which can be a list of term
        // names or IDs separated by ", " depending on the requested dimension.
        // @see reliefweb_analytics_get_terms().
        $parts = explode(', ', $row->getDimensionValues()[1]->getValue());
        foreach ($parts as $part) {
          $part = trim($part);
          if (!isset($results[$part])) {
            $results[$part] = [];
          }
          if (count($results[$part]) < $limit) {
            $results[$part][] = $this->prepareDocumentIdDimension($row->getDimensionValues()[0]->getValue());
          }
        }
      }
    }

    return $results;
  }

  /**
   * Prepare the dimension used to identify a document.
   *
   * This can be the document ID or the document path depending on which
   * dimention was returned.
   *
   * If it's a path, we add a the scheme and host.
   *
   * @param string|int $value
   *   Dimension value.
   *
   * @return strin|int
   *   Prepared dimension.
   */
  protected function prepareDocumentIdDimension($value) {
    static $base_url;
    if (!isset($base_url)) {
      // Use a variable for the base URL so we can override it for local/dev
      // environements.
      $base_url = rtrim($this->state->get('reliefweb_analytics_most_read_base_url', 'https://reliefweb.int'), '/') . '/';
    }
    // ID.
    if (is_numeric($value)) {
      return $value;
    }
    // URL path.
    else {
      return $base_url . ltrim(trim($value), '/');
    }
  }

  /**
   * Our logger.
   */
  protected function getLogger(): LoggerChannelInterface {
    return $this->loggerFactory->get('reliefweb_analytics');
  }

  /**
   * Common payload.
   *
   * @param \Google\Analytics\Data\V1beta\FilterExpression $filter
   *   Additional filter.
   *
   * @return array
   *   Payload for a GA4 API request.
   *
   * @todo Return creation date dimension and use to boost more recent reports.
   */
  protected function buildPayload(FilterExpression $filter = NULL) {
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
      'property' => 'properties/' . Settings::get('reliefweb_analytics_property_id', ''),
      'limit' => $this->state->get('reliefweb_analytics_most_read_limit', 5),
      'returnPropertyQuota' => TRUE,
      'dateRanges' => [
        new DateRange([
          'start_date' => $this->state->get('reliefweb_analytics_most_read_start_date', '30daysAgo'),
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
        new MetricOrderBy(['metric_name' => 'activeUsers']),
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
        'start_date' => $this->state->get('reliefweb_analytics_most_read_homepage_start_date', 'yesterday'),
        'end_date' => 'today',
      ]),
    ];

    // Lower limit.
    $payload['limit'] = $this->state->get('reliefweb_analytics_most_read_homepage_limit', 2);

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
          'match_type' => MatchType::CONTAINS,
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
          'match_type' => MatchType::CONTAINS,
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
          'value' => '^[A-Z]+',
          'match_type' => MatchType::PARTIAL_REGEXP,
        ]),
      ]),
    ]);
    $payload = $this->buildPayload($filter);

    // Add dimension for country.
    $payload['dimensions'][] = new Dimension(['name' => 'customEvent:content_report_primary_country']);

    // Raise limit.
    $payload['limit'] = $this->state->get('reliefweb_analytics_most_read_combined_limit', 100000);

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
          'value' => '^[A-Z]+',
          'match_type' => MatchType::PARTIAL_REGEXP,
        ]),
      ]),
    ]);
    $payload = $this->buildPayload($filter);

    // Add dimension for disaster.
    $payload['dimensions'][] = new Dimension(['name' => 'customEvent:content_report_disaster']);

    // Raise limit.
    $payload['limit'] = $this->state->get('reliefweb_analytics_most_read_combined_limit', 100000);

    return $payload;
  }

}
