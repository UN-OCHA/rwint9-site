<?php

namespace Drupal\reliefweb_job_tagger\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller to compare summaries.
 */
class RequeueController extends ControllerBase {

  /**
   * Output comparison.
   */
  public function requeueJob($id = NULL) {
    /** @var \Drupal\node\Entity\Node $node */
    $node = $this->entityTypeManager()
      ->getStorage('node')
      ->load($id);

    $redirect = new RedirectResponse(Url::fromRoute('entity.node.canonical', [
      'node' => $node->id(),
    ])->toString());

    if ($node->bundle() != 'job') {
      return $redirect;
    }

    if ($node->reliefweb_job_tagger_status->value != 'skipped') {
      return $redirect;
    }

    $node->set('reliefweb_job_tagger_status', 'queued');
    $node->set('field_job_tagger_queue_count', 1);

    $log_message = $node->getRevisionLogMessage();
    $log_message .= (empty($log_message) ? '' : ' ') . 'Job has been manually queued for tagging.';
    $node->setRevisionLogMessage($log_message);
    $node->save();

    $this->messenger()->addMessage($this->t('Job has been re-queued for AI tagging.'));

    return $redirect;
  }

}
