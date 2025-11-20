<?php

declare(strict_types=1);

namespace Drupal\reliefweb_users\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\reliefweb_utility\Helpers\DomainHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for privileged domains.
 */
class PrivilegedDomainsForm extends FormBase {

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
    return 'reliefweb_users_privileged_domains_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Get the current privileged domains from state.
    $domains = $this->state->get('reliefweb_users_privileged_domains', ['un.org']);
    $default_domain_posting_rights = $this->state->get('reliefweb_users_privileged_domains_default_posting_rights', []);
    if (is_string($default_domain_posting_rights)) {
      $default_domain_posting_rights = array_fill_keys(['report', 'job', 'training'], $default_domain_posting_rights);
    }
    elseif (!is_array($default_domain_posting_rights)) {
      $default_domain_posting_rights = [];
    }
    $default_domain_posting_rights += [
      'report' => 'allowed',
      'job' => 'allowed',
      'training' => 'allowed',
    ];

    $form['description'] = [
      '#type' => 'inline_template',
      '#template' => <<<'TEMPLATE'
        <div class="reliefweb-privileged-domains-description">
          <p>{{ 'Domains in this list receive special privileges:'|t }}</p>
          <ul class="reliefweb-privileged-domains-description-list">
            <li>{{ 'Users with email addresses from these domains will automatically be assigned the Submitter and Advertiser roles upon login via Entra ID.'|t }}</li>
            <li>{{ 'Privileged domains will use the selected default posting rights when no specific posting rights record exists, instead of "unverified".'|t }}</li>
          </ul>
        </div>
        TEMPLATE,
    ];

    $form['domains'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Privileged domains'),
      '#description' => $this->t('Enter one domain per line. Users with email addresses from these domains will automatically be assigned the Submitter and Advertiser roles upon login via Entra ID.'),
      '#default_value' => implode("\n", $domains),
      '#rows' => 10,
    ];

    // Field to determine the default posting rights for a domain in the
    // list when there is no existing record for a given source for the domain.
    $form['default_domain_posting_rights'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Default domain posting rights'),
      '#description' => $this->t('When posting a report, job, or training, privileged domains will use the selected default posting rights for the corresponding content type when no specific posting rights record exists, instead of "unverified".'),
      '#tree' => TRUE,
    ];

    foreach (['report', 'job', 'training'] as $bundle) {
      $form['default_domain_posting_rights'][$bundle] = [
        '#type' => 'select',
        '#title' => $this->t('@type default', ['@type' => ucfirst($bundle)]),
        '#options' => [
          'blocked' => $this->t('Blocked'),
          'unverified' => $this->t('Unverified'),
          'allowed' => $this->t('Allowed'),
          'trusted' => $this->t('Trusted'),
        ],
        '#default_value' => $default_domain_posting_rights[$bundle],
      ];
    }

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
      if (!DomainHelper::validateDomain($domain)) {
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
    $this->state->set('reliefweb_users_privileged_domains', $domains);

    // Save the default posting rights to state.
    $defaults = $form_state->getValue('default_domain_posting_rights') ?? [];
    $allowed_values = ['unverified', 'allowed', 'trusted'];
    $values = [];
    foreach (['report', 'job', 'training'] as $bundle) {
      $value = $defaults[$bundle] ?? 'unverified';
      $values[$bundle] = in_array($value, $allowed_values, TRUE) ? $value : 'unverified';
    }
    $this->state->set('reliefweb_users_privileged_domains_default_posting_rights', $values);

    // Show success message.
    $this->messenger()->addStatus($this->t('The privileged domains have been saved.'));
  }

}
