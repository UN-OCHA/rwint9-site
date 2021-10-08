<?php

namespace Drupal\Tests\reliefweb_import\Functional;

use Drupal\Tests\BrowserTestBase;
use Drush\TestTraits\DrushTestTrait;
use Drupal\Core\Config\FileStorage;

/**
 * Tests reliefweb importer.
 *
 * @covers \Drupal\reliefweb_import\Command\ReliefwebImportCommand
 */
class DrushCommandsTest extends BrowserTestBase {

  use DrushTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'path_alias',
    'taxonomy',
    'reliefweb_import',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stable';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Import config from sync.
    /*
    $config_path =  '/var/www/config';
    $config_source = new FileStorage($config_path);
    \Drupal::service('config.installer')->installOptionalConfig($config_source);
    */
    $this->drush('cim', [], [
      'source' => '/var/www/config',
    ]);


    $this->rebuildContainer();
  }

  /**
   * Clean up the Simpletest environment.
   */
  protected function cleanupEnvironment() {
  }

  /**
   * Test drush command.
   */
  public function testDrush() {
    $this->drush('reliefweb_import:jobs');
    $this->assertSame('a', 'b');
  }

}
