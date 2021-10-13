<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_import\ExistingSite;

use Drupal\reliefweb_import\Command\ReliefwebImportCommand;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\reliefweb_import\Traits\XmlTestDataTrait;
use Drupal\user\Entity\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests reliefweb importer.
 *
 * @covers \Drupal\reliefweb_import\Command\ReliefwebImportCommand
 */
class DrushCommandsTest extends ExistingSiteBase {

  use XmlTestDataTrait;

  /**
   * The database connection.
   */
  protected $database;

  /**
   * The entity type manager.
   */
  protected $entityTypeManager;

  /**
   * The account switcher.
   */
  protected $accountSwitcher;

  /**
   * An http client.
   */
  protected $httpClient;

  /**
   * The logger factory.
   */
  protected $loggerFactory;

  /**
   * The state store.
   */
  protected $state;

  /**
   * Reliefweb importer.
   *
   * @var \Drupal\reliefweb_import\Command\ReliefwebImportCommand
   */
  protected $reliefwebImporter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->database = \Drupal::service('database');
    $this->entityTypeManager = \Drupal::service('entity_type.manager');
    $this->accountSwitcher = \Drupal::service('account_switcher');
    $this->httpClient = \Drupal::service('http_client');
    $this->loggerFactory = \Drupal::service('logger.factory');
    $this->state = \Drupal::service('state');

    $mock = new MockHandler([
      new Response(200, [], $this->getTestXml1()),
      new Response(200, [], $this->getTestXml2()),
      new Response(200, [], $this->getTestXml3()),
      new Response(200, [], $this->getTestXml4()),
      new ClientException('Client exception', new Request('GET', '')),
      new RequestException('Request exception', new Request('GET', '')),
      new \Exception('General exception'),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $this->httpClient = new Client(['handler' => $handlerStack]);
    $this->reliefwebImporter = new ReliefwebImportCommand($this->database, $this->entityTypeManager, $this->accountSwitcher, $this->httpClient, $this->loggerFactory, $this->state);
  }

  /**
   * Test XML import.
   */
  public function testSourceImport() {
    // Create system user.
    if (!User::load(2)) {
      $this->createUser([], 'System user', TRUE, [
        'uid' => 2,
      ]);
    }

    // Create a user for the import.
    $author = $this->createUser([], 'Regular', FALSE);

    // Create referenced data.
    $terms = [
      [
        'vocabulary' => 'source',
        'tid' => 2865,
        'label' => 'test source',
        'field_job_import_feed' => [
          'feed_url' => 'https://example.com/feed',
          'uid' => $author->id(),
          'base_url' => 'https://www.aplitrak.com',
        ],
      ],
      [
        'vocabulary' => 'career_category',
        'tid' => 36601,
      ],
      [
        'vocabulary' => 'career_category',
        'tid' => 6867,
      ],
      [
        'vocabulary' => 'career_category',
        'tid' => 6865,
      ],
      [
        'vocabulary' => 'job_experience',
        'tid' => 260,
      ],
      [
        'vocabulary' => 'job_experience',
        'tid' => 258,
      ],
      [
        'vocabulary' => 'job_type',
        'tid' => 263,
      ],
      [
        'vocabulary' => 'theme',
        'tid' => 4600,
      ],
      [
        'vocabulary' => 'theme',
        'tid' => 4594,
      ],
      [
        'vocabulary' => 'country',
        'tid' => 999991,
        'field_iso3' => [
          'value' => 'AFG',
        ]
      ],
      [
        'vocabulary' => 'country',
        'tid' => 999992,
        'field_iso3' => [
          'value' => 'COL',
        ]
      ],
    ];

    foreach ($terms as $term) {
      if (!Term::load($term['tid'])) {
        $vocab = Vocabulary::load($term['vocabulary']);
        $this->createTerm($vocab, [
          'uid' => $author->id(),
          'label' => 'Term ' . $term['tid'],
        ] + $term);
      }
    }

    /** @var \Drupal\taxonomy\Entity\Term $source */
    $source = Term::load(2865);

    // Import jobs.
    $this->reliefwebImporter->jobs();

    $warnings = $this->reliefwebImporter->getWarnings();
    $errors = $this->reliefwebImporter->getErrors();

    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $query->condition('type', 'job');
    $query->condition('field_job_id', 'https://www.aplitrak.com?adid=1');
    $nids = $query->execute();
    $jobs = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

    /** @var Drupal\reliefweb_entities\Entity\Job $job */
    $job = reset($jobs);

    $this->assertSame($warnings[0], 'Validation failed in field_job_experience with message: <em class="placeholder">Job years of experience</em>: this field cannot hold more than 1 values. for job https://www.aplitrak.com?adid=1');
    $this->assertCount(0, $errors);
    $this->assertSame($job->title->value, 'Head of Supply Chain');

    // Import jobs again, triggering updates.
    $this->reliefwebImporter->fetchJobs($source);

    $warnings = $this->reliefwebImporter->getWarnings();
    $errors = $this->reliefwebImporter->getErrors();

    $this->assertArrayNotHasKey(0, $warnings);
    $this->assertCount(0, $errors);
    $this->assertSame($job->title->value, 'The head of Supply Chain');

    // Import job without title.
    $this->reliefwebImporter->fetchJobs($source);

    $warnings = $this->reliefwebImporter->getWarnings();
    $errors = $this->reliefwebImporter->getErrors();

    $this->assertArrayNotHasKey(0, $warnings);
    $this->assertCount(1, $errors);

    // Import job in the past.
    $this->reliefwebImporter->fetchJobs($source);

    $warnings = $this->reliefwebImporter->getWarnings();
    $errors = $this->reliefwebImporter->getErrors();

    // @see RW-202 $this->assertCount(1, $warnings);
    // @see RW-202  $this->assertSame($warnings[0], 'Validation failed in field_job_closing_date with message: <em class="placeholder">Closing date</em> field has to be in the future. for job https://www.aplitrak.com?adid=20');
    $this->assertArrayNotHasKey(0, $errors);

    // Exception log messages.
    $this->reliefwebImporter->jobs();

    $warnings = $this->reliefwebImporter->getWarnings();
    $errors = $this->reliefwebImporter->getErrors();

    $this->assertArrayNotHasKey(0, $warnings);
    $this->assertCount(1, $errors);

    // Exception log messages.
    $this->reliefwebImporter->jobs();

    $warnings = $this->reliefwebImporter->getWarnings();
    $errors = $this->reliefwebImporter->getErrors();

    $this->assertArrayNotHasKey(0, $warnings);
    $this->assertCount(1, $errors);

    // Exception log messages.
    $this->reliefwebImporter->jobs();

    $warnings = $this->reliefwebImporter->getWarnings();
    $errors = $this->reliefwebImporter->getErrors();

    $this->assertArrayNotHasKey(0, $warnings);
    $this->assertCount(1, $errors);
  }

}
