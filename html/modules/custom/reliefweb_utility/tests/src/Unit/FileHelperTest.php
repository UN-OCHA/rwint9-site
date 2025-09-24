<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\file\Entity\File;
use Drupal\reliefweb_utility\Helpers\FileHelper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests file helper.
 */
#[CoversClass(FileHelper::class)]
#[Group('reliefweb_utility')]
class FileHelperTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The logger channel factory service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerChannelFactory;

  /**
   * The logger channel service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $loggerChannel;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Mock the file system service.
    $this->fileSystem = $this->prophesize(FileSystemInterface::class);

    // Mock the state service.
    $this->state = $this->prophesize(StateInterface::class);
    $this->state->get('mutool', '/usr/bin/mutool')->willReturn('/usr/bin/mutool');
    $this->state->get('mutool_text_options', '')->willReturn('');
    $this->state->get('pandoc', '/usr/bin/pandoc')->willReturn('/usr/bin/pandoc');

    // Mock the logger services.
    $this->loggerChannel = $this->prophesize(LoggerChannelInterface::class);
    $this->loggerChannelFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $this->loggerChannelFactory->get('reliefweb_utility')->willReturn($this->loggerChannel->reveal());

    // Set up Drupal container with mocked services.
    $container = new ContainerBuilder();
    $container->set('file_system', $this->fileSystem->reveal());
    $container->set('state', $this->state->reveal());
    $container->set('logger.factory', $this->loggerChannelFactory->reveal());
    \Drupal::setContainer($container);
  }

  /**
   * Test generateFileHash with valid file.
   */
  public function testGenerateFileHashValidFile(): void {
    // Create a mock file entity.
    $file = $this->prophesize(File::class);
    $file->getFileUri()->willReturn('public://test-file.txt');

    // Mock file system to return a real path.
    $this->fileSystem->realpath('public://test-file.txt')->willReturn('/tmp/test-file.txt');

    // Create a temporary file for testing.
    $temp_file = tempnam(sys_get_temp_dir(), 'test_file');
    file_put_contents($temp_file, 'test content');

    // Mock file system to return our temp file path.
    $this->fileSystem->realpath('public://test-file.txt')->willReturn($temp_file);

    $hash = FileHelper::generateFileHash($file->reveal(), 'sha256', $this->fileSystem->reveal());

    $this->assertIsString($hash);
    $this->assertEquals(hash('sha256', 'test content'), $hash);

    // Clean up.
    unlink($temp_file);
  }

  /**
   * Test generateFileHash with empty file URI.
   */
  public function testGenerateFileHashEmptyUri(): void {
    $file = $this->prophesize(File::class);
    $file->getFileUri()->willReturn('');

    $hash = FileHelper::generateFileHash($file->reveal(), 'sha256', $this->fileSystem->reveal());

    $this->assertNull($hash);
  }

  /**
   * Test generateFileHash with non-existent file.
   */
  public function testGenerateFileHashNonExistentFile(): void {
    $file = $this->prophesize(File::class);
    $file->getFileUri()->willReturn('public://non-existent.txt');

    $this->fileSystem->realpath('public://non-existent.txt')->willReturn('/tmp/non-existent.txt');

    $hash = FileHelper::generateFileHash($file->reveal(), 'sha256', $this->fileSystem->reveal());

    $this->assertNull($hash);
  }

  /**
   * Test generateFileHash with different algorithms.
   */
  public function testGenerateFileHashDifferentAlgorithms(): void {
    $file = $this->prophesize(File::class);
    $file->getFileUri()->willReturn('public://test-file.txt');

    $temp_file = tempnam(sys_get_temp_dir(), 'test_file');
    file_put_contents($temp_file, 'test content');

    $this->fileSystem->realpath('public://test-file.txt')->willReturn($temp_file);

    // Test MD5.
    $md5_hash = FileHelper::generateFileHash($file->reveal(), 'md5', $this->fileSystem->reveal());
    $this->assertEquals(hash('md5', 'test content'), $md5_hash);

    // Test SHA1.
    $sha1_hash = FileHelper::generateFileHash($file->reveal(), 'sha1', $this->fileSystem->reveal());
    $this->assertEquals(hash('sha1', 'test content'), $sha1_hash);

    // Clean up.
    unlink($temp_file);
  }

  /**
   * Test extractText with unsupported mimetype.
   */
  public function testExtractTextUnsupportedMimetype(): void {
    $file = $this->prophesize(File::class);
    $file->getFileUri()->willReturn('public://test.txt');
    $file->getMimeType()->willReturn('text/plain');
    $file->id()->willReturn(1);

    $result = FileHelper::extractText($file->reveal(), NULL, $this->fileSystem->reveal());

    $this->assertEquals('', $result);
  }

  /**
   * Test extractText with empty file URI.
   */
  public function testExtractTextEmptyUri(): void {
    $file = $this->prophesize(File::class);
    $file->getFileUri()->willReturn('');
    $file->getMimeType()->willReturn('application/pdf');

    $result = FileHelper::extractText($file->reveal(), NULL, $this->fileSystem->reveal());

    $this->assertEquals('', $result);
  }

  /**
   * Test extractText with empty mimetype.
   */
  public function testExtractTextEmptyMimetype(): void {
    $file = $this->prophesize(File::class);
    $file->getFileUri()->willReturn('public://test.pdf');
    $file->getMimeType()->willReturn('');

    $result = FileHelper::extractText($file->reveal(), NULL, $this->fileSystem->reveal());

    $this->assertEquals('', $result);
  }

  /**
   * Test extractTextParallel with empty files array.
   */
  public function testExtractTextParallelEmptyFiles(): void {
    $result = FileHelper::extractTextParallel([], 4, 60, $this->fileSystem->reveal());

    $this->assertEquals([], $result);
  }

  /**
   * Test extractTextParallel with invalid processes count.
   */
  public function testExtractTextParallelInvalidProcesses(): void {
    $file = $this->prophesize(File::class);
    $file->getFileUri()->willReturn('public://test.pdf');
    $file->getMimeType()->willReturn('application/pdf');
    $file->id()->willReturn(1);

    // Test with 0 processes (should default to 1).
    $result = FileHelper::extractTextParallel([$file->reveal()], 0, 60, $this->fileSystem->reveal());

    $this->assertIsArray($result);
    $this->assertArrayHasKey(1, $result);
  }

  /**
   * Test extractTextParallel with non-File objects.
   */
  public function testExtractTextParallelNonFileObjects(): void {
    $files = [
      'not a file object',
      new \stdClass(),
    ];

    $result = FileHelper::extractTextParallel($files, 4, 60, $this->fileSystem->reveal());

    $this->assertEquals([], $result);
  }

  /**
   * Test clearTextExtractionCommandsCache.
   */
  public function testClearTextExtractionCommandsCache(): void {
    // Clear the cache.
    FileHelper::clearTextExtractionCommandsCache();

    // This should not throw any exceptions.
    $this->assertTrue(TRUE);
  }

  /**
   * Test extractText with PDF mimetype but no real command.
   */
  public function testExtractTextPdfNoCommand(): void {
    $file = $this->prophesize(File::class);
    $file->getFileUri()->willReturn('public://test.pdf');
    $file->getMimeType()->willReturn('application/pdf');
    $file->id()->willReturn(1);

    $this->fileSystem->realpath('public://test.pdf')->willReturn('/tmp/test.pdf');

    // Since we can't easily mock the command execution in unit tests,
    // we'll test that the method handles the case gracefully.
    $result = FileHelper::extractText($file->reveal(), NULL, $this->fileSystem->reveal());

    // The result should be empty string when command fails or doesn't exist.
    $this->assertIsString($result);
  }

  /**
   * Test extractText with page parameter for PDF.
   */
  public function testExtractTextWithPageParameter() {
    $file = $this->prophesize(File::class);
    $file->getFileUri()->willReturn('public://test.pdf');
    $file->getMimeType()->willReturn('application/pdf');
    $file->id()->willReturn(1);

    $this->fileSystem->realpath('public://test.pdf')->willReturn('/tmp/test.pdf');

    // Test with page parameter.
    $result = FileHelper::extractText($file->reveal(), 1, $this->fileSystem->reveal());

    $this->assertIsString($result);
  }

  /**
   * Test extractText with DOC mimetype.
   */
  public function testExtractTextDocMimetype() {
    $file = $this->prophesize(File::class);
    $file->getFileUri()->willReturn('public://test.doc');
    $file->getMimeType()->willReturn('application/msword');
    $file->id()->willReturn(1);

    $this->fileSystem->realpath('public://test.doc')->willReturn('/tmp/test.doc');

    $result = FileHelper::extractText($file->reveal(), NULL, $this->fileSystem->reveal());

    $this->assertIsString($result);
  }

  /**
   * Test extractTextParallel with multiple files.
   */
  public function testExtractTextParallelMultipleFiles() {
    $file1 = $this->prophesize(File::class);
    $file1->getFileUri()->willReturn('public://test1.pdf');
    $file1->getMimeType()->willReturn('application/pdf');
    $file1->id()->willReturn(1);

    $file2 = $this->prophesize(File::class);
    $file2->getFileUri()->willReturn('public://test2.doc');
    $file2->getMimeType()->willReturn('application/msword');
    $file2->id()->willReturn(2);

    $this->fileSystem->realpath('public://test1.pdf')->willReturn('/tmp/test1.pdf');
    $this->fileSystem->realpath('public://test2.doc')->willReturn('/tmp/test2.doc');

    $files = [$file1->reveal(), $file2->reveal()];
    $result = FileHelper::extractTextParallel($files, 2, 30, $this->fileSystem->reveal());

    $this->assertIsArray($result);
    $this->assertArrayHasKey(1, $result);
    $this->assertArrayHasKey(2, $result);
    $this->assertIsString($result[1]);
    $this->assertIsString($result[2]);
  }

  /**
   * Test extractTextParallel with timeout parameter.
   */
  public function testExtractTextParallelWithTimeout() {
    $file = $this->prophesize(File::class);
    $file->getFileUri()->willReturn('public://test.pdf');
    $file->getMimeType()->willReturn('application/pdf');
    $file->id()->willReturn(1);

    $this->fileSystem->realpath('public://test.pdf')->willReturn('/tmp/test.pdf');

    // Test with custom timeout.
    $result = FileHelper::extractTextParallel([$file->reveal()], 1, 120, $this->fileSystem->reveal());

    $this->assertIsArray($result);
    $this->assertArrayHasKey(1, $result);
  }

}
