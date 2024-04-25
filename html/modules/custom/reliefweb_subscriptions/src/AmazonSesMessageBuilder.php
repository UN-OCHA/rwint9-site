<?php

namespace Drupal\reliefweb_subscriptions;

use Drupal\amazon_ses\MessageBuilder;

/**
 * Extend the Amazon SES module's message builder.
 */
class AmazonSesMessageBuilder extends MessageBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildMessage(array $message) {
    // Adjust headers and expected message properties to work with the base
    // amazon_ses message builder.
    if (isset($message['headers'])) {
      foreach ($message['headers'] as $key => $value) {
        switch (strtolower($key)) {
          case 'from':
            $message['from'] = $value;
            unset($message['headers'][$key]);
            $message['headers']['From'] = $value;
            break;

          case 'reply-to':
            $message['reply-to'] = $value;
            unset($message['headers'][$key]);
            $message['headers']['Reply-to'] = $value;
            break;

          case 'cc':
            unset($message['headers'][$key]);
            $message['headers']['Cc'] = $value;
            break;

          case 'bcc':
            unset($message['headers'][$key]);
            $message['headers']['Bcc'] = $value;
            break;
        }
      }
    }

    /** @var Symfony\Component\Mime\Email $email */
    $email = parent::buildMessage($message);

    // Add extra headers.
    // @see \Drupal\reliefweb_subscriptions\ReliefWebSubscriptionsMailer::generateEmail().
    if (isset($message['params']['headers'])) {
      /** @var Symfony\Component\Mime\Headers $headers */
      $headers = $email->getHeaders();
      foreach ($message['params']['headers'] as $header => $value) {
        $headers->addHeader($header, $value);
      }
    }

    // Replace the plain text message if it exists.
    //
    // @see reliefweb_subscriptions_mail().
    // @see reliefweb_entities_mail()
    // @see reliefweb_contact_mail_alter().
    if (isset($message['params']['plaintext'])) {
      $email->text($message['params']['plaintext']);
    }

    // We replace the email with ours that preserves the BCC header so that
    // AWS SES can send emails to the BCC addresses when parsing the raw email
    // data sent by the AmazonSesHandler service.
    //
    // @see \Drupal\amazon_ses\MessageBuilder\AmazonSesHandler::send().
    // @see \Symfony\Component\Mime\Message::getPreparedHeaders()
    $data = $email->__serialize();
    $email_with_bcc = new EmailWithBcc();
    $email_with_bcc->__unserialize($data);

    return $email_with_bcc;
  }

}
