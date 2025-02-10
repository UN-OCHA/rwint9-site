<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for generating a new API key.
 */
class GenerateApiKeyForm extends FormBase {

  /**
   * The tempstore private service.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected PrivateTempStoreFactory $tempStoreFactory;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The tempstore private service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    PrivateTempStoreFactory $temp_store_factory,
    TimeInterface $time,
    TranslationInterface $string_translation,
  ) {
    $this->tempStoreFactory = $temp_store_factory;
    $this->time = $time;

    $this->setStringTranslation($string_translation);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('tempstore.private'),
      $container->get('datetime.time'),
      $container->get('string_translation')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reliefweb_post_api_generate_key_form';
  }

  /**
   * Builds the API key generation form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The render array representing the form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $user = $form_state->getBuildInfo()['args'][0] ?? NULL;
    if (isset($user) && $user instanceof AccountInterface && $user->hasPermission('generate reliefweb api key')) {
      $form['#attributes']['class'][] = 'api-key-form';

      // Add a hidden field to store the current timestamp for validation.
      $form['timestamp'] = [
        '#type' => 'hidden',
        '#value' => $this->time->getRequestTime(),
      ];

      // Add a submit button to trigger API key generation.
      $form['actions'] = [
        '#type' => 'actions',
        'submit' => [
          '#type' => 'submit',
          '#value' => $this->t('Generate a new API key'),
          '#attributes' => [
            'class' => ['button--danger'],
          ],
        ],
      ];
    }

    return $form;
  }

  /**
   * Validates the form submission.
   *
   * Ensure that the timestamp is valid and within an acceptable range
   * (60 seconds).
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Retrieve the submitted timestamp from the form state.
    $submitted_time = (int) $form_state->getValue('timestamp');
    // Get the current server time.
    $current_time = $this->time->getRequestTime();

    // Validate that the request is not older than 60 seconds.
    if ($current_time - $submitted_time > 60) {
      // Set an error message if the timestamp is invalid or expired.
      $form_state->setError($form, $this->t('The request has expired. Please try again.'));
    }
  }

  /**
   * Handles form submission to generate a new API key.
   *
   * Store a timestamp in tempstore to signal that an API key should be
   * generated then reloads the page to display the raw key to the user.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $user = $form_state->getBuildInfo()['args'][0] ?? NULL;
    if (isset($user) && $user instanceof AccountInterface && $user->hasPermission('generate reliefweb api key')) {
      // Retrieve tempstore for this user.
      $temp_store = $this->tempStoreFactory->get('reliefweb_post_api');
      // Store a timestamp to indicate that a generation request was made.
      $temp_store->set($user->id() . '_api_key_timestamp', $this->time->getRequestTime());

      // Redirect back to the current page to display the generated API key.
      // Use cache-busting query parameters to ensure fresh content is loaded.
      $form_state->setRedirect('<current>', [], [
        'query' => ['_time' => $this->time->getRequestTime()],
      ]);
    }
  }

}
