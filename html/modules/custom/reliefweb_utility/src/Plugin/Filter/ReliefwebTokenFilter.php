<?php

namespace Drupal\reliefweb_utility\Plugin\Filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Utility\Token;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a filter that replaces some tokens with their values.
 *
 * @Filter(
 *   id = "reliefweb_token_filter",
 *   title = @Translation("RW: Replaces some tokens with their values"),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
 *   settings = {
 *     "replace_empty" = FALSE
 *   }
 * )
 */
class ReliefwebTokenFilter extends FilterBase implements ContainerFactoryPluginInterface {

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * Constructs a token filter plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Token $token) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->token = $token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('token')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form['replace_empty'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Replace empty values'),
      '#description' => $this->t('Remove tokens from text if they cannot be replaced with a value'),
      '#default_value' => $this->settings['replace_empty'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $data = [];

    $cache = new BubbleableMetadata();
    $options = [
      'clear' => $this->settings['replace_empty'],
      'callback' => [$this, 'tokenCallback'],
    ];

    $replacements = $this->token->replace($text, $data, $options, $cache);

    return (new FilterProcessResult($replacements))->merge($cache);
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE) {
    return $this->t('Allow a limited list of tokens and replace with their values.');
  }

  /**
   * Token callback to limit allowed tokens.
   */
  public static function tokenCallback(&$replacements, $data, $options, $bubbleable_metadata) {
    foreach ($replacements as $key => $replacement) {
      if (strpos($key, '[disaster-map') === FALSE) {
        $replacements[$key] = !empty($options['clear']) ? '' : $key;
      }
    }
  }

}
