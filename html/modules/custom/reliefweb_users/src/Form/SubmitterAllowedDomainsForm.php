<?php

declare(strict_types=1);

namespace Drupal\reliefweb_users\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for submitter allowed domains.
 */
class SubmitterAllowedDomainsForm extends FormBase {

  /**
   * Constructor.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    protected StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('state')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reliefweb_users_submitter_allowed_domains_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Get the current allowed domains from state.
    $domains = $this->state->get('reliefweb_users_submitter_allowed_domains', ['un.org']);

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Configure domains that are allowed for automatic assignment of the submitter role.') . '</p>',
    ];

    $form['domains'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed domains'),
      '#description' => $this->t('Enter one domain per line. Users with email addresses from these domains will automatically be assigned the submitter role upon login if they have a connected Entra ID account.'),
      '#default_value' => implode("\n", $domains),
      '#required' => TRUE,
      '#rows' => 10,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save configuration'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $domains_text = $form_state->getValue('domains');
    $domains = array_filter(array_map('trim', explode("\n", $domains_text)));

    foreach ($domains as $domain) {
      if (!filter_var($domain, \FILTER_VALIDATE_DOMAIN, \FILTER_FLAG_HOSTNAME)) {
        $form_state->setError($form['domains'], $this->t('The domain %domain is not valid.', ['%domain' => $domain]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $domains_text = $form_state->getValue('domains');
    $domains = array_filter(array_map('trim', explode("\n", $domains_text)));

    // Save the domains to state.
    $this->state->set('reliefweb_users_submitter_allowed_domains', $domains);

    $this->messenger()->addStatus($this->t('The submitter allowed domains have been saved.'));
  }

}
