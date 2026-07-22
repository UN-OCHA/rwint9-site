<?php

declare(strict_types=1);

namespace Drupal\reliefweb_entities\Services;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_utility\Helpers\ReliefWebStateHelper;

/**
 * Prepares and sends publication notification emails for moderated documents.
 *
 * Recipient addresses staged by prepare() are held in memory until send()
 * completes for the same entity object in the current request.
 */
final class PublicationNotifier {

  /**
   * Recipient emails staged between prepare() and send(), keyed by entity.
   *
   * @var \WeakMap<\Drupal\Core\Entity\EntityInterface, list<string>>
   */
  private \WeakMap $stagedEmails;

  /**
   * Moderation statuses that trigger a publication notification.
   *
   * @var string[]
   */
  private const NOTIFY_MODERATION_STATUSES = ['to-review', 'published'];

  /**
   * Constructs a PublicationNotifier.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   The mail manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    protected readonly MailManagerInterface $mailManager,
    protected readonly LanguageManagerInterface $languageManager,
  ) {
    $this->stagedEmails = new \WeakMap();
  }

  /**
   * Stages publication notification recipients and updates the revision log.
   *
   * Clears field_notify before save. Call from late entity_presave so
   * moderation status is final for this save.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   */
  public function prepare(EntityInterface $entity): void {
    if (!$this->supports($entity)) {
      return;
    }

    assert($entity instanceof ContentEntityInterface);
    assert($entity instanceof EntityModeratedInterface);

    $status = $entity->getModerationStatus();
    if (!in_array($status, self::NOTIFY_MODERATION_STATUSES, TRUE)) {
      return;
    }

    $emails = $entity->get('field_notify')->value ?? '';
    $emails = preg_split('/([,;]|\s)+/', trim((string) $emails));
    $emails = array_filter(filter_var_array($emails, FILTER_VALIDATE_EMAIL));
    $emails = array_unique($emails);

    $entity->set('field_notify', []);

    if ($emails === []) {
      return;
    }

    $this->setStagedEmails($entity, $emails);

    if (!$entity instanceof RevisionLogInterface) {
      return;
    }

    $log = strtr('Publication notification sent to @to', [
      '@to' => implode(', ', $emails),
    ]);
    $existing = trim((string) ($entity->getRevisionLogMessage() ?? ''));
    $entity->setRevisionLogMessage($existing === '' ? $log : $existing . ' - ' . $log);
  }

  /**
   * Sends a staged publication notification email, if any.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   */
  public function send(EntityInterface $entity): void {
    if (!$this->supports($entity)) {
      return;
    }

    $emails = $this->getStagedEmails($entity);
    if ($emails === []) {
      return;
    }

    $this->resetStagedEmails($entity);

    $from = ReliefWebStateHelper::getSubmitEmail();
    if ($from === '') {
      return;
    }

    $message = ReliefWebStateHelper::getReportPublicationEmailMessage();
    if ($message === '') {
      return;
    }

    assert($entity instanceof ContentEntityInterface);

    $parameters = [
      'subject' => 'ReliefWeb: Your submission has been published',
      'content' => strtr($message, [
        '@title' => $entity->label(),
        '@url' => $entity->toUrl('canonical', [
          'absolute' => TRUE,
          'path_processing' => FALSE,
        ])->toString(FALSE),
      ]),
    ];

    $langcode = $this->languageManager->getCurrentLanguage()->getId();

    $this->mailManager->mail(
      'reliefweb_entities',
      'report_publication_notification',
      implode(', ', $emails),
      $langcode,
      $parameters,
      $from,
      TRUE,
    );
  }

  /**
   * Whether publication notifications apply to this entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   *
   * @return bool
   *   Whether publication notifications apply to this entity.
   */
  protected function supports(EntityInterface $entity): bool {
    return $entity instanceof ContentEntityInterface
      && $entity instanceof EntityModeratedInterface
      && $entity->getEntityTypeId() === 'node'
      && $entity->bundle() === 'report'
      && $entity->hasField('field_notify');
  }

  /**
   * Set staged recipient addresses for the entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   * @param string[] $emails
   *   Valid recipient addresses.
   */
  protected function setStagedEmails(EntityInterface $entity, array $emails): void {
    $this->stagedEmails[$entity] = array_values($emails);
  }

  /**
   * Get staged recipient addresses for the entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   *
   * @return string[]
   *   Staged recipient addresses.
   */
  protected function getStagedEmails(EntityInterface $entity): array {
    return $this->stagedEmails[$entity] ?? [];
  }

  /**
   * Clears staged recipient addresses for the entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   */
  protected function resetStagedEmails(EntityInterface $entity): void {
    unset($this->stagedEmails[$entity]);
  }

}
