<?php

namespace Drupal\reliefweb_moderation;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Dynamic permissions for content operations.
 */
class ContentPermissions implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Constructs a ContentPermissions object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Get dynamic permissions for content operations.
   *
   * @return array
   *   Array of permission definitions.
   */
  public function permissions(): array {
    $permissions = [];

    // Load all node types at once for efficiency.
    $content_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();

    foreach ($content_types as $content_type) {
      $content_type_id = $content_type->id();
      $content_type_label = $content_type->label();

      $permissions['view any ' . $content_type_id . ' content'] = [
        'title' => $this->t('View any @content_type content', ['@content_type' => $content_type_label]),
        'description' => $this->t('Allow users to view any @content_type content.', ['@content_type' => $content_type_label]),
      ];

      $permissions['view own unpublished ' . $content_type_id . ' content'] = [
        'title' => $this->t('View own unpublished @content_type content', ['@content_type' => $content_type_label]),
        'description' => $this->t('Allow users to view their own unpublished @content_type content.', ['@content_type' => $content_type_label]),
      ];

      $permissions['view ' . $content_type_id . ' moderation information'] = [
        'title' => $this->t('View @content_type moderation information', ['@content_type' => $content_type_label]),
        'description' => $this->t('Allow users to view the moderation status and revision message for @content_type content.', ['@content_type' => $content_type_label]),
      ];
    }

    return $permissions;
  }

}
