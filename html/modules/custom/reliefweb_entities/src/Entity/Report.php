<?php

namespace Drupal\reliefweb_entities\Entity;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\reliefweb_entities\BundleEntityInterface;
use Drupal\reliefweb_entities\DocumentInterface;
use Drupal\reliefweb_entities\DocumentTrait;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\EntityModeratedTrait;
use Drupal\reliefweb_moderation\EntityWithPostingRightsTrait;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_revisions\EntityRevisionedTrait;
use Drupal\reliefweb_utility\Helpers\DateHelper;
use Drupal\reliefweb_utility\Helpers\TextHelper;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

/**
 * Bundle class for report nodes.
 */
class Report extends Node implements BundleEntityInterface, EntityModeratedInterface, EntityRevisionedInterface, DocumentInterface {

  use DocumentTrait;
  use EntityModeratedTrait;
  use EntityRevisionedTrait;
  use EntityWithPostingRightsTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getApiResource() {
    return 'reports';
  }

  /**
   * {@inheritdoc}
   */
  public static function addFieldConstraints(&$fields) {
    // No specific constraints.
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityMeta() {
    $origin = $this->field_origin_notes->value;
    if (!UrlHelper::isValid($origin, TRUE)) {
      $origin = '';
    }
    else {
      $origin = UrlHelper::encodeUrl($origin);
    }

    return [
      'origin' => $origin,
      'posted' => $this->createDate($this->getCreatedTime()),
      'published' => $this->createDate($this->field_original_publication_date->value),
      'country' => $this->getEntityMetaFromField('country'),
      'source' => $this->getEntityMetaFromField('source'),
      'disaster' => $this->getEntityMetaFromField('disaster'),
      'format' => $this->getEntityMetaFromField('content_format', 'F'),
      'theme' => $this->getEntityMetaFromField('theme', 'T'),
      'disaster_type' => $this->getEntityMetaFromField('disaster_type', 'DT'),
      'language' => $this->getEntityMetaFromField('language', 'L'),
    ];
  }

  /**
   * Get the source disclaimers.
   *
   * @return array
   *   Render array with the list of disclaimers.
   */
  public function getSourceDisclaimers() {
    if ($this->field_source->isEmpty()) {
      return [];
    }

    $cache = new CacheableMetadata();

    $disclaimers = [];
    foreach ($this->field_source->referencedEntities() as $entity) {
      if (!$entity->field_disclaimer->isEmpty()) {
        $disclaimers[] = [
          'name' => $entity->label(),
          'disclaimer' => $entity->field_disclaimer->value,
        ];
        $cache->addCacheTags($entity->getCacheTags());
      }
    }

    if (empty($disclaimers)) {
      return [];
    }

    return [
      '#theme' => 'reliefweb_entities_entity_source_disclaimers',
      '#disclaimers' => $disclaimers,
      '#cache' => [
        'tags' => $cache->getCacheTags(),
      ],
    ];
  }

  /**
   * Get the report attachments.
   *
   * @param array|null $build
   *   The render array for the attachment field.
   *
   * @return array
   *   Render array with the list of attachments.
   */
  public function getAttachments(?array $build = NULL) {
    if (empty($build) || empty($build['#list'])) {
      return [];
    }

    $formats = [];
    foreach ($this->field_content_format as $item) {
      $formats[$item->target_id] = $item->target_id;
    }

    // The report is an interactive content.
    if (isset($formats[38974])) {
      $build['#theme'] = 'reliefweb_file_list__interactive';

      $build['#title'] = $this->t('Screenshot(s) of the interactive content as of @date', [
        '@date' => DateHelper::format($this->getCreatedTime(), 'custom', 'j M Y'),
      ]);

      $url = NULL;
      if (!$this->get('field_origin_notes')->isEmpty()) {
        $url = Url::fromUri($this->field_origin_notes->value, [
          'attributes' => [
            'target' => '_blank',
            'rel' => 'noopener',
          ],
        ]);
      }

      if (!empty($url)) {
        $build['#footer'] = Link::fromTextAndUrl(
          $this->t('View the interactive content page'),
          $url
        );
      }

      foreach ($build['#list'] as $index => &$item) {
        $description = $item['item']->getFileDescription();
        if (!empty($description)) {
          $item['label'] = $this->t('Screenshot @index: @description', [
            '@index' => $index + 1,
            '@description' => $description,
          ]);
        }
        else {
          $item['label'] = $this->t('Screenshot @index', [
            '@index' => $index + 1,
          ]);
        }
        if (isset($item['preview'])) {
          $item['preview']['#responsive_image_style_id'] = 'large';
          $item['preview']['#attributes']['alt'] = $item['label'];
        }

        // Have the screenshots link to the original content.
        if (!empty($url)) {
          $item['url'] = $url->toString();
        }
        else {
          unset($item['url']);
        }
      }
    }
    // The report is a map or an infographic.
    elseif (isset($formats[12]) || isset($formats[12570])) {
      $label = isset($formats[12]) ? $this->t('Download Map') : $this->t('Download Infographic');
      $build['#attributes']['class'][] = 'rw-attachment--map';
      foreach ($build['#list'] as &$item) {
        if (isset($item['preview'])) {
          $item['preview']['#responsive_image_style_id'] = 'large';
        }
        $item['label'] = $label;
      }
    }
    else {
      $label = $this->t('Download Report');
      $build['#attributes']['class'][] = 'rw-attachment--report';
      foreach ($build['#list'] as &$item) {
        $item['label'] = $label;
      }
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    // Change the publication date if bury is selected, to the original
    // publication date.
    if (!empty($this->field_bury->value) && !$this->field_original_publication_date->isEmpty()) {
      $date = $this->field_original_publication_date->value;
      $timestamp = DateHelper::getDateTimeStamp($date);
      if (!is_null($timestamp)) {
        $this->_original_created = $this->getCreatedTime();
        $this->setCreatedTime($timestamp);
      }
    }
    elseif (isset($this->_original_created)) {
      $this->setCreatedTime($this->_original_created);
    }

    // #KYPCnkXd - No OCHA Product if the source is not OCHA (id: 1503).
    if (!$this->field_source->isEmpty()) {
      $from_ocha = FALSE;
      foreach ($this->field_source as $item) {
        // We don't use a strict equality as tid may be a numeric string...
        if (!$item->isEmpty() && $item->target_id == 1503) {
          $from_ocha = TRUE;
          break;
        }
      }
      if ($from_ocha === FALSE) {
        $this->field_ocha_product->setValue([]);
      }
    }

    // Ensure the country contains the primary field (as first value).
    if (!$this->field_primary_country->isEmpty()) {
      $primary_country_target_id = $this->field_primary_country->target_id;
      $country_values = [['target_id' => $primary_country_target_id]];
      foreach ($this->field_country as $item) {
        if ($item->isEmpty() || $item->target_id == $primary_country_target_id) {
          continue;
        }
        $country_values[] = ['target_id' => $item->target_id];
      }
      $this->field_country->setValue($country_values);
    }

    // Update the entity status based on the user posting rights.
    $this->updateModerationStatusFromPostingRights();

    // Change the status to `embargoed` if there is an embargo date.
    if (!empty($this->field_embargo_date->value) && $this->isPublishedOrEmbargoed()) {
      $this->setModerationStatus('embargoed');

      $message = strtr('Embargoed (to be automatically published on @date).', [
        '@date' => DateHelper::format($this->field_embargo_date->value, 'custom', 'd M Y H:i e'),
      ]);

      $this->updateRevisionLogMessage($message, 'prepend', TRUE, "\n");
    }

    // Prevent publication when the report has neither a body nor an attachment.
    $this->updateModerationStatusFromEmptyContent();

    // Update the entity status based on the source(s) moderation status.
    $this->updateModerationStatusFromSourceStatus();

    parent::preSave($storage);
  }

  /**
   * Whether a status is published-equivalent or embargoed.
   *
   * Published-equivalent matches getPublishedStatuses() from the report
   * moderation service. Embargoed is included as deferred publication.
   *
   * @param string|null $status
   *   Moderation status, or NULL to use the entity's current status.
   *
   * @return bool
   *   TRUE when the status is published-equivalent or embargoed.
   *
   * @see \Drupal\reliefweb_moderation\Services\ReportModeration::getPublishedStatuses()
   */
  public function isPublishedOrEmbargoed(?string $status = NULL): bool {
    $status ??= $this->getModerationStatus();
    $published = $this->getModerationService()?->getPublishedStatuses() ?? [
      'to-review',
      'published',
    ];
    return in_array($status, $published, TRUE) || $status === 'embargoed';
  }

  /**
   * Whether the report has a body or at least one attachment.
   *
   * @return bool
   *   TRUE when the report has publishable content.
   */
  public function hasBodyOrAttachment(): bool {
    $body = $this->hasField('body') ? ($this->get('body')->value ?? '') : '';
    $has_file = $this->hasField('field_file') && !$this->get('field_file')->isEmpty();
    return !TextHelper::isEmptyHtml($body) || $has_file;
  }

  /**
   * Demote published-like status when the report has no body and no attachment.
   */
  protected function updateModerationStatusFromEmptyContent(): void {
    $config = \Drupal::config('reliefweb_entities.settings');
    if (!$config->get('empty_content.prevent_publication')) {
      return;
    }

    // Skip for statuses that won't lead to the publication of the report.
    $status = $this->getModerationStatus();
    if (!$this->isPublishedOrEmbargoed($status)) {
      return;
    }

    if ($this->hasBodyOrAttachment()) {
      return;
    }

    $fallback = $config->get('empty_content.fallback_status') ?: 'on-hold';
    $allowed_statuses = $this->getAllowedModerationStatuses();
    if (!isset($allowed_statuses[$fallback])) {
      $fallback = isset($allowed_statuses['on-hold']) ? 'on-hold' : $this->getDefaultModerationStatus();
    }

    if ($fallback === $status) {
      return;
    }

    $this->setModerationStatus($fallback);
    $this->updateRevisionLogMessage(strtr('No body or attachment: saved as **@status**.', [
      '@status' => (string) ($allowed_statuses[$fallback] ?? $fallback),
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update);

    // Make the sources active.
    $this->updateSourceModerationStatus();
  }

}
