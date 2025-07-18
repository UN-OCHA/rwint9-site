<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Plugin;

use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManager;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface;
use Drupal\reliefweb_post_api\Plugin\reliefweb_post_api\ContentProcessor\Report;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the content processor plugin manager.
 */
#[CoversClass(ContentProcessorPluginManager::class)]
#[Group('reliefweb_post_api')]
class ContentProcessorPluginManagerTest extends ExistingSiteBase {

  /**
   * Content processor plugin manager.
   *
   * @var \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface
   */
  protected ContentProcessorPluginManagerInterface $contentProcessorPluginManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->contentProcessorPluginManager = \Drupal::service('plugin.manager.reliefweb_post_api.content_processor');
  }

  /**
   * Test constructor.
   */
  public function testConstructor(): void {
    $container = \drupal::getContainer();

    $manager = new ContentProcessorPluginManager(
      $container->get('container.namespaces'),
      $container->get('cache.discovery'),
      $container->get('module_handler')
    );

    $this->assertInstanceOf(ContentProcessorPluginManager::class, $manager);
  }

  /**
   * Test get plugin.
   */
  public function testGetPlugin(): void {
    $plugin = $this->contentProcessorPluginManager->getPlugin('reliefweb_post_api.content_processor.report');
    $this->assertInstanceOf(Report::class, $plugin);

    $plugin = $this->contentProcessorPluginManager->getPlugin('unknown');
    $this->assertNull($plugin);
  }

  /**
   * Test get plugin from property.
   */
  public function testGetPluginFromProperty(): void {
    $plugin = $this->contentProcessorPluginManager->getPluginFromProperty('bundle', 'report');
    $this->assertInstanceOf(Report::class, $plugin);

    $plugin = $this->contentProcessorPluginManager->getPluginFromProperty('unknown', '');
    $this->assertNull($plugin);

    $plugin = $this->contentProcessorPluginManager->getPluginFromProperty('unknown', 'unknown');
    $this->assertNull($plugin);
  }

  /**
   * Test get plugin by bundle.
   */
  public function testGetPluginByBundle(): void {
    $plugin = $this->contentProcessorPluginManager->getPluginByBundle('report');
    $this->assertInstanceOf(Report::class, $plugin);

    $plugin = $this->contentProcessorPluginManager->getPluginByBundle('unknown');
    $this->assertNull($plugin);

    $plugin = $this->contentProcessorPluginManager->getPluginByBundle('');
    $this->assertNull($plugin);
  }

  /**
   * Test get plugin by resource.
   */
  public function testGetPluginByResource(): void {
    $plugin = $this->contentProcessorPluginManager->getPluginByResource('reports');
    $this->assertInstanceOf(Report::class, $plugin);

    $plugin = $this->contentProcessorPluginManager->getPluginByResource('unknown');
    $this->assertNull($plugin);

    $plugin = $this->contentProcessorPluginManager->getPluginByResource('');
    $this->assertNull($plugin);
  }

}
