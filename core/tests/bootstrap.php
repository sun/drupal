<?php

/**
 * @file
 * Autoloader for Drupal PHPUnit testing.
 *
 * @see phpunit.xml.dist
 */

/**
 * Finds all valid extension directories recursively within a given directory.
 *
 * @param string $scan_directory
 *   The directory that should be recursively scanned.
 * @return array
 *   An associative array of extension directories found within the scanned
 *   directory, keyed by extension name.
 */
function drupal_phpunit_find_extension_directories($scan_directory) {
  $extensions = array();
  $dirs = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($scan_directory, \RecursiveDirectoryIterator::FOLLOW_SYMLINKS));
  foreach ($dirs as $dir) {
    if (strpos($dir->getPathname(), '.info.yml') !== FALSE) {
      // Cut off ".info.yml" from the filename for use as the extension name.
      $extensions[substr($dir->getFilename(), 0, -9)] = $dir->getPathInfo()->getRealPath();
    }
  }
  return $extensions;
}

/**
 * Returns directories under which contributed extensions may exist.
 *
 * @return array
 *   An array of directories under which contributed extensions may exist.
 */
function drupal_phpunit_contrib_extension_directory_roots() {
  $root = dirname(dirname(__DIR__));
  $paths = array(
    $root . '/core/modules',
    $root . '/core/profiles',
    $root . '/modules',
    $root . '/profiles',
  );
  $sites_path = $root . '/sites';
  foreach (scandir($sites_path) as $site) {
    if ($site[0] === '.' || $site === 'simpletest') {
      continue;
    }
    $path = "$sites_path/$site";
    if (is_dir("$path/modules")) {
      $paths[] = "$path/modules";
    }
    if (is_dir("$path/profiles")) {
      $paths[] = "$path/profiles";
    }
  }
  return $paths;
}

/**
 * Registers the namespace for each extension directory with the autoloader.
 *
 * @param array $dirs
 *   An associative array of extension directories, keyed by extension name.
 */
function drupal_phpunit_get_extension_namespaces($dirs) {
  $namespaces = array();
  foreach ($dirs as $extension => $dir) {
    if (is_dir($dir . '/src')) {
      // Register the PSR-4 directory for module-provided classes.
      $namespaces['Drupal\\' . $extension . '\\'][] = $dir . '/src';
    }
    if (is_dir($dir . '/tests/src')) {
      // Register the PSR-4 directory for PHPUnit test classes.
      $namespaces['Drupal\\' . $extension . '\Tests\\'][] = $dir . '/tests/src';
    }
  }
  return $namespaces;
}

// Start with classes in known locations.
$loader = require __DIR__ . '/../vendor/autoload.php';
$loader->add('Drupal\\Tests', __DIR__);

if (!isset($GLOBALS['namespaces'])) {
  // Scan for arbitrary extension namespaces from core and contrib.
  $extension_roots = drupal_phpunit_contrib_extension_directory_roots();

  $dirs = array_map('drupal_phpunit_find_extension_directories', $extension_roots);
  $dirs = array_reduce($dirs, 'array_merge', array());
  $GLOBALS['namespaces'] = drupal_phpunit_get_extension_namespaces($dirs);
}
foreach ($GLOBALS['namespaces'] as $prefix => $paths) {
  $loader->addPsr4($prefix, $paths);
}

// Look into removing these later.
// PHPUnit process isolation template re-defines constants and reloads included
// files (bootstrap.inc) before including this file (bootstrap.php).
// @todo Fix this upstream and/or use a custom child process template.
if (!defined('REQUEST_TIME')) {
  define('REQUEST_TIME', (int) $_SERVER['REQUEST_TIME']);
}
if (!defined('DRUPAL_ROOT')) {
  define('DRUPAL_ROOT', realpath(__DIR__ . '/../../'));
}

// Set sane locale settings, to ensure consistent string, dates, times and
// numbers handling.
// @see \Drupal\Core\DrupalKernel::bootEnvironment()
setlocale(LC_ALL, 'C');

// Set the default timezone. While this doesn't cause any tests to fail, PHP
// complains if 'date.timezone' is not set in php.ini.
date_default_timezone_set('UTC');

// Clean up.
unset($extension_roots, $dirs);
