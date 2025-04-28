<?php

declare(strict_types=1);

namespace Drupal\reliefweb_import\Plugin\OchaContentAnalyzableFieldProcessor;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ocha_content_classification\Attribute\OchaContentAnalyzableFieldProcessor;
use Drupal\ocha_content_classification\Plugin\OchaContentAnalyzableFieldProcessor\StripAndTrimProcessor;

/**
 * ReliefWeb body processor.
 */
#[OchaContentAnalyzableFieldProcessor(
  id: 'reliefweb_body',
  label: new TranslatableMarkup('ReliefWeb Body'),
  description: new TranslatableMarkup('Process the ReliefWeb body field, converting to markdown and prepending the title.'),
  types: [
    'text_with_summary',
  ]
)]
class ReliefWebBodyProcessor extends StripAndTrimProcessor {

  /**
   * {@inheritdoc}
   */
  public function toFiles(string $placeholder, FieldItemListInterface $field): array {
    $files = parent::toFiles($placeholder, $field);

    // Prepend the title to have better context.
    if (!empty($files)) {
      $title = trim((string) $field->getEntity()->label());
      foreach ($files as $index => $file) {
        if (!empty($file['data'])) {
          $data = $file['data'];
          $files[$index]['data'] = match($file['mimetype']) {
            'text/markdown' => "# $title\n\n$data",
            'text/html' =>  "<h1>$title</h1>\n$data",
            default => "$title\n\n$data",
          };
        }
      }
    }

    return $files;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(FieldDefinitionInterface $field_definition): bool {
    $supported = parent::supports($field_definition);
    return $supported && $field_definition->getName() === 'body';
  }

}
