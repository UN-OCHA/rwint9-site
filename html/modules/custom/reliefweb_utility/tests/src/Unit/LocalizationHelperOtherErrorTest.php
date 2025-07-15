<?php

namespace Drupal\Tests\reliefweb_utility\Unit;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManager;
use Drupal\reliefweb_utility\Helpers\LocalizationHelper;
use Drupal\Tests\reliefweb_utility\Unit\Stub\LocalizationHelperStubOtherError;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests localization helper.
 */
#[CoversClass(LocalizationHelper::class)]
#[Group('reliefweb_utility')]
class LocalizationHelperOtherErrorTest extends UnitTestCase {

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
    $sub->getId()->willReturn('nl');
    $this->languageManager = $this->prophesize(LanguageManager::class);
    $this->languageManager->getCurrentLanguage()->willReturn($sub->reveal());
    $container = new ContainerBuilder();
    \Drupal::setContainer($container);
    $container->set('language_manager', $this->languageManager->reveal());
  }

  /**
   * Test localization sort.
   */
  public function testLocalizationHelpercollatedSortZeroError() {
    $items = [];
    $sorted = $items;
    $isSorted = LocalizationHelperStubOtherError::collatedSort($items, NULL, 'zero');
    $this->assertTrue($isSorted);
    $this->assertEquals($items, $sorted);

    $items = ['c', 'b', 'a', 'é', 'ê', 'è', 'â'];
    $sorted = ['a', 'b', 'c', 'â', 'è', 'é', 'ê'];
    $isSorted = LocalizationHelperStubOtherError::collatedSort($items, NULL, 'zero');
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
    $isSorted = LocalizationHelperStubOtherError::collatedSort($items, 'value', 'zero');
    $this->assertTrue($isSorted);
    $this->assertEquals($items, $sorted);
  }

  /**
   * Test localization asort.
   */
  public function testLocalizationHelpercollatedAsortZeroError() {
    $items = [];
    $sorted = $items;
    $isSorted = LocalizationHelperStubOtherError::collatedAsort($items);
    $this->assertTrue($isSorted);
    $this->assertEquals($items, $sorted);

    $items = ['c', 'b', 'a'];
    $sorted = [
      2 => 'a',
      1 => 'b',
      0 => 'c',
    ];
    $isSorted = LocalizationHelperStubOtherError::collatedAsort($items);
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
    $isSorted = LocalizationHelperStubOtherError::collatedAsort($items, 'value');
    $this->assertTrue($isSorted);
    $this->assertEquals($items, $sorted);
  }

  /**
   * Test localization ksort.
   */
  public function testLocalizationHelpercollatedKsortZeroError() {
    $items = [];
    $sorted = $items;
    $isSorted = LocalizationHelperStubOtherError::collatedKsort($items);
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
    $isSorted = LocalizationHelperStubOtherError::collatedKsort($items);
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
    $isSorted = LocalizationHelperStubOtherError::collatedKsort($items, 'value');
    $this->assertTrue($isSorted);
    $this->assertEquals($items, $sorted);
  }

}
