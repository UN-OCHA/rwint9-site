<?php

namespace Drupal\taxonomy_term_preview\Access;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Determines access to taxonomy term previews.
 */
class TermPreviewAccessCheck implements AccessInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs an EntityCreateAccessCheck object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Checks access to the term preview page.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   * @param \Drupal\term\TermInterface $term_preview
   *   The term that is being previewed.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account, TermInterface $term_preview) {
    if ($term_preview->isNew()) {
      $access_controller = $this->entityTypeManager->getAccessControlHandler('taxonomy_term');
      return $access_controller->createAccess($term_preview->bundle(), $account, [], TRUE);
    }
    else {
      return $term_preview->access('update', $account, TRUE);
    }
  }

}
