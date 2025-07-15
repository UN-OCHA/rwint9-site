<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_utility\ExistingSite;

use DateTime;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\reliefweb_utility\Helpers\DateHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests date helper.
 */
#[CoversClass(DateHelper::class)]
#[Group('reliefweb_utility')]
class DateHelperTest extends ExistingSiteBase {

  /**
   * Test format.
   */
  public function testFormat() {
    $date = '';
    $this->assertEquals(DateHelper::format($date), '');

    $date = 'abcd';
    $this->assertEquals(DateHelper::format($date), '');

    $date = new DateTime();
    $this->assertEquals(DateHelper::format($date, 'custom', 'Y-m-d'), $date->format('Y-m-d'));

    $date = new DrupalDateTime();
    $this->assertEquals(DateHelper::format($date, 'custom', 'Y-m-d'), $date->format('Y-m-d'));

    $date = '2021-10-11';
    $this->assertEquals(DateHelper::format($date, 'custom', 'Y-m-d'), $date);

    $date = 1633910411;
    $this->assertEquals(DateHelper::format($date, 'custom', 'c'), '2021-10-11T00:00:11+00:00');
  }

}
