<?php

/**
 * @file
 * Contains helper functions for WorkflowTransitionForm.
 */

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\workflow\Entity\WorkflowTransitionInterface;

/**
 * Implements hook_form_alter().
 *
 * Adds action/drop buttons next to the 'Save'/'Delete' buttons,
 * when the 'options' widget element is set to 'action buttons'.
 * Note: do not use with multiple workflows per entity: confusing UX.
 */
function workflow_form_alter(&$form, FormStateInterface $form_state, $form_id) {
  // Keep aligned: workflow_form_alter(), WorkflowTransitionForm::actions().
  _workflow_transition_form_get_action_buttons($form, $form_state);
}

/**
 * Getter/Setter to tell if/which action buttons are used.
 *
 * @param string $button_type
 *   If empty, the current button type is returned,
 *   if not empty, the button type is set to input value.
 *
 * @return string
 *   Previous value. If 'dropbutton'||'buttons', action buttons must be created.
 *
 * @see workflow_form_alter()
 * @see WorkflowDefaultWidget::formElement()
 *
 * Used to save some expensive operations on every form.
 */
function _workflow_use_action_buttons($button_type = '') {
  global $_workflow_action_button_type;

  // Reset value if requested.
  switch(true) {
    case $button_type == '';
      // Getting, not setting.
      break;

    case $button_type == 'dropbutton';
    case $button_type == 'buttons';
      $_workflow_action_button_type = $button_type;
      break;

    default:
      // Any other non-button type.
      $_workflow_action_button_type = '';
      break;
  }

  return $_workflow_action_button_type;
}

/**
 * Fetches the first workflow_element from one of the Workflow fields.
 *
 * @param array $form
 *   The form.
 *
 * @return array
 *   The workflow element, or empty array.
 */
function _workflow_transition_form_get_first_workflow_element(&$form) {

  // Find the first workflow.
  // (So this won't work with multiple workflows per entity.)
  $transition = $form['#default_value'] ?? NULL;
  if ($transition instanceof WorkflowTransitionInterface) {
    // We are on the workflow_transition_form.
    return $form;
  }

  // We are on node edit page. First fetche the field.
  $workflow_element = [];
  foreach (Element::children($form) as $key) {
    $transition = $form[$key]['widget'][0]['#default_value'] ?? NULL;
    if ($transition instanceof WorkflowTransitionInterface) {
      $workflow_element = $form[$key]['widget'][0];
      break;
    }
  }
  return $workflow_element;
}

/**
 * Returns the action buttons from the options widget.
 *
 * @param array $form
 *   The form. The list of buttons is updated upon return.
 * @param \Drupal\Core\Form\FormStateInterface $form_state
 *   The form state.
 */
