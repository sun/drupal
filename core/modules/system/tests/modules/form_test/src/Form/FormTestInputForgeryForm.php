<?php

/**
 * @file
 * Contains Drupal/form_test/FormTestInputForgeryForm.
 */

namespace Drupal\form_test\Form;

use Drupal\Core\Form\FormBase;
use Symfony\Component\HttpFoundation\JsonResponse;

class FormTestInputForgeryForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return '_form_test_input_forgery';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, array &$form_state) {
    // For testing that a user can't submit a value not matching one of the
    // allowed options.
    $form['checkboxes'] = array(
      '#title' => t('Checkboxes'),
      '#type' => 'checkboxes',
      '#options' => array(
        'one' => 'One',
        'two' => 'Two',
      ),
    );
    $form['submit'] = array(
      '#type' => 'submit',
      '#value' => t('Submit'),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, array &$form_state) {
    return new JsonResponse($form_state['values']);
  }

}
