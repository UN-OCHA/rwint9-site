<?php

namespace Drupal\reliefweb_utility\Helpers;

/**
 * Helper to get state values specific to ReliefWeb.
 */
class ReliefWebStateHelper {

  /**
   * Get the ReliefWeb submit email address.
   *
   * @return string
   *   The submit email address.
   */
  public static function getSubmitEmail() {
    return \Drupal::state()->get('reliefweb_submit_email');
  }

  /**
   * Get the list of countries that are irrelevant for jobs.
   *
   * @return array
   *   List of theme term ids.
   */
  public static function getJobIrrelevantCountries() {
    // Irrelevant countries (Trello #DI9bxljg):
    // - World (254).
    $default = [254];
    return \Drupal::state()->get('reliefweb_job_irrelevant_countries', $default);
  }

  /**
   * Get the list of themes that are irrelevant for jobs.
   *
   * @return array
   *   List of theme term ids.
   */
  public static function getJobIrrelevantThemes() {
    // Irrelevant themes (Trello #RfWgIdwA):
    // - Contributions (4589) (Collab #2327).
    // - Humanitarian Financing (4597) (Trello #OnXq5cCC).
    // - Logistics and Telecommunications (4598) (Trello #G3YgNUF6).
    $default = [4589, 4597, 4598];
    return \Drupal::state()->get('reliefweb_job_irrelevant_themes', $default);
  }

  /**
   * Get the list of job categories for which themes are irrelevant.
   *
   * @return array
   *   List of theme term ids.
   */
  public static function getJobThemelessCategories() {
    // Disable the themes for some career categories (Trello #RfWgIdwA):
    // - Human Resources (6863).
    // - Administration/Finance (6864).
    // - Information and Communications Technology (6866).
    // - Donor Relations/Grants Management (20966).
    $default = [6863, 6864, 6866, 20966];
    return \Drupal::state()->get('reliefweb_job_themeless_categories', $default);
  }

  /**
   * Get the list of themes that are irrelevant for training ads.
   *
   * @return array
   *   List of term ids.
   */
  public static function getTrainingIrrelevantThemes() {
    // Irrelevant themes (Trello #RfWgIdwA):
    // - Contributions (4589) (Collab #2327).
    // - Logistics and Telecommunications (4598) (Trello #G3YgNUF6).
    // - Camp Coordination and Camp Management (49458).
    $default = [4589, 4598, 49458];
    return \Drupal::state()->get('reliefweb_training_irrelevant_themes', $default);
  }

  /**
   * Get the list of languages that are irrelevant for training ads.
   *
   * @return array
   *   List of term ids.
   */
  public static function getTrainingIrrelevantLanguages() {
    // Irrelevant languages (Collab #4452001), due to limited capacity.
    // - Russian (10906) and Arabic (6876).
    // - Other (31996).
    $default = [6876, 10906, 31996];
    return \Drupal::state()->get('reliefweb_training_irrelevant_languages', $default);
  }

  /**
   * Get the list of training languages that are irrelevant for training ads.
   *
   * @return array
   *   List of term ids.
   */
  public static function getTrainingIrrelevantTrainingLanguages() {
    // Irrelevant languages (Collab #4452001), due to limited capacity.
    // - Other (31996).
    $default = [31996];
    return \Drupal::state()->get('reliefweb_training_irrelevant_training_languages', $default);
  }

}
