<?php

namespace Drupal\reliefweb_ai\EventSubscriber;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Modify caching of the OCHA AI chat form.
 */
class OchaAiChatCacheSubscriber implements EventSubscriberInterface {

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // We need to run last to ensure the cache is not overridden.
      KernelEvents::RESPONSE => ['onResponse', -1000],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    $route = $request->attributes->get('_route');

    if ($route === 'ocha_ai_chat.chat_form' || $route === 'ocha_ai_chat.chat_form.popup') {
      $response = $event->getResponse();

      // Ajax response are not cacheable so only handle normal form response.
      if ($response instanceof CacheableResponseInterface) {
        $config = $this->configFactory->get('reliefweb_ai.settings');

        $cache_metadata = $response->getCacheableMetadata();

        // Vary the cache by role, url parameters and config since they control
        // what is displayed to the user.
        $cache_metadata->addCacheContexts(['user.roles', 'url.query_args']);
        $cache_metadata->addCacheTags(['config:reliefweb_ai.settings']);

        // Cache the response for 1 hour for anonymous user when we just show
        // a disabled form asking to log in or register.
        if ($this->currentUser->isAnonymous() && !$config->get('ocha_ai_chat.allow_for_anonymous')) {
          // Cache for 1 hour.
          $cache_metadata->setCacheMaxAge(3600);
          // Ensure varnish for example can cache the page.
          $response->headers->set('Cache-Control', 'public, max-age=3600');
        }
      }
    }
  }

}
