<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_utility\ExistingSite;

use Drupal\file\Entity\File;
use Drupal\reliefweb_utility\Helpers\FileHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests file helper with real file operations.
 */
#[CoversClass(FileHelper::class)]
#[Group('reliefweb_utility')]
class FileHelperTest extends ExistingSiteBase {

  /**
   * Create a file entity, save it, and mark it for cleanup.
   *
   * @param array $values
   *   Array of values to set on the file entity.
   * @param string|null $content
   *   Optional content to write to the file.
   *
   * @return \Drupal\file\Entity\File
   *   The created file entity.
   */
  protected function createTestFile(array $values = [], ?string $content = NULL): File {
    $defaults = [
      'uid' => 1,
      'filename' => 'test-file.txt',
      'uri' => 'public://test-file.txt',
      'filemime' => 'text/plain',
      'status' => 1,
    ];

    $values = array_merge($defaults, $values);

    // If content is provided, create the file first.
    if ($content !== NULL) {
      $file_system = \Drupal::service('file_system');
      $directory = $file_system->dirname($values['uri']);
      if (!is_dir($directory)) {
        $file_system->prepareDirectory($directory, $file_system::CREATE_DIRECTORY);
      }
      file_put_contents($values['uri'], $content);
    }

    $file = File::create($values);
    $file->save();

    // Mark for cleanup.
    $this->markEntityForCleanup($file);

    return $file;
  }

  /**
   * Test generateFileHash with real file entity.
   */
  public function testGenerateFileHashRealFile(): void {
    $test_content = 'This is test content for hashing.';

    // Create a file entity with content.
    $file = $this->createTestFile([
      'filename' => 'test-file.txt',
      'uri' => 'public://test-file.txt',
      'filemime' => 'text/plain',
    ], $test_content);

    // Test hash generation.
    $hash = FileHelper::generateFileHash($file);

    $this->assertIsString($hash);
    $this->assertEquals(hash('sha256', $test_content), $hash);

    // Test with different algorithm.
    $md5_hash = FileHelper::generateFileHash($file, 'md5');
    $this->assertEquals(hash('md5', $test_content), $md5_hash);
  }

  /**
   * Test generateFileHash with non-existent file.
   */
  public function testGenerateFileHashNonExistentFile(): void {
    // Create a file entity with non-existent file.
    $file = $this->createTestFile([
      'filename' => 'non-existent.txt',
      'uri' => 'public://non-existent.txt',
      'filemime' => 'text/plain',
    ]);

    $hash = FileHelper::generateFileHash($file);

    $this->assertNull($hash);
  }

  /**
   * Test extractText with real text file (unsupported mimetype).
   */
  public function testExtractTextUnsupportedMimetype(): void {
    $test_content = 'This is test text content.';

    $file = $this->createTestFile([
      'filename' => 'test-text.txt',
      'uri' => 'public://test-text.txt',
      'filemime' => 'text/plain',
    ], $test_content);

    // Text extraction should return empty for unsupported mimetype.
    $result = FileHelper::extractText($file);

    $this->assertEquals('', $result);
  }

  /**
   * Test extractText with PDF file (if mutool is available).
   */
  public function testExtractTextPdfFile(): void {
    // Create a simple PDF file for testing.
    // Note: This is a minimal PDF that should work with mutool.
    $pdf_content = <<<'EOT'
      %PDF-1.4
      1 0 obj
      <<
      /Type /Catalog
      /Pages 2 0 R
      >>
      endobj
      2 0 obj
      <<
      /Type /Pages
      /Kids [3 0 R]
      /Count 1
      >>
      endobj
      3 0 obj
      <<
      /Type /Page
      /Parent 2 0 R
      /MediaBox [0 0 612 792]
      /Contents 4 0 R
      >>
      endobj
      4 0 obj
      <<
      /Length 44
      >>
      stream
      BT
      /F1 12 Tf
      100 700 Td
      (Hello World) Tj
      ET
      endstream
      endobj
      xref
      0 5
      0000000000 65535 f
      0000000009 00000 n
      0000000058 00000 n
      0000000115 00000 n
      0000000206 00000 n
      trailer
      <<
      /Size 5
      /Root 1 0 R
      >>
      startxref
      300
      %%EOF
      EOT;

    $file = $this->createTestFile([
      'filename' => 'test.pdf',
      'uri' => 'public://test.pdf',
      'filemime' => 'application/pdf',
    ], $pdf_content);

    // Test text extraction.
    $result = FileHelper::extractText($file);

    // The result should be a string (empty if mutool is not available).
    $this->assertIsString($result);

    // If mutool is available and working, we might get some text.
    // If not, we should get an empty string.
    if (!empty($result)) {
      $this->assertStringContainsString('Hello World', $result);
    }

    // Test with specific page.
    $result_page = FileHelper::extractText($file, 1);
    $this->assertIsString($result_page);
  }

