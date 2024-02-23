<?php

declare(strict_types=1);

namespace Drupal\Tests\reliefweb_post_api\ExistingSite\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\reliefweb_post_api\Theme\ThemeNegotiator;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the ReliefWeb POST API theme negotiator.
 *
 * @coversDefaultClass \Drupal\reliefweb_post_api\Theme\ThemeNegotiator
 *
 * @group reliefweb_post_api
 */
class ThemeNegotiatorTest extends ExistingSiteBase {

  /**
   * @covers ::applies
   */
  public function testApplies(): void {
    $route_match = $this->createConfiguredMock(RouteMatchInterface::class, [
      'getRouteName' => 'entity.reliefweb_post_api_provider.add_form',
    ]);

    $theme_negotiator = new ThemeNegotiator();

    $this->assertTrue($theme_negotiator->applies($route_match));
  }

  /**
   * @covers ::determineActiveTheme
   */
  public function testDetermineActiveTheme(): void {
    $route_match = $this->createMock(RouteMatchInterface::class);

    $theme_negotiator = new ThemeNegotiator();

    $this->assertSame('common_design_subtheme', $theme_negotiator->determineActiveTheme($route_match));
  }

}
