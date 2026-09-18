<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_content_analyzer\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\reliefweb_moderation\EntityModeratedInterface;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;

/**
 * Combined interface for test entity mocks.
 *
 * A single createMock() target that satisfies all instanceof checks in
 * the hook class: ContentEntityInterface, EntityModeratedInterface,
 * RevisionLogInterface, and EntityRevisionedInterface.
 */
interface SeriesMatchTestEntityInterface extends
  ContentEntityInterface,
  EntityModeratedInterface,
  RevisionLogInterface,
  EntityRevisionedInterface {}
