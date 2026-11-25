<?php

declare(strict_types=1);

namespace Drupal\reliefweb_mcp\Plugin\mcp_server\ResourceProvider;

use Drupal\Component\FrontMatter\FrontMatter;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mcp_server\Attribute\ResourceProvider;
use Drupal\mcp_server\Plugin\ResourceProviderBase;
use Drupal\mcp_server\Resource\CacheableResourceContent;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Return a list of skills.
 */
#[ResourceProvider(
  id: 'rw_skills',
  label: new TranslatableMarkup('ReliefWeb Skills'),
  description: new TranslatableMarkup('Provides a list of ReliefWeb skills.'),
)]
final class SkillProvider extends ResourceProviderBase {

  public const SKILL_URI = 'skill://list';

  /**
   * {@inheritdoc}
   */
  protected function defaultConfiguration(): array {
    return [
      'enabled' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function checkAccess(string $uri, AccountInterface $account): AccessResultInterface {
    return AccessResult::allowed();
  }

  /**
   * Constructs a SkillProvider.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleExtensionList
   *   The module extension list service.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $entityTypeManager,
      $currentUser,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('extension.list.module'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getResources(): array {
    $output = [];

    // Get a list of directories from html/modules/custom/reliefweb_mcp/skills.
    $skillsDir = $this->moduleExtensionList->getPath('reliefweb_mcp') . '/skills';
    $frontMatter = FrontMatter::create(file_get_contents($skillsDir . '/reliefweb/SKILL.md'));
    $data = $frontMatter->getData();

    $output[] = [
      'uri' => self::SKILL_URI,
      'name' => 'Skills',
      'description' => $data['description'] ?? 'Provides a list of ReliefWeb skills.',
    ];

    $output = array_merge($output, $this->returnListOfSkills());

    return $output;
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\mcp_server\Resource\CacheableResourceContent|null
   *   The resource content or NULL.
   */
  public function getResourceContent(string $uri): ?CacheableResourceContent {
    if ($uri == self::SKILL_URI) {
      $skillsDir = $this->moduleExtensionList->getPath('reliefweb_mcp') . '/skills';
      $frontMatter = FrontMatter::create(file_get_contents($skillsDir . '/reliefweb/SKILL.md'));
      return CacheableResourceContent::fromArray([
        'uri' => $uri,
        'text' => $frontMatter->getContent(),
      ]);
    }

    if (!preg_match('#^skill://reliefweb_([a-zA-Z0-9_-]+)$#', $uri, $matches)) {
      return NULL;
    }

    $skillsDir = $this->moduleExtensionList->getPath('reliefweb_mcp') . '/skills/' . $matches[1];
    if (!is_dir($skillsDir)) {
      return NULL;
    }

    // Read the SKILL.md file in the skill directory.
    $skillFile = $skillsDir . '/SKILL.md';
    if (!file_exists($skillFile)) {
      return NULL;
    }

    $skillContent = FrontMatter::create(file_get_contents($skillFile))->getContent();

    return CacheableResourceContent::fromArray([
      'uri' => $uri,
      'text' => $skillContent,
    ]);
  }

  /**
   * Returns a list of available skills.
   */
  protected function returnListOfSkills(): array {
    $skillsDir = $this->moduleExtensionList->getPath('reliefweb_mcp') . '/skills';
    $directories = array_filter(glob($skillsDir . '/*'), 'is_dir');
    $skills = array_map(fn($dir) => basename($dir), $directories);
    $skills = array_filter($skills, fn($skill) => $skill !== 'reliefweb');
    $output = [];
    foreach ($skills as $skill) {
      $frontMatter = FrontMatter::create(file_get_contents($skillsDir . '/' . $skill . '/SKILL.md'));
      $output[] = [
        'name' => ucfirst($skill),
        'uri' => 'skill://reliefweb_' . $skill,
        'description' => $frontMatter->getData()['description'] ?? '',
      ];
    }
    return $output;
  }

}
