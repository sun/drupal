<?php

/**
 * @file
 * Definition of Drupal\system\Tests\Database\DatabaseTestBase.
 */

namespace Drupal\system\Tests\Database;

use Drupal\Core\Database\Database;
use Drupal\simpletest\DrupalUnitTestBase;

/**
 * Base class for databases database tests.
 *
 * Because all database tests share the same test data, we can centralize that
 * here.
 */
abstract class DatabaseTestBase extends DrupalUnitTestBase {

  public static $modules = array('database_test');

  /**
   * @var string
   */
  protected $sqliteDb;

  function setUp() {
    parent::setUp();
    $this->installSchema('database_test', array(
      'test',
      'test_people',
      'test_people_copy',
      'test_one_blob',
      'test_two_blobs',
      'test_task',
      'test_null',
      'test_serialized',
    ));
    self::addSampleData();
  }

  /**
   * {@inheritdoc}
   *
   * - SQLite :memory: creates a new database for each connection.
   * - PHP's SQLite extension does not support stream wrappers.
   * - Shared in-memory database DSNs trigger open_basedir restrictions.
   *
   * Therefore, create a real database file on disk.
   *
   * @see http://www.sqlite.org/inmemorydb.html
   * @see https://bugs.php.net/bug.php?id=55154
   */
  protected function getDatabaseConnectionInfo() {
    $this->sqliteDb = tempnam(sys_get_temp_dir(), 'sql');

    $databases['default']['default'] = array(
      'driver' => 'sqlite',
      'namespace' => 'Drupal\\Core\\Database\\Driver\\sqlite',
      'database' => $this->sqliteDb,
    );
    return $databases;
  }

  protected function tearDown() {
    // Tear down all database connections and the container first.
    parent::tearDown();
    // Permission denied on Windows.
    @unlink($this->sqliteDb);
    unset($this->sqliteDb);
  }

  /**
   * Sets up tables for NULL handling.
   */
  function ensureSampleDataNull() {
    db_insert('test_null')
    ->fields(array('name', 'age'))
    ->values(array(
      'name' => 'Kermit',
      'age' => 25,
    ))
    ->values(array(
      'name' => 'Fozzie',
      'age' => NULL,
    ))
    ->values(array(
      'name' => 'Gonzo',
      'age' => 27,
    ))
    ->execute();
  }

  /**
   * Sets up our sample data.
   */
  static function addSampleData() {
    // We need the IDs, so we can't use a multi-insert here.
    $john = db_insert('test')
      ->fields(array(
        'name' => 'John',
        'age' => 25,
        'job' => 'Singer',
      ))
      ->execute();

    $george = db_insert('test')
      ->fields(array(
        'name' => 'George',
        'age' => 27,
        'job' => 'Singer',
      ))
      ->execute();

    db_insert('test')
      ->fields(array(
        'name' => 'Ringo',
        'age' => 28,
        'job' => 'Drummer',
      ))
      ->execute();

    $paul = db_insert('test')
      ->fields(array(
        'name' => 'Paul',
        'age' => 26,
        'job' => 'Songwriter',
      ))
      ->execute();

    db_insert('test_people')
      ->fields(array(
        'name' => 'Meredith',
        'age' => 30,
        'job' => 'Speaker',
      ))
      ->execute();

    db_insert('test_task')
      ->fields(array('pid', 'task', 'priority'))
      ->values(array(
        'pid' => $john,
        'task' => 'eat',
        'priority' => 3,
      ))
      ->values(array(
        'pid' => $john,
        'task' => 'sleep',
        'priority' => 4,
      ))
      ->values(array(
        'pid' => $john,
        'task' => 'code',
        'priority' => 1,
      ))
      ->values(array(
        'pid' => $george,
        'task' => 'sing',
        'priority' => 2,
      ))
      ->values(array(
        'pid' => $george,
        'task' => 'sleep',
        'priority' => 2,
      ))
      ->values(array(
        'pid' => $paul,
        'task' => 'found new band',
        'priority' => 1,
      ))
      ->values(array(
        'pid' => $paul,
        'task' => 'perform at superbowl',
        'priority' => 3,
      ))
      ->execute();
  }
}
