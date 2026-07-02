<?php

namespace Drupal\reliefweb_guidelines\Services;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\pathauto\PathautoState;
use Drupal\taxonomy\TermInterface;

/**
 * Migrates legacy guideline entities to nodes and taxonomy terms.
 */
class GuidelineMigrationService {

  use StringTranslationTrait;

  /**
   * State key for migration map.
   */
  public const STATE_KEY = 'reliefweb_guidelines.migrated';

  /**
   * Fallback user when legacy author/revision user is missing or invalid.
   */
  private const SYSTEM_USER_ID = 2;

  /**
   * Constructs a GuidelineMigrationService.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected StateInterface $state,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Check if the legacy guideline entity type is available.
   */
  public function legacySourceAvailable(): bool {
    return $this->entityTypeManager->hasDefinition('guideline');
  }

  /**
   * Check if migration has completed successfully.
   */
  public function isMigrated(): bool {
    $state = $this->state->get(static::STATE_KEY);
    return !empty($state) && !empty($state['complete']);
  }

  /**
   * Check if a previous migration was interrupted.
   */
  public function isMigrationInProgress(): bool {
    $state = $this->state->get(static::STATE_KEY);
    return !empty($state) && empty($state['complete']);
  }

  /**
   * Check for migrated entities without a completed migration state.
   */
  public function hasOrphanedEntities(): bool {
    if ($this->isMigrated()) {
      return FALSE;
    }

    $node_count = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'guideline')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    if ($node_count > 0) {
      return TRUE;
    }

    $term_count = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->condition('vid', 'guideline_list')
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->count()
      ->execute();

