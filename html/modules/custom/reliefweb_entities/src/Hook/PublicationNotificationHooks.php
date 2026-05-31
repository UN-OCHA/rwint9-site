<?php

declare(strict_types=1);

namespace Drupal\reliefweb_entities\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\reliefweb_entities\Services\PublicationNotifier;

/**
 * Publication notification hooks for moderated document nodes.
 */
final class PublicationNotificationHooks {

  /**
   * Constructs a PublicationNotificationHooks object.
   */
  public function __construct(
    protected readonly PublicationNotifier $publicationNotifier,
  ) {}

  /**
   * Stages publication notification recipients after moderation is resolved.
   */
  #[Hook('entity_presave', order: new OrderAfter(modules: ['reliefweb_moderation']))]
  public function entityPresave(EntityInterface $entity): void {
    $this->publicationNotifier->prepare($entity);
  }

  /**
   * Sends a staged publication notification after the entity is created.
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    $this->publicationNotifier->send($entity);
  }

  /**
   * Sends a staged publication notification after the entity is updated.
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    $this->publicationNotifier->send($entity);
  }

}
