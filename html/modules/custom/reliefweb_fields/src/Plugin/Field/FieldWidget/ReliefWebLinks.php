<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\node\NodeInterface;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

/**
 * Plugin implementation of the 'reliefweb_links' widget.
 *
 * @FieldWidget(
 *   id = "reliefweb_links",
 *   module = "reliefweb_fields",
 *   label = @Translation("ReliefWeb Links widget"),
 *   multiple_values = true,
 *   field_types = {
 *     "reliefweb_links"
 *   }
 * )
 */
class ReliefWebLinks extends WidgetBase implements ContainerFactoryPluginInterface {

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

    // Base of the internal image URLs.
    $base_image_url = file_create_url('public://styles/thumbnail/public/');

    // Url of the link validation route for the field.
    $validate_url = Url::fromRoute('reliefweb_fields.validate.reliefweb_links', [
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
        'data-settings-internal' => !empty($settings['internal']) ? 'true' : 'false',
        'data-settings-keep-archives' => !empty($settings['keep_archives']) ? 'true' : 'false',
        'data-settings-use-cover' => !empty($settings['use_cover']) ? 'true' : 'false',
        'data-settings-base-image-url' => $base_image_url,
        'data-settings-validate-url' => $validate_url,
      ],
    ];

    // Attach the library used manipulate the field.
    $elements['#attached']['library'][] = 'reliefweb_fields/reliefweb-links';

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
      $internal = !empty($settings['internal']);
      $keep_archives = !empty($settings['keep_archives']);
      $use_cover = !empty($settings['use_cover']);

      $links = [
        'active' => [],
        'archives' => [],
      ];

