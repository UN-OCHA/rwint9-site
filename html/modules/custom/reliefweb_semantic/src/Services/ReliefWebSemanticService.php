<?php

namespace Drupal\reliefweb_semantic\Services;

use Aws\BedrockAgentRuntime\BedrockAgentRuntimeClient;

/**
 * ReliefWeb API Drush commandfile.
 */
class ReliefWebSemanticService {

  /**
   * {@inheritdoc}
   */
  public function __construct() {
  }

  /**
   * Query KB.
   */
  public function queryKb(
    string $id,
    string $q,
    string $theme = '',
    string $country = '',
  ) : array {
    $aws_options = reliefweb_semantic_get_aws_client_options();
    $bedrock = new BedrockAgentRuntimeClient($aws_options);

    if (empty($id)) {
      return [];
    }

    $filters = [];
    if (!empty($theme)) {
      $filters['theme'] = str_replace(' ', '', $theme);
    }
    if (!empty($country)) {
      $filters['country'] = str_replace(' ', '', $country);
    }

    $kb_filter = [
      'retrievalConfiguration' => [
        'vectorSearchConfiguration' => [
          'numberOfResults' => 10,
        ],
      ],
    ];

    if (!empty($filters)) {
      if (count($filters) == 1) {
        $key = reset(array_keys($filters));
        $value = reset($filters);
        $kb_filter = [
          'retrievalConfiguration' => [
            'vectorSearchConfiguration' => [
              'filter' => [
                'in' => [
                  'key' => $key,
                  'value' => explode(',', $value),
                ],
              ],
              'numberOfResults' => 10,
            ],
          ],
        ];
      }
      else {
        $all_filters = [];
        foreach ($filters as $key => $value) {
          $all_filters[] = [
            'in' => [
              'key' => $key,
              'value' => explode(',', $value),
            ],
          ];
        }

        $kb_filter = [
          'retrievalConfiguration' => [
            'vectorSearchConfiguration' => [
              'numberOfResults' => 10,
              'overrideSearchType' => 'HYBRID',
              'filter' => [
                'andAll' => $all_filters,
              ],
            ],
          ],
        ];
      }
    }

    $br_options = [
      'knowledgeBaseId' => $id,
      'retrievalQuery' => [
        'text' => $q,
      ],
    ] + $kb_filter;

    $result = $bedrock->retrieve($br_options);

    $result = $result->toArray()['retrievalResults'] ?? [];
    $data = [];

    foreach ($result as $item) {
      $data[$item['metadata']['nid']] = [
        'id' => $item['metadata']['nid'],
        'title' => $item['metadata']['title'],
        'score' => $item['score'],
        'file' => $item['location']['s3Location']['uri'],
        'theme' => $item['metadata']['theme'] ?? [],
        'country' => $item['metadata']['country'] ?? [],
      ];
    }

    return $data;
  }

}
