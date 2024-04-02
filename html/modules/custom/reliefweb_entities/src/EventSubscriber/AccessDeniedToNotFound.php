<?php

namespace Drupal\reliefweb_entities\EventSubscriber;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\EventSubscriber\HttpExceptionSubscriberBase;
use Drupal\Core\Http\Exception\CacheableGoneHttpException;
use Drupal\Core\Http\Exception\CacheableNotFoundHttpException;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_utility\Helpers\EntityHelper;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Redirects 403 page error responses to 404 page for bundle entities.
 */
class AccessDeniedToNotFound extends HttpExceptionSubscriberBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  protected static function getPriority() {
    return 1000;
  }

  /**
   * {@inheritdoc}
   */
  protected function getHandledFormats() {
    return ['html'];
  }

  /**
   * Handles all 4xx errors for all serialization failures.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The event to process.
   */
  public function on403(ExceptionEvent $event) {
    $entity = EntityHelper::getEntityFromRequest($event->getRequest());
    if (isset($entity) && $entity instanceof EntityModeratedInterface) {
      $cacheable_metadata = new CacheableMetadata();

      if ($entity instanceof CacheableDependencyInterface) {
        $cacheable_metadata->addCacheableDependency($entity);
      }

      $throwable = $event->getThrowable();
      if (isset($throwable) && $throwable instanceof CacheableDependencyInterface) {
        $cacheable_metadata->addCacheableDependency($throwable);
      }

      if ($entity->getModerationStatus() === 'expired') {
        $message = $this->t('The @bundle %title is no longer available.', [
          '@bundle' => $entity->bundle(),
          '%title' => $entity->label(),
        ]);

        $exception = new CacheableGoneHttpException($cacheable_metadata, $message);
      }
      else {
        $exception = new CacheableNotFoundHttpException($cacheable_metadata);
      }

      $event->setThrowable($exception);
    }
  }

}
