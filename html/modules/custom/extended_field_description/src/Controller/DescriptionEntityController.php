<?php

namespace Drupal\extended_field_description\Controller;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Url;
use Drupal\extended_field_description\Entity\DescriptionEntityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class DescriptionEntityController.
 *
 *  Returns responses for Description entity routes.
 */
class DescriptionEntityController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\Renderer
   */
  protected $renderer;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->renderer = $container->get('renderer');
    return $instance;
  }

  /**
   * Displays a Description entity revision.
   *
   * @param int $description_entity_revision
   *   The Description entity revision ID.
   *
   * @return array
   *   An array suitable for drupal_render().
   */
  public function revisionShow($description_entity_revision) {
    $description_entity = $this->entityTypeManager()->getStorage('description_entity')
      ->loadRevision($description_entity_revision);
    $view_builder = $this->entityTypeManager()->getViewBuilder('description_entity');

    return $view_builder->view($description_entity);
  }

  /**
   * Page title callback for a Description entity revision.
   *
   * @param int $description_entity_revision
   *   The Description entity revision ID.
   *
   * @return string
   *   The page title.
   */
  public function revisionPageTitle($description_entity_revision) {
    $description_entity = $this->entityTypeManager()->getStorage('description_entity')
      ->loadRevision($description_entity_revision);
    return $this->t('Revision of %title from %date', [
      '%title' => $description_entity->label(),
      '%date' => $this->dateFormatter->format($description_entity->getRevisionCreationTime()),
    ]);
  }

  /**
   * Generates an overview table of older revisions of a Description entity.
   *
   * @param \Drupal\extended_field_description\Entity\DescriptionEntityInterface $description_entity
   *   A Description entity object.
   *
   * @return array
   *   An array as expected by drupal_render().
   */
  public function revisionOverview(DescriptionEntityInterface $description_entity) {
    $account = $this->currentUser();
    $description_entity_storage = $this->entityTypeManager()->getStorage('description_entity');

    $langcode = $description_entity->language()->getId();
    $langname = $description_entity->language()->getName();
    $languages = $description_entity->getTranslationLanguages();
    $has_translations = (count($languages) > 1);
    $build['#title'] = $has_translations ? $this->t('@langname revisions for %title', ['@langname' => $langname, '%title' => $description_entity->label()]) : $this->t('Revisions for %title', ['%title' => $description_entity->label()]);

    $header = [$this->t('Revision'), $this->t('Operations')];
    $revert_permission = (($account->hasPermission("revert all description entity revisions") || $account->hasPermission('administer description entity entities')));
    $delete_permission = (($account->hasPermission("delete all description entity revisions") || $account->hasPermission('administer description entity entities')));

    $rows = [];

    $vids = $description_entity_storage->revisionIds($description_entity);

    $latest_revision = TRUE;

    foreach (array_reverse($vids) as $vid) {
      /** @var \Drupal\extended_field_description\DescriptionEntityInterface $revision */
      $revision = $description_entity_storage->loadRevision($vid);
      // Only show revisions that are affected by the language that is being
      // displayed.
      if ($revision->hasTranslation($langcode) && $revision->getTranslation($langcode)->isRevisionTranslationAffected()) {
        $username = [
          '#theme' => 'username',
          '#account' => $revision->getRevisionUser(),
        ];

        // Use revision link to link to revisions that are not active.
        $date = $this->dateFormatter->format($revision->getRevisionCreationTime(), 'short');
        if ($vid != $description_entity->getRevisionId()) {
          $link = $this->l($date, new Url('entity.description_entity.revision', [
            'description_entity' => $description_entity->id(),
            'description_entity_revision' => $vid,
          ]));
        }
        else {
          $link = $description_entity->link($date);
        }

        $row = [];
        $column = [
          'data' => [
            '#type' => 'inline_template',
            '#template' => '{% trans %}{{ date }} by {{ username }}{% endtrans %}{% if message %}<p class="revision-log">{{ message }}</p>{% endif %}',
            '#context' => [
              'date' => $link,
              'username' => $this->renderer->renderPlain($username),
              'message' => [
                '#markup' => $revision->getRevisionLogMessage(),
                '#allowed_tags' => Xss::getHtmlTagList(),
              ],
            ],
          ],
        ];
        $row[] = $column;

        if ($latest_revision) {
          $row[] = [
            'data' => [
              '#prefix' => '<em>',
              '#markup' => $this->t('Current revision'),
              '#suffix' => '</em>',
            ],
          ];
          foreach ($row as &$current) {
            $current['class'] = ['revision-current'];
          }
          $latest_revision = FALSE;
        }
        else {
          $links = [];
          if ($revert_permission) {
            $links['revert'] = [
              'title' => $this->t('Revert'),
              'url' => $has_translations ?
              Url::fromRoute('entity.description_entity.translation_revert', [
                'description_entity' => $description_entity->id(),
                'description_entity_revision' => $vid,
                'langcode' => $langcode,
              ]) :
              Url::fromRoute('entity.description_entity.revision_revert', [
                'description_entity' => $description_entity->id(),
                'description_entity_revision' => $vid,
              ]),
            ];
          }

          if ($delete_permission) {
            $links['delete'] = [
              'title' => $this->t('Delete'),
              'url' => Url::fromRoute('entity.description_entity.revision_delete', [
                'description_entity' => $description_entity->id(),
                'description_entity_revision' => $vid,
              ]),
            ];
          }

          $row[] = [
            'data' => [
              '#type' => 'operations',
              '#links' => $links,
            ],
          ];
        }

        $rows[] = $row;
      }
    }

    $build['description_entity_revisions_table'] = [
      '#theme' => 'table',
      '#rows' => $rows,
      '#header' => $header,
    ];

    return $build;
  }

}
