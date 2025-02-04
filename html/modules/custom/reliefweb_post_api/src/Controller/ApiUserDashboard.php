<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\reliefweb_post_api\Form\GenerateApiKeyForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for the ReliefWeb API User Dashboard.
 */
class ApiUserDashboard extends ControllerBase {

  /**
   * The tempstore factory service.
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
   * The password service.
   *
   * @var \Drupal\Core\Password\PasswordInterface
   */
  protected PasswordInterface $password;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user service.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The tempstore factory service.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\Core\Password\PasswordInterface $password
   *   The password service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    PrivateTempStoreFactory $temp_store_factory,
    FormBuilderInterface $form_builder,
    LoggerChannelFactoryInterface $logger_factory,
    MessengerInterface $messenger,
    TimeInterface $time,
    TranslationInterface $string_translation,
    PasswordInterface $password,
  ) {
    // Assign injected services to class properties.
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->tempStoreFactory = $temp_store_factory;
    $this->formBuilder = $form_builder;
    $this->time = $time;
    $this->password = $password;

    $this->setLoggerFactory($logger_factory);
    $this->setMessenger($messenger);
    $this->setStringTranslation($string_translation);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('tempstore.private'),
      $container->get('form_builder'),
      $container->get('logger.factory'),
      $container->get('messenger'),
      $container->get('datetime.time'),
      $container->get('string_translation'),
      $container->get('password')
    );
  }

  /**
   * Check the access to the page.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   Current user.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user from the route parameter.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function checkPageAccess(AccountInterface $current_user, AccountInterface $user): AccessResultInterface {
    if ($current_user->id() === $user->id()) {
      return AccessResult::allowedIf($current_user->hasPermission('access own reliefweb api user dashboard'));
    }
    return AccessResult::allowedIf($current_user->hasPermission('access any reliefweb api user dashboard'));
  }

  /**
   * Redirect the current user to the its subscriptions page.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirection response.
   */
  public function redirectCurrentUser(): RedirectResponse {
    return $this->redirect('reliefweb_post_api.api_user_dashboard', [
      'user' => $this->currentUser()->id(),
    ], [], 301);
  }

  /**
   * Builds the dashboard content.
   *
   * @param ?\Drupal\Core\Session\AccountInterface $user
   *   User from the route.
   *
   * @return array
   *   Render array for the page.
   */
  public function getContent(AccountInterface $user): array {
    // Build the dashboard render array.
    return [
      '#theme' => 'reliefweb_api_user_dashboard',
      '#api_key' => $this->buildApiKeySection($user),
      '#providers' => $this->buildProvidersTable($user),
      // Prevent browser caching.
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Builds the API key generation section.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   User.
   *
   * @return array
   *   Render array for the API key generation section.
   */
  protected function buildApiKeySection(AccountInterface $user): array {
    if (!$user->hasPermission('generate reliefweb api key')) {
      return [];
    }

    $build = [];

    // Access tempstore for this user.
    $temp_store = $this->tempStoreFactory->get('reliefweb_post_api');
    $timestamp_key = $user->id() . '_api_key_timestamp';

    // Check if there is a recent valid API key generation request.
    if ($timestamp = $temp_store->get($timestamp_key)) {
      // Delete the timestamp from tempstore after retrieving it.
      $temp_store->delete($timestamp_key);

      // If the timestamp is valid, generate a new key.
      if ($this->isValidTimestamp($timestamp)) {
        $build['key'] = $this->generateAndStoreKey($user);
      }
      else {
        // Log expired request and notify the user.
        $this->getLogger('reliefweb_post_api')->warning(
          'API key generation request expired for user @uid.',
          ['@uid' => $user->id()]
        );

        $this->messenger()->addError($this->t('The API key generation request has expired.'));
      }
    }

    // Add the API key generation form.
    $build['form'] = $this->formBuilder()->getForm(GenerateApiKeyForm::class, $user);

    return $build;
  }

  /**
   * Generates a new API key and stores it securely in the user's account.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   User.
   *
   * @return array
   *   Render array for the generated API key.
   */
  protected function generateAndStoreKey(AccountInterface $user): array {
    // Generate a cryptographically secure random API key.
    $raw_key = bin2hex(random_bytes(32));

    // Hash the key for storage.
    $hashed_key = $this->password->hash($raw_key);

    // Store only the hashed version of the key in the user's field.
    $user->set('field_api_key', $hashed_key);
    $user->save();

    // Log successful API key generation.
    $this->getLogger('reliefweb_post_api')->notice(
      'A new API key was generated for user @uid.',
      ['@uid' => $user->id()]
    );

    $message = [
      '#type' => 'inline_template',
      '#template' => '<div class="reliefweb-api-user-key">{{ message }}</div>',
      '#context' => [
        'message' => $this->t('Your new API key: <strong>@key</strong>', ['@key' => $raw_key]),
      ],
    ];

    return [
      '#theme' => 'status_messages',
      '#message_list' => [
        'status' => [$message],
      ],
      '#status_headings' => [
        'status' => $this->t('Status message'),
        'error' => $this->t('Error message'),
        'warning' => $this->t('Warning message'),
      ],
      '#attributes' => [
        'class' => ['reliefweb-api-user-key'],
      ],
      // Prevent caching of sensitive data.
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Validates whether a timestamp is within a valid time window (1 minute).
   *
   * @param int $timestamp
   *   Timestamp.
   * @param int $window
   *   Validity window in seconds.
   *
   * @return bool
   *   TRUE if within the window.
   */
  protected function isValidTimestamp(int $timestamp, int $window = 60): bool {
    return $this->time->getRequestTime() - $timestamp < $window;
  }

  /**
   * Builds a table of associated Post API providers for the user.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user entity whose providers are being listed.
   *
   * @return array
   *   Render array for the providers table.
   */
  protected function buildProvidersTable(AccountInterface $user): array {
    /** @var \Drupal\reliefweb_post_api\Entity\ProviderInterface[] */
    $providers = $this->entityTypeManager()
      ->getStorage('reliefweb_post_api_provider')
      ->loadByProperties([
        'status' => 1,
        'field_trusted_users' => $user->id(),
      ]);

    // Define table header.
    $header = [
      $this->t('Name'),
      $this->t('UUID'),
      $this->t('Resource Type'),
    ];

    // Populate table rows.
    $rows = [];
    foreach ($providers as $provider) {
      $rows[] = [
        'name' => $provider->label(),
        'uuid' => $provider->uuid(),
        'resource' => $provider->resource->view([
          'label' => 'hidden',
        ]),
      ];
    }

    // Return the render array for the table.
    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No associated API providers found.'),
      '#attributes' => ['class' => ['api-providers-table']],
    ];
  }

}
