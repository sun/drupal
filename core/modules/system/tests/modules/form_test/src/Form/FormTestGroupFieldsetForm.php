<?php

/**
 * @file
 * Contains \Drupal\form_test\Form\FormTestGroupFieldsetForm.
 */

namespace Drupal\form_test\Form;

use Drupal\Core\Form\FormBase;

/**
 * Builds a simple form to test the #group property on #type 'fieldset'.
 */
class FormTestGroupFieldsetForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'form_test_group_fieldset';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, array &$form_state) {
    $form['fieldset'] = array(
      '#type' => 'fieldset',
      '#title' => 'Fieldset',
    );
    $form['meta'] = array(
      '#type' => 'container',
      '#title' => 'Group element',
      '#group' => 'fieldset',
    );
    $form['meta']['element'] = array(
      '#type' => 'textfield',
      '#title' => 'Nest in container element',
    );
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, array &$form_state) {
  }

}
