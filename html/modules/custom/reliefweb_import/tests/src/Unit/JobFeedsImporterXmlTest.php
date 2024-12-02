<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_import\Unit;

use Drupal\Tests\reliefweb_import\Unit\Stub\JobFeedsImporterStub;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionXml;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Tests reliefweb importer.
 *
 * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter
 */
class JobFeedsImporterXmlTest extends JobFeedsImporterTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->prophesizeServices();

    $mock = new MockHandler([]);
    $handlerStack = HandlerStack::create($mock);
    $this->httpClient = new Client(['handler' => $handlerStack]);

    $this->jobImporter = new JobFeedsImporterStub(
      $this->database->reveal(),
      $this->entityTypeManager->reveal(),
      $this->accountSwitcher->reveal(),
      $this->httpClient,
      $this->loggerFactory->reveal(),
      $this->state->reveal(),
    );
  }

  /**
   * Tests fetching.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::fetchXml
   */
  public function testfetchXmlException(): void {
    $url = '';

    $mock = new MockHandler([
      new RequestException('Error Communicating with Server', new Request('GET', '')),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $this->jobImporter->setHttpClient(new Client(['handler' => $handlerStack]));

    $this->expectException(RequestException::class);
    $this->jobImporter->fetchXml($url);
  }

  /**
   * Tests fetching.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::fetchXml
   */
  public function testfetchXmlStatusCode500(): void {
    $url = '';

    $mock = new MockHandler([
      new Response(500, ['Content-Length' => 0]),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $this->jobImporter->setHttpClient(new Client(['handler' => $handlerStack]));

    $this->expectException(RequestException::class);
    $this->jobImporter->fetchXml($url);
  }

  /**
   * Tests fetching.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::fetchXml
   */
  public function testfetchXmlStatusCode404(): void {
    $url = '';

    $mock = new MockHandler([
      new Response(404, ['Content-Length' => 0]),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $this->jobImporter->setHttpClient(new Client(['handler' => $handlerStack]));

    $this->expectException(RequestException::class);
    $this->jobImporter->fetchXml($url);
  }

  /**
   * Tests fetching.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::fetchXml
   */
  public function testfetchXmlStatusCode218(): void {
    $url = '';

    $mock = new MockHandler([
      new Response(218, ['Content-Length' => 0]),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $this->jobImporter->setHttpClient(new Client(['handler' => $handlerStack]));

    $this->expectException(ReliefwebImportExceptionXml::class);
    $this->jobImporter->fetchXml($url);
  }

  /**
   * Tests fetching.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::fetchXml
   */
  public function testfetchXmlEmpty(): void {
    $url = '';

    $mock = new MockHandler([
      new Response(200, ['Content-Length' => 0]),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $this->jobImporter->setHttpClient(new Client(['handler' => $handlerStack]));

    $this->expectException(ReliefwebImportExceptionXml::class);
    $this->jobImporter->fetchXml($url);
  }

  /**
   * Tests fetching.
   *
   * @covers \Drupal\reliefweb_import\Service\JobFeedsImporter::fetchXml
   */
  public function testfetchXmlWithBody(): void {
    $url = '';

    $mock = new MockHandler([
      new Response(200, [], 'body'),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $this->jobImporter->setHttpClient(new Client(['handler' => $handlerStack]));

    $this->assertEquals('body', $this->jobImporter->fetchXml($url));
  }

}
