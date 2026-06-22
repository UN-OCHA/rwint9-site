<?php

declare(strict_types=1);

namespace Drupal\reliefweb_entities\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for ReliefWeb Entities settings.
 */
class ReliefWebEntitiesSettingsForm extends ConfigFormBase {

  /**
   * Query boost field labels keyed by config key.
   */
  private const array BOOST_LABELS = [
    'translation' => 'Translation',
    'disaster_country_recent' => 'Disaster + primary country (recent)',
    'disaster_recent' => 'Disaster (short recency window)',
    'disaster_medium' => 'Disaster (long recency window)',
    'disaster_primary_country' => 'Disaster + primary country',
    'disaster_country' => 'Disaster + country',
    'disaster' => 'Disaster only',
    'title_disaster' => 'Title series + disaster',
    'title_primary_country' => 'Title series + primary country',
    'country_source' => 'Primary country + source',
    'country_theme' => 'Primary country + theme',
  ];

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [
      'reliefweb_entities.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reliefweb_entities_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('reliefweb_entities.settings');

    $form['cron'] = [
      '#type' => 'details',
      '#title' => $this->t('Cron task limits'),
      '#description' => $this->t('Maximum number of entities processed per cron run.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['cron']['embargoed_reports_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Embargoed reports'),
      '#default_value' => $config->get('cron.embargoed_reports_limit') ?? 20,
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['cron']['expired_jobs_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Expired jobs'),
      '#default_value' => $config->get('cron.expired_jobs_limit') ?? 20,
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['cron']['expired_training_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Expired training'),
      '#default_value' => $config->get('cron.expired_training_limit') ?? 20,
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['related_content'] = [
      '#type' => 'details',
      '#title' => $this->t('Related content'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['related_content']['candidate_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('API candidate limit'),
      '#description' => $this->t('Number of API results to fetch before PHP re-ranking.'),
      '#default_value' => $config->get('related_content.candidate_limit') ?? 20,
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['related_content']['recency_short_months'] = [
      '#type' => 'number',
      '#title' => $this->t('Short recency window (months)'),
      '#default_value' => $config->get('related_content.recency_short_months') ?? 3,
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['related_content']['recency_long_months'] = [
      '#type' => 'number',
      '#title' => $this->t('Long recency window (months)'),
      '#default_value' => $config->get('related_content.recency_long_months') ?? 12,
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['related_content']['recency_decay_exponent'] = [
      '#type' => 'number',
      '#title' => $this->t('Recency decay exponent'),
      '#description' => $this->t('Applied during PHP re-ranking as 1 / days^exponent.'),
      '#default_value' => $config->get('related_content.recency_decay_exponent') ?? 1.5,
      '#min' => 0.1,
      '#step' => 0.1,
      '#required' => TRUE,
    ];

    $form['related_content']['translation_date_window_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Translation date window (days)'),
      '#description' => $this->t('Days around the original publication date for translation matching.'),
      '#default_value' => $config->get('related_content.translation_date_window_days') ?? 2,
      '#min' => 0,
      '#required' => TRUE,
    ];

    $token_counts = $config->get('related_content.title_pattern_token_counts') ?? [10, 8, 6, 4];
    $form['related_content']['title_pattern_token_counts'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title pattern token counts'),
      '#description' => $this->t('Comma-separated token counts for title prefix patterns, e.g. 10, 8, 6, 4.'),
      '#default_value' => implode(', ', $token_counts),
      '#required' => TRUE,
    ];

    $form['related_content']['theme_gate_max_count'] = [
      '#type' => 'number',
      '#title' => $this->t('Theme gate maximum'),
      '#description' => $this->t('Skip country + theme matching when a job or training has more than this many themes.'),
      '#default_value' => $config->get('related_content.theme_gate_max_count') ?? 3,
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['related_content']['boosts'] = [
      '#type' => 'details',
      '#title' => $this->t('Query boost weights'),
      '#open' => FALSE,
      '#tree' => TRUE,
    ];

    $boosts = $config->get('related_content.boosts') ?? [];
    foreach (self::BOOST_LABELS as $key => $label) {
      $form['related_content']['boosts'][$key] = [
        '#type' => 'number',
        '#title' => $label,
        '#default_value' => $boosts[$key] ?? 0,
        '#min' => 0,
        '#required' => TRUE,
      ];
    }

    $form['allowed_social_media_links'] = [
      '#type' => 'details',
      '#title' => $this->t('Allowed social media links'),
      '#description' => $this->t('One entry per line as <em>domain_key|Label</em>, e.g. <em>facebook_com|Facebook</em>. Underscores in the key are converted to dots when matching hostnames.'),
      '#open' => FALSE,
    ];

    $form['allowed_social_media_links']['links'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Social media domains'),
      '#default_value' => self::formatSocialMediaLinks(
        $config->get('allowed_social_media_links') ?? [],
      ),
      '#rows' => 12,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $short_months = (int) $form_state->getValue(['related_content', 'recency_short_months']);
    $long_months = (int) $form_state->getValue(['related_content', 'recency_long_months']);
    if ($long_months < $short_months) {
      $form_state->setErrorByName(
        'related_content][recency_long_months',
        $this->t('The long recency window must be greater than or equal to the short recency window.'),
      );
    }

    $token_counts = self::parseTokenCounts(
      (string) $form_state->getValue(['related_content', 'title_pattern_token_counts']),
    );
    if ($token_counts === []) {
      $form_state->setErrorByName(
        'related_content][title_pattern_token_counts',
        $this->t('Enter at least one positive integer token count.'),
      );
    }
    else {
      $form_state->setValue(['related_content', 'title_pattern_token_counts'], $token_counts);
    }

    $links = self::parseSocialMediaLinks(
      (string) $form_state->getValue(['allowed_social_media_links', 'links']),
    );
    if ($links === NULL) {
      $form_state->setErrorByName(
        'allowed_social_media_links][links',
        $this->t('Each line must use the format domain_key|Label, e.g. facebook_com|Facebook.'),
      );
    }
    else {
      $form_state->setValue(['allowed_social_media_links'], $links);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('reliefweb_entities.settings');

    $config->set('cron', $form_state->getValue('cron'));
    $config->set('related_content', $form_state->getValue('related_content'));
    $config->set(
      'allowed_social_media_links',
      $form_state->getValue('allowed_social_media_links'),
    );

    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Format social media links for the textarea default value.
   *
   * @param array<string, string> $links
   *   Domain key to label map.
   *
   * @return string
   *   Textarea value.
   */
  protected static function formatSocialMediaLinks(array $links): string {
    $lines = [];
    foreach ($links as $domain => $label) {
      $lines[] = $domain . '|' . $label;
    }

    return implode("\n", $lines);
  }

  /**
   * Parse a comma-separated list of token counts.
   *
   * @param string $value
   *   Raw form value.
   *
   * @return int[]
   *   Parsed token counts.
   */
  protected static function parseTokenCounts(string $value): array {
    $counts = [];
    foreach (preg_split('/\s*,\s*/', trim($value)) ?: [] as $part) {
      if ($part === '' || !ctype_digit($part) || (int) $part < 1) {
        return [];
      }
      $counts[] = (int) $part;
    }

    return $counts;
  }

  /**
   * Parse social media links from textarea input.
   *
   * @param string $value
   *   Raw form value.
   *
   * @return array<string, string>|null
   *   Parsed domain key to label map, or NULL when invalid.
   */
  protected static function parseSocialMediaLinks(string $value): ?array {
    $links = [];
    $lines = preg_split('/\R/u', trim($value)) ?: [];

    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }

      if (!str_contains($line, '|')) {
        return NULL;
      }

      [$domain, $label] = array_map('trim', explode('|', $line, 2));
      if ($domain === '' || $label === '' || !preg_match('/^[a-z0-9_]+$/', $domain)) {
        return NULL;
      }

      $links[$domain] = $label;
    }

    return $links;
  }

}
