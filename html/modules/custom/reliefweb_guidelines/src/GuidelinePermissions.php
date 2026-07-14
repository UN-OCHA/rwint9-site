<?php

declare(strict_types=1);

namespace Drupal\reliefweb_guidelines;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Dynamic permissions for guideline content access by role.
 */
class GuidelinePermissions implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static();
  }

  /**
   * Build the permission ID for viewing guidelines scoped to a role.
   *
   * @param string $role_id
   *   User role machine name.
   *
   * @return string
   *   Permission ID.
   */
  public static function getViewPermissionId(string $role_id): string {
    return 'view ' . $role_id . ' guideline content';
  }

  /**
   * Build the permission ID for viewing any guideline list.
   *
   * @return string
   *   Permission ID.
   */
  public static function getViewAnyListPermissionId(): string {
    return 'view any guideline list';
  }

  /**
   * Get dynamic permissions for guideline content access.
   *
   * @return array
   *   Permission definitions keyed by permission ID.
   */
  public function permissions(): array {
    $permissions = [];

    foreach (reliefweb_guidelines_get_user_roles() as $role_id => $role_label) {
      $permissions[static::getViewPermissionId($role_id)] = [
        'title' => $this->t('View @role guideline content', ['@role' => $role_label]),
        'description' => $this->t('Allow users to view guidelines assigned to the @role role.', ['@role' => $role_label]),
      ];
    }

    return $permissions;
  }

}
