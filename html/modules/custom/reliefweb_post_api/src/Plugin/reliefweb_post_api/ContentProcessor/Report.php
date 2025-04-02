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
  public function validateFiles(array $data): void {
    parent::validateUrls($data);

    if (!empty($data['image'])) {
      $this->validateFileData($data, $data['image'], 'image');
    }

    foreach ($data['file'] ?? [] as $file) {
      $this->validateFileData($data, $file, 'file');
    }
  }

  /**
   * Validate an attachment or image file.
   *
   * @param array $data
   *   The submitted data.
   * @param array $file
   *   The file data.
   * @param string $type
   *   The file type (file or image).
   *
   * @throws \Drupal\reliefweb_post_api\Plugin\ContentProcessorException
   *   An exception of the file URL or UUID is invalid.
   */
  public function validateFileData(array $data, array $file, string $type): void {
    if (empty($file['url'])) {
      throw new ContentProcessorException(strtr('Missing @type URL.', [
        '@type' => $type,
      ]));
    }

    if (empty($file['uuid'])) {
      throw new ContentProcessorException(strtr('Missing @type UUID.', [
        '@type' => $type,
      ]));
    }

    $provider = $this->getProvider($data['provider'] ?? '');
    $pattern = $provider->getUrlPattern($type);

    if (!empty($file['url']) && !$this->validateUrl($file['url'], $pattern)) {
      throw new ContentProcessorException(strtr('Unallowed @type URL: @url.', [
        '@type' => $type,
        '@url' => $file['url'],
      ]));
    }

    if ($this->generateUuid($file['url'], $data['uuid']) !== $file['uuid']) {
      throw new ContentProcessorException(strtr('The @type UUID @uuid is not derived from the @type url and document UUID.', [
        '@type' => $type,
        '@uuid' => $file['uuid'],
        '@url' => $file['url'],
      ]));
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

    $this->setTextField($node, 'body', $data['body'], format: 'markdown');
    $this->setDateField($node, 'field_original_publication_date', $data['published']);

    $this->setTermField($node, 'field_content_format', 'content_format', $data['format']);
    $this->setTermField($node, 'field_language', 'language', $data['language']);
    $this->setTermField($node, 'field_source', 'source', $data['source']);
    $this->setTermField($node, 'field_country', 'country', $data['country']);
    $this->setField($node, 'field_primary_country', $node->field_country?->first()?->getValue());

    // Set the origin to "API".
    $this->setField($node, 'field_origin', 3);

    // Set the optional fields.
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

    // Save the entity.
    $this->save($node, $provider, $data);

    return $node;
  }

}
