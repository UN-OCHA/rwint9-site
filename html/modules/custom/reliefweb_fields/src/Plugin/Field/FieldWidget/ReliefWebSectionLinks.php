<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\node\NodeInterface;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

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
    return $this->formMultipleElements($items, $form, $form_state);
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
    $initialize = FALSE;
    $field_state = static::getWidgetState($parents, $field_name, $form_state);

    if (!isset($field_state['data'])) {
      $use_override = !empty($settings['use_override']);

      $links = [];

      if (!empty($items)) {
        // We reverse the links as they are displayed by newer first in the
        // form while they are stored by oldest first so that the deltas
        // seldom changes for existing links.
        foreach (array_reverse($items) as $link) {
          $links[] = [
            'url' => $link['url'] ?? '',
            'title' => $link['title'] ?? '',
            'override' => $use_override ? $link['override'] : '',
          ];
        }
      }

      $field_state['data'] = json_encode($links);
      $initialize = TRUE;
    }

    if ($initialize) {
      static::setWidgetState($parents, $field_name, $form_state, $field_state);
    }

    return $field_state;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $field_path = array_merge($form['#parents'], [$field_name, 'data']);

    // Get the raw JSON data from the widget.
    $data = NestedArray::getValue($form_state->getUserInput(), $field_path);

    // Extract and combined the links.
    $links = !empty($data) ? json_decode($data, TRUE) : [];

    // Reverse the links so that the most recent have a higher delta.
    $links = array_reverse($links);

    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();
    if ($cardinality > 0) {
      $links = array_chunk($links, $cardinality, TRUE);
    }
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
      return ['error' => t('Invalid link data')];
    }

    $settings = $instance->getSettings();
    $use_override = $settings['use_override'];

    $link = [
      'url' => $data['url'],
      'title' => $data['title'] ?? '',
      'override' => $use_override ? $data['override'] ?? 0 : 0,
    ];

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
  public static function parseLinkData(array &$item, $settings = []) {
    $invalid = '';
    $database = \Drupal::database();

    if (empty($item['url'])) {
      return t('Missing link url.');
    }

    if ($settings['use_title'] && empty($item['title'])) {
      return t('Title is mandatory.');
    }

    $path = $item['url'];
    $override = $item['override'] ?? NULL;

    // Override has to link to a node.
    if ($override) {
      $override = static::getInternalPath($item['override']);
      if (strpos($override, '/node/') === 0 && strlen($override) > 6) {
        $nid = intval(substr($override, 6), 10);

        // Get document information.
        $result = $database
          ->select('node_field_data', 'n')
          ->fields('n', ['title', 'status', 'type'])
          ->condition('n.nid', $nid)
          ->execute()
          ?->fetchObject();

        // Check that the document exists and is published.
        if (empty($result->title)) {
          $invalid = t('Invalid internal URL: the document was not found.');
        }
        // Only published documents are valid.
        elseif (!isset($result->status) || (int) $result->status !== NodeInterface::PUBLISHED) {
          $invalid = t('Invalid internal URL: the document is not published.');
        }
      }
    }

    // Path has to link to updates.
    if (strpos($path, '/updates') === FALSE && strpos($path, '/training') === FALSE && strpos($path, '/jobs') === FALSE && strpos($path, '/disasters') === FALSE) {
      $invalid = t('Invalid URL: use a link to a river.');
    }

    return $invalid;
  }

  /**
   * Get the internal path from a url if it's the url of a node.
   *
   * @param string $url
   *   URL or path.
   *
   * @return string
   *   Internal path.
   */
  public static function getInternalPath($url) {
    $base_host = \Drupal::request()->getHost();
    if (empty($base_host)) {
      return '';
    }
    $parts = parse_url($url);
    if (empty($parts['path'])) {
      return '';
    }
    // If the url is absolute, the scheme must be http or https.
    if (!empty($parts['scheme']) && $parts['scheme'] !== 'http' && $parts['scheme'] !== 'https') {
      return '';
    }
    // Ensure the host, if defined, matches the current one or reliefweb.int.
    // This is to ensure the compatibility with stage or local site instances.
    if (!empty($parts['host'])) {
      $host = preg_replace('/^www\./', '', $parts['host']);
      if ($host !== $base_host && $host !== 'reliefweb.int') {
        return '';
      }
    }
    // Resolve aliases to their corresponding internal path.
    $path = UrlHelper::getPathFromAlias(urldecode($parts['path']));

    // Only accept links resolved to a node internal path.
    return preg_match('#^/node/[0-9]+$#', $path) === 1 ? $path : '';
  }

}
