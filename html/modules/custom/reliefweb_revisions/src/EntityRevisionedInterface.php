<?php

namespace Drupal\reliefweb_revisions;

/**
 * Interface for entities with a revision history.
 */
interface EntityRevisionedInterface {

  /**
   * Get the entity's revision history.
   *
   * Note: the content will be loaded asynchronously.
   *
   * @return array
   *   Entity revision history render array.
   */
  public function getHistory();

  /**
   * Get the entity's revision history content.
   *
   * @return array
   *   Entity revision history render array.
   */
  public function getHistoryContent();

  /**
   * Get the entity's revision history cache tag.
   *
   * @return string
   *   Cache tag.
   */
  public function getHistoryCacheTag(): string;

  /**
   * Update the revision log message with a new message.
   *
   * Prefer this over setRevisionLogMessage() so append/prepend call sites share
   * deduplication and separator handling. String logic is implemented by
   * \Drupal\reliefweb_utility\Helpers\RevisionLogHelper::updateMessage().
   *
   * @param string $message
   *   The message to add to the revision log. Empty string is allowed only for
   *   the replace action (clears the log).
   * @param string $action
   *   The action to perform on the revision log message:
   *   - prepend: prepend the message to the revision log message.
   *   - append: append the message to the revision log message.
   *   - replace: replace the revision log message.
   * @param bool $skip_if_present
   *   Whether to skip if the message is already present as a full clause in
   *   the revision log (case-insensitive, whitespace-normalized equality of a
   *   clause or of the entire log — not a substring search). Pass FALSE when
   *   setting a full replacement message on a new revision.
   * @param string $separator
   *   Separator used between existing log and message for prepend/append when
   *   both sides are non-empty. Ignored for replace.
   */
  public function updateRevisionLogMessage(string $message, string $action = 'append', bool $skip_if_present = TRUE, string $separator = ' '): void;

}
