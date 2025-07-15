<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\reliefweb_post_api\Theme\ThemeNegotiator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the ReliefWeb Post API theme negotiator.
 */
#[CoversClass(ThemeNegotiator::class)]
#[Group('reliefweb_post_api')]
class ThemeNegotiatorTest extends ExistingSiteBase {

  /**
   * Test applies.
   */
  public function testApplies(): void {
    $route_match = $this->createConfiguredMock(RouteMatchInterface::class, [
      'getRouteName' => 'entity.reliefweb_post_api_provider.add_form',
    ]);

    $theme_negotiator = new ThemeNegotiator();

    $this->assertTrue($theme_negotiator->applies($route_match));
  }

  /**
   * Test determine active theme.
   */
  public function testDetermineActiveTheme(): void {
    $route_match = $this->createMock(RouteMatchInterface::class);

    $theme_negotiator = new ThemeNegotiator();

    $this->assertSame('common_design_subtheme', $theme_negotiator->determineActiveTheme($route_match));
  }

}
