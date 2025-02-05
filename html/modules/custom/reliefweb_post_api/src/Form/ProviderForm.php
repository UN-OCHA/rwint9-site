<?php

declare(strict_types=1);

namespace Drupal\reliefweb_post_api\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\reliefweb_moderation\ModerationServiceBase;
use Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the form for a ReliefWeb Post API provider entity.
 */
class ProviderForm extends ContentEntityForm {

  /**
   * Constructs a ContentEntityForm object.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Password\PasswordInterface $password
   *   The password service.
   * @param \Drupal\reliefweb_post_api\Plugin\ContentProcessorPluginManagerInterface $contentProcessorPluginManager
   *   The ReliefWeb Post API content processor plugin manager.
   */
  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    protected PasswordInterface $password,
    protected ContentProcessorPluginManagerInterface $contentProcessorPluginManager,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('password'),
      $container->get('plugin.manager.reliefweb_post_api.content_processor'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    $form['#attributes']['data-enhanced'] = '';

    $form['field_source']['#attributes']['data-with-autocomplete'] = '';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // No need to do further validation if there is already an error on the
    // resource or resource status. For example if one is missing or not a valid
    // value.
    $errors = $form_state->getErrors();
    if (!isset($errors['resource']) && !isset($errors['field_resource_status'])) {
      $resource = $form_state->getValue(['resource', 0, 'value']);
      $status = $form_state->getValue(['field_resource_status', 0, 'value']);

      $plugin = $this->contentProcessorPluginManager->getPluginByResource($resource);
      $bundle = $plugin->getBundle();
      $service = ModerationServiceBase::getModerationService($bundle);
      $statuses = $service->getStatuses();

      if (!isset($statuses[$status])) {
        $error = $this->t('@status is not supported for this resource, please select one of @statuses', [
          '@status' => $form['field_resource_status']['widget']['#options'][$status] ?? $status,
          '@statuses' => implode(', ', $statuses),
        ]);
        $form_state->setError($form['field_resource_status']['widget'], $error);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) : void {
    $this->entity->save();
    $this->messenger()->addMessage($this->t('Saved the %label provider.', [
      '%label' => $this->entity->label(),
    ]));
    $form_state->setRedirect('entity.reliefweb_post_api_provider.collection');
  }

}