    return $term_count > 0;
  }

  /**
   * Get migration state data.
   */
  public function getMigrationState(): array {
    return $this->state->get(static::STATE_KEY, []);
  }

  /**
   * Run migration.
   *
   * @param bool $dry_run
   *   Report counts without saving.
   * @param bool $verify
   *   Compare legacy and migrated counts after migration.
   * @param bool $force
   *   Re-run migration, rolling back previous state first.
   * @param callable|null $progress
   *   Optional callback invoked with a status message after each entity.
   *
   * @return array
   *   Result summary.
   */
  public function migrate(bool $dry_run = FALSE, bool $verify = FALSE, bool $force = FALSE, ?callable $progress = NULL): array {
    if (!$this->legacySourceAvailable()) {
      throw new \RuntimeException('Legacy guideline entity type is not available. Is the guidelines module installed?');
    }

    if ($this->isMigrationInProgress() && !$force) {
      throw new \RuntimeException('Migration previously failed or was interrupted. Run `drush reliefweb:guidelines-migrate-rollback` or `drush reliefweb:guidelines-migrate-rollback --orphans` then retry.');
    }

    if ($this->isMigrated() && !$force) {
      throw new \RuntimeException('Guidelines have already been migrated. Use --force to run again.');
    }

    $storage = $this->entityTypeManager->getStorage('guideline');
    $list_ids = $storage->getQuery()
      ->condition('type', 'guideline_list')
      ->sort('weight', 'ASC')
      ->accessCheck(FALSE)
      ->execute();
    $guideline_ids = $storage->getQuery()
      ->condition('type', 'field_guideline')
      ->sort('weight', 'ASC')
      ->accessCheck(FALSE)
      ->execute();

    $summary = [
      'lists' => count($list_ids),
      'guidelines' => count($guideline_ids),
      'lists_created' => 0,
      'guidelines_created' => 0,
      'revisions_created' => 0,
      'dry_run' => $dry_run,
    ];

    if ($dry_run) {
      return $summary;
    }

    if ($force) {
      if ($this->isMigrated()) {
        $this->rollback(FALSE);
      }
      elseif ($this->isMigrationInProgress()) {
        $this->rollbackFromState($this->getMigrationState(), FALSE);
        $this->state->delete(static::STATE_KEY);
      }
      elseif ($this->hasOrphanedEntities()) {
        $this->rollbackOrphans(FALSE);
      }
    }

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $node_storage = $this->entityTypeManager->getStorage('node');

    $list_map = [];
    $aliases = [];
    $lists = $storage->loadMultiple($list_ids);

    try {
      foreach ($lists as $legacy_list) {
        $term = $this->migrateLegacyRevisions(
          $storage,
          $term_storage,
          (int) $legacy_list->id(),
          fn (ContentEntityInterface $legacy_revision, ContentEntityInterface $legacy_default) => $this->mapLegacyListRevisionValues($legacy_revision, $legacy_default),
        );
        $this->preserveTermMetadata($term, $legacy_list);
        $list_map[$legacy_list->id()] = (int) $term->id();
        $revision_count = $this->countEntityRevisions('taxonomy_term', (int) $term->id());
        $summary['lists_created']++;
        $summary['revisions_created'] += $revision_count;
        $this->saveMigrationState($list_map, [], $aliases, FALSE);
        if ($progress !== NULL) {
          $progress(sprintf(
            'Migrated guideline list "%s" (legacy %d → term %d, %d revisions)',
            $legacy_list->label(),
            $legacy_list->id(),
            $term->id(),
            $revision_count,
          ));
        }
      }

      $guideline_map = [];
      $guidelines = $storage->loadMultiple($guideline_ids);

      foreach ($guidelines as $legacy) {
        $parent_ids = array_column($legacy->get('parent')->getValue(), 'target_id');
        $list_id = reset($parent_ids);
        if (empty($list_id) || !isset($list_map[$list_id])) {
          $this->loggerFactory->get('reliefweb_guidelines')->warning('Skipping guideline @id: missing parent list.', ['@id' => $legacy->id()]);
          continue;
        }

        $node = $this->migrateLegacyRevisions(
          $storage,
          $node_storage,
          (int) $legacy->id(),
          fn (ContentEntityInterface $legacy_revision, ContentEntityInterface $legacy_default) => $this->mapLegacyGuidelineRevisionValues($legacy_revision, $legacy_default, $list_map),
          TRUE,
        );
        $this->preserveNodeMetadata($node, $legacy);
        $this->transferNodePathAlias((int) $legacy->id(), $node, $aliases);
        $guideline_map[$legacy->id()] = (int) $node->id();
        $revision_count = $this->countEntityRevisions('node', (int) $node->id());
        $summary['guidelines_created']++;
        $summary['revisions_created'] += $revision_count;
        $this->saveMigrationState($list_map, $guideline_map, $aliases, FALSE);
        $alias = $aliases[$legacy->id()]['alias'] ?? '(none)';
        if ($progress !== NULL) {
          $progress(sprintf(
            'Migrated guideline "%s" (legacy %d → node %d, %d revisions, alias %s)',
            $legacy->label(),
            $legacy->id(),
            $node->id(),
            $revision_count,
            $alias,
          ));
        }
      }

      $this->saveMigrationState($list_map, $guideline_map, $aliases, TRUE);
    }
    catch (\Throwable $exception) {
      $partial = $this->getMigrationState();
      if (!empty($partial['lists']) || !empty($partial['guidelines'])) {
        $this->rollbackFromState($partial, TRUE);
      }
      $this->state->delete(static::STATE_KEY);
      throw $exception;
    }

    if ($verify) {
      $summary['verify'] = $this->verify();
    }

    return $summary;
  }

  /**
   * Persist migration state incrementally or on completion.
   */
  protected function saveMigrationState(array $lists, array $guidelines, array $aliases, bool $complete): void {
    $existing = $this->getMigrationState();
    $state = [
      'lists' => $lists,
      'guidelines' => $guidelines,
      'aliases' => $aliases,
      'complete' => $complete,
      'started_at' => $existing['started_at'] ?? time(),
    ];
    if ($complete) {
      $state['migrated_at'] = time();
    }
    $this->state->set(static::STATE_KEY, $state);
  }

  /**
   * Replay legacy revisions onto a target entity.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $legacy_storage
   *   Legacy guideline storage.
   * @param \Drupal\Core\Entity\EntityStorageInterface $target_storage
   *   Target entity storage.
   * @param int $legacy_id
   *   Legacy entity ID.
   * @param callable $map_values
   *   Maps a legacy revision to target field values.
   * @param bool $is_node
   *   Whether the target is a node (needs uid migration).
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   Target entity after all revisions are saved.
   */
  protected function migrateLegacyRevisions(
    EntityStorageInterface $legacy_storage,
    EntityStorageInterface $target_storage,
    int $legacy_id,
    callable $map_values,
    bool $is_node = FALSE,
  ): ContentEntityInterface {
    $revision_map = $legacy_storage->getQuery()
      ->allRevisions()
      ->condition('id', $legacy_id)
      ->sort('vid', 'ASC')
      ->accessCheck(FALSE)
      ->execute();

    if (empty($revision_map)) {
      throw new \RuntimeException(sprintf('Legacy guideline %d has no revisions.', $legacy_id));
    }

    $revision_ids = array_keys($revision_map);
    $legacy_default = $legacy_storage->load($legacy_id);
    if (!$legacy_default instanceof ContentEntityInterface) {
      throw new \RuntimeException(sprintf('Legacy guideline %d could not be loaded.', $legacy_id));
    }

    $entity = NULL;
    $total = count($revision_ids);

    foreach ($revision_ids as $index => $vid) {
      $legacy_revision = $legacy_storage->loadRevision($vid);
      if (!$legacy_revision instanceof ContentEntityInterface) {
        continue;
      }

      $is_last = ($index === $total - 1);

      if ($entity === NULL) {
        $entity = $target_storage->create($map_values($legacy_revision, $legacy_default));
      }
      else {
        $entity = $target_storage->load($entity->id());
        $entity->setNewRevision(TRUE);
        $this->applyMappedValues($entity, $map_values($legacy_revision, $legacy_default));
      }

      if (!$is_last) {
        $entity->isDefaultRevision(FALSE);
      }

      $this->applyLegacyRevisionMetadata($entity, $legacy_revision);

      if ($is_node && $entity instanceof NodeInterface) {
        $entity->setOwnerId($this->resolveLegacyUserId((int) $legacy_revision->getOwnerId()));
      }

      if ($is_node && $is_last && $entity->hasField('path')) {
        $entity->path->pathauto = PathautoState::SKIP;
      }

      $entity->save();
    }

    return $entity;
  }

  /**
   * Apply mapped field values to an existing entity.
   */
  protected function applyMappedValues(ContentEntityInterface $entity, array $values): void {
    foreach ($values as $field => $value) {
      $entity->set($field, $value);
    }
  }

  /**
   * Apply revision metadata from a legacy revision.
   */
  protected function applyLegacyRevisionMetadata(ContentEntityInterface $entity, ContentEntityInterface $legacy_revision): void {
    $entity->setRevisionUserId($this->resolveLegacyUserId((int) $legacy_revision->getRevisionUserId()));
    $entity->setRevisionCreationTime((int) $legacy_revision->getRevisionCreationTime());
    $entity->setRevisionLogMessage($legacy_revision->getRevisionLogMessage() ?? '');
  }

  /**
   * Resolve a legacy user ID, falling back to the system user.
   */
  protected function resolveLegacyUserId(?int $uid): int {
    $uid = (int) ($uid ?? 0);
    if ($uid > 0 && $this->entityTypeManager->getStorage('user')->load($uid)) {
      return $uid;
    }
    return self::SYSTEM_USER_ID;
  }

  /**
   * Map legacy guideline_list revision values to a taxonomy term.
   */
  protected function mapLegacyListRevisionValues(ContentEntityInterface $legacy_revision, ContentEntityInterface $legacy_default): array {
    $values = [
      'vid' => 'guideline_list',
      'name' => $legacy_revision->label(),
      'weight' => (int) $legacy_default->get('weight')->value,
      'status' => (int) $legacy_revision->get('status')->value,
      'moderation_status' => $legacy_revision->get('moderation_status')->value ?? 'published',
    ];

    if (!$legacy_revision->get('field_role')->isEmpty()) {
      $values['field_role'] = $legacy_revision->get('field_role')->target_id;
    }

    return $values;
  }

  /**
   * Map legacy field_guideline revision values to a guideline node.
   */
  protected function mapLegacyGuidelineRevisionValues(ContentEntityInterface $legacy_revision, ContentEntityInterface $legacy_default, array $list_map): array {
    $parent_ids = array_column($legacy_revision->get('parent')->getValue(), 'target_id');
    $list_id = reset($parent_ids);

    $values = [
      'type' => 'guideline',
      'title' => $legacy_revision->label() ?: ($legacy_revision->get('field_title')->value ?? ''),
      'uid' => $this->resolveLegacyUserId((int) $legacy_revision->getOwnerId()),
      'status' => (int) $legacy_revision->get('status')->value,
      'moderation_status' => $legacy_revision->get('moderation_status')->value ?? 'published',
      'field_guideline_list' => $list_map[$list_id] ?? NULL,
      'field_weight' => (int) $legacy_default->get('weight')->value,
    ];

    if (!$legacy_revision->get('field_short_link')->isEmpty()) {
      $values['field_short_link'] = $legacy_revision->get('field_short_link')->value;
    }
    if (!$legacy_revision->get('field_description')->isEmpty()) {
      $values['field_description'] = $legacy_revision->get('field_description')->getValue();
    }
    if (!$legacy_revision->get('field_field')->isEmpty()) {
      $values['field_field'] = $legacy_revision->get('field_field')->getValue();
    }
    if (!$legacy_revision->get('field_images')->isEmpty()) {
      $values['field_images'] = $legacy_revision->get('field_images')->getValue();
    }
    if (!$legacy_revision->get('field_links')->isEmpty()) {
      $values['field_links'] = $legacy_revision->get('field_links')->getValue();
    }

    return $values;
  }

  /**
   * Preserve term created/changed after revision replay.
   */
  protected function preserveTermMetadata(TermInterface $term, ContentEntityInterface $legacy): void {
    $this->database->update('taxonomy_term_field_data')
      ->fields([
        'created' => (int) $legacy->getCreatedTime(),
        'changed' => (int) $legacy->getChangedTime(),
      ])
      ->condition('tid', $term->id())
      ->execute();
  }

  /**
   * Preserve node created/changed/uid after revision replay.
   */
  protected function preserveNodeMetadata(NodeInterface $node, ContentEntityInterface $legacy): void {
    $uid = $this->resolveLegacyUserId((int) $legacy->getOwnerId());
    $changed = (int) $legacy->getChangedTime();

    $this->database->update('node_field_data')
      ->fields([
        'created' => (int) $legacy->getCreatedTime(),
        'changed' => $changed,
        'uid' => $uid,
      ])
      ->condition('nid', $node->id())
      ->execute();

    $this->database->update('node_field_revision')
      ->fields(['changed' => $changed])
      ->condition('vid', $node->getRevisionId())
      ->execute();
  }

  /**
   * Transfer path alias from legacy guideline entity to migrated node.
   */
  protected function transferNodePathAlias(int $legacy_id, NodeInterface $node, array &$aliases): void {
    $legacy_path = '/guideline/' . $legacy_id;
    $alias_storage = $this->entityTypeManager->getStorage('path_alias');

    $existing = $alias_storage->loadByProperties(['path' => $legacy_path]);
    $alias_value = NULL;
    $langcode = $node->language()->getId();

    foreach ($existing as $alias_entity) {
      $alias_value = $alias_entity->get('alias')->value;
      $langcode = $alias_entity->get('langcode')->value ?? $langcode;
      $alias_entity->delete();
    }

    if ($alias_value === NULL && $node->hasField('field_short_link') && !$node->get('field_short_link')->isEmpty()) {
      $alias_value = '/guideline/' . $node->get('field_short_link')->value;
    }

    if ($alias_value === NULL) {
      return;
    }

    $aliases[$legacy_id] = [
      'path' => $legacy_path,
      'alias' => $alias_value,
      'langcode' => $langcode,
    ];

    foreach ($alias_storage->loadByProperties(['path' => '/node/' . $node->id()]) as $node_alias) {
      $node_alias->delete();
    }

    $alias_storage->create([
      'path' => '/node/' . $node->id(),
      'alias' => $alias_value,
      'langcode' => $langcode,
    ])->save();
  }

  /**
   * Count revisions for a migrated entity.
   */
  protected function countEntityRevisions(string $entity_type_id, int $entity_id): int {
    $id_field = $entity_type_id === 'node' ? 'nid' : 'tid';
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    return count($storage->getQuery()
      ->allRevisions()
      ->condition($id_field, $entity_id)
      ->accessCheck(FALSE)
      ->execute());
  }

  /**
   * Count legacy revisions for given entity IDs.
   */
  protected function countLegacyRevisions(array $legacy_ids): int {
    if (empty($legacy_ids)) {
      return 0;
    }
    return (int) $this->database->select('guideline_revision', 'gr')
      ->condition('id', $legacy_ids, 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Count migrated revisions for nodes or terms of a bundle/vocabulary.
   */
  protected function countMigratedRevisions(string $entity_type_id, string $bundle): int {
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $bundle_field = $entity_type_id === 'node' ? 'type' : 'vid';
    $ids = $storage->getQuery()
      ->condition($bundle_field, $bundle)
      ->accessCheck(FALSE)
      ->execute();

    $count = 0;
    foreach ($ids as $id) {
      $count += $this->countEntityRevisions($entity_type_id, (int) $id);
    }
    return $count;
  }

  /**
   * Verify migrated entity counts.
   */
  public function verify(): array {
    $state = $this->getMigrationState();
    $legacy_lists = 0;
    $legacy_guidelines = 0;
    $legacy_list_ids = [];
    $legacy_guideline_ids = [];

    if ($this->legacySourceAvailable()) {
      $legacy_storage = $this->entityTypeManager->getStorage('guideline');
      $legacy_list_ids = $legacy_storage->getQuery()
        ->condition('type', 'guideline_list')
        ->accessCheck(FALSE)
        ->execute();
      $legacy_guideline_ids = $legacy_storage->getQuery()
        ->condition('type', 'field_guideline')
        ->accessCheck(FALSE)
        ->execute();
      $legacy_lists = count($legacy_list_ids);
      $legacy_guidelines = count($legacy_guideline_ids);
    }

    $term_count = count($this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->condition('vid', 'guideline_list')
      ->accessCheck(FALSE)
      ->execute());
    $node_count = count($this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'guideline')
      ->accessCheck(FALSE)
      ->execute());

    $legacy_revision_count = $this->countLegacyRevisions(array_merge($legacy_list_ids, $legacy_guideline_ids));
    $migrated_revision_count = $this->countMigratedRevisions('taxonomy_term', 'guideline_list')
      + $this->countMigratedRevisions('node', 'guideline');

    return [
      'legacy_lists' => $legacy_lists,
      'legacy_guidelines' => $legacy_guidelines,
      'migrated_lists' => $term_count,
      'migrated_guidelines' => $node_count,
      'mapped_lists' => count($state['lists'] ?? []),
      'mapped_guidelines' => count($state['guidelines'] ?? []),
      'lists_match' => $legacy_lists === $term_count,
      'guidelines_match' => $legacy_guidelines === $node_count,
      'legacy_revisions' => $legacy_revision_count,
      'migrated_revisions' => $migrated_revision_count,
      'revisions_match' => $legacy_revision_count === $migrated_revision_count,
    ];
  }

  /**
   * Roll back migrated nodes and terms.
   */
  public function rollback(bool $require_legacy = TRUE): array {
    if ($require_legacy && !$this->legacySourceAvailable()) {
      throw new \RuntimeException('Cannot roll back after the guidelines module has been uninstalled.');
    }

    $state = $this->getMigrationState();
    if (empty($state)) {
      throw new \RuntimeException('No migration state found. Nothing to roll back.');
    }

    $result = $this->rollbackFromState($state, $require_legacy);
    $this->state->delete(static::STATE_KEY);

    return $result;
  }

  /**
   * Roll back from a migration state array.
   */
  public function rollbackFromState(array $state, bool $require_legacy = TRUE): array {
    if ($require_legacy && !$this->legacySourceAvailable()) {
      throw new \RuntimeException('Cannot roll back after the guidelines module has been uninstalled.');
    }

    $aliases = $state['aliases'] ?? [];

    $deleted_nodes = 0;
    $deleted_terms = 0;

    if (!empty($state['guidelines'])) {
      $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($state['guidelines']);
      foreach ($nodes as $node) {
        $node->delete();
        $deleted_nodes++;
      }
    }

    if (!empty($state['lists'])) {
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($state['lists']);
      foreach ($terms as $term) {
        $term->delete();
        $deleted_terms++;
      }
    }

    if (!empty($aliases) && $this->legacySourceAvailable()) {
      $this->restoreLegacyPathAliases($aliases);
    }

    return [
      'deleted_nodes' => $deleted_nodes,
      'deleted_terms' => $deleted_terms,
      'restored_aliases' => count($aliases),
    ];
  }

  /**
   * Delete all migrated guideline nodes and list terms (dev recovery).
   */
  public function rollbackOrphans(bool $require_legacy = TRUE): array {
    if ($require_legacy && !$this->legacySourceAvailable()) {
      throw new \RuntimeException('Cannot roll back after the guidelines module has been uninstalled.');
    }

    $node_storage = $this->entityTypeManager->getStorage('node');
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $node_ids = $node_storage->getQuery()
      ->condition('type', 'guideline')
      ->accessCheck(FALSE)
      ->execute();
    $term_ids = $term_storage->getQuery()
      ->condition('vid', 'guideline_list')
      ->accessCheck(FALSE)
      ->execute();

    $deleted_nodes = 0;
    foreach ($node_storage->loadMultiple($node_ids) as $node) {
      $node->delete();
      $deleted_nodes++;
    }

    $deleted_terms = 0;
    foreach ($term_storage->loadMultiple($term_ids) as $term) {
      $term->delete();
      $deleted_terms++;
    }

    if ($this->state->get(static::STATE_KEY) !== NULL) {
      $this->state->delete(static::STATE_KEY);
    }

    if ($deleted_nodes > 0 || $deleted_terms > 0) {
      $this->loggerFactory->get('reliefweb_guidelines')->warning(
        'Orphan rollback removed @nodes guideline nodes and @terms list terms. Legacy path aliases deleted during a partial migration cannot be restored without migration state.',
        ['@nodes' => $deleted_nodes, '@terms' => $deleted_terms],
      );
    }

    return [
      'deleted_nodes' => $deleted_nodes,
      'deleted_terms' => $deleted_terms,
      'restored_aliases' => 0,
    ];
  }

  /**
   * Recreate legacy path aliases after rollback.
   */
  protected function restoreLegacyPathAliases(array $aliases): void {
    $alias_storage = $this->entityTypeManager->getStorage('path_alias');

    foreach ($aliases as $metadata) {
      if (empty($metadata['path']) || empty($metadata['alias'])) {
        continue;
      }

      $existing_path = $alias_storage->loadByProperties(['path' => $metadata['path']]);
      $existing_alias = $alias_storage->loadByProperties(['alias' => $metadata['alias']]);
      if (!empty($existing_path) || !empty($existing_alias)) {
        continue;
      }

      $alias_storage->create([
        'path' => $metadata['path'],
        'alias' => $metadata['alias'],
        'langcode' => $metadata['langcode'] ?? 'en',
      ])->save();
    }
  }

}
