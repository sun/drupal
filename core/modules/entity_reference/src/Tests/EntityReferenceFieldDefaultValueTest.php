<?php

/**
 * @file
 * Contains \Drupal\entity_reference\Tests\EntityReferenceFieldDefaultValueTest.
 */

namespace Drupal\entity_reference\Tests;

use Drupal\simpletest\WebTestBase;

/**
 * Tests entity reference field default values storage in CMI.
 *
 * @group entity_reference
 */
class EntityReferenceFieldDefaultValueTest extends WebTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('entity_reference', 'field_ui', 'node', 'options');

  function setUp() {
    parent::setUp();

    // Create default content type.
    $this->drupalCreateContentType(array('type' => 'reference_content'));
    $this->drupalCreateContentType(array('type' => 'referenced_content'));

    // Create admin user.
    $this->admin_user = $this->drupalCreateUser(array('access content', 'administer content types', 'administer node fields', 'administer node form display', 'bypass node access'));
    $this->drupalLogin($this->admin_user);
  }

  /**
   * Tests that default values are correctly translated to UUIDs in config.
   */
  function testEntityReferenceDefaultValue() {
    // Create a node to be referenced.
    $referenced_node = $this->drupalCreateNode(array('type' => 'referenced_content'));

    $this->fieldStorage = entity_create('field_storage_config', array(
      'name' => drupal_strtolower($this->randomName()),
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => array('target_type' => 'node'),
    ));
    $this->fieldStorage->save();
    $this->instance = entity_create('field_instance_config', array(
      'field_storage' => $this->fieldStorage,
      'bundle' => 'reference_content',
      'settings' => array(
        'handler' => 'default',
        'handler_settings' => array(
          'target_bundles' => array('referenced_content'),
          'sort' => array('field' => '_none'),
        ),
      ),
    ));
    $this->instance->save();

    // Set created node as default_value.
    $instance_edit = array(
      'default_value_input[' . $this->fieldStorage->name . '][0][target_id]' => $referenced_node->getTitle() . ' (' .$referenced_node->id() . ')',
    );
    $this->drupalPostForm('admin/structure/types/manage/reference_content/fields/node.reference_content.' . $this->fieldStorage->name, $instance_edit, t('Save settings'));

    // Check that default value is selected in default value form.
    $this->drupalGet('admin/structure/types/manage/reference_content/fields/node.reference_content.' . $this->fieldStorage->name);
    $this->assertRaw('name="default_value_input[' . $this->fieldStorage->name . '][0][target_id]" value="' . $referenced_node->getTitle() .' (' .$referenced_node->id() . ')', 'The default value is selected in instance settings page');

    // Check if the ID has been converted to UUID in config entity.
    $config_entity = $this->container->get('config.factory')->get('field.instance.node.reference_content.' . $this->fieldStorage->name)->get();
    $this->assertTrue(isset($config_entity['default_value'][0]['target_uuid']), 'Default value contains target_uuid property');
    $this->assertEqual($config_entity['default_value'][0]['target_uuid'], $referenced_node->uuid(), 'Content uuid and config entity uuid are the same');

    // Clear field definitions cache in order to avoid stale cache values.
    \Drupal::entityManager()->clearCachedFieldDefinitions();

    // Create a new node to check that UUID has been converted to numeric ID.
    $new_node = entity_create('node', array('type' => 'reference_content'));
    $this->assertEqual($new_node->get($this->fieldStorage->name)->offsetGet(0)->target_id, $referenced_node->id());
  }

}
