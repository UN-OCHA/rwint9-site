<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_import\ExistingSite;

use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\reliefweb_import\Traits\XmlTestDataTrait;
use Drupal\Tests\reliefweb_import\Unit\ExistingSite\LoggerStub;
use Drupal\Tests\reliefweb_import\Unit\ExistingSite\ReliefwebImportCommandWrapper;
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
      new Response(200, [], $this->getTestXml5()),
      new Response(200, [], $this->getTestXml6()),
      new Response(200, [], $this->getTestXml7()),
      new ClientException('Client exception', new Request('GET', ''), NULL),
      new RequestException('Request exception', new Request('GET', '')),
      new \Exception('General exception'),
    ]);

    $handlerStack = HandlerStack::create($mock);
    //$logger = new LoggerStub();
    $this->httpClient = new Client(['handler' => $handlerStack]);
    $this->reliefwebImporter = new ReliefwebImportCommandWrapper($this->database, $this->entityTypeManager, $this->accountSwitcher, $this->httpClient, $this->loggerFactory, $this->state);
    $this->reliefwebImporter->setLogger(new LoggerStub());
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

    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $query->condition('type', 'job');
    $query->condition('field_import_guid', 'https://www.aplitrak.com?adid=1');
    $nids = $query->execute();
    $jobs = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

    /** @var Drupal\reliefweb_entities\Entity\Job $job */
    $job = reset($jobs);

    $this->assertStringContainsStringIgnoringCase('imported from', $job->getRevisionLogMessage());
    $this->assertSame($job->title->value, 'Head of Supply Chain');

    $year = date('Y') + 1;
    $this->assertSame($job->field_job_closing_date->value, $year . '-10-05');

    // Import jobs again, triggering updates.
    $this->reliefwebImporter->getLogger()->resetMessages();
    $this->reliefwebImporter->fetchJobs($source);
    $this->assertStringContainsStringIgnoringCase('updated from', $job->getRevisionLogMessage());
    $this->assertSame($job->title->value, 'The head of Supply Chain');

    // Import job without title.
    $this->reliefwebImporter->getLogger()->resetMessages();
    $this->reliefwebImporter->fetchJobs($source);
    $this->assertStringContainsStringIgnoringCase('Job found with empty title.', $this->reliefwebImporter->getLogger()->getMessages('error'));

    // Import job in the past: allowed, no errors or warnings.
    $this->reliefwebImporter->getLogger()->resetMessages();
    $this->reliefwebImporter->fetchJobs($source);
    $this->assertFalse($this->reliefwebImporter->getLogger()->hasMessages('error'));
    $this->assertFalse($this->reliefwebImporter->getLogger()->hasMessages('warning'));

    // Import job in the past.
    // @todo check another type of error.
    $this->reliefwebImporter->getLogger()->resetMessages();
    $this->reliefwebImporter->fetchJobs($source);
    $this->assertFalse($this->reliefwebImporter->getLogger()->hasMessages('error'));
    $this->assertFalse($this->reliefwebImporter->getLogger()->hasMessages('warning'));

    // Import job with city, no country: city should be empty.
    $this->reliefwebImporter->getLogger()->resetMessages();
    $this->reliefwebImporter->fetchJobs($source);
    $this->assertFalse($this->reliefwebImporter->getLogger()->hasMessages('error'));
    $this->assertFalse($this->reliefwebImporter->getLogger()->hasMessages('warning'));
    $this->assertTrue($job->field_city->isEmpty());

    // Import job with too short how to apply.
    $this->reliefwebImporter->getLogger()->resetMessages();
    $this->reliefwebImporter->fetchJobs($source);
    $this->assertFalse($this->reliefwebImporter->getLogger()->hasMessages('error'));
    $this->assertTrue($this->reliefwebImporter->getLogger()->hasMessages('warning'));
    $this->assertStringContainsStringIgnoringCase('Invalid field size for field_how_to_apply, 13 characters found, has to be between 100 and 10000', $this->reliefwebImporter->getLogger()->getMessages('warning'));

    // Client exception.
    $this->reliefwebImporter->getLogger()->resetMessages();
    $this->reliefwebImporter->jobs();
    $this->assertTrue($this->reliefwebImporter->getLogger()->hasMessages('error'));
    $this->assertStringContainsStringIgnoringCase('Client Exception', $this->reliefwebImporter->getLogger()->getMessages('error'));

    // Request exception.
    $this->reliefwebImporter->getLogger()->resetMessages();
    $this->reliefwebImporter->jobs();
    $this->assertTrue($this->reliefwebImporter->getLogger()->hasMessages('error'));
    $this->assertStringContainsStringIgnoringCase('Request Exception', $this->reliefwebImporter->getLogger()->getMessages('error'));

    // General exception.
    $this->reliefwebImporter->getLogger()->resetMessages();
    $this->reliefwebImporter->jobs();
    $this->assertTrue($this->reliefwebImporter->getLogger()->hasMessages('error'));
    $this->assertStringContainsStringIgnoringCase('General Exception', $this->reliefwebImporter->getLogger()->getMessages('error'));
  }

}
