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
    $this->drupalGet('/user/login/entraid');
    $this->assertSession()->statusCodeEquals(404);

    // Set the endpoints. The don't exists and will, on purpose return a 503.
    $data = $entraid_config->get();
    $data['settings']['authorization_endpoint_wa'] = 'http://test.test/common/oauth2/v2.0/authorize';
    $data['settings']['token_endpoint_wa'] = 'http://test.test/common/oauth2/v2.0/token';
    $data['settings']['iss_allowed_domains'] = 'http://test.test/{tenantid}/v2.0';
    $entraid_config->setData($data)->save();

    // If the redirection works, a 503 will be returned because the EntraID
    // endpoints do not exist.
    $this->drupalGet('/user/login/entraid');
    $this->assertSession()->statusCodeEquals(503);
  }

}
