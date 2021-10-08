<?php

namespace Drupal\reliefweb_entities;

use Drupal\Core\Form\FormStateInterface;

/**
 * Interface for entity form alteration services.
 */
interface EntityFormAlterServiceInterface {

  /**
   * Alter the form.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function alterForm(array &$form, FormStateInterface $form_state);

  /**
   * Alter an entity form.
   *
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public static function alterEntityForm(array &$form, FormStateInterface $form_state);

  /**
   * Get an entity form alter service.
   *
   * @param string $bundle
   *   The entity bundle.
   *
   * @return \Drupal\reliefweb_entities\EntityFormAlterServiceInterface|null
   *   The service or NULL if not found.
   */
  public static function getFormAlterService($bundle);

}
