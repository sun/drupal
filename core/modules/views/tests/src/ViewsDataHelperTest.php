<?php

/**
 * @file
 * Contains \Drupal\views\Tests\ViewsDataHelperTest.
 */

namespace Drupal\views\Tests;

use Drupal\Component\Utility\String;
use Drupal\Tests\UnitTestCase;
use Drupal\views\ViewsDataHelper;

/**
 * @coversDefaultClass \Drupal\views\ViewsDataHelper
 * @group views
 */
class ViewsDataHelperTest extends UnitTestCase {

  /**
   * Returns the views data definition.
   *
   * @return array
   */
  protected function viewsData() {
    $data = ViewTestData::viewsData();

    // Tweak the views data to have a base for testing
    // \Drupal\views\ViewsDataHelper::fetchFields().
    unset($data['views_test_data']['id']['field']);
    unset($data['views_test_data']['name']['argument']);
    unset($data['views_test_data']['age']['filter']);
    unset($data['views_test_data']['job']['sort']);
    $data['views_test_data']['created']['area']['id'] = 'text';
    $data['views_test_data']['age']['area']['id'] = 'text';
    $data['views_test_data']['age']['area']['sub_type'] = 'header';
    $data['views_test_data']['job']['area']['id'] = 'text';
    $data['views_test_data']['job']['area']['sub_type'] = array('header', 'footer');

    return $data;
  }

  /**
   * Tests fetchFields.
   */
  public function testFetchFields() {
    $views_data = $this->getMockBuilder('Drupal\views\ViewsData')
      ->disableOriginalConstructor()
      ->getMock();
    $views_data->expects($this->once())
      ->method('get')
      ->will($this->returnValue($this->viewsData()));

    $data_helper = new ViewsDataHelper($views_data);

    $expected = array(
      'field' => array(
        'age',
        'created',
        'job',
        'name',
        'status',
      ),
      'argument' => array(
        'age',
        'created',
        'id',
        'job',
      ),
      'filter' => array(
        'created',
        'id',
        'job',
        'name',
        'status',
      ),
      'sort' => array(
        'age',
        'created',
        'id',
        'name',
        'status',
      ),
      'area' => array(
        'age',
        'created',
        'job',
      ),
      'header' => array(
        'age',
        'created',
        'job',
      ),
      'footer' => array(
        'age',
        'created',
        'job',
      ),
    );

    $handler_types = array('field', 'argument', 'filter', 'sort', 'area');
    foreach ($handler_types as $handler_type) {
      $fields = $data_helper->fetchFields('views_test_data', $handler_type);
      $expected_keys = $expected[$handler_type];
      array_walk($expected_keys, function(&$item) {
        $item = "views_test_data.$item";
      });
      $this->assertEquals($expected_keys, array_keys($fields), String::format('Handlers of type @handler_type are not listed as expected.', array('@handler_type' => $handler_type)));
    }

    // Check for subtype filtering, so header and footer.
    foreach (array('header', 'footer') as $sub_type) {
      $fields = $data_helper->fetchFields('views_test_data', 'area', FALSE, $sub_type);

      $expected_keys = $expected[$sub_type];
      array_walk($expected_keys, function(&$item) {
        $item = "views_test_data.$item";
      });
      $this->assertEquals($expected_keys, array_keys($fields), String::format('Sub_type @sub_type is not filtered as expected.', array('@sub_type' => $sub_type)));
    }
  }

}
