<?php

// @codingStandardsIgnoreFile

/**
 * @file
 * Local development override configuration feature.
 *
 * To activate this feature, copy and rename it such that its path plus
 * filename is 'sites/default/settings.local.php'. Then, go to the bottom of
 * 'sites/default/settings.php' and uncomment the commented lines that mention
 * 'settings.local.php'.
 *
 * If you are using a site name in the path, such as 'sites/example.com', copy
 * this file to 'sites/example.com/settings.local.php', and uncomment the lines
 * at the bottom of 'sites/example.com/settings.php'.
 */

/**
 * Enable local development services.
 */
$settings['container_yamls'][] = '/srv/www/shared/settings/services.yml';

/**
 * Show all error messages, with backtrace information.
 *
 * In case the error level could not be fetched from the database, as for
 * example the database connection failed, we rely only on this value.
 */
$config['system.logging']['error_level'] = 'verbose';

/**
 * Disable CSS and JS aggregation.
 */
$config['system.performance']['css']['preprocess'] = FALSE;
$config['system.performance']['js']['preprocess'] = FALSE;

/**
 * Disable the render cache.
 *
 * Note: you should test with the render cache enabled, to ensure the correct
 * cacheability metadata is present. However, in the early stages of
 * development, you may want to disable it.
 *
 * This setting disables the render cache by using the Null cache back-end
 * defined by the development.services.yml file above.
 *
 * Only use this setting once the site has been installed.
 */
# $settings['cache']['bins']['render'] = 'cache.backend.null';

/**
 * Disable caching for migrations.
 *
 * Uncomment the code below to only store migrations in memory and not in the
 * database. This makes it easier to develop custom migrations.
 */
# $settings['cache']['bins']['discovery_migration'] = 'cache.backend.memory';

/**
 * Disable Internal Page Cache.
 *
 * Note: you should test with Internal Page Cache enabled, to ensure the correct
 * cacheability metadata is present. However, in the early stages of
 * development, you may want to disable it.
 *
 * This setting disables the page cache by using the Null cache back-end
 * defined by the development.services.yml file above.
 *
 * Only use this setting once the site has been installed.
 */
# $settings['cache']['bins']['page'] = 'cache.backend.null';

/**
 * Disable Dynamic Page Cache.
 *
 * Note: you should test with Dynamic Page Cache enabled, to ensure the correct
 * cacheability metadata is present (and hence the expected behavior). However,
 * in the early stages of development, you may want to disable it.
 */
# $settings['cache']['bins']['dynamic_page_cache'] = 'cache.backend.null';

/**
 * Allow test modules and themes to be installed.
 *
 * Drupal ignores test modules and themes by default for performance reasons.
 * During development it can be useful to install test extensions for debugging
 * purposes.
 */
# $settings['extension_discovery_scan_tests'] = TRUE;

/**
 * Enable access to rebuild.php.
 *
 * This setting can be enabled to allow Drupal's php and database cached
 * storage to be cleared via the rebuild.php page. Access to this page can also
 * be gained by generating a query string from rebuild_token_calculator.sh and
 * using these parameters in a request to rebuild.php.
 */
$settings['rebuild_access'] = TRUE;

/**
 * Skip file system permissions hardening.
 *
 * The system module will periodically check the permissions of your site's
 * site directory to ensure that it is not writable by the website user. For
 * sites that are managed with a version control system, this can cause problems
 * when files in that directory such as settings.php are updated, because the
 * user pulling in the changes won't have permissions to modify files in the
 * directory.
 */
$settings['skip_permissions_hardening'] = TRUE;

// Workaround for permission issues with NFS shares
$settings['file_chmod_directory'] = 0777;
$settings['file_chmod_file'] = 0666;

# File system settings.
$config['system.file']['path']['temporary'] = '/tmp';
$settings['file_private_path'] = '/srv/www/html/sites/default/private';

// Default sync directory.
$settings['config_sync_directory'] = '/srv/www/config';

