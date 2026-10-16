<?php

declare(strict_types=1);

namespace Drupal\reliefweb_moderation;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Dynamic permissions to edit content in terminal moderation statuses.
 */
class TerminalStatusPermissions implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Constructs a TerminalStatusPermissions object.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   */
  public function __construct(
    protected ContainerInterface $container,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container);
  }

  /**
   * Get dynamic permissions for terminal moderation statuses.
   *
   * @return array
   *   Array of permission definitions keyed by permission name.
   */
  public function permissions(): array {
    $permissions = [];
    $labels = [];

    foreach (ModerationServiceBase::getAllModerationServices() as $service) {
      $status_labels = $service->getStatuses();
      foreach ($service->getTerminalStatuses() as $status) {
        if (isset($status_labels[$status])) {
          $labels[$status] = $status_labels[$status];
        }
        elseif (!isset($labels[$status])) {
          $labels[$status] = $status;
        }
      }
    }

    ksort($labels);
    foreach ($labels as $status => $label) {
      $permission = ModerationServiceBase::getTerminalStatusPermission($status);
      $permissions[$permission] = [
        'title' => $this->t('Edit @status content', ['@status' => $label]),
        'description' => $this->t('Allow users to edit content with the @status moderation status.', [
          '@status' => $label,
        ]),
      ];
    }

    return $permissions;
  }

}
