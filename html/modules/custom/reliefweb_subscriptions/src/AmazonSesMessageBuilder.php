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
        // Remove existing unique headers to use our version.
        if ($headers->has($header) && $headers::isUniqueHeader($header)) {
          $headers->remove($header);
        }
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

  /**
   * Get the content from a MIME message part with base64 decoding support.
   *
   * @param string $part
   *   The message part.
   *
   * @return string|false
   *   The content, or FALSE if it could not be parsed.
   */
  protected function getPartContent($part) {
    $split = preg_split('#\r?\n\r?\n#', $part, 2);

    if ($split && isset($split[1])) {
      $headers = $split[0];
      $content = $split[1];

      // Check if the content is base64 encoded.
      if (preg_match('/Content-Transfer-Encoding:\s*base64/i', $headers)) {
        // Remove any whitespace and newlines from base64 content.
        $content = preg_replace('/\s+/', '', $content);
        $decoded = base64_decode($content);
        return $decoded;
      }
      // Handle quoted-printable encoding.
      elseif (preg_match('/Content-Transfer-Encoding:\s*quoted-printable/i', $headers)) {
        return quoted_printable_decode($content);
      }

      // Return content as-is for other encodings (7bit, 8bit, binary).
      return $content;
    }

    return FALSE;
  }

}
