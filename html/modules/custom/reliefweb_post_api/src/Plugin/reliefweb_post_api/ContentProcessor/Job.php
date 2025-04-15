<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Plugin\reliefweb_post_api\ContentProcessor;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reliefweb_post_api\Attribute\ContentProcessor;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorException;
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
  public function process(array $data, ?string $schema = NULL): ?ContentEntityInterface {
    // Ensure the data is valid.
    $this->validate($data, $schema);

    $bundle = $this->getbundle();
    $provider = $this->getProvider($data['provider'] ?? '');
    $user_id = $data['user'] ?? $provider->getUserId();

    // Generate the UUID corresponding to the document URL.
    $uuid = $this->generateUuid($data['url']);

    // Load or create a new node.
    $node = $this->entityRepository->loadEntityByUuid('node', $uuid) ??
            $this->entityTypeManager->getStorage('node')->create([
              'uuid' => $uuid,
              'type' => $bundle,
              'langcode' => $this->getDefaultLangcode(),
              'uid' => $user_id,
              // This is important to avoid content imported in the same batch
              // to have the exact same timestamp.
              'created' => time(),
            ]);

    // Verify the bundle if the entity already exists.
    if (!$node->isNew() && $node->bundle() !== $bundle) {
      throw new ContentProcessorException(strtr('Existing entity with the UUID @uuid is not a @bundle.', [
        '@uuid' => $uuid,
        '@bundle' => $bundle,
      ]));
    }

    // Skip if the node was marked as refused.
    if (!$node->isNew() && $node->getModerationStatus() === 'refused') {
      throw new ContentProcessorException(strtr('Skipping processing: existing entity with the UUID @uuid is marked as refused.', [
        '@uuid' => $uuid,
        '@bundle' => $bundle,
      ]));
    }

    // Set the mandatory fields.
    $node->title = $this->sanitizeString($data['title']);

    $this->setTermField($node, 'field_source', 'source', $data['source']);
    $this->setDateField($node, 'field_job_closing_date', $data['closing_date']);

    $this->setTermField($node, 'field_job_type', 'job_type', $data['job_type']);
    $this->setTermField($node, 'field_job_experience', 'job_experience', $data['job_experience']);

    $this->setTextField($node, 'body', $data['body'], format: 'markdown');
    $this->setTextField($node, 'field_how_to_apply', $data['how_to_apply'], format: 'markdown');

    // Set the optional fields.
    $this->setTermField($node, 'field_country', 'country', $data['country'] ?? []);
    $this->setStringField($node, 'field_city', $data['city'] ?? '');

    $this->setTermField($node, 'field_career_categories', 'career_category', $data['career_category'] ?? []);
    $this->setTermField($node, 'field_theme', 'theme', $data['theme'] ?? []);

    // Save the entity.
    $this->save($node, $provider, $data);

    return $node;
  }

}
