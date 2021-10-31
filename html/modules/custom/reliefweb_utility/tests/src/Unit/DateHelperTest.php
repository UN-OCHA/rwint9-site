<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_utility\Unit;

use DateTime;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\reliefweb_utility\Helpers\DateHelper;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManager;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests date helper.
 *
 * @covers \Drupal\reliefweb_utility\Helpers\DateHelper
 */
class DateHelperTest extends UnitTestCase {

  /**
   * The language manager.
   */
  protected $language_manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $sub = $this->prophesize(LanguageInterface::class);
    $sub->getId()->willReturn('und');
    $this->language_manager = $this->prophesize(LanguageManager::class);
    $this->language_manager->getCurrentLanguage()->willReturn($sub->reveal());
    $container = new ContainerBuilder();
    \Drupal::setContainer($container);
    $container->set('language_manager', $this->language_manager->reveal());
  }

  /**
   * Test get date time timestamp.
   *
   * @covers \Drupal\reliefweb_utility\Helpers\DateHelper::getDateTimeStamp
   */
  public function testGetTimeStamp() {
    $this->assertEquals(DateHelper::getDateTimeStamp(''), NULL);

    $date = new DateTime();
    $this->assertEquals(DateHelper::getDateTimeStamp($date), $date->getTimestamp());

    $date = new DrupalDateTime();
    $this->assertEquals(DateHelper::getDateTimeStamp($date), $date->getTimestamp());

    $date = 'abcd';
    $this->assertEquals(DateHelper::getDateTimeStamp($date), NULL);

    $date = '2021-10-11';
    $this->assertEquals(DateHelper::getDateTimeStamp($date), 1633910400);

    $date = 1633910411;
    $this->assertEquals(DateHelper::getDateTimeStamp($date), 1633910411);
  }

  /**
   * Test date format.
   *
   * @covers \Drupal\reliefweb_utility\Helpers\DateHelper::format
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
    $this->assertEquals(DateHelper::format($date, 'custom', 'c'), '2021-10-29T00:00:00+00:00');
  }
}
