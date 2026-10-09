<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Plugin\reliefweb_post_api\ContentProcessor;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\reliefweb_post_api\Attribute\ContentProcessor;
use Drupal\reliefweb_post_api\Enum\ContentProcessorMessage;
use Drupal\reliefweb_post_api\Exception\DuplicateException;
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
  protected function applyPartialSchemaMutations(array &$decoded, array $mandatory_fields): void {
    // Nested null in the file map deletes an attachment (PATCH only). Wrap
    // optional nested props (description/language) before wrapping the map
    // value itself, then parent wraps optional root properties (including
    // file for clear-all via file: null).
    if (isset($decoded['properties']['file']['patternProperties']) && is_array($decoded['properties']['file']['patternProperties'])) {
      foreach ($decoded['properties']['file']['patternProperties'] as &$value_schema) {
        if (!is_array($value_schema)) {
          continue;
        }
        foreach (['description', 'language'] as $property) {
          if (isset($value_schema['properties'][$property]) && is_array($value_schema['properties'][$property])) {
            $this->wrapSchemaWithNullableOneOf($value_schema['properties'][$property]);
          }
        }
        $this->wrapSchemaWithNullableOneOf($value_schema);
      }
      unset($value_schema);
    }

    parent::applyPartialSchemaMutations($decoded, $mandatory_fields);
  }

  /**
   * {@inheritdoc}
   */
  public function validateFiles(array $data): void {
    if (isset($data['image']) && is_array($data['image'])) {
      $this->validateImage($data, $data['image']);
    }

    if (!isset($data['file']) || !is_array($data['file'])) {
      return;
    }

    $needs_existing = FALSE;
    foreach ($data['file'] as $file) {
      if (is_array($file) && empty($file['url'])) {
        $needs_existing = TRUE;
        break;
      }
    }
    $existing_uuids = $needs_existing ? $this->loadExistingFileUuids($data) : [];

    foreach ($data['file'] as $attachment_uuid => $file) {
      // Null means delete that attachment (PATCH); nothing further to validate.
      if ($file === NULL) {
        continue;
      }
      if (!is_array($file)) {
        continue;
      }
      $this->validateAttachment($data, $file, (string) $attachment_uuid, $existing_uuids);
    }
  }

  /**
   * Validate a report image payload.
   *
   * @param array $data
   *   Post API data.
   * @param array $image
   *   Image object from the payload.
   *
   * @throws \Drupal\reliefweb_post_api\Plugin\ContentProcessorException
   *   When validation fails.
   */
  protected function validateImage(array $data, array $image): void {
    $type = 'image';
    $allow_raw_bytes = $this->getPluginSetting('allow_raw_bytes', FALSE);
    if (!$allow_raw_bytes && !empty($image['bytes'])) {
      throw new ContentProcessorException(ContentProcessorMessage::RawBytesNotAllowed->format([
        '@type' => $type,
      ]));
    }

    if (empty($image['download_url'])) {
      throw new ContentProcessorException(ContentProcessorMessage::MissingTypeDownloadUrl->format([
        '@type' => $type,
      ]));
    }

    $provider = $this->getProvider($data['provider'] ?? '');
    $pattern = $provider->getUrlPattern($type);

    if (!$this->validateUrl($image['download_url'], $pattern)) {
      throw new ContentProcessorException(ContentProcessorMessage::UnallowedTypeUrl->format([
        '@type' => $type,
        '@url' => $image['download_url'],
      ]));
    }
  }

  /**
   * Validate one PDF attachment in the file map.
   *
   * @param array $data
   *   Post API data.
   * @param array $file
   *   Attachment object from the map.
   * @param string $attachment_uuid
   *   Permanent attachment UUID (file map key).
   * @param array<string, true> $existing_uuids
   *   Existing permanent and legacy managed-file UUIDs on the document.
   *
   * @throws \Drupal\reliefweb_post_api\Plugin\ContentProcessorException
   *   When validation fails.
   * @throws \Drupal\reliefweb_post_api\Exception\DuplicateException
   *   When the checksum is already attached to another report.
   */
  protected function validateAttachment(array $data, array $file, string $attachment_uuid, array $existing_uuids): void {
    $type = 'file';
    $allow_raw_bytes = $this->getPluginSetting('allow_raw_bytes', FALSE);
    if (!$allow_raw_bytes && !empty($file['bytes'])) {
      throw new ContentProcessorException(ContentProcessorMessage::RawBytesNotAllowed->format([
        '@type' => $type,
      ]));
    }

    if (empty($file['uuid'])) {
      throw new ContentProcessorException(ContentProcessorMessage::MissingTypeUuid->format([
        '@type' => $type,
      ]));
    }

    if ($attachment_uuid === '' || $file['uuid'] !== $attachment_uuid) {
      throw new ContentProcessorException(ContentProcessorMessage::FileUuidKeyMismatch->format([
        '@uuid' => (string) $file['uuid'],
        '@key' => $attachment_uuid,
      ]));
    }

    $provider = $this->getProvider($data['provider'] ?? '');
    $pattern = $provider->getUrlPattern($type);

    if (empty($file['download_url'])) {
      throw new ContentProcessorException(ContentProcessorMessage::MissingTypeDownloadUrl->format([
        '@type' => $type,
      ]));
    }

    if (!$this->validateUrl($file['download_url'], $pattern)) {
      throw new ContentProcessorException(ContentProcessorMessage::UnallowedTypeUrl->format([
        '@type' => $type,
        '@url' => $file['download_url'],
      ]));
    }

    $is_existing = isset($existing_uuids[$attachment_uuid]);
    if (empty($file['url'])) {
      if (!$is_existing) {
        throw new ContentProcessorException(ContentProcessorMessage::UnknownFileUuid->format([
          '@uuid' => $attachment_uuid,
        ]));
      }
    }
    else {
      if (!$this->validateUrl($file['url'], $pattern)) {
        throw new ContentProcessorException(ContentProcessorMessage::UnallowedTypeUrl->format([
          '@type' => $type,
          '@url' => $file['url'],
        ]));
      }
      if ($this->generateUuid($file['url'], $data['uuid']) !== $file['uuid']) {
        throw new ContentProcessorException(ContentProcessorMessage::TypeUuidNotDerived->format([
          '@type' => $type,
          '@uuid' => $file['uuid'],
          '@url' => $file['url'],
        ]));
      }
    }

    if (empty($file['checksum'])) {
      throw new ContentProcessorException(ContentProcessorMessage::MissingTypeChecksum->format([
        '@type' => $type,
      ]));
    }

    $this->validateFileChecksumUnique((string) $data['uuid'], (string) $file['uuid'], (string) $file['checksum']);
  }

  /**
   * Reject checksums already attached to another report.
   *
   * @param string $document_uuid
   *   This document's UUID.
   * @param string $file_uuid
   *   Attachment UUID (for the error message).
   * @param string $checksum
   *   SHA-256 checksum of the file content.
   *
   * @throws \Drupal\reliefweb_post_api\Exception\DuplicateException
   *   When another report already has this checksum.
   */
  protected function validateFileChecksumUnique(string $document_uuid, string $file_uuid, string $checksum): void {
    $query = $this->database->select('node_field_data', 'nfd');
    $query->join('node', 'n', 'nfd.nid = n.nid');
    $query->join('node__field_file', 'ff', 'n.nid = ff.entity_id');
    $query->leftJoin('path_alias', 'pa', "pa.path = CONCAT('/node/', n.nid)");

    $result = $query
      ->fields('nfd', ['nid', 'title'])
      ->fields('pa', ['alias'])
      ->condition('nfd.type', 'report', '=')
      ->condition('n.uuid', $document_uuid, '<>')
      ->condition('ff.field_file_file_hash', $checksum, '=')
      ->orderBy('nfd.nid', 'ASC')
      ->range(0, 1)
      ->execute()
      ?->fetchAssoc();

    if (empty($result)) {
      return;
    }

    $nid = $result['nid'];
    $url = Url::fromUserInput($result['alias'] ?: '/node/' . $nid, ['absolute' => TRUE]);
    throw new DuplicateException(strtr('Duplicate detected: file "@uuid" is already attached to "@label" (:url).', [
      '@uuid' => $file_uuid,
      '@label' => $result['title'],
      ':url' => $url->toString(),
    ]));
  }

  /**
   * Load permanent file UUIDs already attached to the document.
   *
   * @param array $data
   *   Post API data.
   *
   * @return array<string, true>
   *   Existing permanent UUIDs (and legacy managed-file UUIDs) as keys.
   */
  protected function loadExistingFileUuids(array $data): array {
    $document_uuid = (string) ($data['uuid'] ?? '');
    if ($document_uuid === '') {
      return [];
    }

    $query = $this->database->select('node__field_file', 'ff');
    $query->join('node', 'n', 'n.nid = ff.entity_id');
    $query->fields('ff', ['field_file_uuid', 'field_file_file_uuid']);
    $query->condition('n.uuid', $document_uuid);

    $existing = [];
    foreach ($query->execute() as $row) {
      $permanent = (string) ($row->field_file_uuid ?? '');
      if ($permanent !== '') {
        $existing[$permanent] = TRUE;
      }
      $managed = (string) ($row->field_file_file_uuid ?? '');
      if ($managed !== '') {
        $existing[$managed] = TRUE;
      }
    }
    return $existing;
  }

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

    // Partial update?
    $partial = !$node->isNew() && !empty($data['partial']);

    // Set the mandatory fields.
    if (!$partial || array_key_exists('title', $data)) {
      $this->setStringField($node, 'title', (string) ($data['title'] ?? ''));
    }
    if (!$partial || array_key_exists('body', $data)) {
      $this->setTextField($node, 'body', (string) ($data['body'] ?? ''), format: 'markdown');
    }
    if (!$partial || array_key_exists('published', $data)) {
      $this->setDateField($node, 'field_original_publication_date', (string) ($data['published'] ?? ''));
    }
    if (!$partial || array_key_exists('format', $data)) {
      $this->setTermField($node, 'field_content_format', 'content_format', $data['format'] ?? []);
    }
    if (!$partial || array_key_exists('language', $data)) {
      $this->setTermField($node, 'field_language', 'language', $data['language'] ?? []);
    }
    if (!$partial || array_key_exists('source', $data)) {
      $this->setTermField($node, 'field_source', 'source', $data['source'] ?? []);
    }
    if (!$partial || array_key_exists('country', $data)) {
      $this->setTermField($node, 'field_country', 'country', $data['country'] ?? []);
      $this->setField($node, 'field_primary_country', $node->field_country?->first()?->getValue());
    }

    // Set the optional fields. Null clears (coerced via ?? empty defaults).
    if (!$partial || array_key_exists('origin', $data)) {
      $this->setUrlField($node, 'field_origin_notes', $data['origin'] ?? '', $provider->getUrlPattern());
    }
    if (!$partial || array_key_exists('disaster', $data)) {
      $this->setTermField($node, 'field_disaster', 'disaster', $data['disaster'] ?? []);
    }
    if (!$partial || array_key_exists('disaster_type', $data)) {
      $this->setTermField($node, 'field_disaster_type', 'disaster_type', $data['disaster_type'] ?? []);
    }
    if (!$partial || array_key_exists('theme', $data)) {
      $this->setTermField($node, 'field_theme', 'theme', $data['theme'] ?? []);
    }
    if (!$partial || array_key_exists('embargoed', $data)) {
      $this->setDateField($node, 'field_embargo_date', $data['embargoed'] ?? '', FALSE);
    }

    // Add the optional files (attachments and image).
    if (!$partial || array_key_exists('file', $data) || array_key_exists('file_order', $data)) {
      $this->setReliefWebFileField(
        $node,
        'field_file',
        array_key_exists('file', $data) ? $data['file'] : ($partial ? [] : NULL),
        $partial,
        $data['file_order'] ?? NULL,
      );
    }
    if (!$partial || array_key_exists('image', $data)) {
      $this->setImageField($node, 'field_image', $data['image'] ?? []);
    }

    // Empty some other fields.
    // This is to remove changes made by editors when updating the document
    // since those changes may not be relevant or accurate anymore.
    // @todo review if we actually want to do that.
    if (!$partial) {
      $node->field_headline->setValue(0);
      $node->field_headline_title->setValue(NULL);
      $node->field_headline_summary->setValue(NULL);
      $node->field_headline_image->setValue(NULL);
      $node->field_feature->setValue(NULL);
      $node->field_ocha_product->setValue(NULL);
    }

    // Emails to notify when the document is published.
    if (!$partial) {
      $emails = implode(',', $data['notify'] ?? $provider->getEmailsToNotify() ?? []);
      $this->setField($node, 'field_notify', $emails ?: NULL);
    }
    elseif (array_key_exists('notify', $data)) {
      $emails = implode(',', $data['notify'] ?? []);
      $this->setField($node, 'field_notify', $emails ?: NULL);
    }

    // Set the origin to "API".
    $this->setField($node, 'field_origin', 3);

    // Save the entity.
    $this->save($node, $provider, $data);

    return $node;
  }

}
