<?php

/**
 * @file
 * Contains \Drupal\simpletest\TestBaseTest.
 */

namespace Drupal\simpletest\Tests;

use Drupal\simpletest\TestBase;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\simpletest\TestBase
 * @group simpletest
 */
class TestBaseTest extends UnitTestCase {

  /**
   * TestBase class stub.
   *
   * @var \Drupal\simpletest\TestBase
   */
  private $stub;

  protected function setUp() {
    $this->stub = new StubTestBase(0);
  }

  /**
   * Provides data for the random string validation test.
   *
   * @return array
   *   An array of values passed to the test method.
   */
  public function randomStringValidateProvider () {
    return array(
      array(' curry paste', FALSE),
      array('curry paste ', FALSE),
      array('curry  paste', FALSE),
      array('curry   paste', FALSE),
      array('curry paste', TRUE),
      array('thai green curry paste', TRUE),
      array('@startswithat', FALSE),
      array('contains@at', TRUE),
    );
  }

  /**
   * Tests the random strings validation rules.
   *
   * @param string $string
   *   The string to validate.
   * @param bool $expected
   *   The expected result of the validation.
   *
   * @see \Drupal\simpletest\TestBase::randomStringValidate().
   *
   * @dataProvider randomStringValidateProvider
   * @covers ::randomStringValidate
   */
  public function testRandomStringValidate($string, $expected) {
    $actual = $this->stub->randomStringValidate($string);
    $this->assertEquals($expected, $actual);
  }

}

class StubTestBase extends TestBase {

  public function randomStringValidate($string) {
    return parent::randomStringValidate($string);
  }

  protected function setUp() {
  }

}
