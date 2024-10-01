<?php

namespace Drupal\reliefweb_job_tagger\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Re-queue job tagging.
 */
class RwJobTaggerRequeueForm extends ConfirmFormBase {

  /**
   * The node.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $node;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'reliefweb_job_tagger_requeue_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to re-queue the job?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.node.canonical', ['node' => $this->node->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Re-queue job');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL) {
    if ($node->bundle() != 'job') {
      $this->messenger()->addWarning($this->t('Only jobs can be re-queued.'));
      $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
      return;
    }

    if ($node->reliefweb_job_tagger_status->value != 'skipped') {
      $this->messenger()->addWarning($this->t('Jobs does not need to be re-queued.'));
      $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
      return;
    }

    // Check permissions.
    $user = \Drupal::currentUser();
    if (!$user->hasPermission('ocha ai job tag requeue job')) {
      $data['tabs'][0]['reliefweb_job_tagger.requeue']['#access'] = FALSE;
      return;
    }

    $this->node = $node;
    $form = parent::buildForm($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $node = $this->node;

    $node->set('reliefweb_job_tagger_status', 'queued');
    $node->set('field_job_tagger_queue_count', 1);
    reliefweb_job_tagger_queue_job($node);

    $log_message = $node->getRevisionLogMessage();
    $log_message .= (empty($log_message) ? '' : ' ') . 'Job has been manually queued for tagging.';
    $node->setRevisionLogMessage($log_message);
    $node->save();

    $this->messenger()->addMessage($this->t('Job has been re-queued for AI tagging.'));

    $form_state->setRedirect('entity.node.canonical', ['node' => $this->node->id()]);
  }

}
