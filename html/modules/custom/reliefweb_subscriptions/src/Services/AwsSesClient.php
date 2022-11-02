<?php

namespace Drupal\reliefweb_subscriptions\Services;

use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\SesV2\SesV2Client;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service client for the Amazon SES.
 */
class AwsSesClient {

  /**
   * ReliefWeb API config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * AWS client.
   *
   * @var \Aws\SesV2\SesV2Client
   */
  protected $awsSesClient;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->config = $config_factory->get('reliefweb_subscriptions.settings');
    $this->logger = $logger_factory->get('reliefweb_subscriptions');
  }

  /**
   * Get the AWS SES API client.
   *
   * @return \Aws\SesV2\SesV2Client
   *   AWS SES API client.
   */
  public function getAwsSesClient() {
    if (!isset($this->awsSesV2Client)) {
      $settings = [
        'version' => $this->config->get('aws_ses_api_version'),
        'region' => $this->config->get('aws_ses_api_region'),
        'credentials' => new Credentials(
          $this->config->get('aws_ses_api_key'),
          $this->config->get('aws_ses_api_secret'),
          $this->config->get('aws_ses_api_token')
        ),
      ];

      if (!empty($this->config->get('aws_ses_api_endpoint'))) {
        $settings['endpoint'] = $this->config->get('aws_ses_api_endpoint');
      }

      if (!empty($this->config->get('aws_ses_api_endpoint'))) {
        $settings['endpoint'] = $this->config->get('aws_ses_api_endpoint');
      }

      $this->awsSesClient = new SesV2Client($settings);
    }
    return $this->awsSesClient;
  }

  /**
   * Create an email template.
   *
   * @param string $name
   *   Template name.
   * @param string $subject
   *   Email subject.
   * @param string $html
   *   HTML content.
   * @param string $text
   *   Text content.
   *
   * @throws \Exception
   *   Exception if the request to AWS SES failed.
   */
  public function createTemplate($name, $subject, $html, $text) {
    // Delete the template if it already exists.
    try {
      $this->getAwsSesClient()->deleteEmailTemplate([
        'TemplateName' => $name,
      ]);
    }
    catch (AwsException $exception) {
      if ($exception->getStatusCode() != 404) {
        $this->logger->error('AWS SES - Unable to delete template @name: @error', [
          '@name' => $name,
          '@error' => $exception->getMessage(),
        ]);
        // @todo extract the error message.
        throw $exception;
      }
    }

    // Create the template.
    try {
      $this->getAwsSesClient()->createEmailTemplate([
        'TemplateName' => $name,
        'TemplateContent' => [
          'Subject' => $subject,
          'Text' => $text,
          'Html' => $html,
        ],
      ]);
    }
    catch (AwsException $exception) {
      $this->logger->error('AWS SES - Unable to create template @name: @error', [
        '@name' => $name,
        '@error' => $exception->getMessage(),
      ]);
      // @todo extract the error message.
      throw $exception;
    }
  }

  /**
   * Send a bulk message.
   *
   * @param string $from
   *   From email address.
   * @param string $template
   *   Name of the template to use.
   * @param array $destinations
   *   List of recipients with a recipient key with the email address as value
   *   and an key/value array of replacements for the template.
   * @param array $replacements
   *   Default key/value array of replacements for the template.
   *
   * @return array
   *   List of email sending success/error for each destination.
   *
   * @throws \Exception
   *   Exception if the request to AWS SES failed.
   */
  public function sendBulkEmail($from, $template, array $destinations, array $replacements = []) {
    $results = [];

    try {
      $email = [
        'FromEmailAddress' => $from,
        'DefaultContent' => [
          'Template' => [
            'TemplateName' => $template,
            'TemplateData' => $this->convertReplacements($replacements),
          ],
        ],
        'BulkEmailEntries' => array_map(function ($destination) {
          return [
            'Destination' => [
              'ToAddresses' => [$destination['recipient']],
            ],
            'ReplacementEmailContent' => [
              'ReplacementTemplate' => [
                'ReplacementTemplateData' => $this->convertReplacements($destination['replacements'] ?? []),
              ],
            ],
          ];
        }, $destinations),
      ];

      $identity = $this->config->get('aws_ses_api_identity');
      if (!empty($identity)) {
        $email['FromEmailAddressIdentityArn'] = $identity;
      }

      /** @var \Aws\Result $result */
      $result = $this->getAwsSesClient()->SendBulkEmail($email);

      // Build the result array with the success or error for each recipient.
      foreach ($result->get('BulkEmailEntryResults') as $item) {
        if (strtolower($item['Status']) !== 'success') {
          $results[] = [
            'success' => FALSE,
            'error' => '[' . $item['Status'] . '] ' . $item['Error'],
          ];
        }
        else {
          $results[] = [
            'success' => TRUE,
          ];
        }
      }
    }
    catch (AwsException $exception) {
      $this->logger->error('AWS SES - Unable to send bulk email: @error', [
        '@error' => $exception->getMessage(),
      ]);
      // @todo extract the error message.
      throw $exception;
    }

    return $results;
  }

  /**
   * Get the maxium number of emails that can sent in a second.
   *
   * @return int
   *   Send rate.
   *
   * @throws \Exception
   *   Exception if the request to AWS SES failed.
   */
  public function getSendRate() {
    try {
      /** @var \Aws\Result $result */
      $result = $this->getAwsSesClient()->getAccount();
      $quota = $result->get('SendQuota');
      if (isset($quota['MaxSendRate'])) {
        return intval($quota['MaxSendRate'], 10);
      }
      return 1;
    }
    catch (AwsException $exception) {
      $this->logger->error('AWS SES - Unable to get send rate: @error', [
        '@error' => $exception->getMessage(),
      ]);
      // @todo extract the error message.
      throw $exception;
    }
  }

  /**
   * Render a template with the given replacement data.
   *
   * @param string $template
   *   The template name.
   * @param array $replacements
   *   The replacement data.
   *
   * @return string
   *   The rendered template.
   *
   * @throws \Exception
   *   Exception if the request to AWS SES failed.
   */
  public function renderTemplate($template, array $replacements = []) {
    try {
      /** @var \Aws\Result $result */
      $result = $this->getAwsSesClient()->testRenderTemplate([
        'TemplateName' => $template,
        'TemplateData' => json_encode($replacements),
      ]);
      return $result->get('RenderedTemplate');
    }
    catch (AwsException $exception) {
      $this->logger->error('AWS SES - Unable to get rendered template: @error', [
        '@error' => $exception->getMessage(),
      ]);
      // @todo extract the error message.
      throw $exception;
    }
  }

  /**
   * Convert key value replacements into the structure expected by SES.
   *
   * @param array $replacements
   *   Key value array of replacements.
   *
   * @return string
   *   JSON encoded key/value pairs.
   */
  protected function convertReplacements(array $replacements = []) {
    return json_encode($replacements, \JSON_FORCE_OBJECT);
  }

}
