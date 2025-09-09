<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_sync_orgs\ExistingSite;

/**
 * Test queue worker.
 */
class ProcessCsvItemTest extends ImportBase {

  /**
   * Test exception for missing source.
   */
  public function testExceptionMissingSource() {
    // Clear the queue.
    $this->clearQueue($this->queueName);

    $this->queue->createItem([
      'id' => '123',
    ]);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Source must be provided in the item data.');
    $this->runQueue($this->queueName);
  }

  /**
   * Test exception for missing field info.
   */
  public function testExceptionMissingFieldInfo() {
    // Clear the queue.
    $this->clearQueue($this->queueName);

    $this->queue->createItem([
      '_source' => 'abc',
      'id' => '123',
    ]);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('No field info found for source: abc');
    $this->runQueue($this->queueName);
  }

  public function testExceptionMissingId() {
    // Clear the queue.
    $this->clearQueue($this->queueName);

    $this->queue->createItem([
      '_source' => 'hdx',
      'not_id' => '123',
    ]);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('ID must be provided in the item data for source: hdx');
    $this->runQueue($this->queueName);
  }

}
