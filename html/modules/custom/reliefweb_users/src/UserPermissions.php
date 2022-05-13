<?php

namespace Drupal\reliefweb_users;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ReliefWeb Users permission provider.
 */
class UserPermissions implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Get permissions to assign roles.
   *
   * @return array
   *   Associative array witht the permissions as keys and an array with the
   *   permission title and description as values.
   */
  public function permissions() {
    $permissions = [];

    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    foreach ($roles as $id => $role) {
      if ($id === $role::ANONYMOUS_ID || $id === $role::AUTHENTICATED_ID) {
        continue;
      }

      $permissions['assign ' . $id . ' role'] = [
        'title' => $this->t('Assign @label role.', [
          '@label' => $role->label(),
        ]),
        'description' => $this->t('Allow users to assign the @label role to other accounts.', [
          '@label' => $role->label(),
        ]),
      ];
    }

    return $permissions;
  }

}
