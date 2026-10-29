<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_api\Unit\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\reliefweb_api\EventSubscriber\ReliefWebApiFailureCacheSubscriber;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests ReliefWeb API failure page-cache Expires subscriber.
 */
#[CoversClass(ReliefWebApiFailureCacheSubscriber::class)]
#[Group('reliefweb_api')]
class ReliefWebApiFailureCacheSubscriberTest extends UnitTestCase {

  /**
   * Fixed request time used in tests.
   */
  protected const REQUEST_TIME = 1_700_000_000;

  /**
   * Subscriber under test.
   */
  protected ReliefWebApiFailureCacheSubscriber $subscriber;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(static::REQUEST_TIME);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('cache_namespace')->willReturn('reliefweb:api');

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('reliefweb_api.settings')->willReturn($config);

    $this->subscriber = new ReliefWebApiFailureCacheSubscriber($time, $config_factory);
  }

  /**
   * Sets Expires and Cache-Control when the failure tag is present.
   */
  public function testSetsExpiresForFailureTag(): void {
    $response = new CacheableResponse('ok');
    $response->headers->set('Cache-Control', 'public, max-age=900');
    $response->getCacheableMetadata()
      ->addCacheTags(['reliefweb:api:failure'])
      ->setCacheMaxAge(60);

    $this->subscriber->onRespond($this->createResponseEvent($response));

    $this->assertSame(static::REQUEST_TIME + 60, $response->getExpires()->getTimestamp());
    // Symfony normalizes Cache-Control directive order when reading.
    $this->assertSame('max-age=60, public', $response->headers->get('Cache-Control'));
  }

  /**
   * Leaves headers alone when no failure tag is present.
   */
  public function testIgnoresResponsesWithoutFailureTag(): void {
    $response = new CacheableResponse('ok');
    $response->getCacheableMetadata()
      ->addCacheTags(['reliefweb:api:reports', 'node_list:report'])
      ->setCacheMaxAge(60);
    $response->setExpires(new \DateTime('@' . (static::REQUEST_TIME - 100)));
    $response->headers->set('Cache-Control', 'public, max-age=900');

    $this->subscriber->onRespond($this->createResponseEvent($response));

    $this->assertSame(static::REQUEST_TIME - 100, $response->getExpires()->getTimestamp());
    $this->assertSame('max-age=900, public', $response->headers->get('Cache-Control'));
  }

  /**
   * Leaves headers alone when max-age is permanent.
   */
  public function testIgnoresPermanentMaxAge(): void {
    $response = new CacheableResponse('ok');
    $response->getCacheableMetadata()
      ->addCacheTags(['reliefweb:api:failure'])
      ->setCacheMaxAge(Cache::PERMANENT);
    $response->setExpires(new \DateTime('@' . (static::REQUEST_TIME - 100)));
    $response->headers->set('Cache-Control', 'public, max-age=900');

    $this->subscriber->onRespond($this->createResponseEvent($response));

    $this->assertSame(static::REQUEST_TIME - 100, $response->getExpires()->getTimestamp());
    $this->assertSame('max-age=900, public', $response->headers->get('Cache-Control'));
  }

  /**
   * Leaves headers alone when max-age is 0.
   */
  public function testIgnoresZeroMaxAge(): void {
    $response = new CacheableResponse('ok');
    $response->getCacheableMetadata()
      ->addCacheTags(['reliefweb:api:failure'])
      ->setCacheMaxAge(0);
    $response->setExpires(new \DateTime('@' . (static::REQUEST_TIME - 100)));
    $response->headers->set('Cache-Control', 'public, max-age=900');

    $this->subscriber->onRespond($this->createResponseEvent($response));

    $this->assertSame(static::REQUEST_TIME - 100, $response->getExpires()->getTimestamp());
    $this->assertSame('max-age=900, public', $response->headers->get('Cache-Control'));
  }

  /**
   * Leaves non-cacheable responses alone.
   */
  public function testIgnoresNonCacheableResponses(): void {
    $response = new Response('ok');
    $response->setExpires(new \DateTime('@' . (static::REQUEST_TIME - 100)));
    $response->headers->set('Cache-Control', 'public, max-age=900');

    $this->subscriber->onRespond($this->createResponseEvent($response));

    $this->assertSame(static::REQUEST_TIME - 100, $response->getExpires()->getTimestamp());
    $this->assertSame('max-age=900, public', $response->headers->get('Cache-Control'));
  }

  /**
   * Creates a main-request response event for the given response.
   */
  protected function createResponseEvent(Response $response): ResponseEvent {
    $kernel = $this->createMock(HttpKernelInterface::class);
    return new ResponseEvent(
      $kernel,
      Request::create('/'),
      HttpKernelInterface::MAIN_REQUEST,
      $response,
    );
  }

}
