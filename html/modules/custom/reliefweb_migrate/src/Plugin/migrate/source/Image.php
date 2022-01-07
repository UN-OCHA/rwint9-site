<?php

namespace Drupal\reliefweb_migrate\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\reliefweb_utility\Helpers\LegacyHelper;

/**
 * Retrieve images from the Drupal 7 database.
 *
 * @MigrateSource(
 *   id = "reliefweb_image"
 * )
 */
class Image extends EntityBase {

  /**
   * {@inheritdoc}
   */
  protected $idField = 'fid';

  /**
   * Directory replacements.
   *
   * @var array
   */
  protected $directoryReplacements = [
    'announcements' => 'images/announcements',
    'attached-images' => 'images/blog-posts',
    'blog-post-images' => 'images/blog-posts',
    'headline-images' => 'images/reports',
    'report-images' => 'images/reports',
    'topic-icons' => 'images/topics',
  ];

  /**
   * Directory per entity bundle.
   *
   * @var array
   */
  protected $directoriesByBundle = [
    'announcement' => 'images/announcements',
    'blog_post' => 'images/blog-posts',
    'report' => 'images/reports',
    'topics' => 'images/topics',
  ];

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('file_managed', 'fm')
      ->fields('fm')
      ->condition('fm.filemime', 'image/%', 'LIKE');

    // @todo check if we want to port the images from the legacy
    // field_report_image field.
    $fields = [
      'field_attached_images' => [
        'node' => [
          'blog_post',
        ],
      ],
      'field_headline_image' => [
        'node' => [
          'report',
        ],
      ],
      'field_image' => [
        'node' => [
          'announcement',
          'blog_post',
          'report',
        ],
      ],
      'field_term_image' => [
        'node' => [
          'topics',
        ],
      ],
    ];

    // Limit to the file ids in the used fields, ignoring legacy one.
    $union_query = NULL;
    foreach ($fields as $field => $entity_types) {
      foreach ($entity_types as $entity_type => $bundles) {
        $table = 'field_data_' . $field;

        $sub_query = $this->select($table, $table);
        $sub_query->addField($table, $field . '_fid', 'fid');
        // Store the entity bundle using the file. On RW, there are no
        // files shared across entities so it's 1-1 mapping normally.
        $sub_query->addField($table, 'bundle', 'usage_bundle');
        $sub_query->condition($table . '.entity_type', $entity_type, '=');
        $sub_query->condition($table . '.bundle', $bundles, 'IN');

        if (!isset($union_query)) {
          $union_query = $sub_query;
        }
        else {
          $union_query->union($sub_query);
        }
      }
    }
    $query->innerJoin($union_query, 'u', 'u.fid = fm.fid');
    $query->addField('u', 'usage_bundle', 'usage_bundle');

    // Limit to files in use.
    $query->innerJoin('file_usage', 'fu', 'fu.fid = fm.fid');
    $query->condition('fu.count', 0, '>');

    $query->orderBy('fm.fid', 'ASC');
    return $query->distinct();
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    // Ex: public://report-images/example.png.
    $uri = $row->getSourceProperty('uri');

    // Generate the UUID based on the URI.
    $uuid = LegacyHelper::generateImageUuid($uri);

    // Note: the locale is assumed to be UTF-8.
    $info = pathinfo($uri);

    // Replace the image directory. We do that after generating the UUID to
    // avoid collisions between blog post images as they will all end up in the
    // same `/images/blog-posts/` directory.
    $dirname = strtr($info['dirname'], $this->directoryReplacements);

    // Some old files like topic icons are not stored in a proper directory.
    // We can detect that by checking if a directory replacement occured.
    // If that's not the case, we assign a directory based on the bundle of
    // the entity using the file.
    if ($dirname === $info['dirname']) {
      $bundle = $row->getSourceProperty('usage_bundle');
      $dirname = 'public://' . $this->directoriesByBundle[$bundle];
    }

    // Use the existing directory + the first 4 letters of the uuid.
    $directory = implode('/', [
      $dirname,
      substr($uuid, 0, 2),
      substr($uuid, 2, 2),
    ]);

    // We use the UUID as filename, preserving only the extension so that
    // the URI is short and predictable.
    $new_uri = $directory . '/' . $uuid . '.' . $info['extension'];

    // Set the new file URI.
    $row->setSourceProperty('uri', $new_uri);

    // Save the UUID.
    $row->setSourceProperty('uuid', $uuid);

    // Store the old URI so it can be saved in the mapping table.
    $row->setSourceProperty('old_uri', $uri);

    return parent::prepareRow($row);
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'uuid' => $this->t('File UUID based on its URI'),
      'fid' => $this->t('File ID'),
      'uid' => $this->t('The {users}.uid who added the file. If set to 0, this file was added by an anonymous user.'),
      'filename' => $this->t('File name'),
      'uri' => $this->t('The URI to access the file'),
      'filemime' => $this->t('File MIME Type'),
      'status' => $this->t('The published status of a file.'),
      'timestamp' => $this->t('The time that the file was added.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['fid']['type'] = 'integer';
    $ids['fid']['alias'] = 'fm';
    return $ids;
  }

}
