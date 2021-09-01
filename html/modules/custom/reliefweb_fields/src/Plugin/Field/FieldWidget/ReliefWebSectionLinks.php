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
        'data-settings-validate-url' => $validate_url,
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
            'url' => $link['url'],
            'title' => $link['title'],
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
    return array_reverse($links);
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

    $invalid = static::parseLinkData($link);
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
   *
   * @return string
   *   Return an error message if the link is invalid.
   */
  public static function parseLinkData(array &$item) {
    $invalid = '';
    $database = \Drupal::database();

    if (empty($item['url'])) {
      return t('Missing link url.');
    }

    if (empty($item['title'])) {
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
    if (strpos($path, '/updates') === FALSE) {
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

  /**
   * Update entities on node change.
   *
   * Update entities which have a reliefweb_field_link field
   * when a node is updated or deleted to avoid broken links.
   *
   * @param string $op
   *   Operation on the node: update or delete.
   * @param Drupal\node\NodeInterface $node
   *   Node that is being updated or deleted.
   */
  public static function updateFields($op, NodeInterface $node) {
    // We only handle reports.
    if ($node->bundle() !== 'report') {
      return;
    }

    // We only proceed if the node was updated or deleted.
    // In theory there is no other possible operation.
    if ($op !== 'delete' && $op !== 'update') {
      return;
    }

    // Internal short url used to identify the link.
    $url = '/node/' . $node->id();

    // Link data for the node.
    $link = [
      'url' => $url,
      'title' => '',
      'image' => '',
    ];

    // Remove links to the node if not published.
    if (!$node->isPublished()) {
      $op = 'delete';
    }
    // Otherwise if the node is to be updated, retrieve its data.
    elseif ($op !== 'delete') {
      $invalid = self::parseLinkData($link, TRUE, TRUE, FALSE);
      // Remove links to the node if something was invalid.
      if (!empty($invalid)) {
        $op = 'delete';
      }
    }

    // Entity services.
    $entity_field_manager = \Drupal::service('entity_field.manager');
    $entity_type_manager = \Drupal::service('entity_type.manager');

    // Retrieve the 'reliefweb_field_link' fields that accepts internal links.
    $field_map = [];
    foreach ($entity_field_manager->getFieldMap() as $entity_type_id => $field_list) {
      foreach ($field_list as $field_name => $field_info) {
        // Skip non reliefweb_links fields.
        if (!isset($field_info['type']) || $field_info['type'] !== 'reliefweb_links') {
          continue;
        }

        // For each bundle using the field, check if the field is for internal
        // links in which case store the link data for the field.
        foreach ($field_info['bundles'] as $bundle) {
          $instance = FieldConfig::loadByName($entity_type_id, $bundle, $field_name);
          $settings = $instance->getSettings();

          if (!empty($settings['internal'])) {
            // Store the prepared link data.
            $field_map[$entity_type_id][$field_name] = $instance;
          }
        }
      }
    }

    // Skip if there are no fields to update.
    if (empty($field_map)) {
      return;
    }

    // For each field, load the entities to update.
    foreach ($field_map as $entity_type_id => $field_list) {
      $storage = $entity_type_manager->getStorage($entity_type_id);

      $entities = [];
      foreach ($field_list as $field_name => $instance) {
        $settings = $instance->getSettings();

        // Link data.
        $data = [
          'url' => $link['url'],
          'title' => $link['title'],
          'image' => !empty($settings['use_cover']) ? $link['image'] : '',
        ];

        // Retrieve the ids of the entities referencing the node.
        $ids = $storage
          ->getQuery()
          ->condition($field_name . '.url', $url, '=')
          // This is a system update so the results should not be limited to
          // what the current user has access to.
          ->accessCheck(FALSE)
          ->execute();

        if (empty($ids)) {
          continue;
        }

        // Update the entities.
        foreach ($storage->loadMultiple($ids) as $entity) {
          $field = $entity->get($field_name);
          $items = $field->getValue();
          $changed = FALSE;

          // Update or delete the node link.
          foreach ($items as $delta => $item) {
            if (isset($item['url']) && $item['url'] === $url) {
              if ($op === 'delete') {
                unset($items[$delta]);
                $changed = TRUE;
              }
              elseif ($data['title'] !== $item['title'] || $data['image'] !== $item['image']) {
                $items[$delta] = array_merge($item, $data);
                $changed = TRUE;
              }
            }
          }

          if ($changed) {
            $field->setValue(array_values($items));
            $entities[$entity->id()]['entity'] = $entity;
            $entities[$entity->id()]['fields'][$field_name] = $instance;
          }
        }
      }

      // Update the entities.
      foreach ($entities as $data) {
        $entity = $data['entity'];
        $fields = array_map(function ($instance) {
          return $instance->getLabel();
        }, $data['fields']);

        // Set the revision log. Not using `t` as it's an editorial message
        // that should always be in English.
        $entity->setRevisionLogMessage(strtr('Automatic update of the !fields !plural due to changes to node #!nodeid.', [
          '!fields' => implode(', ', $fields),
          '!plural' => count($fields) > 1 ? 'fields' : 'field',
          '!nodeid' => $node->id(),
        ]));

        // Force a new revision.
        $entity->setNewRevision(TRUE);

        // Save as the System user.
        $entity->setRevisionUserId(2);

        // Ensure notifications are disabled.
        $entity->notifications_content_disable = TRUE;

        // Update the entity.
        $entity->save();
      }
    }
  }

}
