<?php

namespace Drupal\Tests\reliefweb_entraid\ExistingSite;

use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests entraid login widgets.
 *
 * @covers \Drupal\reliefweb_entraid\Controller\AuthController
 * @coversDefaultClass \Drupal\reliefweb_entraid\Controller\AuthController
 */
class ReliefwebEntraidLoginTest extends ExistingSiteBase {

  /**
   * Store the original EntraID config data.
   *
   * @var array
   */
  protected array $entraIdConfigData;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entraIdConfigData = $this->container
      ->get('config.factory')
      ->getEditable('openid_connect.client.entraid')
      ->get();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Restore the original config data.
    $this->container
      ->get('config.factory')
      ->getEditable('openid_connect.client.entraid')
      ->setData($this->entraIdConfigData)
      ->save();

    parent::tearDown();
  }

  /**
   * @covers ::redirectLogin()
   */
  public function testRedirectLogin() {
    // Skip if the module is not installed.
    if (!$this->container->get('module_handler')->moduleExists('reliefweb_entraid')) {
      $this->assertTrue(TRUE);
      return;
    }

    // Get the EntraID configuration.
    $entraid_config = $this->container
      ->get('config.factory')
      ->getEditable('openid_connect.client.entraid');

    // Empty the enpoints to test the redirection when the config is not set.
    $data = $entraid_config->get();
    $data['settings']['authorization_endpoint_wa'] = '';
    $data['settings']['token_endpoint_wa'] = '';
    $data['settings']['iss_allowed_domains'] = '';
    $entraid_config->setData($data)->save();

    // The incomplete config will results in an exception and 404 response
    // will be returned.
    $this->drupalGet('/user/login/reliefweb-entraid-direct');
    $this->assertSession()->statusCodeEquals(404);

    // Set the endpoints. We just point at the robots.txt as we know it exists
    // and so, if the reponse status code in 200, then the redirection worked.
    $data = $entraid_config->get();
    $data['settings']['authorization_endpoint_wa'] = 'http://localhost/robots.txt';
    $data['settings']['token_endpoint_wa'] = 'http://localhost/robots.txt';
    $data['settings']['iss_allowed_domains'] = 'http://localhost/robots.txt';
    $entraid_config->setData($data)->save();

    // If the redirection works, a 200 will be returned.
    $this->drupalGet('/user/login/reliefweb-entraid-direct');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertStringContainsString('Disallow:', $this->getSession()->getPage()->getContent());
  }

}
