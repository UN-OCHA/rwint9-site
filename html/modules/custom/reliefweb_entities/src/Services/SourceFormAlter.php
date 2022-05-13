<?php

namespace Drupal\reliefweb_entities\Services;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\reliefweb_entities\EntityFormAlterServiceBase;
use Drupal\reliefweb_utility\Helpers\UrlHelper;

/**
 * Source form alteration service.
 */
class SourceFormAlter extends EntityFormAlterServiceBase {

  /**
   * {@inheritdoc}
   */
  public function alterForm(array &$form, FormStateInterface $form_state) {
    parent::alterForm($form, $form_state);

    // Restrict the description to the markdown format.
    $form['description']['widget'][0]['#allowed_formats'] = [
      'markdown' => 'markdown',
    ];

    // Hide term relations as they are not used.
    $form['relations']['#access'] = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function addBundleFormAlterations(array &$form, FormStateInterface $form_state) {
    // Use an autocomplete widget for the country and source fields.
    $form['field_country']['#attributes']['data-with-autocomplete'] = '';

    // Validate homepage.
    $form['#validate'][] = [$this, 'validateSourceHomepage'];

    // Validate source uniquess.
    $form['#validate'][] = [$this, 'validateSourceUniqueness'];

    // Validate social media links.
    $form['#validate'][] = [$this, 'validateSourceSocialMediaLinks'];
  }

  /**
   * Validate the source homepage.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateSourceHomepage(array $form, FormStateInterface $form_state) {
    $homepage = $form_state->getValue(['field_homepage', 0, 'uri'], '');

    // Check if the homepage is a valid URL.
    if (!empty($homepage) && !UrlHelper::isValid($homepage, TRUE)) {
      $form_state->setErrorByName('field_homepage][0][uri', $this->t('The homepage must be a URL starting with https:// or http://'));
    }
  }

  /**
   * Validate the GLIDE number uniqueness.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateSourceUniqueness(array $form, FormStateInterface $form_state) {
    $entity_id = $form_state->getFormObject()->getEntity()->id();
    $homepage = trim($form_state->getValue(['field_homepage', 0, 'uri'], ''));
    $names = array_filter([
      trim($form_state->getValue(['name', 0, 'value'], '')),
      trim($form_state->getValue(['field_longname', 0, 'name'], '')),
    ]);

    if (empty($homepage) && empty($names)) {
      return;
    }

    $table = $this->getEntityTypeDataTable('taxonomy_term');
    $id_field = $this->getEntityTypeIdField('taxonomy_term');
    $label_field = $this->getEntityTypeLabelField('taxonomy_term');

    $homepage_field_table = $this->getFieldTableName('taxonomy_term', 'field_homepage');
    $homepage_field_column_name = $this->getFieldColumnName('taxonomy_term', 'field_homepage', 'uri');

    $longname_field_table = $this->getFieldTableName('taxonomy_term', 'field_longname');
    $longname_field_column_name = $this->getFieldColumnName('taxonomy_term', 'field_longname', 'value');

    // Query the term table.
    $query = $this->getDatabase()->select($table, $table);
    $query->fields($table, [$id_field, $label_field]);

    // Join the homepage and longname field tables.
    $homepage_field_table_alias = $query->leftJoin($homepage_field_table, $homepage_field_table, "%alias.entity_id = {$table}.{$id_field}");
    $longname_field_table_alias = $query->leftJoin($longname_field_table, $longname_field_table, "%alias.entity_id = {$table}.{$id_field}");

    // Condition to check the name, longname and homepage.
    $or = $query->orConditionGroup();
    $or->condition($table . '.' . $label_field, $names, 'IN');
    $or->condition($longname_field_table_alias . '.' . $longname_field_column_name, $names, 'IN');
    if (!empty($homepage)) {
      $or->condition($homepage_field_table_alias . '.' . $homepage_field_column_name, $homepage, '=');
    }
    $query->condition($or);

    // If we're not crearting a new source, exclude it.
    if (!empty($entity_id)) {
      $query->condition($table . '.' . $id_field, $entity_id, '<>');
    }

    $links = [];
    foreach ($query->execute() ?? [] as $record) {
      $url = Url::fromUserInput('/taxonomy/term/' . $record->{$id_field});
      $links[] = Link::fromTextAndUrl($record->{$label_field}, $url)->toString();
    }

    if (!empty($links)) {
      // We need the double Markups to avoid the links to be escaped.
      // We also don't pass a field to the setErrorByName so the the message
      // appears at the top because it can be due to the name, longname or
      // homepage.
      $message = $this->t('Source(s) with the same name or homepage already exist: @links', [
        '@links' => Markup::create(implode(', ', $links), []),
      ]);
      $form_state->setErrorByName('', Markup::create($message));
    }
  }

  /**
   * Validate the source social media links.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function validateSourceSocialMediaLinks(array $form, FormStateInterface $form_state) {
    $links = $form_state->getValue(['field_links'], []);

    // Check that the links are valid URLs.
    foreach ($links as $delta => $link) {
      if (is_array($link) && !empty($link['uri']) && !UrlHelper::isValid($link['uri'], TRUE)) {
        $form_state->setErrorByName('field_links][' . $delta . '][uri', $this->t('The social media link must be a URL starting with https:// or http://'));
      }
    }
  }

}
