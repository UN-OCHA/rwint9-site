<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Plugin\reliefweb_post_api\ContentProcessor;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reliefweb_post_api\Attribute\ContentProcessor;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorException;
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

    $bundle = $this->getbundle();
    $provider = $this->getProvider($data['provider'] ?? '');

    // Generate the UUID corresponding to the document URL.
    $uuid = $this->generateUuid($data['url']);

    // Load or create a new node.
    $node = $this->entityRepository->loadEntityByUuid('node', $uuid) ??
            $this->entityTypeManager->getStorage('node')->create([
              'uuid' => $uuid,
              'type' => $bundle,
              'langcode' => $this->getDefaultLangcode(),
              'uid' => $provider->getUserId(),
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
    $this->setTermField($node, 'field_training_format', 'training_format', $data['format'] ?? []);

    $this->setUrlField($node, 'field_link', $data['event_url'], $provider->getUrlPattern());
    $this->setStringField($node, 'field_cost', $data['cost']);

    $this->setTermField($node, 'field_training_type', 'training_type', $data['category']);
    $this->setTermField($node, 'field_training_language', 'training_language', $data['training_language']);

    $this->setTermField($node, 'field_language', 'language', $data['language']);
    $this->setTextField($node, 'body', $data['body'], format: 'markdown');
    $this->setTextField($node, 'field_how_to_register', $data['how_to_register'], format: 'markdown');

    // Set the optional fields.
    $this->setTermField($node, 'field_country', 'country', $data['country'] ?? []);
    $this->setStringField($node, 'field_city', $data['city'] ?? '');

    if (!empty($data['dates'])) {
      $this->setField($node, 'field_training_date', [
        'start' => $data['dates']['start'],
        'end' => $data['dates']['end'],
      ]);
      $this->setDateField($node, 'field_registration_deadline', $data['dates']['registration_deadline']);
    }

    $this->setTextField($node, 'field_fee_information', $data['fee_information'] ?? '', format: 'plain');

    $this->setTermField($node, 'field_career_categories', 'career_category', $data['professional_function'] ?? []);
    $this->setTermField($node, 'field_theme', 'theme', $data['theme'] ?? []);

    // Set the provider.
    $this->setField($node, 'field_post_api_provider', $provider);

    // Set the new status.
    $node->setModerationStatus($provider->getDefaultResourceStatus());

    // Set the log message based on whether it was updated or created.
    $message = $node->isNew() ? 'Automatic creation from POST API.' : 'Automatic update from POST API.';

    // Save the node.
    $node->setNewRevision(TRUE);
    $node->setRevisionCreationTime(time());
    $node->setRevisionUserId(2);
    $node->setRevisionLogMessage($message);
    $node->save();

    return $node;
  }

}