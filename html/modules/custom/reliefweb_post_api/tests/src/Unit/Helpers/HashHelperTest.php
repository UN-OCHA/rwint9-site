<?php

declare(strict_types=1);

namespace Drupal\Test\reliefweb_post_api\Unit\Helpers;

use Drupal\reliefweb_post_api\Helpers\HashHelper;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\reliefweb_post_api\Helpers\HashHelper
 */
final class HashHelperTest extends TestCase {

  /**
   * Test that equivalent data produces same hashes irrespective of key order.
   *
   * @covers ::generateHash
   */
  public function testHashConsistencyForEquivalentData(): void {
    $data_one = [
      'title' => 'something',
      'file' => [
        'mimetype' => 'bla',
        'url' => 'https://example.com/file',
      ],
      'source' => [456, 123.0],
    ];
    $data_two = [
      'file' => [
        'url' => 'https://example.com/file',
        'mimetype' => 'bla',
      ],
      'source' => [123.0, 456],
      'title' => 'something',
    ];

    $hash_one = HashHelper::generateHash($data_one);
    $hash_two = HashHelper::generateHash($data_two);

    $this->assertSame(
      $hash_one,
      $hash_two,
      'Hashes should be identical for equivalent data irrespective of key order.'
    );
  }

  /**
   * Test that float values are normalized to fixed 6-decimal strings.
   *
   * @covers ::normalizeData
   * @covers ::generateHash
   */
  public function testFloatNormalization(): void {
    $data = [
      'value_one' => 3.1415926535,
      'value_two' => 2.5,
      'value_three' => 2.500000,
    ];

    $expected_normalized = [
      'value_one' => '3.141593',
      'value_two' => '2.500000',
      'value_three' => '2.500000',
    ];

    $hash = HashHelper::generateHash($data);
    $expected_hash = HashHelper::generateHash($expected_normalized);

    $this->assertSame(
      $expected_hash,
      $hash,
      'Hash should reflect normalized float values with fixed precision.'
    );
  }

  /**
   * Test that excluded properties are properly removed before hashing.
   *
   * @covers ::removeExclusions
   * @covers ::removePropertyPath
   * @covers ::generateHash
   */
  public function testExclusionsRemoveSpecificProperties(): void {
    $data_one = [
      'title' => 'document',
      'metadata' => [
        'author' => 'John Doe',
        'published' => '2023-10-12',
      ],
    ];
    $data_two = [
      'title' => 'document',
      'metadata' => [
        // Different author value.
        'author' => 'Jane Doe',
        'published' => '2023-10-12',
      ],
    ];

    $exclusions = ['metadata.author'];
    $hash_one = HashHelper::generateHash($data_one, $exclusions);
    $hash_two = HashHelper::generateHash($data_two, $exclusions);

    $this->assertSame(
      $hash_one,
      $hash_two,
      'Hashes should be identical when excluded properties differ.'
    );
  }

  /**
   * Test that an exclusion path that does not exist does not alter the hash.
   *
   * @covers ::removeExclusions
   * @covers ::removePropertyPath
   * @covers ::generateHash
   */
  public function testNonExistentExclusionPath(): void {
    $data = [
      'title' => 'document',
      'details' => [
        'info' => 'some information',
      ],
    ];

    // Exclusion path that doesn't exist in the data.
    $exclusions = ['nonexistent.path'];
    $hash_with_exclusion = HashHelper::generateHash($data, $exclusions);
    $hash_without_exclusion = HashHelper::generateHash($data);

    $this->assertSame(
      $hash_without_exclusion,
      $hash_with_exclusion,
      'Excluding a non-existent path should not alter the hash.'
    );
  }

}
