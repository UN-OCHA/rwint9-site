<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManager;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Drupal\Tests\reliefweb_utility\Unit\Stub\LocalizationHelperStubZeroError;

/**
 * Tests localization helper.
 *
 * @covers \Drupal\reliefweb_utility\Helpers\LocalizationHelper
 */
class LocalizationHelperZeroErrorTest extends UnitTestCase {

  /**
   * The language manager.
   */
  protected $language_manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $sub = $this->prophesize(LanguageInterface::class);
    $sub->getId()->willReturn('nl');
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
  public function testLocalizationHelpercollatedSortZeroError() {
    $items = [];
    $sorted = $items;
    $isSorted = LocalizationHelperStubZeroError::collatedSort($items, NULL, 'zero');
    $this->assertTrue($isSorted);
    $this->assertEquals($items, $sorted);

    $items = ['c', 'b', 'a', 'é', 'ê', 'è', 'â'];
    $sorted = ['a', 'â', 'b', 'c', 'é', 'è', 'ê'];
    $isSorted = LocalizationHelperStubZeroError::collatedSort($items, NULL, 'zero');
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
    $isSorted = LocalizationHelperStubZeroError::collatedSort($items, 'value', 'zero');
    $this->assertTrue($isSorted);
    $this->assertEquals($items, $sorted);
  }

  /**
   * Test localization asort.
   *
   * @covers \Drupal\reliefweb_utility\Helpers\LocalizationHelper::collatedAsort
   */
  public function testLocalizationHelpercollatedAsortZeroError() {
    $items = [];
    $sorted = $items;
    $isSorted = LocalizationHelperStubZeroError::collatedAsort($items);
    $this->assertTrue($isSorted);
    $this->assertEquals($items, $sorted);

    $items = ['c', 'b', 'a'];
    $sorted = [
      2 => 'a',
      1 => 'b',
      0 => 'c',
    ];
    $isSorted = LocalizationHelperStubZeroError::collatedAsort($items);
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
    $isSorted = LocalizationHelperStubZeroError::collatedAsort($items, 'value');
    $this->assertTrue($isSorted);
    $this->assertEquals($items, $sorted);
  }

  /**
   * Test localization ksort.
   *
   * @covers \Drupal\reliefweb_utility\Helpers\LocalizationHelper::collatedKsort
   */
  public function testLocalizationHelpercollatedKsortZeroError() {
    $items = [];
    $sorted = $items;
    $isSorted = LocalizationHelperStubZeroError::collatedKsort($items);
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
    $isSorted = LocalizationHelperStubZeroError::collatedKsort($items);
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
    $isSorted = LocalizationHelperStubZeroError::collatedKsort($items, 'value');
    $this->assertTrue($isSorted);
    $this->assertEquals($items, $sorted);
  }

}
