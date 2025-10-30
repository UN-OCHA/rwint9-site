<?php

namespace Drupal\reliefweb_moderation;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Dynamic permissions for affiliated content operations.
 */
class AffiliatedContentPermissions implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Constructs an AffiliatedContentPermissions object.
   *
   * @param \Drupal\reliefweb_moderation\Services\UserPostingRightsManagerInterface $userPostingRightsManager
   *   The user posting rights manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   */
  public function __construct(
    protected UserPostingRightsManagerInterface $userPostingRightsManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('reliefweb_moderation.user_posting_rights'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Get dynamic permissions for affiliated content operations.
   *
   * @return array
   *   Array of permission definitions.
   */
  public function permissions(): array {
    $permissions = [];
    $content_types = $this->userPostingRightsManager->getSupportedContentTypes();

    // Load all supported node types at once for efficiency.
    $node_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple($content_types);

    foreach ($content_types as $content_type) {
      if (!isset($node_types[$content_type])) {
        continue;
      }

      $content_type_label = $node_types[$content_type]->label();

      $permissions['view affiliated unpublished ' . $content_type . ' content'] = [
        'title' => $this->t('View affiliated unpublished @content_type content', ['@content_type' => $content_type_label]),
        'description' => $this->t('Allow users to view unpublished @content_type content from organizations they have posting rights for.', ['@content_type' => $content_type_label]),
      ];

      $permissions['edit affiliated ' . $content_type . ' content'] = [
        'title' => $this->t('Edit affiliated @content_type content', ['@content_type' => $content_type_label]),
        'description' => $this->t('Allow users to edit @content_type content from organizations they have posting rights for.', ['@content_type' => $content_type_label]),
      ];

      $permissions['delete affiliated ' . $content_type . ' content'] = [
        'title' => $this->t('Delete affiliated @content_type content', ['@content_type' => $content_type_label]),
        'description' => $this->t('Allow users to delete @content_type content from organizations they have posting rights for.', ['@content_type' => $content_type_label]),
      ];
    }

    return $permissions;
  }

}
