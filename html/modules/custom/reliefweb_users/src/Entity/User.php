<?php

namespace Drupal\reliefweb_users\Entity;

use Drupal\Core\Entity\RevisionLogEntityTrait;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\user\Entity\User as UserBase;
use Drupal\reliefweb_revisions\EntityRevisionedInterface;
use Drupal\reliefweb_revisions\EntityRevisionedTrait;

/**
 * User entity class with revision helpers.
 */
class User extends UserBase implements EntityRevisionedInterface, RevisionLogInterface {

  use EntityRevisionedTrait;
  use RevisionLogEntityTrait;

}