function _workflow_transition_form_get_action_buttons(array &$form, FormStateInterface $form_state) {

  // Get the list of default buttons.
  $actions = &$form['actions'];
  if (!$actions) {
    // Sometimes, no actions are defined. Discard this form.
    return $actions;
  }

  // Use a fast, custom way to check if we need to do this.
  // @todo Make this work with multiple workflows per entity.
  if (!_workflow_use_action_buttons()) {
    // Change the default submit button on the Workflow History tab.
    return $actions;
  }

  // Find the first workflow. Quit if there is no Workflow on this page.
  // @todo Support multiple workflows per entity.
  $workflow_element = _workflow_transition_form_get_first_workflow_element($form);
  if (!$workflow_element) {
    return $actions;
  }

  $to_sid_widget = $workflow_element['to_sid']['widget'][0]['target_id'];
  $options = $to_sid_widget['#options'];
  $current_sid = $to_sid_widget['#default_value'];

  // Quit if there are no Workflow Action buttons.
  // (If user has only 1 workflow option, there are no Action buttons.)
  if (count($options) <= 1) {
    return $actions;
  }

  /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
  $transition = $workflow_element['#default_value'];
  $field_name = $transition->getFieldName();

  // Find the default submit button and replace with our own action buttons.
  $default_submit_action = [];
  $default_submit_action = $actions['save'] ?? $default_submit_action;
  $default_submit_action = $actions['submit'] ?? $default_submit_action;

  // Find the default submit button and add our action buttons before it.
  // Get the min weight for our buttons.
  $option_weight = $default_submit_action['#weight'] ?? 0;
  $option_weight = $option_weight - count($options);
  $min_weight = $option_weight;

  // Add the new submit buttons next to/below the default submit buttons.
  foreach ($options as $sid => $option_name) {
    // Make the workflow button act exactly like the original submit button.
    $same_state_button = ($sid == $current_sid);

    $workflow_submit_action = $default_submit_action;
    // Add target State ID and Field name,
    // to set correct value in validate_buttons callback.
    $workflow_submit_action['#workflow'] = [
      'field_name' => $field_name,
      'to_sid' => $sid,
    ];
    // Keep option order. Put current state first.
    $workflow_submit_action['#weight'] = ($same_state_button) ? $min_weight : ++$option_weight;

    // Add/Overwrite some other settings.
    $workflow_submit_action['#value'] = $option_name;
    $workflow_submit_action['#access'] = TRUE;
    $workflow_submit_action['#button_type'] = '';
    $workflow_submit_action['#attributes'] = ($same_state_button) ? ['class' => ['form-save-default-button']] : [];
    $workflow_submit_action['#attributes']['class'][] = Html::getClass('workflow_button_' . $option_name);
    // $workflow_submit_action['#executes_submit_callback'] = TRUE;

    // Append the form's #validate function, or it won't be called upon submit,
    // because the workflow buttons have its own #validate.
    if (!isset($default_submit_action['#validate'])) {
      if (isset($form['#validate'])) {
        $workflow_submit_action['#validate'] = $form['#validate'];
      }
    }

    // Append the submit-buttons's #submit function,
    // or it won't be called upon submit.
    if (!isset($default_submit_action['#submit'])) {
      if (isset($form['#submit'])) {
        $workflow_submit_action['#submit'] = $form['#submit'];
      }
    }

    // Use one drop button, instead of several action buttons.
    if ('dropbutton' == _workflow_use_action_buttons()) {
      $workflow_submit_action['#dropbutton'] = 'save';
      $workflow_submit_action['#button_type'] = '';
    }

    // Hide the same-state button in some cases.
    if ($same_state_button) {
      $workflow_submit_action['#button_type'] = ($same_state_button) ? 'primary' : '';
      $workflow_submit_action['#attributes'] = ($same_state_button) ? ['class' => ['form-save-default-button']] : [];
      $workflow_submit_action['#attributes']['class'][] = Html::getClass('workflow_button_' . $option_name);
    }

    // Add the new state button.
    $actions['workflow_' . $sid] = $workflow_submit_action;

  }

  unset($actions['submit']);
  unset($actions['save']);

  return $actions;
}

/**
 * Get the Workflow parameter from the button, pressed by the user.
 *
 * @param \Drupal\Core\Form\FormStateInterface $form_state
 *   The form state.
 *
 * @return array
 *   A $field_name => $to_sid array.
 */
function _workflow_transition_form_get_triggering_button(FormStateInterface $form_state) {
  $result = ['field_name' => NULL, 'to_sid' => NULL];

  $triggering_element = $form_state->getTriggeringElement();
  if (isset($triggering_element['#workflow'])) {
    $result['field_name'] = $triggering_element['#workflow']['field_name'];
    $result['to_sid'] = $triggering_element['#workflow']['to_sid'];
  }
  else {
    $input = $form_state->getUserInput();
    $result['field_name'] = $input['field_name'] ?? NULL;
    $result['to_sid'] = $input['to_sid'][0]['target_id'] ?? NULL;
  }

  return $result;
}

/**
 * Submit callback function for the Workflow Form / DefaultWidget.
 *
 * Validate form data for 'time' element.
 *
 * @param array $element
 *   The element.
 * @param \Drupal\Core\Form\FormStateInterface $form_state
 *   The form state.
 * @param array $form
 *   The form.
 */
function _workflow_transition_form_element_validate_time($element, FormStateInterface &$form_state, $form) {
  // @todo validate_time does not work when entering a character.
  if (!strtotime($element['#value'])) {
    $form_state->setError($element, t('Please enter a valid value for time.'));
  }
}
