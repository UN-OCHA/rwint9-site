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
  public function buildHeader() {
    $header['id'] = $this->t('Guideline ID');
    $header['name'] = $this->t('Name');
    $header['weight'] = $this->t('Weight');
    $header['parent'] = $this->t('Parent(s)');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\guidelines\Entity\Guideline $entity */
    $row['id'] = $entity->id();
    $row['name'] = Link::createFromRoute(
      $entity->label(),
      'entity.guideline.canonical',
      ['guideline' => $entity->id()]
    );
    $row['weight'] = !empty($entity->getWeight()) ? $entity->getWeight() : '';

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
