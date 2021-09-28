<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\reliefweb_fields\Plugin\Field\FieldType\ReliefWebSectionLinks as ReliefWebSectionLinksFieldType;
use Drupal\reliefweb_rivers\RiverServiceBase;

/**
 * Plugin implementation of the 'reliefweb_section_links' widget.
 *
 * @FieldWidget(
 *   id = "reliefweb_section_links",
 *   module = "reliefweb_fields",
 *   label = @Translation("Reliefweb section links"),
 *   multiple_values = true,
 *   field_types = {
 *     "reliefweb_section_links"
 *   }
 * )
 */
class ReliefWebSectionLinks extends WidgetBase {

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
    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();
    $parents = $form['#parents'];

    // Url of the link validation route for the field.
    $validate_url = Url::fromRoute('reliefweb_fields.validate.reliefweb_section_links', [
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
        'data-settings-use-override' => $settings['use_override'] ? 'true' : 'false',
        'data-settings-use-title' => $settings['use_title'] ? 'true' : 'false',
        'data-settings-validate-url' => $validate_url,
        'data-settings-cardinality' => $cardinality,
      ],
    ];

    // Attach the library used manipulate the field.
    $elements['#attached']['library'][] = 'reliefweb_fields/reliefweb-section-links';

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
    $use_override = !empty($settings['use_override']);
    $field_state = static::getWidgetState($parents, $field_name, $form_state);

    // Generate the links of links and store the JSON serialized version which
    // for use by the JS script.
    $links = [];
    if (!empty($items)) {
      foreach ($items as $link) {
        $links[] = [
          'url' => $link['url'] ?? '',
          'title' => $link['title'] ?? '',
          'override' => $use_override && !empty($link['override']) ? $link['override'] : '',
        ];
      }
    }
    $field_state['data'] = json_encode($links);

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

    // Extract and combined the links.
    $links = !empty($data) ? json_decode($data, TRUE) : [];

    // Limit the number of links if the cardinality is not unlimited.
    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();
    if ($cardinality > 0) {
      $links = array_slice($links, 0, $cardinality);
    }

    // Normalize the links.
    foreach ($links as $delta => $link) {
      if (empty($link['override'])) {
        unset($link['override']);
      }
      if (empty($link['title'])) {
        $link['title'] = "";
      }
      $links[$delta] = $link;
    }

    // Update the field state so that we modified values are the ones used when
    // going back from the preview for example.
    static::setFieldState($form['#parents'], $field_name, $form_state, $links, $settings);

    return $links;
  }

  /**
   * Link validation callback.
   *
   * @param string $entity_type_id
   *   Entity type id.
   * @param string $bundle
   *   Entity bundle.
   * @param string $field_name
   *   Field name.
   */
  public static function validateLink($entity_type_id, $bundle, $field_name) {
    $instance = FieldConfig::loadByName($entity_type_id, $bundle, $field_name);
    if (empty($instance)) {
      return ['error' => t('Field not found')];
    }

    // Limit to 10,000 bytes (should never be reached).
    $data = json_decode(file_get_contents('php://input', FALSE, NULL, 0, 10000), TRUE);
    if (empty($data['url'])) {
      return ['error' => t('Invalid link data: missing URL.')];
    }

    $settings = $instance->getSettings();
    $use_override = $settings['use_override'];

    $link = [
      'url' => $data['url'],
      'title' => $data['title'] ?? '',
    ];

    if ($use_override && isset($data['override']) && $data['override'] !== '') {
      $link['override'] = $data['override'];
    }

    $invalid = static::parseLinkData($link, $settings);
    if (empty($invalid)) {
      return $link;
    }
    return ['error' => $invalid];
  }

  /**
   * Get and validate the link data from a url.
   *
   * @param array &$item
   *   Link item with url, title and image fields.
   * @param array $settings
   *   Field settings.
   *
   * @return string
   *   Return an error message if the link is invalid.
   */
  public static function parseLinkData(array &$item, array $settings = []) {
    // Ensure there is a URL.
    if (empty($item['url'])) {
      return t('Missing link url.');
    }

    // Check if the link title is set.
    if ($settings['use_title'] && empty($item['title'])) {
      return t('Title is mandatory.');
    }

    // Check the URL is for a river on the current site or reliefweb.int.
    // This ensures compatibility with local and dev sites.
    $allowed_hosts = [\Drupal::request()->getHost(), 'reliefweb.int'];
    $host = preg_replace('/^www\./', '', parse_url($item['url'], PHP_URL_HOST));
    if (!in_array($host, $allowed_hosts)) {
      return t('Invalid URL host.');
    }

    // Ensure the url is to one of the allowed rivers for the field.
    $allowed_rivers = array_filter($settings['rivers'] ?? []);
    if (empty($allowed_rivers)) {
      return t('Invalid configuration: no river allowed.');
    }

    $info = RiverServiceBase::getRiverServiceFromUrl($item['url']);
    if (empty($info) || !isset($allowed_rivers[$info['bundle']])) {
      $allowed_rivers = array_intersect_key(ReliefWebSectionLinksFieldType::getAllowedRivers(), $allowed_rivers);
      return t('Invalid URL: use a link to one of the following rivers: @rivers.', [
        '@rivers' => implode(', ', $allowed_rivers),
      ]);
    }

    // Check that the override if defined, corresponds to a published entity
    // of the same type as the river entities.
    if (isset($item['override'])) {
      if (!is_numeric($item['override']) || (int) $item['override'] < 1) {
        return t('The override must be a valid entity ID.');
      }

      $entity_type = \Drupal::entityTypeManager()
        ->getStorage($info['service']->getEntityTypeId())
        ->getEntityType();

      $table = $entity_type->getDataTable();
      $id_key = $entity_type->getKey('id');
      $bundle_key = $entity_type->getKey('bundle');
      $published_key = $entity_type->getKey('published');

      // Get document information.
      $result = \Drupal::database()
        ->select($table, $table)
        ->fields($table, [$bundle_key, $published_key])
        ->condition($table . '.' . $id_key, $item['override'], '=')
        ->execute()
        ?->fetchObject();

      // Check that the document exists and is published.
      if (empty($result)) {
        return t('Invalid override: the document was not found.');
      }
      // Check that the document's type matches the river bundle.
      elseif ($result->{$bundle_key} !== $info['bundle']) {
        return t('Invalid override: the document is not a @bundle.', [
          '@bundle' => $info['bundle'],
        ]);
      }
      // Only published documents are valid.
      elseif (empty($result->{$published_key})) {
        return t('Invalid override: the document is not published.');
      }
    }

    return '';
  }

}
