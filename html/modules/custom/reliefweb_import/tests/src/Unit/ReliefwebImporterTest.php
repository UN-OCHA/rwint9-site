<?php

namespace Drupal\Tests\reliefweb_import\Unit;

use Drupal\Component\Utility\Random;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\State;
use Drupal\Tests\reliefweb_import\Unit\Stub\ReliefwebImportCommandStub;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;

/**
 * Tests reliefweb importer.
 */
class ReliefwebImporterTest extends UnitTestCase {

  /**
   * Reliefweb importer.
   *
   * @var \Drupal\Tests\reliefweb_import\Unit\Stub\ReliefwebImportCommandStub
   */
  protected $reliefwebImporter;

  /**
   * Random helper.
   *
   * @var \Drupal\Component\Utility\Random
   */
  protected $random;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $database = $this
      ->getMockBuilder(Connection::class)
      ->disableOriginalConstructor()
      ->getMock();

    $entityTypeManager = $this
      ->getMockBuilder(EntityTypeManagerInterface::class)
      ->disableOriginalConstructor()
      ->getMock();

    $accountSwitcher = $this
      ->getMockBuilder(AccountSwitcherInterface::class)
      ->disableOriginalConstructor()
      ->getMock();

    $httpClient = $this
      ->getMockBuilder(ClientInterface::class)
      ->disableOriginalConstructor()
      ->getMock();

    $loggerFactory = $this
      ->getMockBuilder(LoggerChannelFactoryInterface::class)
      ->disableOriginalConstructor()
      ->getMock();

    $state = $this
      ->getMockBuilder(State::class)
      ->disableOriginalConstructor()
      ->getMock();

    $database = $this->prophesize(Connection::class)->reveal();
    $entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class)->reveal();
    $accountSwitcher = $this->prophesize(AccountSwitcherInterface::class)->reveal();
    $httpClient = $this->prophesize(ClientInterface::class)->reveal();
    $loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class)->reveal();
    $state = $this->prophesize(State::class)->reveal();

    $this->reliefwebImporter = new ReliefwebImportCommandStub($database, $entityTypeManager, $accountSwitcher, $httpClient, $loggerFactory, $state);
    $this->random = new Random();
  }

  /**
   * Tests for sanize text.
   */
  public function testsanitizeTextEmpty() {
    $test_string = '';

    $this->expectExceptionMessage(strtr('Invalid field size for body, @length characters found, has to be between 400 and 50000', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->reliefwebImporter->validateBody($test_string);
  }

  /**
   * Tests for sanize text.
   */
  public function testsanitizeTextSpaces() {
    $test_string = '      ';

    $this->expectExceptionMessage(strtr('Invalid field size for body, @length characters found, has to be between 400 and 50000', [
      '@length' => 0,
    ]));
    $this->reliefwebImporter->validateBody($test_string);
  }

  /**
   * Tests for sanize text.
   */
  public function testsanitizeTextTooShort() {
    $test_string = $this->random->sentences(5);

    $this->expectExceptionMessage(strtr('Invalid field size for body, @length characters found, has to be between 400 and 50000', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->reliefwebImporter->validateBody($test_string);
  }

  /**
   * Tests for sanize text.
   */
  public function testsanitizeTextTooLong() {
    $test_string = $this->random->sentences(25000);

    $this->expectExceptionMessage(strtr('Invalid field size for body, @length characters found, has to be between 400 and 50000', [
      '@length' => mb_strlen($test_string),
    ]));
    $this->reliefwebImporter->validateBody($test_string);
  }

  /**
   * Tests for sanize text.
   */
  public function testsanitizeTextArray() {
    $test_string = [];
    $this->expectExceptionMessage('Invalid field size for body, 0 characters found, has to be between 400 and 50000');
    $this->reliefwebImporter->validateBody($test_string);
  }

  /**
   * Tests for sanize text.
   */
  public function testsanitizeTextPlain() {
    $test_string = $this->random->sentences(300);
    $this->assertEquals($test_string, $this->reliefwebImporter->validateBody($test_string));
  }

  /**
   * Tests for sanize text.
   */
  public function testsanitizeTextClosingTag() {
    $test_string = $this->random->sentences(300);
    $this->assertEquals($test_string, $this->reliefwebImporter->sanitizeText('body', $test_string . '</body>'));
  }

  /**
   * Tests for sanize text.
   */
  public function testsanitizeTextCdata() {
    $test_string = $this->random->sentences(300);
    $this->assertEquals($test_string, $this->reliefwebImporter->sanitizeText('body', '<![CDATA[' . $test_string . ']]>'));
  }

  /**
   * Tests for sanize text.
   */
  public function testsanitizeTextEmbedImage() {
    $test_string = $this->random->sentences(300);
    $this->assertEquals($test_string, $this->reliefwebImporter->sanitizeText('body', $test_string . '<img src="">'));
  }

  /**
   * Tests for sanize text.
   */
  public function testsanitizeTextPtag() {
    $test_string = '<p style="font-family: Arial;">The Opportunity</p>';
    $this->assertEquals('The Opportunity', $this->reliefwebImporter->sanitizeText('body', $test_string));
  }

}
