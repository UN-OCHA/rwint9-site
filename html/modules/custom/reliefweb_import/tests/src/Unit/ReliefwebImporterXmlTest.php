<?php

namespace Drupal\Tests\reliefweb_import\Unit;

use Drupal\Tests\reliefweb_import\Unit\Stub\ReliefwebImportCommandStub;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;
use Drupal\reliefweb_import\Exception\ReliefwebImportExceptionXml;

/**
 * Tests reliefweb importer.
 */
class ReliefwebImporterXmlTest extends ReliefwebImporterTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->prophesizeServices();

    $mock = new MockHandler([]);
    $handlerStack = HandlerStack::create($mock);
    $this->httpClient = new Client(['handler' => $handlerStack]);

    $this->reliefwebImporter = new ReliefwebImportCommandStub($this->database->reveal(), $this->entityTypeManager->reveal(), $this->accountSwitcher->reveal(), $this->httpClient, $this->loggerFactory->reveal(), $this->state->reveal());
  }

  /**
   * Tests fetching.
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
   */
  public function testfetchXmlStatusCode() {
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
