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
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_revisions\EntityRevisionedTrait;
use Drupal\reliefweb_utility\Helpers\DateHelper;
use Drupal\reliefweb_utility\Helpers\ReliefWebStateHelper;
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

    // Change the publication date if bury is selected, to the original
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

    // Change the status to `embargoed` if there is an embargo date.
    if (!empty($this->field_embargo_date->value) && $this->getModerationStatus() !== 'draft') {
      $this->setModerationStatus('embargoed');

      $message = strtr('Embargoed (to be automatically published on @date).', [
        '@date' => DateHelper::format($this->field_embargo_date->value, 'custom', 'd M Y H:i e'),
      ]);

      $log = trim($this->getRevisionLogMessage());
      $log = $message . (!empty($log) ? "\n" . $log : '');
      $this->setRevisionLogMessage($log);
    }

    // Prepare notifications.
    $this->preparePublicationNotification();
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update);

    $this->sendPublicationNotification();
  }

  /**
   * Prepare the list of recipients to notify of the publication.
   */
  protected function preparePublicationNotification() {
    // Only send the notifications when the report is published.
    $status = $this->getModerationStatus();
    if ($status !== 'to-review' && $status !== 'published') {
      return;
    }

    // Extract the emails.
    $emails = $this->field_notify->value ?? '';
    $emails = preg_split('/([,;]|\s)+/', trim($emails));
    $emails = array_filter(filter_var_array($emails, FILTER_VALIDATE_EMAIL));
    $emails = array_unique($emails);

    // Empty the field.
    $this->field_notify->setValue([]);

    // Skip if there is no recipients.
    if (empty($emails)) {
      return;
    }

    // Store the emails to notify after the node is saved.
    $this->_publication_notification_emails = $emails;

    // Update the log message with the list of emails to notify.
    $log_field = $this->getEntityType()
      ->getRevisionMetadataKey('revision_log_message');

    // Not using `t()` because this is an internal editorial message.
    $log = strtr('Publication notification sent to @to', [
      '@to' => implode(', ', $emails),
    ]);
    if (!empty($this->{$log_field}->value)) {
      $this->{$log_field}->value .= ' - ' . $log;
    }
    else {
      $this->{$log_field}->value = $log;
    }
  }

  /**
   * Notify of the publication.
   */
  protected function sendPublicationNotification() {
    if (empty($this->_publication_notification_emails)) {
      return;
    }
    $emails = $this->_publication_notification_emails;
    unset($this->_publication_notification_emails);

    // Recipients and sender.
    $to = implode(', ', $emails);
    $from = ReliefWebStateHelper::getSubmitEmail();
    if (empty($from)) {
      return;
    }

    // Subject and content.
    $parameters = [];
    $parameters['subject'] = 'ReliefWeb: Your submission has been published';
    $parameters['content'] = strtr(implode("\n\n", [
      "Thank you for your submission to ReliefWeb.",
      "Your submission \"@title\" has been published with the following URL:",
      "@url",
      "Please respond to this email in case you have questions or corrections to your submission.",
      "Best regards,",
      "ReliefWeb team",
    ]), [
      '@title' => $this->label(),
      '@url' => $this->toUrl('canonical', [
        'absolute' => TRUE,
        'path_processing' => FALSE,
      ])->toString(FALSE),
    ]);

    $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();

    // Send the email.
    \Drupal::service('plugin.manager.mail')
      ->mail('reliefweb_entities', 'report_publication_notification', $to, $langcode, $parameters, $from, TRUE);
  }

}
