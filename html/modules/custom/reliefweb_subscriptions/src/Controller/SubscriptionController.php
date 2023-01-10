<?php

namespace Drupal\reliefweb_subscriptions\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Handle subscription routes.
 */
class SubscriptionController extends ControllerBase {

  /**
   * Redirect the current user to the its subscriptions page.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirection response.
   */
  public function currentUserSubscriptionsPage() {
    return $this->redirect('reliefweb_subscriptions.subscription_form', [
      'user' => $this->currentUser()->id(),
    ], [], 301);
  }

}
