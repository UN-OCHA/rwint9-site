<?php

declare(strict_types=1);

namespace Drupal\reliefweb_meta;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\State\StateInterface;
use Drupal\json_ld_schema\Entity\JsonLdEntityBase;
use Drupal\reliefweb_utility\Helpers\HtmlSummarizer;
use Drupal\taxonomy\TermInterface;
use Spatie\SchemaOrg\Schema;
use Spatie\SchemaOrg\Type;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base entity.
 */
class BaseEntity extends JsonLdEntityBase implements ContainerFactoryPluginInterface {

  /**
   * Constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected StateInterface $state,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(EntityInterface $entity, $view_mode): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getData(EntityInterface $entity, $view_mode): Type {
    return Schema::thing();
  }

  /**
   * Get entity permalink.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   *
   * @return string
   *   Entity permalink.
   */
  public function getEntityPermalinkUrl(EntityInterface $entity): string {
    // Non-aliased URL for the entity permalink.
    return $entity->toUrl('canonical', [
      'absolute' => TRUE,
      // Disable path aliasing.
      'path_processing' => FALSE,
    ])->toString();
  }

  /**
   * Get entity canonical URL.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   *
   * @return string
   *   Entity canonical URL.
   */
  public function getEntityCanonicalUrl(EntityInterface $entity): string {
    // Aliased URL for the entity canonical URL.
    return $entity->toUrl('canonical', [
      'absolute' => TRUE,
    ])->toString();
  }

  /**
   * Build source reference.
   *
   * @param \Drupal\taxonomy\TermInterface $source
   *   Source term.
   *
   * @return \Spatie\SchemaOrg\Type
   *   Source reference.
   */
  protected function buildSourceReference(TermInterface $source): Type {
    return Schema::organization()
      ->identifier($this->getEntityPermalinkUrl($source))
      ->name($source->label());
  }

  /**
   * Build disaster event reference.
   *
   * @param \Drupal\taxonomy\TermInterface $disaster
   *   Disaster event term.
   *
   * @return \Spatie\SchemaOrg\Type
   *   Disaster event reference.
   */
  protected function buildDisasterEventReference(TermInterface $disaster): Type {
    return Schema::event()
      ->identifier($this->getEntityPermalinkUrl($disaster))
      ->name($disaster->label());
  }

  /**
   * Build country reference.
   *
   * @param \Drupal\taxonomy\TermInterface $country
   *   Country term.
   *
   * @return \Spatie\SchemaOrg\Type
   *   Country reference.
   */
  protected function buildCountryReference(TermInterface $country): Type {
    return Schema::country()
      ->identifier($this->getEntityPermalinkUrl($country))
      ->name($country->label());
  }

  /**
   * Get language codes from entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   * @param string $field
   *   Field name.
   *
   * @return array
   *   Language codes.
   */
  protected function getEntityLanguageCodes(EntityInterface $entity, string $field = 'field_language'): array {
    $language_codes = [];
    if ($entity->hasField($field) && !$entity->get($field)->isEmpty()) {
      foreach ($entity->get($field)->referencedEntities() as $language) {
        if ($language->hasField('field_language_code') && !$language->get('field_language_code')->isEmpty()) {
          $language_code = $language->get('field_language_code')->value;
          // Skip "Other" since it's not a valid language code.
          if (!empty($language_code) && $language_code !== 'ot') {
            $language_codes[] = $language_code;
          }
        }
      }
    }
    return $language_codes;
  }

  /**
   * Summarize content.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Entity.
   * @param string $field
   *   Field name.
   * @param int $default_length
   *   Length.
   *
   * @return string
   *   Summarized content.
   */
  protected function summarizeContent(EntityInterface $entity, string $field, int $default_length = 1000): string {
    // Skip if the field is not defined or empty.
    if (!$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return '';
    }

    // Get the content length for the entity.
    $entity_type_id = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    $length = $this->state->get("reliefweb_meta_schema_org_content_length:$entity_type_id:$bundle", $default_length);
    if (is_numeric($length)) {
      $length = (int) $length;
    }

    // Skip if the length is not a valid number.
    if (!is_int($length)) {
      return '';
    }

    // Skip if the length is 0.
    if ($length === 0) {
      return '';
    }

    // Get the content and skip if empty.
    $content = $entity->get($field)->value;
    if (empty($content)) {
      return '';
    }

    // Render the content.
    $content = (string) check_markup($content, $entity->get($field)->format);
    if (empty($content)) {
      return '';
    }

    // If the length is -1, do not truncate.
    $length = $length === -1 ? mb_strlen($content) : $length;

    // Summarize the content.
    return HtmlSummarizer::summarize($content, $length, TRUE);
  }

}
