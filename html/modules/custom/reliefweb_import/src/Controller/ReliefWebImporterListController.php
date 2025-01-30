<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\reliefweb_import\Plugin\ReliefWebImporterPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * List ReliefWeb content importers.
 */
class ReliefWebImporterListController extends ControllerBase {

  /**
   * Constructor.
   *
   * @param \Drupal\reliefweb_import\Plugin\ReliefWebImporterPluginManager $pluginManager
   *   The ReliefWeb content importer plugin manager.
   */
  public function __construct(
    protected ReliefWebImporterPluginManager $pluginManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('plugin.manager.reliefweb_import.reliefweb_importer')
    );
  }

  /**
   * Display a list of ReliefWeb content importers.
   *
   * @return array
   *   Render array with a table of the available content importer plugins.
   */
  public function listImporters(): array {
    $build = [];
    $headers = [
      $this->t('Plugin ID'),
      $this->t('Label'),
      $this->t('Description'),
      $this->t('Enabled'),
      $this->t('Operations'),
    ];

    $rows = [];
    foreach ($this->pluginManager->getDefinitions() as $plugin_id => $definition) {
      $plugin = $this->pluginManager->getPlugin($plugin_id);

      $rows[] = [
        $plugin_id,
        $definition['label'],
        $definition['description'],
        $plugin->enabled() ? $this->t('Yes') : $this->t('No'),
        [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Configure'),
            '#url' => Url::fromRoute('reliefweb_import.reliefweb_importer.plugin.configure', [
              'plugin_id' => $plugin_id,
            ]),
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $headers,
      '#rows' => $rows,
      '#empty' => $this->t('No ReliefWeb Importer plugins found.'),
    ];

    return $build;
  }

}
