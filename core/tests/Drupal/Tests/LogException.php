<?php

/**
 * @file
 * Contains \Drupal\Tests\LogException.
 */

namespace Drupal\Tests;

/**
 * Exception thrown in case of unexpected severe log messages.
 */
class LogException extends \PHPUnit_Framework_Exception implements \PHPUnit_Framework_SelfDescribing {

  /**
   * @var int
   */
  protected $severity;

  /**
   * Constructs a new LogException.
   *
   * @param string $message
   *   The log message.
   * @param int $severity
   *   The log message severity.
   * @param \Exception $previous
   *   (optional) A previously thrown exception.
   */
  public function __construct($message = '', $severity = 0, \Exception $previous = NULL) {
    parent::__construct($message, $severity, $previous);
    $this->severity = $severity;
  }

  /**
   * {@inheritdoc}
   */
  public function toString() {
    $names = ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG'];
    if (isset($names[$this->severity])) {
      $name = 'WATCHDOG_' . $names[$this->severity];
    }
    else {
      $name = 'UNKNOWN';
    }
    return $name . ': ' . $this->getMessage();
  }

}
