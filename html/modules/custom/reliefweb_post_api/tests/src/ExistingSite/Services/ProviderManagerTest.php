<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Services;

use Drupal\Core\Site\Settings;
use Drupal\reliefweb_post_api\Entity\ProviderInterface;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the provider manager service.
 *
 * @coversDefaultClass \Drupal\reliefweb_post_api\Services\ProviderManager
 *
 * @group reliefweb_post_api
 */
class ProviderManagerTest extends ExistingSiteBase {

  /**
   * @covers ::getProvider
   */
  public function testGetProvider(): void {
    $settings = Settings::getAll();
    $settings['reliefweb_post_api.providers']['test-provider'] = [
      'key' => 'test-provider-key',
      'url_pattern' => '@^https://test.test/@',
    ];
    new Settings($settings);

    $manager = \Drupal::service('reliefweb_post_api.provider.manager');

    $provider = $manager->getProvider('');
    $this->assertNull($provider);

    $provider = $manager->getProvider('test-unknow');
    $this->assertNull($provider);

    $provider = $manager->getProvider('test-provider');
    $this->assertInstanceOf(ProviderInterface::class, $provider);
  }

}
