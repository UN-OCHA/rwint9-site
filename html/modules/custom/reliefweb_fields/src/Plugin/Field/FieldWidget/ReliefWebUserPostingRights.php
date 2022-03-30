<?php

namespace Drupal\reliefweb_fields\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\user\UserInterface;

/**
 * Plugin implementation of the 'reliefweb_user_posting_rights' widget.
 *
 * @FieldWidget(
 *   id = "reliefweb_user_posting_rights",
 *   module = "reliefweb_fields",
 *   label = @Translation("ReliefWeb User Posting Rights widget"),
 *   multiple_values = true,
 *   field_types = {
 *     "reliefweb_user_posting_rights"
 *   }
 * )
 */
class ReliefWebUserPostingRights extends WidgetBase implements ContainerFactoryPluginInterface {

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
    $validate_url = Url::fromRoute('reliefweb_fields.validate.reliefweb_user_posting_rights', [
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
    $elements['#attached']['library'][] = 'reliefweb_fields/reliefweb-user-posting-rights';

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

    // Extract the user ids.
    $ids = [];
    foreach ($items as $item) {
      if (!empty($item)) {
        $ids[] = $item['id'];
      }
    }

    // Retrieve the user data.
    if (!empty($ids)) {
      $users = \Drupal::database()
        ->select('users_field_data', 'u')
        ->fields('u', ['uid', 'name', 'mail', 'status'])
        ->condition('u.uid', $ids, 'IN')
        ?->execute()
        ?->fetchAllAssoc('uid', \PDO::FETCH_ASSOC);

      foreach ($items as $item) {
        if (isset($users[$item['id']])) {
          $data[] = static::normalizeData($item + $users[$item['id']]);
        }
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
        'id' => intval($item['id'], 10),
        'job' => intval($item['job'], 10),
        'training' => intval($item['training'], 10),
        'notes' => $item['notes'],
      ];
    }

    // Update the field state so that we modified values are the ones used when
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
    // The user ID key can be either `id` when retrieved from the field or
    // `uid` when retrieved from the database (see ::validateUserId()).
    $data['id'] = intval($data['id'] ?? $data['uid'], 10);
    $data['job'] = isset($data['job']) ? intval($data['job'], 10) : 0;
    $data['training'] = isset($data['training']) ? intval($data['training'], 10) : 0;
    $data['notes'] = isset($data['notes']) ? trim($data['notes']) : '';

    $data['name'] = trim($data['name']);
    $data['mail'] = trim($data['mail']);
    $data['status'] = intval($data['status'], 10);
    // Blocked users are not allowed to post.
    if ($data['status'] === 0) {
      $data['job'] = 1;
      $data['training'] = 1;
    }

    return $data;
  }

  /**
   * Check if a user Id is valid and if the user exists if asked.
   *
   * @param int $uid
   *   User ID.
   *
   * @return array|null
   *   Return the base user data if the user exists, FALSE otherwise.
   */
  public static function validateUserId($uid) {
    $options = ['options' => ['min_range' => 1]];

    if (filter_var($uid, FILTER_VALIDATE_INT, $options) !== FALSE) {
      return \Drupal::database()
        ->select('users_field_data', 'u')
        ->fields('u', ['uid', 'name', 'mail', 'status'])
        ->condition('u.uid', $uid, '=')
        ?->execute()
        ?->fetchAssoc();
    }

    return NULL;
  }

  /**
   * Check if a user mail is valid and if the user exists if asked.
   *
   * @param string $mail
   *   User mail.
   *
   * @return array|null
   *   Return the base user data if the user exists, FALSE otherwise.
   */
  public static function validateUserMail($mail) {
    if (filter_var($mail, FILTER_VALIDATE_EMAIL) !== FALSE) {
      return \Drupal::database()
        ->select('users_field_data', 'u')
        ->fields('u', ['uid', 'name', 'mail', 'status'])
        ->condition('u.mail', $mail, '=')
        ?->execute()
        ?->fetchAssoc();
    }

    return NULL;
  }

  /**
   * User validation callback.
   *
   * @param string $entity_type_id
   *   Entity type.
   * @param string $bundle
   *   Entity bundle.
   * @param string $field_name
   *   Field nane.
   *
   * @return array
   *   Normalized data if valid or array with error message.
   */
  public static function validateUser($entity_type_id, $bundle, $field_name) {
    $instance = FieldConfig::loadByName($entity_type_id, $bundle, $field_name);
    if (empty($instance)) {
      return ['error' => t('Field not found')];
    }

    // Limit to 10,000 bytes (should never be reached).
    $input = json_decode(file_get_contents('php://input', FALSE, NULL, 0, 10000) ?? '', TRUE);
    if (empty($input['value']) || !is_scalar($input['value'])) {
      return ['error' => t('Invalid user data')];
    }

    $value = $input['value'];

    // Validate and retrieve the user data.
    if (is_numeric($value)) {
      $data = self::validateUserId($value);
      if (empty($data)) {
        $invalid = t('Invalid user. No account found for the user id "@uid".', [
          '@uid' => $value,
        ]);
      }
    }
    else {
      $data = self::validateUserMail($value);
      if (empty($data)) {
        $invalid = t('Invalid user. No account found for the user mail "@mail".', [
          '@mail' => $value,
        ]);
      }
    }

    // Return error message.
    if (!empty($invalid)) {
      return ['error' => $invalid];
    }

    // Return nornalized data.
    return self::normalizeData($data);
  }

  /**
   * Update entities on user change.
   *
   * Update entities which have a reliefweb_user_posting_rights field
   * when a user is updated or deleted.
   *
   * @param string $op
   *   Operation on the user: update or delete.
   * @param Drupal\user\UserInterface $user
   *   User that is being updated or deleted.
   */
  public static function updateFields($op, UserInterface $user) {
    // We only handle non system or anonymous users.
    if ($user->id() < 3) {
      return;
    }

    // We only proceed if the user was updated or deleted.
    // In theory there is no other possible operation.
    if ($op !== 'delete' && $op !== 'update') {
      return;
    }

    $uid = $user->id();
    $blocked = $user->isBlocked();
    $email_changed = isset($user->original) && $user->getEmail() !== $user->original->getEmail();
    $message = '';
    $date = date_format(date_create(), 'Y-m-d');

    // Note: we're not using `t` for the messages on purpose as they are
    // editorial messages that will be added to the posting rights notes and
    // thus shouldn't be translated.
    if ($op === 'update') {
      if ($blocked) {
        $message = 'Account blocked on ' . $date . '.';
      }
      elseif ($email_changed) {
        $message = 'Rights reset due to email change on ' . $date . '.';
      }
      // No need to continue as it's not a modification that can result in
      // changes to the posting rights.
      else {
        return;
      }
    }

    // Entity services.
    $entity_field_manager = \Drupal::service('entity_field.manager');
    $entity_type_manager = \Drupal::service('entity_type.manager');

    // Retrieve the 'reliefweb_field_link' fields that accepts internal links.
    $field_map = [];
    foreach ($entity_field_manager->getFieldMap() as $entity_type_id => $field_list) {
      foreach ($field_list as $field_name => $field_info) {
        // Skip non reliefweb_user_posting_rights fields.
        if (!isset($field_info['type']) || $field_info['type'] !== 'reliefweb_user_posting_rights') {
          continue;
        }

        // Retrieve the user posting rights fields for each entity type and
        // bundle.
        foreach ($field_info['bundles'] as $bundle) {
          $instance = FieldConfig::loadByName($entity_type_id, $bundle, $field_name);
          $field_map[$entity_type_id][$field_name] = $instance;
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
        // Get the entities referencing the user.
        $query = $storage
          ->getQuery()
          ->condition($field_name . '.id', $user->id());

        // On update, only search for entities with rights for the user
        // that are different than 'blocked'. Blocked users will not have their
        // rights record automatically updated.
        if ($op === 'update') {
          $query->condition($query
            ->orConditionGroup()
            ->condition($field_name . '.job', 1, '<>')
            ->condition($field_name . '.training', 1, '<>'));
        }

        $ids = $query?->execute();
        if (empty($ids)) {
          continue;
        }

        // Update the entities.
        foreach ($storage->loadMultiple($ids) as $entity) {
          $field = $entity->get($field_name);
          $items = $field->getValue();
          $changed = FALSE;

          // Update or delete the user rights.
          foreach ($items as $delta => $item) {
            // No strict equality as values can be strings or integers.
            if (isset($item['id']) && $item['id'] == $uid) {
              // Remove the rights if the user is deleted.
              if ($op === 'delete') {
                unset($items[$delta]);
                $changed = TRUE;
              }
              // Set the rights to 'blocked' if the account is blocked.
              elseif ($blocked) {
                if ($item['job'] != 1 || $item['training'] != 1) {
                  $items[$delta]['job'] = 1;
                  $items[$delta]['training'] = 1;
                  if (!empty($item['notes'])) {
                    $items[$delta]['notes'] .= ' ' . $message;
                  }
                  else {
                    $items[$delta]['notes'] = $message;
                  }
                  $changed = TRUE;
                }
              }
              // Reset the rights to 'unverified' if the email changed but
              // preserve the 'blocked' rights.
              elseif ($email_changed) {
                if ($item['job'] > 1 || $item['training'] > 1) {
                  $items[$delta]['job'] = $item['job'] == 1 ? 1 : 0;
                  $items[$delta]['training'] = $item['training'] == 1 ? 1 : 0;
                  if (!empty($item['notes'])) {
                    $items[$delta]['notes'] .= ' ' . $message;
                  }
                  else {
                    $items[$delta]['notes'] = $message;
                  }
                  $changed = TRUE;
                }
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
        $entity->setRevisionLogMessage(strtr('Automatic update of the !fields !plural due to changes to user #!uid.', [
          '!fields' => implode(', ', $fields),
          '!plural' => count($fields) > 1 ? 'fields' : 'field',
          '!uid' => $user->id(),
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
