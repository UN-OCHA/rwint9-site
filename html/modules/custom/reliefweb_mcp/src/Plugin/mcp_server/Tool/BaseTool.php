<?php

declare(strict_types=1);

namespace Drupal\reliefweb_mcp\Plugin\mcp_server\Tool;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\mcp_server\Plugin\ToolPluginBase;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for taxonomy-related tools.
 */
abstract class BaseTool extends ToolPluginBase {

  /**
   * Constructs a DisasterTool object.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    AccountProxyInterface $currentUser,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $currentUser);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defaultConfiguration(): array {
    return [
      'enabled' => TRUE,
    ];
  }

  /**
   * Make sure input only contains letters and numbers.
   */
  protected function sanitizeInput(string $input): string {
    $input = trim($input);
    return preg_replace('/[^a-zA-Z0-9\(\) \-]/', '', $input);
  }

  /**
   * Get a taxonomy term by its label and vocabulary.
   *
   * @param string $label
   *   The label of the term.
   * @param string $vocabulary
   *   The vocabulary ID.
   * @param string $field
   *   The field to match the label against, defaults to 'name'.
   *
   * @return \Drupal\taxonomy\TermInterface|null
   *   The taxonomy term if found, NULL otherwise.
   */
  protected function getTerm(string $label, string $vocabulary, string $field = 'name') : Term|NULL {
    $term = NULL;

    if (empty($label)) {
      return NULL;
    }

    $values = [
      $field => trim($label),
      'vid' => $vocabulary,
    ];
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties($values);
    if (!empty($terms)) {
      $term = reset($terms);
    }

    return $term;
  }

}
