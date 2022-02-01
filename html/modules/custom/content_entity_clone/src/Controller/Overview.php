<?php

namespace Drupal\content_entity_clone\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Overview of the content entities with cloning enabled.
 */
class Overview extends ControllerBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $bundleInfo;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeBundleInfoInterface $bundle_info,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->configFactory = $config_factory;
    $this->bundleInfo = $bundle_info;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Get the overview render array.
   *
   * @return array
   *   Render array.
   */
  public function getPageContent() {
    $rows = [];
    foreach ($this->bundleInfo->getAllBundleInfo() as $entity_type_id => $bundles) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      if (!($entity_type instanceof ContentEntityTypeInterface)) {
        continue;
      }

      foreach ($bundles as $bundle => $info) {
        $row = [];

        // Entity label.
        $row[] = $entity_type->getLabel();

        // Bundle label.
        $row[] = $info['label'] ?? ucfirst(strtr($bundle, '_', ' '));

        // Status.
        $id = 'content_entity_clone.bundle.settings.' . $entity_type_id . '.' . $bundle;
        $config = $this->configFactory->get($id);
        $enabled = !empty($config) && !empty($config->get('enabled'));
        $row[] = $enabled ? $this->t('Enabled') : '';

        // Link to edit the cloning setting for the bundle.
        $url = Url::fromRoute('content_entity_clone.bundle.field_settings', [
          'entity_type' => $entity_type_id,
          'bundle' => $bundle,
        ]);
        $row[] = Link::fromTextAndUrl($this->t('Edit'), $url);

        $rows[] = $row;
      }
    }

    return [
      '#theme' => 'table',
      '#header' => [
        $this->t('Entity Type'),
        $this->t('Bundle'),
        $this->t('Status'),
        $this->t('Field settings'),
      ],
      '#rows' => $rows,
    ];
  }

}
