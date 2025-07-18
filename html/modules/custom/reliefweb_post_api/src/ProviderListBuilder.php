<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Defines the list builder for ReliefWeb Post API provider entities.
 */
class ProviderListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['name'] = $this->t('Name');
    $header['uuid'] = $this->t('UUID');
    $header['resource'] = $this->t('Resource');
    $header['resource_status'] = $this->t('Default status');
    $header['source'] = $this->t('Sources');
    $header['status'] = $this->t('Active');
    $header['skip_queue'] = $this->t('Skip queue');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    $row['name']['data'] = $entity->toLink()->toRenderable();

    $row['uuid']['data'] = $entity->uuid->view([
      'label' => 'hidden',
    ]);

    $row['resource']['data'] = $entity->resource->view([
      'label' => 'hidden',
    ]);

    $row['resource_status']['data'] = $entity->field_resource_status->view([
      'label' => 'hidden',
    ]);

    if (!$entity->field_source->isEmpty()) {
      $sources = [];
      foreach ($entity->field_source as $item) {
        $source = $item->entity;
        if (!empty($source)) {
          $sources[] = $this->t('@link (@id)', [
            '@link' => $source->toLink($source->field_shortname?->value ?? $source->label())->toString(),
            '@id' => $source->id(),
          ]);
        }
      }
      $row['source']['data'] = [
        '#theme' => 'item_list',
        '#items' => $sources,
      ];
    }
    else {
      $row['source']['data'] = [
        '#markup' => $this->t('Any source'),
      ];
    }

    $row['status']['data'] = $entity->status->view([
      'label' => 'hidden',
      'format' => 'yes-no',
      'settings' => [
        'format' => 'yes-no',
      ],
    ]);

    $row['skip_queue']['data'] = $entity->field_skip_queue->view([
      'label' => 'hidden',
      'format' => 'yes-no',
      'settings' => [
        'format' => 'yes-no',
      ],
    ]);

    return $row + parent::buildRow($entity);
  }

}
