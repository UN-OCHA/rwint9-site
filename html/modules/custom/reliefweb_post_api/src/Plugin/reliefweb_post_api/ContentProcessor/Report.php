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
  public function validateFiles(array $data): void {
    if (isset($data['image']) && is_array($data['image'])) {
      $this->validateFileData($data, $data['image'], 'image');
    }

    if (isset($data['file']) && is_array($data['file'])) {
      foreach ($data['file'] as $file) {
        $this->validateFileData($data, $file, 'file');
      }
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
      throw new ContentProcessorException(ContentProcessorMessage::MissingTypeUrl->format([
        '@type' => $type,
      ]));
    }

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

    $provider = $this->getProvider($data['provider'] ?? '');
    $pattern = $provider->getUrlPattern($type);

    if (!empty($file['url']) && !$this->validateUrl($file['url'], $pattern)) {
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

    if ($type === 'file') {
      if (empty($file['checksum'])) {
        throw new ContentProcessorException(ContentProcessorMessage::MissingTypeChecksum->format([
          '@type' => $type,
        ]));
      }

      $query = $this->database->select('node_field_data', 'nfd');
      $query->join('node', 'n', 'nfd.nid = n.nid');
      $query->join('node__field_file', 'ff', 'n.nid = ff.entity_id');
      $query->leftJoin('path_alias', 'pa', "pa.path = CONCAT('/node/', n.nid)");

      $result = $query
        ->fields('nfd', ['nid', 'title'])
        ->fields('pa', ['alias'])
        ->condition('nfd.type', 'report', '=')
        ->condition('n.uuid', $data['uuid'], '<>')
        ->condition('ff.field_file_file_hash', $file['checksum'], '=')
        ->orderBy('nfd.nid', 'ASC')
        ->range(0, 1)
        ->execute()
        ?->fetchAssoc();

      if (!empty($result)) {
        $nid = $result['nid'];
        $url = Url::fromUserInput($result['alias'] ?: '/node/' . $nid, ['absolute' => TRUE]);
        throw new DuplicateException(strtr('Duplicate detected: file "@uuid" is already attached to "@label" (:url).', [
          '@uuid' => $file['uuid'],
          '@label' => $result['title'],
          ':url' => $url->toString(),
        ]));
      }
    }
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
    if (!$partial || array_key_exists('file', $data)) {
      $this->setReliefWebFileField($node, 'field_file', $data['file'] ?? []);
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
