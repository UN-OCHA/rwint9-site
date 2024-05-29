<?php

declare(strict_types=1);

namespace Drupal\reliefweb_reporting\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Checks access for reporting.
 */
class ReliefWebReportingAccess implements AccessInterface {

  use LoggerChannelTrait;

  /**
   * Constructor.
   */
  public function __construct(
    protected RequestStack $requestStack,
    protected ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * Retrieves a configuration object.
   */
  protected function config($name) {
    return $this->configFactory->get($name);
  }

  /**
   * Access result callback.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Determines the access to controller.
   */
  public function access(AccountInterface $account) {
    $header_secret = $this->requestStack->getCurrentRequest()->headers->get('reliefweb-reporting') ?? NULL;
    $config_secret = $this->config('reliefweb_reporting')->get('statistics.key');
    if ((!empty($header_secret) && $header_secret === $config_secret)
      || $account->hasPermission('view site reports')) {
      $access_result = AccessResult::allowed();
    }
    else {
      $access_result = AccessResult::forbidden('Invalid key');
      $logger = $this->getLogger('reliefweb_reporting');
      $logger->warning('Unauthorized access to reports');
    }
    $access_result
      ->setCacheMaxAge(0)
      ->addCacheContexts([
        'headers:reliefweb-reporting',
        'user.roles',
      ])
      ->addCacheTags(['reliefweb_reporting']);

    return $access_result;
  }

}
