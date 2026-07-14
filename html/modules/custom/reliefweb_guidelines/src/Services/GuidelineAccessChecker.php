<?php

declare(strict_types=1);

namespace Drupal\reliefweb_guidelines\Services;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\reliefweb_guidelines\Entity\Node\Guideline;
use Drupal\reliefweb_guidelines\Entity\Taxonomy\GuidelineList;
use Drupal\reliefweb_guidelines\GuidelinePermissions;

/**
 * Checks guideline and guideline list access based on role permissions.
 */
class GuidelineAccessChecker {

  /**
   * Constructs a GuidelineAccessChecker.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
  ) {}

  /**
   * Get role IDs for which the user may view role-scoped guideline content.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User account.
   *
   * @return string[]
   *   Role IDs the user may view guidelines for.
   */
  public function getViewableGuidelineRoleIds(AccountInterface $account): array {
    $accessible = [];
    foreach (array_keys(reliefweb_guidelines_get_user_roles()) as $role_id) {
      if ($this->userCanViewGuidelinesForRole($role_id, $account)) {
        $accessible[] = $role_id;
      }
    }
    return $accessible;
  }

  /**
   * Check if a user can access a guideline list term.
   *
   * @param \Drupal\reliefweb_guidelines\Entity\Taxonomy\GuidelineList $list
   *   Guideline list term.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User account.
   *
   * @return bool
   *   TRUE if accessible.
   */
  public function isGuidelineListAccessible(GuidelineList $list, AccountInterface $account): bool {
    if ($this->userCanViewAnyGuidelineList($account)) {
      return TRUE;
    }

    return $this->userCanViewGuidelinesForRole($list->get('field_role')->target_id, $account);
  }

  /**
   * Check if a user can view guidelines scoped to a given role.
   *
   * @param string $role_id
   *   Audience role ID (e.g. contributor, editor).
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User account.
   *
   * @return bool
   *   TRUE if the user has the matching role-scoped view permission.
   */
  public function userCanViewGuidelinesForRole(string $role_id, AccountInterface $account): bool {
    return $account->hasPermission(GuidelinePermissions::getViewPermissionId($role_id));
  }

  /**
   * Check if a user can access a guideline node.
   *
   * @param \Drupal\reliefweb_guidelines\Entity\Node\Guideline $guideline
   *   Guideline node.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User account.
   *
   * @return bool
   *   TRUE if accessible.
   */
  public function isGuidelineAccessible(Guideline $guideline, AccountInterface $account): bool {
    $list = $guideline->getGuidelineList();
    if (!$list) {
      return FALSE;
    }
    return $this->isGuidelineListAccessible($list, $account);
  }

  /**
   * Check if a user can access a guideline image file.
   *
   * This resolves the role of the guideline list associated with the guideline
   * node the image is attached to, then checks the role permission. It avoids
   * loading the guideline node and its parent list entities.
   *
   * @param \Drupal\file\FileInterface $file
   *   Image file entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User account.
   *
   * @return bool
   *   TRUE if accessible.
   */
  public function userCanAccessGuidelineFile(FileInterface $file, AccountInterface $account): bool {
    if ($this->userCanViewAnyGuidelineContent($account)) {
      return TRUE;
    }

    // Resolve the role of the guideline list the image's guideline belongs to.
    // The role field is mandatory on guideline lists, so an inner join is
    // enough: no row means the image is not associated with a guideline list.
    $query = $this->database->select('node__field_images', 'gfi');
    $query->addField('gfr', 'field_role_target_id', 'role');
    $query->innerJoin('node__field_guideline_list', 'ggl', '%alias.entity_id = gfi.entity_id');
    $query->innerJoin('taxonomy_term__field_role', 'gfr', '%alias.entity_id = ggl.field_guideline_list_target_id');
    $query->condition('gfi.field_images_target_id', $file->id());
    $query->range(0, 1);
    $role_id = $query->execute()?->fetchField();

    // No guideline list is associated with the file: deny access.
    if ($role_id === FALSE) {
      return FALSE;
    }

    return $this->userCanViewGuidelinesForRole($role_id, $account);
  }

  /**
   * Get IDs of published guideline lists accessible to the user.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User account.
   *
   * @return int[]
   *   Guideline list term IDs.
   */
  public function getAccessibleGuidelineListIds(AccountInterface $account): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $query = $storage
      ->getQuery()
      ->condition('moderation_status', 'published', '=')
      ->condition('vid', 'guideline_list', '=')
      ->sort('weight', 'ASC')
      ->accessCheck(TRUE);

    // Limit the query to the guideline lists that the user can view based
    // on their roles.
    if (!$this->userCanViewAnyGuidelineList($account)) {
      $accessible_role_ids = $this->getViewableGuidelineRoleIds($account);
      if (empty($accessible_role_ids)) {
        return [];
      }

      $query->condition('field_role', $accessible_role_ids, 'IN');
    }

    return $query->execute();
  }

  /**
   * Check if a user can view any guideline content.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User account.
   *
   * @return bool
   *   TRUE if the user can view any guideline content.
   */
  public function userCanViewAnyGuidelineContent(AccountInterface $account): bool {
    return $account->hasPermission('view any guideline content');
  }

  /**
   * Check if a user can view any guideline list.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User account.
   *
   * @return bool
   *   TRUE if the user can view any guideline list.
   */
  public function userCanViewAnyGuidelineList(AccountInterface $account): bool {
    return $account->hasPermission(GuidelinePermissions::getViewAnyListPermissionId());
  }

  /**
   * User can access editorial guidelines.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User account.
   *
   * @return bool
   *   TRUE if the user can access editorial guidelines.
   */
  public function userCanAccessEditorialGuidelines(AccountInterface $account): bool {
    return $account->hasPermission('access editorial guidelines');
  }

}
