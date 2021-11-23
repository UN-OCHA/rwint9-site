<?php

namespace Drupal\guidelines;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;

/**
 * Defines a class to build a listing of Guideline entities.
 *
 * @ingroup guidelines
 */
class GuidelineListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  protected $limit = FALSE;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'guidelines_list';
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Name');
    $header['parent'] = $this->t('Parent(s)');
    $header = $header + parent::buildHeader();

    return $header;
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\guidelines\Entity\Guideline $entity */
    $row['label'] = Link::createFromRoute(
      $entity->label(),
      'entity.guideline.canonical',
      ['guideline' => $entity->id()]
    )->toString();

    $parents = [];
    $parent_entities = $entity->getParents();
    foreach ($parent_entities as $parent_entity) {
      $parents[] = Link::createFromRoute(
        $parent_entity->label(),
        'entity.guideline.canonical',
        ['guideline' => $parent_entity->id()]
      )->toString();
    }

    $row['parent'] = [
      'data' => [
        '#markup' => implode(', ', $parents),
      ],
    ];

    return $row + parent::buildRow($entity);
  }

}
