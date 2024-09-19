<?php

namespace Drupal\reliefweb_entities;

use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\reliefweb_entities\Entity\Source;
use Drupal\reliefweb_moderation\Helpers\UserPostingRightsHelper;
use Drupal\reliefweb_utility\Helpers\TaxonomyHelper;
use Drupal\reliefweb_utility\Helpers\UserHelper;

/**
 * Trait for "opportunity" documents like jobs, trainings and reports.
 *
 * @see Drupal\reliefweb_entities\DocuemntInterface
 */
trait OpportunityDocumentTrait {

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
    if (!UserHelper::userHasRoles(['editor'], $user) && $status === 'pending') {
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
        $right = UserPostingRightsHelper::getUserConsolidatedPostingRight($user, $this->bundle(), $sources);

        // Update the status based on the user's right.
        // Note: we don't use `t()` because those are log messages for editors.
        switch ($right['name']) {
          // Unverified for some sources => pending + flag.
          case 'unverified':
            $status = 'pending';
            $message = strtr('Unverified user for @sources.', [
              '@sources' => implode(', ', TaxonomyHelper::getSourceShortnames($right['sources'])),
            ]);
            break;

          // Blocked for some sources => refused + flag.
          case 'blocked':
            $status = 'refused';
            $message = strtr('Blocked user for @sources.', [
              '@sources' => implode(', ', TaxonomyHelper::getSourceShortnames($right['sources'])),
            ]);
            break;

          // Allowed for all sources => pending.
          case 'allowed':
            $status = 'pending';
            break;

          // Trusted for all the sources => published.
          case 'trusted':
            $status = 'published';
            break;
        }

        $this->setModerationStatus($status);

        // Update the log message.
        if (!empty($message)) {
          $revision_log_field = $this->getEntityType()
            ->getRevisionMetadataKey('revision_log_message');

          if (!empty($revision_log_field)) {
            $log = trim($this->{$revision_log_field}->value ?? '');
            $log = $message . (!empty($log) ? ' ' . $log : '');
            $this->{$revision_log_field}->value = $log;
          }
        }
      }
    }
  }

  /**
   * Update the status for the entity based on the expiration date.
   */
  protected function updateModerationStatusFromExpirationDate() {
    if ($this->getModerationStatus() === 'published' && $this->hasExpired()) {
      $this->setModerationStatus('expired');
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

  /**
   * Update creation date when the opportunity is published for the first time.
   */
  protected function updateDateWhenPublished() {
    if ($this->id() === NULL || $this->getModerationStatus() !== 'published') {
      return;
    }

    $entity_type = $this->getEntityType();
    $table = $entity_type->getRevisionDataTable();
    $id_field = $entity_type->getKey('id');

    $previously_published = \Drupal::database()
      ->select($table, $table)
      ->fields($table, [$entity_type->getKey('revision')])
      ->condition($table . '.' . $id_field, $this->id(), '=')
      ->condition($table . '.moderation_status', 'published', '=')
      ->range(0, 1)
      ->execute()
      ?->fetchField();

    // Update publication date if published for the first time.
    if (empty($previously_published)) {
      $this->setCreatedTime($this->getChangedTime());
    }
  }

}
