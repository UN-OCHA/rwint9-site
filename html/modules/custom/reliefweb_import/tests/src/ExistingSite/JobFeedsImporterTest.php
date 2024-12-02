<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_import\ExistingSite;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\reliefweb_import\Traits\XmlTestDataTrait;
use Drupal\Tests\reliefweb_import\Unit\ExistingSite\JobFeedsImporterWrapper;
use Drupal\Tests\reliefweb_import\Unit\ExistingSite\LoggerStub;
use Drupal\reliefweb_entities\Entity\Job;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
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
 * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter
 */
class JobFeedsImporterTest extends ExistingSiteBase {

  use XmlTestDataTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The account switcher.
   *
   * @var \Drupal\Core\Session\AccountSwitcherInterface
   */
  protected AccountSwitcherInterface $accountSwitcher;

  /**
   * An http client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The state store.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * Reliefweb importer.
   *
   * @var \Drupal\Tests\reliefweb_import\Unit\ExistingSite\JobFeedsImporterWrapper
   */
  protected JobFeedsImporterWrapper $jobImporter;

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
      new ClientException('Client exception', new Request('GET', ''), new Response(400)),
      new RequestException('Request exception', new Request('GET', '')),
      new \Exception('General exception'),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $this->httpClient = new Client(['handler' => $handlerStack]);
    $this->jobImporter = new JobFeedsImporterWrapper(
      $this->database,
      $this->entityTypeManager,
      $this->accountSwitcher,
      $this->httpClient,
      $this->loggerFactory,
      $this->state,
    );
    $this->jobImporter->setLogger(new LoggerStub());
  }

  /**
   * Test XML import.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::importJobs
   */
  public function testSourceImport(): void {
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
        ],
      ],
      [
        'vocabulary' => 'country',
        'tid' => 999992,
        'field_iso3' => [
          'value' => 'COL',
        ],
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
    // Data from getTestXml1().
    $this->jobImporter->importJobs();
    $job = $this->getJobFromImportUrl('https://www.aplitrak.com?adid=1');
    $this->assertStringContainsStringIgnoringCase('imported from', $job->getRevisionLogMessage());
    $this->assertSame($job->title->value, 'Head of Supply Chain');
    $year = date('Y') + 1;
    $this->assertSame($job->field_job_closing_date->value, $year . '-10-05');

    // Import jobs again, triggering updates.
    // Data from getTestXml2().
    $this->jobImporter->getLogger()->resetMessages();
    $this->jobImporter->fetchJobs($source);
    $this->assertStringContainsStringIgnoringCase('updated from', $job->getRevisionLogMessage());
    $this->assertSame($job->title->value, 'The head of Supply Chain');

    // Import job without title.
    // Data from getTestXml3().
    $this->jobImporter->getLogger()->resetMessages();
    $this->jobImporter->fetchJobs($source);
    $this->assertStringContainsStringIgnoringCase('Job found with empty title.', $this->jobImporter->getLogger()->getMessages('error'));

    // Import job in the past: allowed, no errors or warnings.
    // Data from getTestXml4().
    $this->jobImporter->getLogger()->resetMessages();
    $this->jobImporter->fetchJobs($source);
    $this->assertFalse($this->jobImporter->getLogger()->hasMessages('error'));
    $this->assertFalse($this->jobImporter->getLogger()->hasMessages('warning'));

    // Import job in the past.
    // Data from getTestXml5().
    // @todo check another type of error.
    $this->jobImporter->getLogger()->resetMessages();
    $this->jobImporter->fetchJobs($source);
    $this->assertFalse($this->jobImporter->getLogger()->hasMessages('error'));
    $this->assertFalse($this->jobImporter->getLogger()->hasMessages('warning'));

    // Import job with city, no country: city should be empty.
    // Data from getTestXml6().
    $this->jobImporter->getLogger()->resetMessages();
    $this->jobImporter->fetchJobs($source);
    $this->assertFalse($this->jobImporter->getLogger()->hasMessages('error'));
    $this->assertFalse($this->jobImporter->getLogger()->hasMessages('warning'));
    $job = $this->getJobFromImportUrl('https://www.aplitrak.com?adid=21');
    $this->assertTrue($job->field_city->isEmpty());

    // Import job with too short how to apply.
    // Data from getTestXml7().
    $this->jobImporter->getLogger()->resetMessages();
    $this->jobImporter->fetchJobs($source);
    $this->assertFalse($this->jobImporter->getLogger()->hasMessages('error'));
    $this->assertTrue($this->jobImporter->getLogger()->hasMessages('warning'));
    $this->assertStringContainsStringIgnoringCase('Invalid field size for field_how_to_apply, 13 characters found, has to be between 100 and 10000', $this->jobImporter->getLogger()->getMessages('warning'));

    // Client exception.
    $this->jobImporter->getLogger()->resetMessages();
    $this->jobImporter->importJobs();
    $this->assertTrue($this->jobImporter->getLogger()->hasMessages('error'));
    $this->assertStringContainsStringIgnoringCase('Client Exception', $this->jobImporter->getLogger()->getMessages('error'));

    // Request exception.
    $this->jobImporter->getLogger()->resetMessages();
    $this->jobImporter->importJobs();
    $this->assertTrue($this->jobImporter->getLogger()->hasMessages('error'));
    $this->assertStringContainsStringIgnoringCase('Request Exception', $this->jobImporter->getLogger()->getMessages('error'));

    // General exception.
    $this->jobImporter->getLogger()->resetMessages();
    $this->jobImporter->importJobs();
    $this->assertTrue($this->jobImporter->getLogger()->hasMessages('error'));
    $this->assertStringContainsStringIgnoringCase('General Exception', $this->jobImporter->getLogger()->getMessages('error'));
  }

  /**
   * Load a job from it's import URL.
   *
   * @param string $url
   *   Import URL.
   *
   * @return \Drupal\reliefweb_entities\Entity\Job|null
   *   Job entity.
   */
  protected function getJobFromImportUrl(string $url): ?Job {
    $jobs = $this->entityTypeManager->getStorage('node')->loadByProperties([
      'type' => 'job',
      'field_import_guid' => $url,
    ]);
    return reset($jobs) ?: NULL;
  }

}
