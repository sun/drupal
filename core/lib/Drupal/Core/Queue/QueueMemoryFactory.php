<?php

/**
 * @file
 * Contains \Drupal\Core\Queue\QueueMemoryFactory.
 */

namespace Drupal\Core\Queue;

/**
 * Defines the queue factory for the memory backend.
 */
class QueueMemoryFactory {

  /**
   * Constructs a new queue object for a given name.
   *
   * @param string $name
   *   The name of the collection holding key and value pairs.
   *
   * @return \Drupal\Core\Queue\Memory
   *   A queue implementation for the given $collection.
   */
  public function get($name) {
    return new Memory($name);
  }

}
