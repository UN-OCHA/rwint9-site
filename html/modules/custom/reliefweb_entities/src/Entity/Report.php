<?php

namespace Drupal\reliefweb_entities\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\Entity\Node;
use Drupal\reliefweb_entities\BundleEntityInterface;
use Drupal\reliefweb_entities\DocumentInterface;
use Drupal\reliefweb_entities\DocumentTrait;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\EntityModeratedTrait;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_revisions\EntityRevisionedTrait;
use Drupal\reliefweb_utility\Helpers\DateHelper;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

/**
 * Bundle class for report nodes.
 */
class Report extends Node implements BundleEntityInterface, EntityModeratedInterface, EntityRevisionedInterface, DocumentInterface {

  use DocumentTrait;
  use EntityModeratedTrait;
  use EntityRevisionedTrait;
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
      'country' => $this->getEntityMetaFromField('country', 'C'),
      'source' => $this->getEntityMetaFromField('source', 'S'),
      'disaster' => $this->getEntityMetaFromField('disaster', 'D'),
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

    $disclaimers = [];
    foreach ($this->field_source->referencedEntities() as $entity) {
      if (!$entity->field_disclaimer->isEmpty()) {
        $disclaimers[] = [
          'name' => $entity->label(),
          'disclaimer' => $entity->field_disclaimer->value,
        ];
      }
    }

    if (empty($disclaimers)) {
      return [];
    }

    return [
      '#theme' => 'reliefweb_entities_entity_source_disclaimers',
      '#disclaimers' => $disclaimers,
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
        '@date' => DateHelper::format($this->getCreatedTime(), 'custom', 'j m Y'),
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
          $item['preview']['#style_name'] = 'large';
          $item['preview']['#alt'] = $item['label'];
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
          $item['preview']['#style_name'] = 'large';
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
    parent::preSave($storage);

    // Change the publication date if bury is selected to the original
    // publication date.
    if (!empty($this->field_bury->value) && !$this->field_original_publication_date->isEmpty()) {
      $date = $this->field_original_publication_date->value;
      $timestamp = DateHelper::getDateTimeStamp($date);
      if (!empty($timestamp)) {
        $this->_original_created = $this->getCreatedTime();
        $this->setCreatedTime($timestamp);
      }
    }
    elseif (isset($this->_original_created)) {
      $this->setCreatedTime($this->_original_created);
    }
  }

}
