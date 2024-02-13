<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Entity;

use Drupal\reliefweb_post_api\Entity\Provider;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the provider entity.
 *
 * @coversDefaultClass \Drupal\reliefweb_post_api\Entity\Provider
 *
 * @group reliefweb_post_api
 */
class ProviderTest extends ExistingSiteBase {

  /**
   * Provider data.
   *
   * @var array
   */
  protected $data = [
    'id' => 'test-provider',
    'key' => 'test-provider-key',
    'url_pattern' => '@^https://test.test/@',
    'notify' => ['test@test.test'],
    'sources' => [1503],
    'uid' => 12,
  ];

  /**
   * @covers ::__construct
   */
  public function testContructor(): void {
    $provider = new Provider($this->data);
    $this->assertInstanceOf(Provider::class, $provider);
  }

  /**
   * @covers ::id
   */
  public function testId(): void {
    $data = $this->data;

    $provider = new Provider($data);
    $this->assertEquals($this->data['id'], $provider->id());

    unset($data['id']);
    $provider = new Provider($data);
    $this->assertEquals('', $provider->id());
  }

  /**
   * @covers ::getUrlPattern
   */
  public function testGetUrlPattern(): void {
    $data = $this->data;

    $provider = new Provider($data);
    $this->assertEquals($this->data['url_pattern'], $provider->getUrlPattern());

    unset($data['url_pattern']);
    $provider = new Provider($data);
    $this->assertEquals('#^https://.+$#', $provider->getUrlPattern());
  }

  /**
   * @covers ::getEmailsToNotify
   */
  public function testGetEmailsToNotify(): void {
    $data = $this->data;

    $provider = new Provider($data);
    $this->assertEquals($this->data['notify'], $provider->getEmailsToNotify());

    unset($data['notify']);
    $provider = new Provider($data);
    $this->assertEquals([], $provider->getEmailsToNotify());
  }

  /**
   * @covers ::getAllowedSources
   */
  public function testGetAllowedSources(): void {
    $data = $this->data;

    $provider = new Provider($data);
    $this->assertEquals($this->data['sources'], $provider->getAllowedSources());

    unset($data['sources']);
    $provider = new Provider($data);
    $this->assertEquals([], $provider->getAllowedSources());
  }

  /**
   * @covers ::getUserId
   */
  public function testGetUserId(): void {
    $data = $this->data;

    $provider = new Provider($data);
    $this->assertEquals($this->data['uid'], $provider->getUserId());

    unset($data['uid']);
    $provider = new Provider($data);
    $this->assertEquals(2, $provider->getUserId());
  }

  /**
   * @covers ::validateKey
   */
  public function testValidateKey(): void {
    $data = $this->data;

    $provider = new Provider($data);
    $this->assertTrue($provider->validateKey($this->data['key']));
    $this->assertFalse($provider->validateKey('wrong'));
    $this->assertFalse($provider->validateKey(''));

    unset($data['key']);
    $provider = new Provider($data);
    $this->assertFalse($provider->validateKey($this->data['key']));
  }

}
