<?php

namespace Drupal\reliefweb_user_history\Entity;

use Drupal\Component\Utility\Html;
use Drupal\Core\Url;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\user\Entity\User as UserBase;

/**
 * User entity class with revision helpers.
 */
class User extends UserBase implements EntityRevisionedInterface {

  /**
   * {@inheritdoc}
   */
  public function getHistory() {
    // No history for new entities.
    if ($this->id() === NULL) {
      return [];
    }

    // History render array.
    return [
      '#theme' => 'reliefweb_revisions_history',
      '#id' => Html::getUniqueId('rw-revisions-history-' . $this->id()),
      '#entity' => $this,
      '#url' => Url::fromRoute('reliefweb_revisions.entity.history', [
        'entity_type_id' => $this->getEntityTypeId(),
        'entity' => $this->id(),
      ])->toString(),
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => $this->getCacheTags(),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getHistoryContent() {
    return reliefweb_user_history_get_account_history_content($this);
  }

  /**
   * Get the entity's revision history cache tag.
   *
   * @see \Drupal\reliefweb_revisions\EntityRevisionedInterface::getHistoryCacheTags()
   */
  public function getHistoryCacheTag(): string {
    return implode(':', [
      'reliefweb_revisions',
      'history',
      'entity',
      $this->getEntityTypeId(),
      $this->bundle(),
      $this->id(),
    ]);
  }

  /**
   * Update the revision log message with a new message.
   *
   * @see \Drupal\reliefweb_revisions\EntityRevisionedInterface::updateRevisionLogMessage()
   */
  public function updateRevisionLogMessage(string $message, string $action = 'append', bool $skip_if_present = TRUE): void {
    // No revision log message for users since they are not revisioned.
  }

}
