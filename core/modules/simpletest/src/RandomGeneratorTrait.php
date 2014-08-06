<?php

/**
 * @file
 * Contains \Drupal\simpletest\RandomGeneratorTrait.
 */

namespace Drupal\simpletest;

use Drupal\Component\Utility\Random;

/**
 * Provides random generator utility methods.
 */
trait RandomGeneratorTrait {

  /**
   * The random generator.
   *
   * @var \Drupal\Component\Utility\Random
   */
  protected $randomGenerator;

  /**
   * Generates a unique random string of ASCII characters of codes 32 to 126.
   *
   * Do not use this method when special characters are not possible (e.g., in
   * machine or file names that have already been validated); instead, use
   * randomMachineName().
   *
   * @param int $length
   *   Length of random string to generate.
   *
   * @return string
   *   Randomly generated unique string.
   *
   * @see \Drupal\Component\Utility\Random::string()
   */
  protected function randomString($length = 8) {
    return $this->getRandomGenerator()->string($length, TRUE, array($this, 'randomStringValidate'));
  }

  /**
   * Callback for random string validation.
   *
   * @see \Drupal\Component\Utility\Random::string()
   *
   * @param string $string
   *   The random string to validate.
   *
   * @return bool
   *   TRUE if the random string is valid, FALSE if not.
   */
  protected function randomStringValidate($string) {
    // Consecutive spaces causes issues for
    // Drupal\simpletest\WebTestBase::assertLink().
    if (preg_match('/\s{2,}/', $string)) {
      return FALSE;
    }
    // Starting with a space means that length might not be what is expected.
    // Starting with an @ sign causes CURL to fail if used in conjunction with a
    // file upload, see https://drupal.org/node/2174997.
    if (preg_match('/^(\s|@)/', $string)) {
      return FALSE;
    }
    // Ending with a space means that length might not be what is expected.
    if (preg_match('/\s$/', $string)) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Generates a unique random string containing letters and numbers.
   *
   * Do not use this method when testing unvalidated user input. Instead, use
   * \Drupal\simpletest\TestBase::randomString().
   *
   * @param int $length
   *   Length of random string to generate.
   *
   * @return string
   *   Randomly generated unique string.
   *
   * @see \Drupal\Component\Utility\Random::name()
   */
  protected function randomMachineName($length = 8) {
    return $this->getRandomGenerator()->name($length, TRUE);
  }

  /**
   * Generates a random PHP object.
   *
   * @param int $size
   *   The number of random keys to add to the object.
   *
   * @return \stdClass
   *   The generated object, with the specified number of random keys. Each key
   *   has a random string value.
   *
   * @see \Drupal\Component\Utility\Random::object()
   */
  protected function randomObject($size = 4) {
    return $this->getRandomGenerator()->object($size);
  }

  /**
   * Gets the random generator for the utility methods.
   *
   * @return \Drupal\Component\Utility\Random
   *   The random generator.
   */
  protected function getRandomGenerator() {
    if (!is_object($this->randomGenerator)) {
      $this->randomGenerator = new Random();
    }
    return $this->randomGenerator;
  }

}
