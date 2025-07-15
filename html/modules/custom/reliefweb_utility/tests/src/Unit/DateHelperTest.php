<?php

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\reliefweb_utility\Helpers\DateHelper;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests date helper.
 */
#[CoversClass(DateHelper::class)]
#[Group('reliefweb_utility')]
class DateHelperTest extends UnitTestCase {

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $sub = $this->prophesize(LanguageInterface::class);
    $sub->getId()->willReturn('und');
    $this->languageManager = $this->prophesize(LanguageManager::class);
    $this->languageManager->getCurrentLanguage()->willReturn($sub->reveal());
    $container = new ContainerBuilder();
    \Drupal::setContainer($container);
    $container->set('language_manager', $this->languageManager->reveal());
  }

  /**
   * Test get date time timestamp.
   */
  public function testGetTimeStamp() {
    $this->assertEquals(DateHelper::getDateTimeStamp(''), NULL);

    $date = new \DateTime();
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

}
