<?php

namespace Drupal\reliefweb_guidelines\Entity\Taxonomy;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\EntityModeratedTrait;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_revisions\EntityRevisionedTrait;

/**
 * Bundle class for guideline list taxonomy terms.
 */
class GuidelineList extends Term implements EntityModeratedInterface, EntityRevisionedInterface {

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
  public function label(): string|MarkupInterface|null {
    if (!$this->hasField('field_role') || $this->field_role?->isEmpty()) {
      $role = $this->t('Editor');
    }
    else {
      $role = $this->field_role->entity->label();
    }
    return $this->t('[@role] @label', ['@role' => $role, '@label' => parent::label()]);
  }

  /**
   * Get the display name without the role prefix.
   *
   * @return string
   *   Guideline list name.
   */
  public function getName(): string {
    return (string) $this->get('name')->value;
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities) {
    if (!empty($entities)) {
      $ids = [];
      foreach ($entities as $entity) {
        $ids[$entity->id()] = $entity->id();
      }

      $node_storage = \Drupal::entityTypeManager()->getStorage('node');
      $nodes = $node_storage->loadByProperties([
        'type' => 'guideline',
        'field_guideline_list' => $ids,
      ]);

      foreach ($nodes as $node) {
        $node->set('field_guideline_list', NULL);
        $node->save();
      }
    }

    parent::postDelete($storage, $entities);
  }

  /**
   * Get child guideline nodes for this list.
   *
   * @return \Drupal\reliefweb_guidelines\Entity\Node\Guideline[]
   *   Guideline nodes.
   */
  public function getChildren(): array {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->condition('type', 'guideline')
      ->condition('field_guideline_list', $this->id())
      ->sort('field_weight', 'ASC')
      ->accessCheck(FALSE)
      ->execute();

    return $storage->loadMultiple($ids);
  }

  /**
   * Get the list of guidelines under this list.
   *
   * @return array
   *   Render array.
   */
  public function getGuidelineLinks(): array {
    $children = $this->getChildren();
    if (empty($children)) {
      return [];
    }

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
      '#heading' => [
        'text' => $this->t('Guidelines'),
        'level' => 'h2',
      ],
      '#cache' => [
        'tags' => ['taxonomy_term_list:guideline_list'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * @todo Add granular permissions for revision history access.
   */
  public function getHistory() {
    if (!$this->access('update')) {
      return [];
    }
    return $this->getEntityHistoryService()->getEntityHistory($this);
  }

  /**
   * {@inheritdoc}
   *
   * @todo Add granular permissions for revision history access.
   */
  public function getHistoryContent() {
    if (!$this->access('update')) {
      return [];
    }
    return $this->getEntityHistoryService()->getEntityHistoryContent($this);
  }

}
