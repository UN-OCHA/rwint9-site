<?php

namespace Drupal\reliefweb_form\Controller;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Controller\ControllerBase;
use Drupal\reliefweb_utility\Helpers\HtmlSanitizer;
use Drupal\reliefweb_utility\Traits\EntityDatabaseInfoTrait;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller to retrieve/validate data for node forms.
 */
class NodeForm extends ControllerBase {

  use EntityDatabaseInfoTrait;

  /**
   * Get the source attention messages for the node bundle and given sources.
   *
   * @param string $bundle
   *   Entity bundle.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with the attention message (or empty).
   */
  public function getSourceAttentionMessages($bundle) {
    $content = [];

    if (!in_array($bundle, ['job', 'report', 'training'])) {
      return new JsonResponse([]);
    }

    // Limit to 10,000 bytes (should never be reached).
    $data = json_decode(file_get_contents('php://input', FALSE, NULL, 0, 10000) ?? '', TRUE);
    if (empty($data) || !is_array($data)) {
      return new JsonResponse([]);
    }

    // Filter the array, ensuring only numeric values are left.
    $ids = array_filter($data, function ($item) {
      return filter_var($item, FILTER_VALIDATE_INT, [
        'options' => [
          'min_range' => 1,
        ],
      ]);
    });
    if (empty($ids)) {
      return new JsonResponse([]);
    }

    // For reports, we simply load the messages from the report attention field.
    if ($bundle === 'report') {
      // No messages for contributors.
      if (!$this->currentUser()->hasRole('contributor')) {
        $messages = $this->loadSourceAttentionMessages($bundle, $ids);
      }
    }
    // For jobs or training, we combine the job and training attention messages
    // as the information is useful for both teams.
    else {
      $homepage_links = $this->loadSourceHomepageLinks($ids);
      $job_messages = $this->loadSourceAttentionMessages('job', $ids);
      $training_messages = $this->loadSourceAttentionMessages('training', $ids);

      $messages = [];
      foreach ($ids as $id) {
        $message = [];
        $job_message = $job_messages[$id] ?? '';
        $training_message = $training_messages[$id] ?? '';

        if (!empty($job_message) || !empty($training_message)) {
          if ($job_message === $training_message) {
            $message[] = '<strong class="title">Jobs and Training</strong>' . $job_message;
          }
          else {
            if (!empty($job_message)) {
              $message[] = '<strong class="title">Jobs</strong>' . $job_message;
            }
            if (!empty($training_message)) {
              $message[] = '<strong class="title">Training</strong>' . $training_message;
            }
          }
          if ($bundle === 'training') {
            $message = array_reverse($message);
          }
        }
        else {
          $message[] = '<p><em>No attention messages</em></p>';
        }

        // Source page and homepage links.
        $links[] = '<a href="/taxonomy/term/' . $id . '" target="_blank">View source page</a>';
        if (!empty($homepage_links[$id])) {
          $links[] = (string) new FormattableMarkup('<span>Homepage: </span><a href="@homepage" target="_blank" rel="noopener">@homepage</a>', [
            '@homepage' => $homepage_links[$id],
          ]);
        }

        $message[] = '<p>' . implode(' &mdash; ', $links) . '</p>';
        $messages[$id] = implode("\n", $message);
      }
    }

    // Convert the messages into an array for easier processing by the script.
    $content = [];
    foreach ($ids as $id) {
      $content[] = [
        'id' => $id,
        'message' => $messages[$id] ?? '',
      ];
    }

    return new JsonResponse($content);
  }

  /**
   * Retrieve the attention messages for the given sources.
   *
   * @param string $bundle
   *   Node bundle.
   * @param array $ids
   *   Source ids.
   *
   * @return array
   *   List of messages keyed by source id.
   */
  protected function loadSourceAttentionMessages($bundle, array $ids) {
    if (empty($ids) || !in_array($bundle, ['job', 'report', 'training'])) {
      return [];
    }

    $entity_type_id = 'taxonomy_term';
    $field_name = 'field_attention_' . $bundle;
    $field_table = $this->getFieldTableName($entity_type_id, $field_name);
    $field_field = $this->getFieldColumnName($entity_type_id, $field_name, 'value');

    $query = $this->getDatabase()->select($field_table, $field_table);
    $query->addField($field_table, 'entity_id', 'id');
    $query->addField($field_table, $field_field, 'message');
    $query->condition($field_table . '.entity_id', $ids, 'IN');
    $query->condition($field_table . '.' . $field_field, '', '<>');

    $messages = [];
    foreach ($query->execute() ?? [] as $record) {
      $message = trim($record->message);
      if (!empty($message)) {
        // Transform to markdown list.
        $message = preg_replace("/^|(\s*[\n\r]+\s*)/", "\n - ", $message);
        // Convert to HTML.
        $message = (string) check_markup($message, 'markdown');
        // Sanitize the HTML, using heading level 6 so that all headings if any
        // (shouldn't be) are converted to <strong>.
        $messages[$record->id] = HtmlSanitizer::sanitize($message, FALSE, 6);
      }
    }

    return $messages;
  }

  /**
   * Get the homepage links for the give sources.
   *
   * @param array $ids
   *   Source ids.
   *
   * @return array
   *   List of homepage links keyed by source ids.
   */
  protected function loadSourceHomepageLinks(array $ids) {
    $entity_type_id = 'taxonomy_term';
    $field_name = 'field_homepage';
    $field_table = $this->getFieldTableName($entity_type_id, $field_name);
    $field_field = $this->getFieldColumnName($entity_type_id, $field_name, 'uri');

    $query = $this->getDatabase()->select($field_table, $field_table);
    $query->addField($field_table, 'entity_id', 'id');
    $query->addField($field_table, $field_field, 'url');
    $query->condition($field_table . '.entity_id', $ids, 'IN');
    $query->condition($field_table . '.' . $field_field, '', '<>');

    return $query->execute()?->fetchAllKeyed() ?? [];
  }

}
