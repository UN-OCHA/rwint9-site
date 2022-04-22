<?php

namespace Drupal\reliefweb_guidelines\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\guidelines\Entity\Guideline as GuidelineBase;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\EntityModeratedTrait;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_revisions\EntityRevisionedTrait;

/**
 * Bundle class for the guideline pages.
 */
class Guideline extends GuidelineBase implements EntityModeratedInterface, EntityRevisionedInterface {

  use EntityModeratedTrait;
  use EntityRevisionedTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);

    // Populate the field title with the guideline label.
    if ($this->hasField('field_title')) {
      $this->get('field_title')->setValue($this->label());
    }

    // Generate a unique short ID for the guideline.
    if ($this->hasField('field_short_link') && $this->get('field_short_link')->isEmpty()) {
      $this->get('field_short_link')->setValue(static::generateShortId());
    }
  }

  /**
   * Get the guideline short ID.
   *
   * @return string
   *   Guideline's short ID.
   */
  public function getShortId() {
    if ($this->hasField('field_short_link') && !$this->get('field_short_link')->isEmpty()) {
      return $this->field_short_link->value;
    }
    return $this->id();
  }

  /**
   * Generate a random unique short ID.
   *
   * @return string
   *   Random unique short ID.
   */
  public static function generateShortId() {
    static $shortids;
    static $characters;

    if (!isset($shortids)) {
      $shortids = \Drupal::database()
        ->select('guideline__field_short_link', 'f')
        ->fields('f', ['field_short_link_value'])
        ->distinct()
        ->execute()
        ?->fetchCol() ?? [];
      $shortids = array_flip($shortids);
    }

    if (!isset($characters)) {
      // 0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz.
      $characters = array_merge(range(48, 57), range(65, 90), range(97, 122));
    }

    $counter = 0;
    do {
      // Prevent unlikely infinite loop.
      if ($counter === 100) {
        throw new \RuntimeException('Unable to generate a unique short ID');
      }
      $counter++;
      // Generate a 8 characters long random string.
      $shortid = '';
      for ($i = 0; $i < 8; $i++) {
        $shortid .= chr($characters[mt_rand(0, 61)]);
      }
    } while (isset($shortids[$shortid]));

    $shortids[$shortid] = count($shortids);
    return $shortid;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultModerationStatus() {
    return 'published';
  }

  /**
   * Get the list, this guideline belongs to.
   *
   * @return \Drupal\reliefweb_guidelines\Entity\GuidelineList|null
   *   Guideline List.
   */
  public function getGuidelineList() {
    $parents = $this->getParents();
    return !empty($parents) ? reset($parents) : NULL;
  }

  /**
   * Get the list, this guideline belongs to.
   *
   * @return \Drupal\Core\GeneratedLink|null
   *   Link to the guideline list.
   */
  public function getGuidelineListLink() {
    return $this->getGuidelineList()?->toLink()?->toString();
  }

}
