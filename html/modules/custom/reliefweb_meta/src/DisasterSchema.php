<?php

declare(strict_types=1);

namespace Drupal\reliefweb_meta;

use Spatie\SchemaOrg\Event;

/**
 * Schema.org Event for a disaster.
 */
class DisasterSchema extends Event {

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    // Preserve the 'Event' type since it's an official type and there is
    // no DisasterEvent type.
    return 'Event';
  }

  /**
   * {@inheritdoc}
   */
  protected function serializeIdentifier(): void {
    // Do not merge the 'identifier' property with the '@id' property so that
    // we can add the Glide numbers as extra identifiers to the event.
  }

}