// Hash salt.
$settings['hash_salt'] = 'rwint9-test-site-salt';

// Memcache.
if (file_exists('sites/default/memcache.services.yml')) {
  // Add our memcache services definitions. This file is added by the docker build.
  $settings['container_yamls'][] = 'sites/default/memcache.services.yml';
  // Add our memcache services definitions.
  if (file_exists('modules/contrib/memcache/memcache.services.yml')) {
    $settings['container_yamls'][] = 'modules/contrib/memcache/memcache.services.yml';
  }
  else if (file_exists('modules/memcache/memcache.services.yml')) {
    $settings['container_yamls'][] = 'modules/memcache/memcache.services.yml';
  }
  // Configure memcache.
  $settings['memcache']['servers']    = ['memcache:11211' => 'default'];
  $settings['memcache']['bins']       = ['default' => 'default'];
  $settings['memcache']['key_prefix'] = 'rwint9-test';
  $settings['cache']['default']       = 'cache.backend.memcache';

  // Performance tweaks.
  $settings['memcache']['options'] = [
    Memcached::OPT_COMPRESSION => TRUE,
    Memcached::OPT_DISTRIBUTION => Memcached::DISTRIBUTION_CONSISTENT,
  ];

  // Stick the bootstrap container in memcache, too!
  $class_loader->addPsr4('Drupal\\memcache\\', 'modules/contrib/memcache/src');

  // Define custom bootstrap container definition to use Memcache for cache.container.
  $settings['bootstrap_container_definition'] = [
    'parameters' => [],
    'services' => [
      # Dependencies.
      'settings' => [
        'class' => 'Drupal\Core\Site\Settings',
        'factory' => 'Drupal\Core\Site\Settings::getInstance',
      ],
      'request_stack' => [
        'class' => 'Symfony\Component\HttpFoundation\RequestStack',
        'tags' => ['name' => 'persist'],
      ],
      'datetime.time' => [
        'class' => 'Drupal\Component\Datetime\Time',
        'arguments' => ['@request_stack'],
      ],
      'memcache.settings' => [
        'class' => 'Drupal\memcache\MemcacheSettings',
        'arguments' => ['@settings'],
      ],
      'memcache.factory' => [
        'class' => 'Drupal\memcache\Driver\MemcacheDriverFactory',
        'arguments' => ['@memcache.settings'],
      ],
      'memcache.timestamp.invalidator.bin' => [
        'class' => 'Drupal\memcache\Invalidator\MemcacheTimestampInvalidator',
        # Adjust tolerance factor as appropriate when not running memcache on localhost.
        'arguments' => ['@memcache.factory', 'memcache_bin_timestamps', 0.001],
      ],
      'memcache.timestamp.invalidator.tag' => [
        'class' => 'Drupal\memcache\Invalidator\MemcacheTimestampInvalidator',
        # Remember to update your main service definition in sync with this!
        # Adjust tolerance factor as appropriate when not running memcache on localhost.
        'arguments' => ['@memcache.factory', 'memcache_tag_timestamps', 0.001],
      ],
      'memcache.backend.cache.container' => [
        'class' => 'Drupal\memcache\DrupalMemcacheInterface',
        'factory' => ['@memcache.factory', 'get'],
        # Actual cache bin to use for the container cache.
        'arguments' => ['container'],
      ],
      # Define a custom cache tags invalidator for the bootstrap container.
      'cache_tags_provider.container' => [
        'class' => 'Drupal\memcache\Cache\TimestampCacheTagsChecksum',
        'arguments' => ['@memcache.timestamp.invalidator.tag'],
      ],
      'cache.container' => [
        'class' => 'Drupal\memcache\MemcacheBackend',
        'arguments' => ['container', '@memcache.backend.cache.container', '@cache_tags_provider.container', '@memcache.timestamp.invalidator.bin', '@datetime.time'],
      ],
    ],
  ];
}

$settings['cache']['bins']['config'] = 'cache.backend.null';
