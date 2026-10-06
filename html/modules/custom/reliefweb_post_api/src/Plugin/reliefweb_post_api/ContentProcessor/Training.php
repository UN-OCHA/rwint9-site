<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Plugin\reliefweb_post_api\ContentProcessor;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reliefweb_post_api\Attribute\ContentProcessor;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginBase;

/**
 * Training content handler.
 */
#[ContentProcessor(
  id: 'reliefweb_post_api.content_processor.training',
  label: new TranslatableMarkup('Training'),
  entityType: 'node',
  bundle: 'training',
  resource: 'training'
)]
class Training extends ContentProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function process(array $data): ?ContentEntityInterface {
    // Ensure the data is valid.
    $this->validate($data);

    $provider = $this->getProvider($data['provider'] ?? '');
    $user_id = $data['user'] ?? $provider->getUserId();

    $node = $this->loadEntityForProcessing($data, (int) $user_id);

    // Verify the bundle if the entity already exists.
    $this->validateEntityBundle($node);

    // Verify the entity is processable (not in a terminal status).
    $this->validateEntityProcessable($node);

    // Identical payload: no field writes, revision, or status change.
    if ($this->isUnchanged($node, $data)) {
      return $node;
    }

    $partial = !$node->isNew() && !empty($data['partial']);

    // Set the mandatory fields.
    if (!$partial || array_key_exists('title', $data)) {
      $node->title = $this->sanitizeString((string) ($data['title'] ?? ''));
    }

    if (!$partial || array_key_exists('source', $data)) {
      $this->setTermField($node, 'field_source', 'source', $data['source'] ?? []);
    }
    if (!$partial || array_key_exists('format', $data)) {
      $this->setTermField($node, 'field_training_format', 'training_format', $data['format'] ?? []);
    }

    if (!$partial || array_key_exists('event_url', $data)) {
      $this->setUrlField($node, 'field_link', (string) ($data['event_url'] ?? ''), $provider->getUrlPattern());
    }
    if (!$partial || array_key_exists('cost', $data)) {
      $this->setStringField($node, 'field_cost', (string) ($data['cost'] ?? ''));
    }

    if (!$partial || array_key_exists('category', $data)) {
      $this->setTermField($node, 'field_training_type', 'training_type', $data['category'] ?? []);
    }
    if (!$partial || array_key_exists('training_language', $data)) {
      $this->setTermField($node, 'field_training_language', 'training_language', $data['training_language'] ?? []);
    }

    if (!$partial || array_key_exists('language', $data)) {
      $this->setTermField($node, 'field_language', 'language', $data['language'] ?? []);
    }
    if (!$partial || array_key_exists('body', $data)) {
      $this->setTextField($node, 'body', (string) ($data['body'] ?? ''), format: 'markdown');
    }
    if (!$partial || array_key_exists('how_to_register', $data)) {
      $this->setTextField($node, 'field_how_to_register', (string) ($data['how_to_register'] ?? ''), format: 'markdown');
    }

    // Set the optional fields. Null clears.
    if (!$partial || array_key_exists('country', $data)) {
      $this->setTermField($node, 'field_country', 'country', $data['country'] ?? []);
    }

    if (!$partial || array_key_exists('dates', $data)) {
      if (!empty($data['dates']) && is_array($data['dates'])) {
        $this->setField($node, 'field_training_date', [
          'start' => $data['dates']['start'],
          'end' => $data['dates']['end'],
        ]);
        $this->setDateField($node, 'field_registration_deadline', (string) ($data['dates']['registration_deadline'] ?? ''));
      }
      else {
        $this->setField($node, 'field_training_date', NULL);
        $this->setDateField($node, 'field_registration_deadline', '');
      }
    }

    if (!$partial || array_key_exists('fee_information', $data)) {
      $this->setTextField($node, 'field_fee_information', $data['fee_information'] ?? '', format: 'plain');
    }

    if (!$partial || array_key_exists('professional_function', $data)) {
      $this->setTermField($node, 'field_career_categories', 'career_category', $data['professional_function'] ?? []);
    }
    if (!$partial || array_key_exists('theme', $data)) {
      $this->setTermField($node, 'field_theme', 'theme', $data['theme'] ?? []);
    }

    // Save the entity.
    $this->save($node, $provider, $data);

    return $node;
  }

}
