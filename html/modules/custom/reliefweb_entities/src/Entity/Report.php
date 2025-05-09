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
use Drupal\reliefweb_moderation\Helpers\UserPostingRightsHelper;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_revisions\EntityRevisionedTrait;
use Drupal\reliefweb_utility\Helpers\DateHelper;
use Drupal\reliefweb_utility\Helpers\ReliefWebStateHelper;
use Drupal\reliefweb_utility\Helpers\TaxonomyHelper;
use Drupal\reliefweb_utility\Helpers\UrlHelper;
use Drupal\reliefweb_utility\Helpers\UserHelper;

/**
 * Bundle class for report nodes.
 */
class Report extends Node implements BundleEntityInterface, EntityModeratedInterface, EntityRevisionedInterface, DocumentInterface {

  use DocumentTrait;
  use EntityModeratedTrait;
  use EntityRevisionedTrait;
  use StringTranslationTrait;

  /**
   * Store the emails for the publication notifications.
   *
   * @var ?array<string>
   */
  protected ?array $publicationNotificationEmails = NULL;

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
    $embargo_statuses = ['embargoed', 'to-review', 'published'];
    if (!empty($this->field_embargo_date->value) && in_array($this->getModerationStatus(), $embargo_statuses)) {
      $this->setModerationStatus('embargoed');

      $message = strtr('Embargoed (to be automatically published on @date).', [
        '@date' => DateHelper::format($this->field_embargo_date->value, 'custom', 'd M Y H:i e'),
      ]);

      $log = trim($this->getRevisionLogMessage() ?: '');
      $log = $message . (!empty($log) ? "\n" . $log : '');
      $this->setRevisionLogMessage($log);
    }

    // Prepare notifications.
    $this->preparePublicationNotification();

    // Update the entity status based on the source(s) moderation status.
    $this->updateModerationStatusFromSourceStatus();

