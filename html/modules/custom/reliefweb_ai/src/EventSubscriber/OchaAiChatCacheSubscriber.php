<?php

namespace Drupal\reliefweb_ai\EventSubscriber;

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
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   */
  public function __construct(
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
      $cache_metadata = $response->getCacheableMetadata();

      $cache_metadata->addCacheContexts(['user.roles:anonymous']);
      if ($this->currentUser->isAnonymous()) {
        // Cache for 1 hour.
        $cache_metadata->setCacheMaxAge(3600);
        // Ensure varnish for example can cache the page.
        $response->headers->set('Cache-Control', 'public, max-age=3600');
      }
    }
  }

}
