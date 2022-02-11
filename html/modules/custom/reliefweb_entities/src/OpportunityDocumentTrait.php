<?php

namespace Drupal\reliefweb_entities;

use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\reliefweb_entities\Entity\Source;
use Drupal\reliefweb_moderation\Helpers\UserPostingRightsHelper;
use Drupal\reliefweb_utility\Helpers\TaxonomyHelper;
use Drupal\reliefweb_utility\Helpers\UserHelper;

/**
 * Trait for "opportunity" documents like jobs and training.
 *
 * @see Drupal\reliefweb_entities\DocuemntInterface
 */
trait OpportunityDocumentTrait {

  /**
   * Update the status for the entity based on the user posting rights.
   */
  protected function updateModerationStatusFromPostingRights() {
    $user = $this->getRevisionUser();
    $status = $this->getModerationStatus();

    // For non editors, we determine the real status based on the user
    // posting rights for the selected sources.
    if (!UserHelper::userHasRoles(['editor']) && $status === 'pending') {
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
   * Update the status of the sources when publishing an opportunity.
   */
  protected function updateSourceModerationStatus() {
    if (!$this->hasField('field_source') || $this->field_source->isEmpty()) {
      return;
    }

    if (!($this instanceof EntityPublishedInterface) || !$this->isPublished()) {
      return;
    }

    // Make the inactive or archive sources active when an apportunity is
    // published.
    foreach ($this->field_source as $item) {
      $source = $item->entity;
      if (empty($source) || !($source instanceof Source)) {
        continue;
      }

      if (in_array($source->getModerationStatus(), ['inactive', 'archive'])) {
        $entity->notifications_content_disable = TRUE;
        $source->setModerationStatus('active');
        $source->setNewRevision(TRUE);
        $source->setRevisionLogMessage('Automatic status update');
        $source->setRevisionUserId(2);
        $source->save();
      }
    }
  }

}
