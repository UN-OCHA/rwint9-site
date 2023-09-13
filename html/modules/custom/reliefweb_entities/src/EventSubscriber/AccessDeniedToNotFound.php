<?php

namespace Drupal\reliefweb_entities\EventSubscriber;

use Drupal\Core\EventSubscriber\HttpExceptionSubscriberBase;
use Drupal\reliefweb_entities\BundleEntityInterface;
use Drupal\reliefweb_utility\Helpers\EntityHelper;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Redirects 403 page error responses to 404 page for bundle entities.
 */
class AccessDeniedToNotFound extends HttpExceptionSubscriberBase {

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
    if (isset($entity) && $entity instanceof BundleEntityInterface) {
      $event->setThrowable(new NotFoundHttpException());
    }
  }

}
