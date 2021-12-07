<?php

namespace Drupal\guidelines\Controller;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Url;
use Drupal\guidelines\Entity\GuidelineInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class GuidelineController.
 *
 *  Returns responses for Guideline routes.
 */
class GuidelineController extends ControllerBase implements ContainerInjectionInterface {

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
   * Displays a Guideline revision.
   *
   * @param int $guideline_revision
   *   The Guideline revision ID.
   *
   * @return array
   *   An array suitable for drupal_render().
   */
  public function revisionShow($guideline_revision) {
    $guideline = $this->entityTypeManager()->getStorage('guideline')
      ->loadRevision($guideline_revision);
    $view_builder = $this->entityTypeManager()->getViewBuilder('guideline');

    if ($guideline) {
      return $view_builder->view($guideline);
    }

    return [];
  }

  /**
   * Page title callback for a Guideline revision.
   *
   * @param int $guideline_revision
   *   The Guideline revision ID.
   *
   * @return string
   *   The page title.
   */
  public function revisionPageTitle($guideline_revision) {
    $guideline = $this->entityTypeManager()->getStorage('guideline')
      ->loadRevision($guideline_revision);

    if ($guideline) {
      return $this->t('Revision of %title from %date', [
        '%title' => $guideline->label(),
        '%date' => $this->dateFormatter->format($guideline->getRevisionCreationTime()),
      ]);
    }

    return $this->t('Non existing revision');
  }

  /**
   * Generates an overview table of older revisions of a Guideline.
   *
   * @param \Drupal\guidelines\Entity\GuidelineInterface $guideline
   *   A Guideline object.
   *
   * @return array
   *   An array as expected by drupal_render().
   */
  public function revisionOverview(GuidelineInterface $guideline) {
    $account = $this->currentUser();
    $guideline_storage = $this->entityTypeManager()->getStorage('guideline');

    $langcode = $guideline->language()->getId();
    $langname = $guideline->language()->getName();
    $languages = $guideline->getTranslationLanguages();
    $has_translations = (count($languages) > 1);
    $build['#title'] = $has_translations ? $this->t('@langname revisions for %title', [
      '@langname' => $langname,
      '%title' => $guideline->label(),
    ]) : $this->t('Revisions for %title', [
      '%title' => $guideline->label(),
    ]);

    $header = [$this->t('Revision'), $this->t('Operations')];
    $revert_permission = $account->hasPermission("revert all guideline revisions") || $account->hasPermission('administer guideline entities');
    $delete_permission = $account->hasPermission("delete all guideline revisions") || $account->hasPermission('administer guideline entities');

    $rows = [];

    $vids = $guideline_storage->revisionIds($guideline);

    $latest_revision = TRUE;

    foreach (array_reverse($vids) as $vid) {
      /** @var \Drupal\guidelines\GuidelineInterface $revision */
      $revision = $guideline_storage->loadRevision($vid);
      // Only show revisions that are affected by the language that is being
      // displayed.
      if ($revision->hasTranslation($langcode) && $revision->getTranslation($langcode)->isRevisionTranslationAffected()) {
        $username = [
          '#theme' => 'username',
          '#account' => $revision->getRevisionUser(),
        ];

        // Use revision link to link to revisions that are not active.
        $date = $this->dateFormatter->format($revision->getRevisionCreationTime(), 'short');
        if ($vid != $guideline->getRevisionId()) {
          $link = $this->l($date, new Url('entity.guideline.revision', [
            'guideline' => $guideline->id(),
            'guideline_revision' => $vid,
          ]));
        }
        else {
          $link = $guideline->toLink($date)->toString();
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
              Url::fromRoute('entity.guideline.translation_revert', [
                'guideline' => $guideline->id(),
                'guideline_revision' => $vid,
                'langcode' => $langcode,
              ]) :
              Url::fromRoute('entity.guideline.revision_revert', [
                'guideline' => $guideline->id(),
                'guideline_revision' => $vid,
              ]),
            ];
          }

          if ($delete_permission) {
            $links['delete'] = [
              'title' => $this->t('Delete'),
              'url' => Url::fromRoute('entity.guideline.revision_delete', [
                'guideline' => $guideline->id(),
                'guideline_revision' => $vid,
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

    $build['guideline_revisions_table'] = [
      '#theme' => 'table',
      '#rows' => $rows,
      '#header' => $header,
    ];

    return $build;
  }

}
