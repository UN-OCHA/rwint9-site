<?php

use Drupal\ocha_ai_tag\Services\OchaAiTagTagger;
use Drush\Drush;

function processDoc(string $text) {
    // Load vocabularies.
    $mapping = [];
    $term_cache_tags = [];
    $vocabularies = [
      'career_category',
      'theme',
    ];
    foreach ($vocabularies as $vocabulary) {
      $mapping[$vocabulary] = [];

      $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
        'status' => 1,
        'vid' => $vocabulary,
      ]);

      /** @var \Drupal\taxonomy\Entity\Term $term */
      foreach ($terms as $term) {
        $mapping[$vocabulary][$term->getName()] = $term->getDescription() ?? $term->getName();
        $term_cache_tags = array_merge($term_cache_tags, $term->getCacheTags());
      }
    }

    $jobTagger = \Drupal::service('ocha_ai_tag.tagger');
    $data = $jobTagger
      ->setVocabularies($mapping, $term_cache_tags)
      ->tag($text, [OchaAiTagTagger::CALCULATION_METHOD_MEAN_WITH_CUTOFF], OchaAiTagTagger::AVERAGE_FULL_AVERAGE);

    $data = $data[OchaAiTagTagger::AVERAGE_FULL_AVERAGE][OchaAiTagTagger::CALCULATION_METHOD_MEAN_WITH_CUTOFF];

    return $data['career_category'];
}

/** @var \Drush\Drush $this */
$id = $this->input()->getArguments()['extra'][1];
$this->output()->writeln('Processing ' . $id);

$filename = __DIR__ . '/' . $id . '.txt';
if (!file_exists($filename)) {
  $this->output()->writeln('File not found at ' . $filename);
  return;
}

$text = file_get_contents($filename);
$data = processDoc($text);

$this->output()->writeln('## Results for ' . $id);
$this->output()->writeln('');
$this->output()->writeln('| Category | Percentage |');
$this->output()->writeln('| -------- | ---------- |');
foreach ($data as $category => $percentage) {
  $this->output()->writeln('| ' . $category . ' | ' . round(100 * $percentage, 2) . ' |');
}
