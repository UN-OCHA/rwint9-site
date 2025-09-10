<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;

/**
 * Plugin implementation of the 'reliefweb_domain_posting_rights' widget.
 *
 * @FieldWidget(
 *   id = "reliefweb_domain_posting_rights",
 *   module = "reliefweb_fields",
 *   label = @Translation("ReliefWeb Domain Posting Rights widget"),
 *   multiple_values = true,
 *   field_types = {
 *     "reliefweb_domain_posting_rights"
 *   }
 * )
 */
class ReliefWebDomainPostingRights extends WidgetBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element['#type'] = 'fieldset';
    return $element + $this->formMultipleElements($items, $form, $form_state);
  }

  /**
   * Overrides \Drupal\Core\Field\WidgetBase::formMultipleElements().
   *
   * Special handling for draggable multiple widgets and 'add more' button.
   */
  protected function formMultipleElements(FieldItemListInterface $items, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $settings = $this->fieldDefinition->getSettings();
    $parents = $form['#parents'];

    // Url of the link validation route for the field.
    $validate_url = Url::fromRoute('reliefweb_fields.validate.reliefweb_domain_posting_rights', [
      'entity_type_id' => $this->fieldDefinition->getTargetEntityTypeId(),
      'bundle' => $this->fieldDefinition->getTargetBundle(),
      'field_name' => $field_name,
    ])->toString();

    // Retrieve (and initialize if needed) the field widget state with the
    // the json encoded field data.
    $field_state = static::getFieldState($parents, $field_name, $form_state, $items->getValue(), $settings);

    // Store a json encoded version of the fields data.
    $elements['data'] = [
      '#type' => 'hidden',
      '#value' => $field_state['data'],
      '#attributes' => [
        'data-settings-field' => $field_name,
        'data-settings-label' => $this->fieldDefinition->getLabel(),
        'data-settings-validate-url' => $validate_url,
      ],
    ];

    // Attach the library used manipulate the field.
    $elements['#attached']['library'][] = 'reliefweb_fields/reliefweb-domain-posting-rights';

    return $elements;
  }

  /**
   * Get the field state, initializing it if necessary.
   *
   * @param array $parents
   *   Form element parents.
   * @param string $field_name
   *   Field name.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   * @param array $items
   *   Existing items to initialize the state with.
   * @param array $settings
   *   Field instance settings.
   *
   * @return array
   *   Field state.
   */
  public static function getFieldState(array $parents, $field_name, FormStateInterface &$form_state, array $items = [], array $settings = []) {
    $field_state = static::getWidgetState($parents, $field_name, $form_state);

    if (!isset($field_state['data'])) {
      $field_state = static::setFieldState($parents, $field_name, $form_state, $items, $settings);
    }

    return $field_state;
  }

  /**
   * Set the field state.
   *
   * @param array $parents
   *   Form element parents.
   * @param string $field_name
   *   Field name.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   * @param array $items
   *   Existing items to initialize the state with.
   * @param array $settings
   *   Field instance settings.
   *
   * @return array
   *   Field state.
   */
  public static function setFieldState(array $parents, $field_name, FormStateInterface &$form_state, array $items = [], array $settings = []) {
    $field_state = static::getWidgetState($parents, $field_name, $form_state);

    $data = [];

    foreach ($items as $item) {
      if (!empty($item)) {
        $data[] = static::normalizeData($item);
      }
    }

    $field_state['data'] = json_encode($data);

    static::setWidgetState($parents, $field_name, $form_state, $field_state);

    return $field_state;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $settings = $this->fieldDefinition->getSettings();
    $parents = $form['#parents'];
    $field_name = $this->fieldDefinition->getName();
    $field_path = array_merge($parents, [$field_name, 'data']);

    // Get the raw JSON data from the widget.
    $data = NestedArray::getValue($form_state->getUserInput(), $field_path);

    // Decode the data.
    $data = !empty($data) ? json_decode($data, TRUE) : [];

    // Extract the relevant properties.
    $values = [];
    foreach ($data as $item) {
      $values[] = [
        'domain' => mb_strtolower(trim($item['domain'])),
        'job' => intval($item['job'], 10),
        'training' => intval($item['training'], 10),
        'report' => intval($item['report'], 10),
        'notes' => trim($item['notes']),
      ];
    }

    // Update the field state so that the modified values are the ones used when
    // going back from the preview for example.
    static::setFieldState($parents, $field_name, $form_state, $values, $settings);

    return $values;
  }

  /**
   * Normalize a user's data.
   *
   * @param array $data
   *   User's data.
   *
   * @return array
   *   Normalized data.
   */
  public static function normalizeData(array $data) {
    $data['domain'] = mb_strtolower(trim($data['domain'] ?? ''));
    $data['job'] = isset($data['job']) ? intval($data['job'], 10) : 0;
    $data['training'] = isset($data['training']) ? intval($data['training'], 10) : 0;
    $data['report'] = isset($data['report']) ? intval($data['report'], 10) : 0;
    $data['notes'] = isset($data['notes']) ? trim($data['notes']) : '';
    $data['status'] = 1;
    return $data;
  }

  /**
   * User validation callback.
   *
   * @param string $entity_type_id
   *   Entity type.
   * @param string $bundle
   *   Entity bundle.
   * @param string $field_name
   *   Field name.
   *
   * @return array
   *   Normalized data if valid or array with error message.
   */
  public static function validateDomain($entity_type_id, $bundle, $field_name) {
    $instance = FieldConfig::loadByName($entity_type_id, $bundle, $field_name);
    if (empty($instance)) {
      return ['error' => t('Field not found')];
    }

    // Limit to 10,000 bytes (should never be reached).
    $input = json_decode(file_get_contents('php://input', FALSE, NULL, 0, 10000) ?? '', TRUE);
    if (empty($input['value']) || !is_scalar($input['value'])) {
      return ['error' => t('Invalid user data')];
    }

    $domain = trim($input['value']);
    $ascii_domain = idn_to_ascii($domain);
    if (filter_var($ascii_domain, \FILTER_VALIDATE_DOMAIN, \FILTER_FLAG_HOSTNAME)) {
      $data = ['domain' => $domain];
    }
    else {
      $invalid = t('Invalid domain.');
    }

    // Return error message.
    if (!empty($invalid)) {
      return ['error' => $invalid];
    }

    // Return normalized data.
    return self::normalizeData($data);
  }

}
