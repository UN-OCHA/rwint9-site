<?php

namespace Drupal\Tests\reliefweb_import\Unit;

use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionXml;
use Drupal\Tests\reliefweb_import\Unit\Stub\ReliefwebImportCommandStub;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Tests reliefweb importer.
 *
 * @covers \Drupal\reliefweb_import\Command\ReliefwebImportCommand
 */
class ReliefwebImporterXmlTest extends ReliefwebImporterTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->prophesizeServices();

    $mock = new MockHandler([]);
    $handlerStack = HandlerStack::create($mock);
    $this->httpClient = new Client(['handler' => $handlerStack]);

    $this->reliefwebImporter = new ReliefwebImportCommandStub($this->database->reveal(), $this->entityTypeManager->reveal(), $this->accountSwitcher->reveal(), $this->httpClient, $this->loggerFactory->reveal(), $this->state->reveal());
  }

  /**
   * Tests fetching.
   *
   * @covers \Drupal\reliefweb_import\Command\ReliefwebImportCommand::fetchXml
   */
  public function testfetchXmlException() {
    $url = '';

    $mock = new MockHandler([
      new RequestException('Error Communicating with Server', new Request('GET', '')),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $this->reliefwebImporter->setHttpClient(new Client(['handler' => $handlerStack]));

    $this->expectException(RequestException::class);
    $this->reliefwebImporter->fetchXml($url);
  }

  /**
   * Tests fetching.
   *
   * @covers \Drupal\reliefweb_import\Command\ReliefwebImportCommand::fetchXml
   */
  public function testfetchXmlStatusCode500() {
    $url = '';

    $mock = new MockHandler([
      new Response(500, ['Content-Length' => 0]),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $this->reliefwebImporter->setHttpClient(new Client(['handler' => $handlerStack]));

    $this->expectException(RequestException::class);
    $this->reliefwebImporter->fetchXml($url);
  }

  /**
   * Tests fetching.
   *
   * @covers \Drupal\reliefweb_import\Command\ReliefwebImportCommand::fetchXml
   */
  public function testfetchXmlStatusCode404() {
    $url = '';

    $mock = new MockHandler([
      new Response(404, ['Content-Length' => 0]),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $this->reliefwebImporter->setHttpClient(new Client(['handler' => $handlerStack]));

    $this->expectException(RequestException::class);
    $this->reliefwebImporter->fetchXml($url);
  }

  /**
   * Tests fetching.
   *
   * @covers \Drupal\reliefweb_import\Command\ReliefwebImportCommand::fetchXml
   */
  public function testfetchXmlStatusCode218() {
    $url = '';

    $mock = new MockHandler([
      new Response(218, ['Content-Length' => 0]),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $this->reliefwebImporter->setHttpClient(new Client(['handler' => $handlerStack]));

    $this->expectException(ReliefwebImportExceptionXml::class);
    $this->reliefwebImporter->fetchXml($url);
  }

  /**
   * Tests fetching.
   *
   * @covers \Drupal\reliefweb_import\Command\ReliefwebImportCommand::fetchXml
   */
  public function testfetchXmlEmpty() {
    $url = '';

    $mock = new MockHandler([
      new Response(200, ['Content-Length' => 0]),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $this->reliefwebImporter->setHttpClient(new Client(['handler' => $handlerStack]));

    $this->expectException(ReliefwebImportExceptionXml::class);
    $this->reliefwebImporter->fetchXml($url);
  }

  /**
   * Tests fetching.
   *
   * @covers \Drupal\reliefweb_import\Command\ReliefwebImportCommand::fetchXml
   */
  public function testfetchXmlWithBody() {
    $url = '';

    $mock = new MockHandler([
      new Response(200, [], 'body'),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $this->reliefwebImporter->setHttpClient(new Client(['handler' => $handlerStack]));

    $this->assertEquals('body', $this->reliefwebImporter->fetchXml($url));
  }

}
