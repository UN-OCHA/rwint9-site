<?php

namespace Drupal\reliefweb_guidelines\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\guidelines\Entity\Guideline as GuidelineBase;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\EntityModeratedTrait;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_revisions\EntityRevisionedTrait;

/**
 * Bundle class for the guideline lists.
 */
class GuidelineList extends GuidelineBase implements EntityModeratedInterface, EntityRevisionedInterface {

  use EntityModeratedTrait;
  use EntityRevisionedTrait;

  /**
   * {@inheritdoc}
   */
  public function getDefaultModerationStatus() {
    return 'published';
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities) {
    // Remove reference to the guideline list from guidelines that have it as
    // parent.
    if (!empty($entities)) {
      $ids = [];
      foreach ($entities as $entity) {
        $ids[$entity->id()] = $entity->id();
      }

      $guidelines = $storage->loadByProperties([
        'parent' => $ids,
      ]);

      foreach ($guidelines as $guideline) {
        $guideline->parent->filter(function ($item) use ($ids) {
          return !isset($ids[$item->target_id]);
        });
        $guideline->save();
      }
    }

    parent::postDelete($storage, $entities);
  }

  /**
   * Get the list of guidelines under this list.
   *
   * @return array
   *   Render array.
   */
  public function getGuidelineLinks() {
    $children = $this->getChildren();
    if (!empty($children)) {
      $links = [];
      foreach ($children as $child) {
        $links[] = [
          'title' => $child->label(),
          'url' => $child->toUrl(),
        ];
      }
      return [
        '#theme' => 'links',
        '#links' => $links,
        '#cache' => [
          'tags' => ['guideline_list'],
        ],
      ];
    }
    return [];
  }

}
