<?php

namespace Drupal\reliefweb_guidelines\Entity\Node;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\reliefweb_guidelines\Entity\Taxonomy\GuidelineList;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_moderation\EntityModeratedTrait;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_revisions\EntityRevisionedTrait;

/**
 * Bundle class for guideline nodes.
 */
class Guideline extends Node implements EntityModeratedInterface, EntityRevisionedInterface {

  use EntityModeratedTrait;
  use EntityRevisionedTrait;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);

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
  public function getShortId(): string {
    if ($this->hasField('field_short_link') && !$this->get('field_short_link')->isEmpty()) {
      return (string) $this->field_short_link->value;
    }
    return (string) $this->id();
  }

  /**
   * Get the link to the guidelines page.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $title
   *   Link title.
   *
   * @return array|null
   *   Render array for the link to the guidelines page.
   */
  public function getLinkToGuidelines($title = ''): ?array {
    $title = $title ?: new TranslatableMarkup('View on guidelines page');
    return Link::fromTextAndUrl($title, Url::fromUserInput('/guidelines', [
      'fragment' => $this->getShortId(),
      'attributes' => [
        'target' => '_blank',
        'rel' => 'noopener',
      ],
    ]))->toRenderable();
  }

  /**
   * Generate a random unique short ID.
   *
   * @return string
   *   Random unique short ID.
   */
  public static function generateShortId(): string {
    static $shortids;
    static $characters;

    if (!isset($shortids)) {
      $shortids = \Drupal::database()
        ->select('node__field_short_link', 'f')
        ->fields('f', ['field_short_link_value'])
        ->distinct()
        ->execute()
        ?->fetchCol() ?? [];
      $shortids = array_flip($shortids);
    }

    if (!isset($characters)) {
      $characters = array_merge(range(48, 57), range(65, 90), range(97, 122));
    }

    $counter = 0;
    do {
      if ($counter === 100) {
        throw new \RuntimeException('Unable to generate a unique short ID');
      }
      $counter++;
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
   * Get the list this guideline belongs to.
   *
   * @return \Drupal\reliefweb_guidelines\Entity\Taxonomy\GuidelineList|null
   *   Guideline list term.
   */
  public function getGuidelineList() {
    $list = $this->get('field_guideline_list')->entity;
    return $list instanceof GuidelineList ? $list : NULL;
  }

  /**
   * Get a render array for the guideline list link.
   *
   * @return array|null
   *   Link to the guideline list.
   */
  public function getGuidelineListLink(): ?array {
    return $this->getGuidelineList()?->toLink()?->toRenderable();
  }

  /**
   * {@inheritdoc}
   *
   * @todo Add granular permissions for revision history access.
   */
  public function getHistory() {
    if (!$this->access('update')) {
      return [];
    }
    return $this->getEntityHistoryService()->getEntityHistory($this);
  }

  /**
   * {@inheritdoc}
   *
   * @todo Add granular permissions for revision history access.
   */
  public function getHistoryContent() {
    if (!$this->access('update')) {
      return [];
    }
    return $this->getEntityHistoryService()->getEntityHistoryContent($this);
  }

}
