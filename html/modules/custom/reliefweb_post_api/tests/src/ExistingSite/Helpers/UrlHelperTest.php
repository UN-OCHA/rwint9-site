<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Helpers;

use Drupal\Core\State\StateInterface;
use Drupal\reliefweb_post_api\Helpers\UrlHelper;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the ReliefWeb Post API URL helper.
 *
 * @coversDefaultClass \Drupal\reliefweb_post_api\Helpers\UrlHelper
 *
 * @group reliefweb_post_api
 */
class UrlHelperTest extends ExistingSiteBase {

  /**
   * @covers ::replaceBaseUrl
   */
  public function testReplaceBaseUrl(): void {
    $url = 'https://test.test/some/path';

    $this->setStateMock(NULL);
    $this->assertSame(UrlHelper::replaceBaseUrl($url), $url);

    $this->setStateMock([]);
    $this->assertSame(UrlHelper::replaceBaseUrl($url), $url);

    $this->setStateMock(['https://some.thing' => 'http://other.thing']);
    $this->assertSame(UrlHelper::replaceBaseUrl($url), $url);

    $this->setStateMock(['https://test.test' => 'http://other.thing']);
    $this->assertSame(UrlHelper::replaceBaseUrl($url), 'http://other.thing/some/path');
  }

  /**
   * Set the state service mock.
   *
   * @param ?array $mapping
   *   URL mapping.
   */
  protected function setStateMock(?array $mapping = NULL): void {
    UrlHelper::$urlMapping = NULL;

    $state = $this->createConfiguredMock(StateInterface::class, [
      'get' => $mapping,
    ]);
    $container = \Drupal::getContainer();
    $container->set('state', $state);
  }

}