  /**
   * Test extractTextParallel with multiple files.
   */
  public function testExtractTextParallelMultipleFiles(): void {
    // Create multiple test files.
    $files = [];

    for ($i = 1; $i <= 3; $i++) {
      $test_content = "Test content for file $i";

      $file = $this->createTestFile([
        'filename' => "test-file-$i.txt",
        'uri' => "public://test-file-$i.txt",
        'filemime' => 'text/plain',
      ], $test_content);

      $files[] = $file;
    }

    // Test parallel extraction.
    $results = FileHelper::extractTextParallel($files, 2, 30);

    $this->assertIsArray($results);
    $this->assertCount(3, $results);

    // All results should be empty strings since text/plain is not supported.
    foreach ($results as $result) {
      $this->assertIsString($result);
      $this->assertEquals('', $result);
    }
  }

  /**
   * Test extractTextParallel with empty files array.
   */
  public function testExtractTextParallelEmptyArray(): void {
    $results = FileHelper::extractTextParallel([]);

    $this->assertEquals([], $results);
  }

  /**
   * Test extractTextParallel with invalid processes count.
   */
  public function testExtractTextParallelInvalidProcesses(): void {
    $file = $this->createTestFile([
      'filename' => 'test-file.txt',
      'uri' => 'public://test-file.txt',
      'filemime' => 'text/plain',
    ], 'test content');

    // Test with 0 processes (should default to 1).
    $results = FileHelper::extractTextParallel([$file], 0, 60);

    $this->assertIsArray($results);
    $this->assertArrayHasKey($file->id(), $results);
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
   * Test extractText with file that has empty URI.
   */
  public function testExtractTextEmptyUri(): void {
    // Create a mock file that returns empty URI to test the edge case.
    $mock_file = $this->createMock(File::class);
    $mock_file->method('getFileUri')->willReturn('');
    $mock_file->method('getMimeType')->willReturn('text/plain');
    $mock_file->method('id')->willReturn(1);

    $result = FileHelper::extractText($mock_file);

    $this->assertEquals('', $result);
  }

  /**
   * Test extractText with file that has empty mimetype.
   */
  public function testExtractTextEmptyMimetype(): void {
    $file = $this->createTestFile([
      'filename' => 'empty-mimetype.txt',
      'uri' => 'public://empty-mimetype.txt',
      'filemime' => '',
    ], 'test content');

    $result = FileHelper::extractText($file);

    $this->assertEquals('', $result);
  }

  /**
   * Test extractTextParallel with mixed file types.
   */
  public function testExtractTextParallelMixedFileTypes(): void {
    $files = [];

    // Create a text file (unsupported).
    $file1 = $this->createTestFile([
      'filename' => 'test-text.txt',
      'uri' => 'public://test-text.txt',
      'filemime' => 'text/plain',
    ], 'text content');
    $files[] = $file1;

    // Create a PDF file (potentially supported).
    $pdf_content = <<<'EOT'
      %PDF-1.4
      1 0 obj
      <<
      /Type /Catalog
      /Pages 2 0 R
      >>
      endobj
      2 0 obj
      <<
      /Type /Pages
      /Kids [3 0 R]
      /Count 1
      >>
      endobj
      3 0 obj
      <<
      /Type /Page
      /Parent 2 0 R
      /MediaBox [0 0 612 792]
      /Contents 4 0 R
      >>
      endobj
      4 0 obj
      <<
      /Length 44
      >>
      stream
      BT
      /F1 12 Tf
      100 700 Td
      (Test PDF) Tj
      ET
      endstream
      endobj
      xref
      0 5
      0000000000 65535 f
      0000000009 00000 n
      0000000058 00000 n
      0000000115 00000 n
      0000000206 00000 n
      trailer
      <<
      /Size 5
      /Root 1 0 R
      >>
      startxref
      300
      %%EOF
      EOT;

    $file2 = $this->createTestFile([
      'filename' => 'test.pdf',
      'uri' => 'public://test.pdf',
      'filemime' => 'application/pdf',
    ], $pdf_content);
    $files[] = $file2;

    // Test parallel extraction.
    $results = FileHelper::extractTextParallel($files, 2, 30);

    $this->assertIsArray($results);
    $this->assertCount(2, $results);
    $this->assertArrayHasKey($file1->id(), $results);
    $this->assertArrayHasKey($file2->id(), $results);

    // Text file should return empty string.
    $this->assertEquals('', $results[$file1->id()]);

    // PDF file result depends on mutool availability.
    $this->assertIsString($results[$file2->id()]);
  }

}
