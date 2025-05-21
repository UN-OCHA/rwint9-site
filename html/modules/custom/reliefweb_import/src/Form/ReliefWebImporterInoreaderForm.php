<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * ReliefWeb Ioreader.
 */
class ReliefWebImporterInoreaderForm extends FormBase {

  /**
   * Constructor.
   */
  public function __construct(
    protected StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reliefweb_importer_inoreader_form';
  }

  /**
   * {@inheritdoc}
   *
   * Builds the configuration form for a specific ReliefWeb importer plugin.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param string|null $plugin_id
   *   The ID of the plugin being configured.
   *
   * @return array
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $plugin_id = NULL): array {
    $extra_tags = $this->state->get('reliefweb_importer_inoreader_extra_tags', []);

    $form['#attached']['library'][] = 'reliefweb_import/inoreader_yaml_editor';
    $form['extra_tags'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Extra tags'),
      '#default_value' => Yaml::dump($extra_tags, 99, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),
      '#rows' => 20,
      '#attributes' => [
        'data-inoreader-yaml-editor' => 'true',
      ],
      '#description' => $this->t('Add extra tags to the imported items. The top level keys are organization ids, the next level is the tag name and beneath that you can specify tag values. Check the @readme for more information', [
        '@readme' => Link::fromTextAndUrl($this->t('README'), Url::fromUri('https://github.com/UN-OCHA/rwint9-site/blob/develop/html/modules/custom/reliefweb_import/README.md'))->toString(),
      ]),
    ];

    $form['actions']['#type'] = 'actions';
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
    try {
      $extra_tags = $form_state->getValue('extra_tags');
      $extra_tags = Yaml::parse($extra_tags, Yaml::PARSE_OBJECT);

      if (!is_array($extra_tags)) {
        $form_state->setErrorByName('extra_tags', $this->t('Invalid YAML format'));
      }
      else {
        // Make sure all keys are numeric.
        foreach ($extra_tags as $key => $value) {
          if (!is_numeric($key)) {
            $form_state->setErrorByName('extra_tags', $this->t('Invalid YAML format: @message', ['@message' => 'All keys must be numeric.']));

            // Stop the loop.
            return;
          }
        }
      }
    }
    catch (ParseException $e) {
      $form_state->setErrorByName('extra_tags', $this->t('Invalid YAML format: @message', ['@message' => $e->getMessage()]));
    }
    catch (\Exception $e) {
      $form_state->setErrorByName('extra_tags', $this->t('Invalid YAML format: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $extra_tags = $form_state->getValue('extra_tags');
      $extra_tags = Yaml::parse($extra_tags, Yaml::PARSE_OBJECT);
      if (is_array($extra_tags)) {
        $this->state->set('reliefweb_importer_inoreader_extra_tags', $extra_tags);
        $this->messenger()->addStatus($this->t('The configuration has been saved.'));
      }
    }
    catch (\Exception $e) {
      // Ignore the error, it has already been handled in the validateForm().
    }
  }

}
