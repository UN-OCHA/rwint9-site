<?php

declare(strict_types=1);

namespace Drupal\reliefweb_meta;

use Spatie\SchemaOrg\Country;

/**
 * Schema.org Country for a country.
 */
class CountrySchema extends Country {

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    // Preserve the 'Country' type.
    return 'Country';
  }

  /**
   * {@inheritdoc}
   */
  protected function serializeIdentifier(): void {
    // Do not merge the 'identifier' property with the '@id' property so that
    // we can add the ISO3 code as extra identifiers to the country.
  }

}
