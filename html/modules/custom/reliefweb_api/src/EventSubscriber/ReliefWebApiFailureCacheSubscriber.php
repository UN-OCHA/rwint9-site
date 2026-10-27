<?php

declare(strict_types=1);

namespace Drupal\reliefweb_api\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Aligns page-cache and HTTP TTLs when a ReliefWeb API query failed.
 *
 * Internal Page Cache ignores render max-age and stores entries as permanent
 * unless the response carries a future Expires header. FinishResponseSubscriber
 * also sets Cache-Control from system.performance, which browsers and CDNs use.
 * When the API client bubbles a {cache_namespace}:failure tag with a short
 * max-age, this subscriber maps that onto both Expires and Cache-Control so
 * degraded pages leave page cache and external caches on the same TTL.
 *
 * @see \Drupal\page_cache\StackMiddleware\PageCache::storeResponse()
 * @see \Drupal\Core\EventSubscriber\FinishResponseSubscriber::setResponseCacheable()
 * @see \Drupal\reliefweb_api\Services\ReliefWebApiClient::requestMultiple()
 */
class ReliefWebApiFailureCacheSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a ReliefWebApiFailureCacheSubscriber.
   *
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Sets Expires and Cache-Control from ReliefWeb API failure cacheability.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onRespond(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $response = $event->getResponse();
    if (!$response instanceof CacheableResponseInterface) {
      return;
    }

    $metadata = $response->getCacheableMetadata();
    if (!in_array($this->getFailureCacheTag(), $metadata->getCacheTags(), TRUE)) {
      return;
    }

    $max_age = $metadata->getCacheMaxAge();
    if ($max_age === Cache::PERMANENT || $max_age <= 0) {
      return;
    }

    // Overwrite FinishResponseSubscriber's past Expires so Page Cache uses a
    // real expire timestamp instead of Cache::PERMANENT.
    $response->setExpires(new \DateTime('@' . ($this->time->getRequestTime() + $max_age)));
    // Align browsers/CDNs with the same TTL (replaces system.performance
    // cache.page.max_age for these responses).
    $response->headers->set('Cache-Control', 'public, max-age=' . $max_age);
  }

  /**
   * Cache tag marking responses that embedded a failed API query.
   *
   * @return string
   *   Failure cache tag matching ReliefWebApiClient.
   */
  protected function getFailureCacheTag(): string {
    $namespace = $this->configFactory->get('reliefweb_api.settings')->get('cache_namespace') ?: 'reliefweb:api';
    return $namespace . ':failure';
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run after FinishResponseSubscriber::onRespond (priority 0), which sets
    // Expires to a past date and Cache-Control from system.performance.
    return [
      KernelEvents::RESPONSE => ['onRespond', -10],
    ];
  }

}
