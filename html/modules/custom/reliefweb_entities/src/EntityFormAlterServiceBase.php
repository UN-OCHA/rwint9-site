<?php

namespace Drupal\reliefweb_entities;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\reliefweb_utility\Traits\EntityDatabaseInfoTrait;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

/**
 * Base service to alter entity forms.
 */
abstract class EntityFormAlterServiceBase implements EntityFormAlterServiceInterface {

  use EntityDatabaseInfoTrait;
  use StringTranslationTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The translation manager service.
   */
  public function __construct(
    Connection $database,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeManagerInterface $entity_type_manager,
    TranslationInterface $string_translation
  ) {
    $this->database = $database;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  abstract public function alterForm(array &$form, FormStateInterface $form_state);

  /**
   * Alter a primary field field to add empty value and validation.
   *
   * @param string $field
   *   Field name.
   * @param array $form
   *   Form to alter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  protected function alterPrimaryField($field, array &$form, FormStateInterface $form_state) {
    $widget = &$form[$field]['widget'];
    $options = $widget['#options'];

    // Ensure there is an empty value option available so that we can
    // remove the selected value when modifying the country field.
    if (!isset($options['_none'])) {
      $options = ['_none' => $this->t('- Select a value -')] + $options;
      $widget['#options'] = $options;
    }

    // Add a validation callback to check that the selected value is one of
    // the selected values of the corresponding non primary field (ex: the
    // primary country should match one of the selected countries).
    $widget['#element_validate'][] = get_class() . '::validatePrimaryField';
  }

  /**
   * Validate a primary field.
   *
   * Ensuring the selected value in the primary field is among the selected
   * values of the non primary field.
   *
   * @param array $element
   *   Form element to validate.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   * @param array $form
   *   The complete form.
   */
  public static function validatePrimaryField(array &$element, FormStateInterface $form_state, array &$form) {
    $field = $element['#field_name'];
    $non_primary_field = str_replace('_primary', '', $field);
    $key_column = $element['#key_column'];
    $primary_value = $form_state->getValue([$field, 0, $key_column]);

    $found = FALSE;
    if (!empty($primary_value)) {
      foreach ($form_state->getValue($non_primary_field) as $value) {
        if ($value[$key_column] === $primary_value) {
          $found = TRUE;
          break;
        }
      }
    }

    if (!$found) {
      $form_state->setError($element, t('The %primary_field value must be one of the selected %field values', [
        '%primary_field' => $element['#title'],
        '%field' => $form[$non_primary_field]['widget']['#title'],
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getFormAlterService($bundle) {
    try {
      return \Drupal::service('reliefweb_entities.' . $bundle . '.form_alter');
    }
    catch (ServiceNotFoundException $exception) {
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function alterEntityForm(array &$form, FormStateInterface $form_state) {
    $form_object = $form_state->getFormObject();
    if (isset($form_object) && $form_object instanceof ContentEntityForm) {
      $service = static::getFormAlterService($form_object->getEntity()->bundle());
      if (!empty($service)) {
        $service->alterForm($form, $form_state);
      }
    }
  }

}