      if (!empty($items)) {
        // We reverse the links as they are displayed by newer first in the
        // form while they are stored by oldest first so that the deltas
        // seldom changes for existing links.
        foreach (array_reverse($items) as $link) {
          $active = !empty($link['active']);
          // Ignore archive links if keep_archives is FALSE. This way changes to
          // the settings are properly reflected.
          if (!$active && !$keep_archives) {
            continue;
          }

          $links[$active ? 'active' : 'archives'][] = [
            'url' => $link['url'],
            'title' => $link['title'],
            // Ensure the image is available only when the current settings are:
            // non internal or use cover is selected. This way changes to the
            // settings are properly reflected.
            'image' => (!$internal || $use_cover) && !empty($link['image']) ? $link['image'] : '',
            'active' => $active ? 1 : 0,
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
    $links = array_merge($links['active'] ?? [], $links['archives'] ?? []);

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
    $data = json_decode(file_get_contents('php://input', NULL, NULL, 0, 10000), TRUE);
    if (empty($data['url'])) {
      return ['error' => t('Invalid link data')];
    }

    $settings = $instance->getSettings();
    $internal = !empty($settings['internal']);
    $use_cover = !empty($settings['use_cover']);

    $link = [
      'url' => $data['url'],
      'title' => $data['title'] ?? '',
      'image' => $data['image'] ?? '',
    ];

    $invalid = static::parseLinkData($link, $internal, $use_cover);
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
   * @param bool $internal
   *   Indicates that the links are internal to the site.
   * @param bool $use_cover
   *   Indicates that the internal links should have a cover.
   * @param bool $generate_cover
   *   Whether to attempt to generate the cover or not.
   * @param bool $ignore_logo
   *   Whether to ignore invalid logos (and remove them) or not.
   *
   * @return string
   *   Return an error message if the link is invalid.
   */
  public static function parseLinkData(array &$item, $internal, $use_cover, $generate_cover = TRUE, $ignore_logo = FALSE) {
    $invalid = '';
    $database = \Drupal::database();

    if (empty($item['url'])) {
      return t('Missing link url.');
    }

    // Validate internal URL.
    if (!empty($internal)) {
      $path = static::getInternalPath($item['url']);
      $title = '';
      $image = '';

      // Only nodes are supported as internal links.
      if (empty($path)) {
        $invalid = t('Invalid internal URL.');
      }
      elseif (strpos($path, '/node/') === 0 && strlen($path) > 6) {
        $nid = intval(substr($path, 6), 10);

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
        // Only 'report' type nodes are supported.
        elseif ($result->type !== 'report') {
          $invalid = t('Invalid internal URL: the document is not a report.');
        }
        // Only published documents are valid.
        elseif (!isset($result->status) || (int) $result->status !== NodeInterface::PUBLISHED) {
          $invalid = t('Invalid internal URL: the document is not published.');
        }
        else {
          // Retrieve the first document source by alpha.
          $source = static::getReportSourceShortname($nid);

          // Prepend the source shortname if available.
          $title = !empty($source) ? $source . ': ' . $result->title : $result->title;
        }

        // Get the attachment cover if any.
        if (empty($invalid) && !empty($use_cover)) {
          $image = static::getReportPreviewPath($nid);
        }
      }
      else {
        $invalid = t('Invalid internal URL: only links to reports are accepted.');
      }

      // Update the item url, title amd image.
      if (empty($invalid)) {
        $item['url'] = $path;
        $item['title'] = $title;
        $item['image'] = $image;
      }
    }
    // Validate external URL.
    elseif (!UrlHelper::isValid($item['url'], TRUE)) {
      $invalid = t('Invalid URL.');
    }
    // Validate external title.
    elseif (empty($item['title'])) {
      $invalid = t('The link title is mandatory.');
    }
    // Validate external image URL.
    elseif (!empty($item['image'])) {
      if (strpos($item['image'], 'https://') !== 0) {
        $invalid = t('Invalid image URL. It must be a secure URL (https).');
      }
      elseif (!UrlHelper::isValid($item['image'], TRUE)) {
        $invalid = t('Invalid image URL.');
      }
      // If instructed, ignore and remove invalid logos.
      if (!empty($invalid) && !empty($ignore_logo)) {
        $invalid = '';
        $item['image'] = '';
      }
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
   * Get the shortname of the first source by alpha for the given report.
   *
   * @param int $nid
   *   Node id.
   *
   * @return string
   *   Source shortname.
   */
  public static function getReportSourceShortname($nid) {
    $query = \Drupal::database()->select('node__field_source', 'ns');
    $query->innerJoin('taxonomy_term__field_shortname', 'ts', 'ts.entity_id = ns.field_source_target_id');
    $query->fields('ts', ['field_shortname_value']);
    $query->condition('ns.entity_id', $nid, '=');
    $query->orderBy('ts.field_shortname_value', 'ASC');
    $query->range(0, 1);

    return $query->execute()?->fetchField();
  }

  /**
   * Get the path of the first attachment of the report.
   *
   * @param int $nid
   *   Node id.
   *
   * @return string
   *   Source shortname.
   *
   * @todo change logic once attachments are migrated.
   */
  public static function getReportPreviewPath($nid) {
    $payload = [
      'fields' => [
        'include' => [
          'file.preview.url',
        ],
      ],
      'filter' => [
        'field' => 'id',
        'value' => (int) $nid,
      ],
      'limit' => 1,
    ];

    $data = \Drupal::service('reliefweb_api.client')
      ->request('reports', $payload);

    $url = $data['data']['file'][0]['preview']['url'] ?? '';

    return preg_replace('#https?://[^/]+/sites/[^/]+/files/#', 'public://', $url);
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
            $field_map[$entity_type_id][$field_name] = [
              'url' => $link['url'],
              'title' => $link['title'],
              'image' => !empty($settings['use_cover']) ? $link['image'] : '',
            ];
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
      foreach ($field_list as $field_name => $link) {
        foreach ($storage->loadByProperties([$field_name . '.' . 'url' => $url]) as $entity) {
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
              elseif ($link['title'] !== $item['title'] || $link['image'] !== $item['image']) {
                $items[$delta] = array_merge($item, $link);
                $changed = TRUE;
              }
            }
          }

          if ($changed) {
            $field->setValue(array_values($items));
            $entities[$entity->id()]['entity'] = $entity;
            $entities[$entity->id()]['fields'][] = $field_name;
          }
        }
      }

      // Update the entities.
      foreach ($entities as $data) {
        $entity = $data['entity'];
        $fields = $data['fields'];

        // Set the revision log.
        $entity->setRevisionLogMessage(strtr('Automatic update of !fields !plural due to changes to node #!nodeid.', [
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
