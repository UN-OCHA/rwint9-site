<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Plugin\reliefweb_post_api\ContentProcessor;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reliefweb_post_api\Attribute\ContentProcessor;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginBase;

/**
 * Job content handler.
 */
#[ContentProcessor(
  id: 'reliefweb_post_api.content_processor.job',
  label: new TranslatableMarkup('Jobs'),
  entityType: 'node',
  bundle: 'job',
  resource: 'jobs'
)]
class Job extends ContentProcessorPluginBase {

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
    if (!$partial || array_key_exists('closing_date', $data)) {
      $this->setDateField($node, 'field_job_closing_date', (string) ($data['closing_date'] ?? ''));
    }

    if (!$partial || array_key_exists('job_type', $data)) {
      $this->setTermField($node, 'field_job_type', 'job_type', $data['job_type'] ?? []);
    }
    if (!$partial || array_key_exists('job_experience', $data)) {
      $this->setTermField($node, 'field_job_experience', 'job_experience', $data['job_experience'] ?? []);
    }

    if (!$partial || array_key_exists('body', $data)) {
      $this->setTextField($node, 'body', (string) ($data['body'] ?? ''), format: 'markdown');
    }
    if (!$partial || array_key_exists('how_to_apply', $data)) {
      $this->setTextField($node, 'field_how_to_apply', (string) ($data['how_to_apply'] ?? ''), format: 'markdown');
    }

    // Set the optional fields. Null clears.
    if (!$partial || array_key_exists('country', $data)) {
      $this->setTermField($node, 'field_country', 'country', $data['country'] ?? []);
    }

    if (!$partial || array_key_exists('career_category', $data)) {
      $this->setTermField($node, 'field_career_categories', 'career_category', $data['career_category'] ?? []);
    }
    if (!$partial || array_key_exists('theme', $data)) {
      $this->setTermField($node, 'field_theme', 'theme', $data['theme'] ?? []);
    }

    // Save the entity.
    $this->save($node, $provider, $data);

    return $node;
  }

}