    parent::preSave($storage);
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update);

    // Make the sources active.
    $this->updateSourceModerationStatus();

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
    $this->setPublicationNotificationEmails($emails);

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
    if (!$this->hasPublicationNotificationEmails()) {
      return;
    }
    $emails = $this->getPublicationNotificationEmails();
    $this->resetPublicationNotificationEmails();

    // Recipients and sender.
    $to = implode(', ', $emails);
    $from = ReliefWebStateHelper::getSubmitEmail();
    if (empty($from)) {
      return;
    }

    $message = ReliefWebStateHelper::getReportPublicationEmailMessage();
    if (empty($message)) {
      return;
    }

    // Subject and content.
    $parameters = [];
    $parameters['subject'] = 'ReliefWeb: Your submission has been published';
    $parameters['content'] = strtr($message, [
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

  /**
   * Update the status for the entity based on the user posting rights.
   */
  protected function updateModerationStatusFromPostingRights() {
    // In theory the revision user here, is the current user saving the entity.
    /** @var \Drupal\user\UserInterface|null $user */
    $user = $this->getRevisionUser();
    $status = $this->getModerationStatus();

    // Skip if there is no revision user. That should normally not happen with
    // new content but some old revisions may reference users that don't exist
    // anymore (which should not happen either but...).
    if (empty($user)) {
      return;
    }

    // For non editors, we determine the real status based on the user
    // posting rights for the selected sources.
    if (!UserHelper::userHasRoles(['editor'], $user) && in_array($status, ['pending'])) {
      // Retrieve the list of sources and check the user rights.
      if (!$this->field_source->isEmpty()) {
        // Extract source ids.
        $sources = [];
        foreach ($this->field_source as $item) {
          if (!empty($item->target_id)) {
            $sources[] = $item->target_id;
          }
        }

        // Get the user's posting right for the document.
        $rights = [];
        foreach (UserPostingRightsHelper::getUserPostingRights($user, $sources) as $tid => $data) {
          $rights[$data[$this->bundle()] ?? 0][] = $tid;
        }

        $role = match(TRUE) {
          $user->hasRole('contributor') => 'contributor',
          $user->hasRole('submitter') =>  'submitter',
          default => '*',
        };

        // Blocked for some sources.
        if (!empty($rights[1])) {
          $right = 'blocked';
          $default = match($role) {
            'contributor' => 'refused',
            'submitter' => 'refused',
            default => 'refused',
          };
        }
        // Trusted for all the sources.
        elseif (isset($rights[3]) && count($rights[3]) === count($sources)) {
          $right = 'trusted';
          $default = match($role) {
            'contributor' => 'published',
            'submitter' => 'published',
            default => 'published',
          };
        }
        // Trusted for at least 1.
        elseif (isset($rights[3]) && count($rights[3]) > 0) {
          $right = 'trusted_partial';
          $default = match($role) {
            'contributor' => 'to-review',
            'submitter' => 'to-review',
            default => 'to-review',
          };
        }
        // Allowed for all the sources.
        elseif (isset($rights[2]) && count($rights[2]) === count($sources)) {
          $right = 'allowed';
          $default = match($role) {
            'contributor' => 'to-review',
            'submitter' => 'pending',
            default => 'pending',
          };
        }
        // Allowed for some sources.
        elseif (isset($rights[2]) && count($rights[2]) > 0) {
          $right = 'allowed_partial';
          $default = match($role) {
            'contributor' => 'pending',
            'submitter' => 'pending',
            default => 'pending',
          };
        }
        // Unverified for all sources.
        else {
          $right = 'unverified';
          $default = match($role) {
            'contributor' => 'pending',
            'submitter' => 'pending',
            default => 'pending',
          };
        }

        $status = ReliefWebStateHelper::getPostingRightsDefaultModerationStatus(
          $this->getEntityTypeId(),
          $this->bundle(),
          $role,
          $right,
          $default,
        );

        $this->setModerationStatus($status);

        // Add messages indicating the posting rights for easier review.
        $message = '';
        if (!empty($rights[1])) {
          $message = trim($message . strtr(' Blocked user for @sources.', [
            '@sources' => implode(', ', TaxonomyHelper::getSourceShortnames($rights[1])),
          ]));
        }
        if (!empty($rights[0])) {
          $message = trim($message . strtr(' Unverified user for @sources.', [
            '@sources' => implode(', ', TaxonomyHelper::getSourceShortnames($rights[0])),
          ]));
        }
        if (!empty($rights[2])) {
          $message = trim($message . strtr(' Allowed user for @sources.', [
            '@sources' => implode(', ', TaxonomyHelper::getSourceShortnames($rights[2])),
          ]));
        }
        if (!empty($rights[3])) {
          $message = trim($message . strtr(' Trusted user for @sources.', [
            '@sources' => implode(', ', TaxonomyHelper::getSourceShortnames($rights[3])),
          ]));
        }

        // Update the log message.
        if (!empty($message)) {
          $revision_log_field = $this->getEntityType()
            ->getRevisionMetadataKey('revision_log_message');

          if (!empty($revision_log_field)) {
            $log = trim($this->{$revision_log_field}->value ?? '');
            // Only add the message if not already in the revision log.
            if (mb_stripos($log, $message) === FALSE) {
              $log = $message . (!empty($log) ? ' ' . $log : '');
              $this->{$revision_log_field}->value = $log;
            }
          }
        }
      }
    }
  }

  /**
   * Temporarily store the email address to notify after publication.
   *
   * @param array $emails
   *   Emails to notify.
   */
  protected function setPublicationNotificationEmails(array $emails): void {
    $this->publicationNotificationEmails = $emails;
  }

  /**
   * Get the email address to notify after publication.
   *
   * @return array
   *   Emails to notify.
   */
  protected function getPublicationNotificationEmails(): array {
    return $this->publicationNotificationEmails ?? [];
  }

  /**
   * Check if there are email address to notify after publication.
   *
   * @return bool
   *   TRUE if there are emails to noify.
   */
  protected function hasPublicationNotificationEmails(): bool {
    return !empty($this->publicationNotificationEmails);
  }

  /**
   * Remove set publication notification emails.
   */
  protected function resetPublicationNotificationEmails(): void {
    $this->publicationNotificationEmails = NULL;
  }

}
