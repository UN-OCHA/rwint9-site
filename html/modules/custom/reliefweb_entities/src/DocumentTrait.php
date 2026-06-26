<?php

namespace Drupal\reliefweb_entities;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\reliefweb_entities\Entity\Source;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_entities\Services\RelatedContentServiceInterface;
use Drupal\reliefweb_rivers\RiverServiceBase;
use Drupal\reliefweb_utility\Helpers\HtmlSummarizer;
use Drupal\reliefweb_utility\Helpers\MediaHelper;

/**
 * Trait implementing most methods of the DocumentInterface.
 *
 * @see Drupal\reliefweb_entities\DocumentInterface
 */
trait DocumentTrait {

  use StringTranslationTrait;

  /**
   * Get the meta information for the entity.
   *
   * @see Drupal\reliefweb_entities\DocumentInterface::getEntityMeta()
   */
  public function getEntityMeta() {
    return [];
  }

  /**
   * Get the entity image.
   *
   * @see Drupal\reliefweb_entities\DocumentInterface::getEntityImage()
   */
  public function getEntityImage() {
    if ($this->field_image->isEmpty()) {
      return [];
    }
    return MediaHelper::getImage($this->field_image);
  }

  /**
   * Get the list of social media links to share the content.
   *
   * @see Drupal\reliefweb_entities\DocumentInterface::getShareLinks()
   */
  public function getShareLinks() {
    $entity_id = $this->id();

    // Skip if the entity doesn't have an id, for example when previewing
    // an entity being created.
    if (empty($entity_id)) {
      return [];
    }

    $source = 'ReliefWeb';

    // Title.
    $title = $this->label();

    // Summary (use the headline summary if available).
    $summary = '';
    if ($this->hasField('field_headline_summary') && !$this->field_headline_summary->isEmpty()) {
      $summary = $this->field_headline_summary->value;
    }
    elseif ($this->hasField('body') && !$this->body->isEmpty()) {
      $body = check_markup($this->body->value, $this->body->format);
      $summary = HtmlSummarizer::summarize($body, 200, TRUE);
    }

    // Url with the tracking parameters.
    $url = Url::fromUri('entity:node/' . $entity_id, [
      'absolute' => TRUE,
      'query' => [
        'utm_medium' => 'social',
        'utm_campaign' => 'shared',
      ],
    ])->toString();

    // Social media platforms we support.
    $links['facebook'] = [
      'title' => $this->t('Share this on Facebook'),
      'url' => Url::fromUri('https://www.facebook.com/sharer.php', [
        'query' => [
          'u' => $url . '&utm_source=facebook.com',
          't' => $title,
        ],
      ]),
    ];
    $links['x'] = [
      'title' => $this->t('Share this on X'),
      'url' => Url::fromUri('https://x.com/share', [
        'query' => [
          'url' => $url . '&utm_source=x.com',
          // Truncate the title as text for X to stay within the allowed
          // number of characters.
          'text' => Unicode::truncate($title, 90, FALSE, TRUE),
          'via' => 'reliefweb',
        ],
      ]),
    ];
    $links['linkedin'] = [
      'title' => $this->t('Post this on LinkedIn'),
      'url' => Url::fromUri('https://www.linkedin.com/shareArticle', [
        'query' => [
          'mini' => 'true',
          'url' => $url . '&utm_source=linkedin.com',
          'title' => $title,
          'summary' => $summary,
          'source' => $source,
        ],
      ]),
    ];

    return [
      '#theme' => 'reliefweb_entities_entity_social_media_links',
      '#title' => $this->t('Share'),
      '#links' => $links,
      '#icons_only' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getShareLink(): array {
    // Skip if the document doesn't have an ID to avoid toUrl() to throw
    // an exception...
    if (empty($this->id())) {
      return [];
    }

    $url = $this->toUrl('canonical', [
      'absolute' => TRUE,
      'alias' => TRUE,
    ])->toString();

    if ($this->getEntityTypeId() === 'node') {
      $label = $this->t('Share @type link', [
        '@type' => mb_strtolower($this->type->entity->label()),
      ]);
    }
    else {
      $label = $this->t('Share link');
    }

    return [
      '#theme' => 'reliefweb_entities_entity_share_link',
      '#label' => $label,
      '#url' => $url,
      '#attached' => [
        'library' => [
          'reliefweb_utility/copy-link',
        ],
      ],
      '#cache' => [
        'contexts' => ['url'],
        'tags' => $this->getCacheTags(),
      ],
    ];
  }

  /**
   * Get the reports related to the entity.
   *
   * @see Drupal\reliefweb_entities\DocumentInterface::getRelatedContent()
   */
  public function getRelatedContent($limit = 4) {
    return \Drupal::service(RelatedContentServiceInterface::class)
      ->getRelatedContent($this, $limit);
  }

  /**
   * Get the entity meta information for the given entity reference field.
   *
   * @see Drupal\reliefweb_entities\DocumentInterface::getEntityMetaFromField()
   */
  public function getEntityMetaFromField(
    string $field,
    string $code = '',
    array $extra_fields = ['shortname' => 'shortname'],
    array $exclude = [],
  ) {
    $field = 'field_' . $field;

    if (!$this->hasField($field) || !$this->{$field} instanceof EntityReferenceFieldItemList) {
      return [];
    }

    // Country and disaster have aprimary fields, retrieve the corresponding id.
    $main_field = str_replace('field_', 'field_primary_', $field);
    $main_id = NULL;
    if ($this->hasField($main_field) && $this->{$main_field} instanceof EntityReferenceFieldItemList) {
      $main_id = $this->{$main_field}->target_id;
    }

    $items = [];
    foreach ($this->{$field}->referencedEntities() as $entity) {
      if ($entity instanceof EntityModeratedInterface && !$entity->access('view')) {
        continue;
      }

      // Skip if the entity is in the exclude list.
      if (!empty($exclude) && in_array($entity->id(), $exclude)) {
        continue;
      }

      if (!empty($code)) {
        $url = RiverServiceBase::getRiverUrl($this->bundle(), [
          'advanced-search' => '(' . $code . $entity->id() . ')',
        ], $entity->label(), TRUE);
      }
      else {
        $url = $entity->toUrl();
      }

      $item = [
        'name' => $entity->label(),
        'url' => $url,
      ];

      if (!empty($main_id) && $entity->id() === $main_id) {
        $item['main'] = TRUE;
      }

      // Add any extra field.
      foreach ($extra_fields as $name => $property) {
        $extra_field = 'field_' . $name;
        if ($entity->hasField($extra_field)) {
          $item[$property] = $entity->{$extra_field}->value ?? $entity->label();
        }
      }

      $items[] = $item;
    }

    return $items;
  }

  /**
   * Get the entity ids from a entity reference field.
   *
   * @see Drupal\reliefweb_entities\DocumentInterface::getReferencedEntityIds()
   */
  public function getReferencedEntityIds($field) {
    $ids = [];
    if ($this->hasField($field) && !$this->{$field}->isEmpty()) {
      foreach ($this->{$field} as $item) {
        $target_id = $item->target_id;
        if (!empty($target_id)) {
          $ids[] = $target_id;
        }
      }
    }
    return $ids;
  }

  /**
   * Convert a date to a \DateTime object.
   *
   * @see Drupal\reliefweb_entities\DocumentInterface::createDate()
   */
  public function createDate($date) {
    if (is_string($date) || is_int($date)) {
      $date = is_numeric($date) ? '@' . $date : $date;
      return new \DateTime((string) $date, new \DateTimeZone('UTC'));
    }
    elseif (is_a($date, \DateTime::class)) {
      return $date;
    }
    return NULL;
  }

  /**
   * Update the status of the sources when publishing an opportunity.
   */
  protected function updateSourceModerationStatus() {
    if (!$this->hasField('field_source') || $this->field_source->isEmpty()) {
      return;
    }

    if (!($this instanceof EntityPublishedInterface) || !$this->isPublished()) {
      return;
    }

    // Make the inactive or archive sources active when the node is published.
    foreach ($this->field_source as $item) {
      $source = $item->entity;
      if (empty($source) || !($source instanceof Source)) {
        continue;
      }

      if (in_array($source->getModerationStatus(), ['inactive', 'archive'])) {
        $source->notifications_content_disable = TRUE;
        $source->setModerationStatus('active');
        $source->setNewRevision(TRUE);
        $source->setRevisionLogMessage('Automatic status update due to publication of node ' . $this->id());
        $source->setRevisionUserId(2);
        $source->setRevisionCreationTime(time());
        $source->save();
      }
    }
  }

  /**
   * Update the status to refused if any of the sources is blocked.
   */
  protected function updateModerationStatusFromSourceStatus() {
    if (!$this->hasField('field_source') || $this->field_source->isEmpty()) {
      return;
    }

    $blocked = [];
    foreach ($this->field_source as $item) {
      $source = $item->entity;
      if (empty($source) || !($source instanceof Source)) {
        continue;
      }

      if ($source->getModerationStatus() === 'blocked') {
        $blocked[] = $source->label();
      }
    }

    if (!empty($blocked)) {
      $this->setModerationStatus('refused');

      // Add a message to the revision log.
      if ($this instanceof RevisionLogInterface) {
        $message = 'Submissions from "' . implode('", "', $blocked) . '" are no longer allowed.';

        $log = $this->getRevisionLogMessage();
        if (empty($log)) {
          $this->setRevisionLogMessage($message);
        }
        else {
          $this->setRevisionLogMessage($message . ' ' . $log);
        }
      }
    }
  }

}
