<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines a provider entity.
 *
 * @ContentEntityType(
 *   id = "reliefweb_post_api_provider",
 *   label = @Translation("ReliefWeb POST API provider"),
 *   label_collection = @Translation("ReliefWeb POST API providers"),
 *   label_singular = @Translation("ReliefWeb POST API provider"),
 *   label_plural = @Translation("ReliefWeb POST API providers"),
 *   label_count = @PluralTranslation(
 *     singular = "@count ReliefWeb POST API provider",
 *     plural = "@count ReliefWeb POST API providers"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\reliefweb_post_api\ProviderStorage",
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\reliefweb_post_api\ProviderListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "form" = {
 *       "default" = "Drupal\reliefweb_post_api\Form\ProviderForm",
 *       "add" = "Drupal\reliefweb_post_api\Form\ProviderForm",
 *       "edit" = "Drupal\reliefweb_post_api\Form\ProviderForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "default" = "Drupal\reliefweb_post_api\Routing\ProviderHtmlRouteProvider"
 *     }
 *   },
 *   base_table = "reliefweb_post_api_provider",
 *   admin_permission = "administer reliefweb post api providers",
 *   translatable = FALSE,
 *   fieldable = TRUE,
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "name"
 *   },
 *   links = {
 *     "canonical" = "/reliefweb-post-api-provider/{reliefweb_post_api_provider}",
 *     "add-form" = "/reliefweb-post-api-provider/add",
 *     "edit-form" = "/reliefweb-post-api-provider/{reliefweb_post_api_provider}/edit",
 *     "delete-form" = "/reliefweb-post-api-provider/{reliefweb_post_api_provider}/delete",
 *     "collection" = "/admin/content/reliefweb-post-api-providers",
 *   },
 *   field_ui_base_route = "entity.reliefweb_post_api_provider.collection"
 * )
 */
class Provider extends ContentEntityBase implements ProviderInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    /** @var \Drupal\Core\Field\BaseFieldDefinition[] $fields */
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel('Name')
      ->setDescription('The provider name.')
      ->setRequired(TRUE)
      ->setSettings([
        'default_value' => '',
        'max_length' => 255,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['key'] = BaseFieldDefinition::create('reliefweb_post_api_key')
      ->setLabel(t('API key'))
      ->setDescription(t('The API key of this provider.'))
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $options = [];
    $plugins = \Drupal::service('plugin.manager.reliefweb_post_api.content_processor')->getDefinitions();
    foreach ($plugins as $definition) {
      $options[$definition['resource']] = (string) $definition['label'];
    }

    $fields['resource'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Resource'))
      ->setDescription(t('The type of resource that the provider can submit.'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', $options)
      ->setDisplayOptions('form', [
        'type' => 'options_select',
      ])
      ->setDisplayOptions('view', [
        'type' => 'string',
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Active'))
      ->setDescription(t('Whether the provider is active or blocked.'))
      ->setDefaultValue(FALSE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel('Created')
      ->setDescription('The time when the provider was created.');

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel('Changed')
      ->setDescription('The time when the provider was last edited.');

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getUrlPattern(string $type = 'document'): string {
    $field = 'field_' . $type . '_url';
    $default = '#^https://.+#';

    if (!$this->hasField($field)) {
      return $default;
    }
    if ($this->get($field)->isEmpty()) {
      return $default;
    }
    $parts = [];
    foreach ($this->get($field) as $item) {
      if (!empty($item->uri)) {
        $parts[] = preg_quote($item->uri);
      }
    }
    return empty($parts) ? $default : '#^(' . implode('|', $parts) . ')#';
  }

  /**
   * {@inheritdoc}
   */
  public function getAllowedSources(): array {
    $field = 'field_source';
    if (!$this->hasField($field)) {
      return [];
    }
    if ($this->get($field)->isEmpty()) {
      return [];
    }
    $sources = [];
    foreach ($this->get($field) as $item) {
      $sources[$item->target_id] = $item->target_id;
    }
    return $sources;
  }

  /**
   * {@inheritdoc}
   */
  public function getEmailsToNotify(): array {
    $field = 'field_notify';
    if (!$this->hasField($field)) {
      return [];
    }
    if ($this->get($field)->isEmpty()) {
      return [];
    }
    $emails = [];
    foreach ($this->get($field) as $item) {
      $emails[] = $item->value;
    }
    return $emails;
  }

  /**
   * {@inheritdoc}
   */
  public function getUserId(): int {
    $field = 'field_user';
    if (!$this->hasField($field)) {
      return 2;
    }
    if (empty($this->get($field)->target_id)) {
      return 2;
    }
    return (int) $this->get($field)->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultResourceStatus(): string {
    $field = 'field_resource_status';
    // Draft is the only common status among all RW content entities.
    if (!$this->hasField($field)) {
      return 'draft';
    }
    if (empty($this->get($field)->value)) {
      return 'draft';
    }
    return $this->get($field)->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuota(): int {
    $field = 'field_quota';
    if (!$this->hasField($field)) {
      return 0;
    }
    if (empty($this->get($field)->value)) {
      return 0;
    }
    return (int) $this->get($field)->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getRateLimit(): int {
    $field = 'field_rate_limit';
    if (!$this->hasField($field)) {
      return 0;
    }
    if (empty($this->get($field)->value)) {
      return 0;
    }
    return (int) $this->get($field)->value;
  }

  /**
   * {@inheritdoc}
   */
  public function validateKey(string $key): bool {
    if (empty($key) || empty($this->key->value)) {
      return FALSE;
    }
    return \Drupal::service('password')->check($key, $this->key->value);
  }

  /**
   * Notify the provider of an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity that has been created, updated or deleted.
   */
  public static function notifyProvider(EntityInterface $entity): void {
    if ($entity instanceof ContentEntityInterface && $entity->hasField('field_post_api_provider')) {
      $provider = $entity->field_post_api_provider->entity;
      if (!empty($provider)) {
        $client = \Drupal::httpClient();
        $timeout = \Drupal::state()->get('reliefweb_post_api.timeout', 1);
        $logger = \Drupal::logger('reliefweb_post_api.webhook');

        foreach ($provider->field_webhook_url as $item) {
          if (!empty($item->uri)) {
            $url = $item->uri . '/' . $entity->uuid();
            try {
              $client->get($url, ['timeout' => $timeout]);

              $logger->info(strtr('Request sent to @url for provider @provider.', [
                '@url' => $url,
                '@provider' => $provider->uuid(),
              ]));
            }
            catch (\Exception $exception) {
              $logger->notice(strtr('Request to @url for provider @provider failed: @error', [
                '@url' => $url,
                '@provider' => $provider->uuid(),
                '@error' => $exception->getMessage(),
              ]));
            }
          }
        }
      }
    }
  }

}
