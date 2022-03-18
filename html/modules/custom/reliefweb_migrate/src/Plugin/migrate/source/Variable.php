<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Row;

/**
 * Migrate variables.
 *
 * @MigrateSource(
 *   id = "reliefweb_variable",
 * )
 */
class Variable extends SqlBase {

  /**
   * Variables to migrate.
   *
   * @var array
   */
  protected $variables;

  /**
   * {@inheritdoc}
   */
  public function query() {
    return $this->select('variable', 'v')
      ->fields('v', ['name', 'value'])
      ->condition('name', array_keys($this->getVariables()), 'IN');
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'name' => $this->t('Name'),
      'value' => $this->t('Value'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $variables = $this->getVariables();
    $name = $row->getSourceProperty('name');
    $row->setSourceProperty('name', $variables[$name]);

    return parent::prepareRow($row);
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['name']['type'] = 'string';
    return $ids;
  }

  /**
   * Get the variables to migrate.
   *
   * @return array
   *   Associative array with the the D7 variable names as keys and the D9 ones
   *   as values.
   */
  protected function getVariables() {
    if (!isset($this->variables)) {
      $this->variables = (array) $this->configuration['variables'] ?? [];
    }
    return $this->variables;
  }

}
