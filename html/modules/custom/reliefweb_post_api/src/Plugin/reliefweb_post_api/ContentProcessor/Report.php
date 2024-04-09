<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Plugin\reliefweb_post_api\ContentProcessor;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reliefweb_post_api\Attribute\ContentProcessor;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorException;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginBase;

/**
 * Report content handler.
 */
#[ContentProcessor(
  id: 'reliefweb_post_api.content_processor.report',
  label: new TranslatableMarkup('Reports'),
  entityType: 'node',
  bundle: 'report',
  resource: 'reports'
)]
class Report extends ContentProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function validateUrls(array $data): void {
    parent::validateUrls($data);

    $provider = $this->getProvider($data['provider'] ?? '');

    $image_pattern = $provider->getUrlPattern('image');
    if (!empty($data['image']['url']) && !$this->validateUrl($data['image']['url'], $image_pattern)) {
      throw new ContentProcessorException('Unallowed image URL: ' . $data['image']['url']);
    }

    $file_pattern = $provider->getUrlPattern('file');
    foreach ($data['file'] ?? [] as $file) {
      if (!empty($file['url']) && !$this->validateUrl($file['url'], $file_pattern)) {
        throw new ContentProcessorException('Unallowed file URL: ' . $file['url']);
      }
    }
  }

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

    // Set the mandatory fields.
    $node->title = $this->sanitizeString($data['title']);

    $this->setTextField($node, 'body', $data['body'], format: 'markdown');
    $this->setDateField($node, 'field_original_publication_date', $data['published']);

    $this->setTermField($node, 'field_content_format', 'content_format', $data['format']);
    $this->setTermField($node, 'field_language', 'language', $data['language']);
    $this->setTermField($node, 'field_source', 'source', $data['source']);
    $this->setTermField($node, 'field_country', 'country', $data['country']);
    $this->setField($node, 'field_primary_country', $node->field_country?->first()?->getValue());

    // Set the optional fields.
    $this->setField($node, 'field_origin', 0);
    $this->setUrlField($node, 'field_origin_notes', $data['origin'] ?? '', $provider->getUrlPattern());
    $this->setTermField($node, 'field_disaster', 'disaster', $data['disaster'] ?? []);
    $this->setTermField($node, 'field_disaster_type', 'disaster_type', $data['disaster_type'] ?? []);
    $this->setTermField($node, 'field_theme', 'theme', $data['theme'] ?? []);
    $this->setDateField($node, 'field_embargo_date', $data['embargoed'] ?? '', FALSE);

    // Emails to notify when the document is published.
    $emails = implode(',', $data['notify'] ?? $provider->getEmailsToNotify() ?? []);
    $this->setField($node, 'field_notify', $emails ?: NULL);

    // Add the optional files (attachments and image).
    $this->setReliefWebFileField($node, 'field_file', $data['file'] ?? []);
    $this->setImageField($node, 'field_image', $data['image'] ?? []);

    // Empty some other fields.
    // This is to remove changes made by editors when updating the document
    // since those changes may not be relevant or accurate anymore.
    // @todo review if we actually want to do that.
    $node->field_headline->setValue(NULL);
    $node->field_headline_title->setValue(NULL);
    $node->field_headline_summary->setValue(NULL);
    $node->field_headline_image->setValue(NULL);
    $node->field_feature->setValue(NULL);
    $node->field_ocha_product->setValue(NULL);

    // Set the provider.
    $this->setField($node, 'field_post_api_provider', $provider);

    // Set the new status.
    $node->moderation_status = $provider->getDefaultResourceStatus();

    // Set the log message based on whether it was updated or created.
    $message = $node->isNew() ? 'Automatic creation from POST API.' : 'Automatic update from POST API.';

    // Save the node.
    $node->setOwnerId(2);
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId(2);
    $node->setRevisionLogMessage($message);
    $node->save();

    return $node;
  }

}
