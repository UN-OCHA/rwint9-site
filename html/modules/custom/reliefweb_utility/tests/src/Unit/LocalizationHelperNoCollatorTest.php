<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManager;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Drupal\Tests\reliefweb_utility\Unit\Stub\LocalizationHelperStubNoCollator;

/**
 * Tests localization helper.
 *
 * @covers \Drupal\reliefweb_utility\Helpers\LocalizationHelper
 */
class LocalizationHelperNoCollatorTest extends UnitTestCase {

  /**
   * The language manager.
   */
  protected $language_manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $sub = $this->prophesize(LanguageInterface::class);
    $sub->getId()->willReturn('no');
    $this->language_manager = $this->prophesize(LanguageManager::class);
    $this->language_manager->getCurrentLanguage()->willReturn($sub->reveal());
    $container = new ContainerBuilder();
    \Drupal::setContainer($container);
    $container->set('language_manager', $this->language_manager->reveal());
  }

  /**
   * Test localization sort.
   *
   * @covers \Drupal\reliefweb_utility\Helpers\LocalizationHelper::collatedSort
   */
  public function testLocalizationHelpercollatedSortNoCollator() {
    $items = [];
    $sorted = $items;
    $isSorted = LocalizationHelperStubNoCollator::collatedSort($items, NULL, 'no');
    $this->assertTrue($isSorted);
    $this->assertEquals($items, $sorted);

    $items = ['c', 'b', 'a'];
    $sorted = ['a', 'b', 'c'];
    $isSorted = LocalizationHelperStubNoCollator::collatedSort($items, NULL, 'no');
    $this->assertTrue($isSorted);
    $this->assertEquals($items, $sorted);

    $items = [
      ['value' => 'c'],
      ['value' => 'b'],
      ['value' => 'a'],
    ];
    $sorted = [
      ['value' => 'a'],
      ['value' => 'b'],
      ['value' => 'c'],
    ];
    $isSorted = LocalizationHelperStubNoCollator::collatedSort($items, 'value', 'no');
    $this->assertTrue($isSorted);
    $this->assertEquals($items, $sorted);
  }

  /**
   * Test localization asort.
   *
   * @covers \Drupal\reliefweb_utility\Helpers\LocalizationHelper::collatedAsort
   */
  public function testLocalizationHelpercollatedAsortNoCollator() {
    $items = [];
    $sorted = $items;
    $isSorted = LocalizationHelperStubNoCollator::collatedAsort($items);
    $this->assertTrue($isSorted);
    $this->assertEquals($items, $sorted);

    $items = ['c', 'b', 'a'];
    $sorted = [
      2 => 'a',
      1 => 'b',
      0 => 'c',
    ];
    $isSorted = LocalizationHelperStubNoCollator::collatedAsort($items);
    $this->assertTrue($isSorted);
    $this->assertEquals($items, $sorted);

    $items = [
      ['value' => 'c'],
      ['value' => 'b'],
      ['value' => 'a'],
    ];
    $sorted = [
      2 => ['value' => 'a'],
      1 => ['value' => 'b'],
      0 => ['value' => 'c'],
    ];
    $isSorted = LocalizationHelperStubNoCollator::collatedAsort($items, 'value');
    $this->assertTrue($isSorted);
    $this->assertEquals($items, $sorted);
  }

  /**
   * Test localization ksort.
   *
   * @covers \Drupal\reliefweb_utility\Helpers\LocalizationHelper::collatedKsort
   */
  public function testLocalizationHelpercollatedKsortNoCollator() {
    $items = [];
    $sorted = $items;
    $isSorted = LocalizationHelperStubNoCollator::collatedKsort($items);
    $this->assertTrue($isSorted);
    $this->assertEquals($items, $sorted);

    $items = [
      'b' => 'c',
      'a' => 'b',
      'c' => 'a',
    ];
    $sorted = [
      'a' => 'b',
      'b' => 'c',
      'c' => 'a',
    ];
    $isSorted = LocalizationHelperStubNoCollator::collatedKsort($items);
    $this->assertTrue($isSorted);
    $this->assertEquals($items, $sorted);

    $items = [
      'b' => ['value' => 'c'],
      'a' => ['value' => 'b'],
      'c' => ['value' => 'a'],
    ];
    $sorted = [
      'a' => ['value' => 'b'],
      'b' => ['value' => 'c'],
      'c' => ['value' => 'a'],
    ];
    $isSorted = LocalizationHelperStubNoCollator::collatedKsort($items, 'value');
    $this->assertTrue($isSorted);
    $this->assertEquals($items, $sorted);
  }

}
