<?php

/**
 * @file
 * Contains \Drupal\system\Tests\Cache\PhpBackendUnitTest.
 */

namespace Drupal\system\Tests\Cache;

use Drupal\Core\Cache\PhpBackend;

/**
 * Unit test of the PHP cache backend using the generic cache unit test base.
 *
 * @group Cache
 */
class PhpBackendUnitTest extends GenericCacheBackendUnitTestBase {

  protected function setUp() {
    parent::setUp();

    $this->setSetting('php_storage', array(
      'default' => array(
        // tempnam() does not work with stream wrappers.
        'class' => 'Drupal\Component\PhpStorage\FileStorage',
      ),
    ));
  }

  /**
   * Creates a new instance of MemoryBackend.
   *
   * @return
   *   A new MemoryBackend object.
   */
  protected function createCacheBackend($bin) {
    return new PhpBackend($bin);
  }

}
