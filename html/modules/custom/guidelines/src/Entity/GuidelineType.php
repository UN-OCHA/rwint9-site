<?php

namespace Drupal\guidelines\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;

/**
 * Defines the Guideline type entity.
 *
 * @ConfigEntityType(
 *   id = "guideline_type",
 *   label = @Translation("Guideline type"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\guidelines\GuidelineTypeListBuilder",
 *     "form" = {
 *       "add" = "Drupal\guidelines\Form\GuidelineTypeForm",
 *       "edit" = "Drupal\guidelines\Form\GuidelineTypeForm",
 *       "delete" = "Drupal\guidelines\Form\GuidelineTypeDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\guidelines\GuidelineTypeHtmlRouteProvider",
 *     },
 *   },
 *   config_prefix = "guideline_type",
 *   admin_permission = "administer site configuration",
 *   bundle_of = "guideline",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *   },
 *   links = {
 *     "canonical" = "/admin/structure/guideline_type/{guideline_type}",
 *     "add-form" = "/admin/structure/guideline_type/add",
 *     "edit-form" = "/admin/structure/guideline_type/{guideline_type}/edit",
 *     "delete-form" = "/admin/structure/guideline_type/{guideline_type}/delete",
 *     "collection" = "/admin/structure/guideline_type"
 *   }
 * )
 */
class GuidelineType extends ConfigEntityBundleBase implements GuidelineTypeInterface {

  /**
   * The Guideline type ID.
   *
   * @var string
   */
  protected $id;

  /**
   * The Guideline type label.
   *
   * @var string
   */
  protected $label;

}
