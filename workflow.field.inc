<?php

/**
 * @file
 * Defines a Workflow field, widget and formatter. (copied from list field).
 */

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\workflow\Entity\WorkflowState;

/**
 * Implements hook_form_FORM_ID_alter() for 'field_storage_config_edit_form'.
 *
 * Changes the hook_field_settings_form.
 * Fixes some Field settings and Field Instance settings,
 * and makes sure users cannot change it.
 *
 * @deprecated version 8.x-1.7, to be removed in 8.x-2.0
 * @see https://www.drupal.org/project/workflow/issues/3425370
 */
function workflow_form_field_storage_config_edit_form_alter(&$form, FormStateInterface $form_state, $form_id) {
  if (version_compare(\Drupal::VERSION, '10.2', '>=')) {
    // This is now done in WorkflowItem::storageSettingsForm
    // And in workflow_form_field_config_edit_form_alter.
  }
  else {
    $field_type = $form_state->getFormObject()->getEntity()->getType();
    if ($field_type == 'workflow') {
      // Make sure only 1 value can be entered in the Workflow field.
      $form['cardinality_container']['cardinality']['#default_value'] = 'number';
      $form['cardinality_container']['cardinality']['#disabled'] = TRUE;
      $form['cardinality_container']['cardinality_number']['#default_value'] = 1;
      $form['cardinality_container']['cardinality_number']['#disabled'] = TRUE;
      $form['cardinality_container']['cardinality_number']['#states'] = [];
    }
  }

}

/**
 * Implements hook_form_FORM_ID_alter() for 'field_config_edit_form'.
 */
function workflow_form_field_config_edit_form_alter(&$form,
                                                    FormStateInterface $form_state,
  /* @noinspection PhpUnusedParameterInspection */  $form_id) {
  /* @noinspection PhpUndefinedMethodInspection */
  $field_type = $form_state->getFormObject()->getEntity()->getType();
  if ($field_type == 'workflow') {
    // The Workflow field must have a value, so set to required.
    $form['required']['#default_value'] = 1;
    $form['required']['#disabled'] = TRUE;
    $form['set_default_value']['#default_value'] = FALSE;
    $form['set_default_value']['#disabled'] = TRUE;
    $form['default_value']['#access'] = FALSE;
  }
}

/**
 * Using some default formatters and widgets from the List and Options modules.
 */

/**
 * Implements hook_field_formatter_info_alter().
 *
 * The module reuses the formatters defined in list.module.
 */
function workflow_field_formatter_info_alter(&$info) {
  $info['list_key']['field_types'][] = 'workflow';
  $info['list_default']['field_types'][] = 'workflow';
}

/**
 * Implements hook_field_widget_info_alter().
 *
 * The module does not implement widgets of its own, but reuses the
 * widgets defined in options.module.
 *
 * @see workflow_options_list()
 */
function workflow_field_widget_info_alter(&$info) {
  $info['options_select']['field_types'][] = 'workflow';
  $info['options_buttons']['field_types'][] = 'workflow';
}

/**
 * Creates a form element to show the current value of a Workflow state.
 *
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   The entity this field is on.
 * @param string $field_name
 *   The field_name.
 * @param string $current_sid
 *   The current State Id.
 *
 * @return array
 *   Form element, resembling the formatter of List module.
 *   If state 0 is given, return an empty form element.
 */
function workflow_state_formatter(EntityInterface $entity, $field_name, $current_sid = '') {
  $element = [];

  if (!$current_sid) {
    $current_sid = workflow_node_current_state($entity, $field_name);
  }
  // If user creates a node, and only 1 option is available, the formatter
  // is shown with key, not value, because creation state does not count.
  // In this case, hide the formatter.
  /** @var \Drupal\workflow\Entity\WorkflowState $state */
  $state = WorkflowState::load($current_sid);
  if ($state && $state->isCreationState()) {
    return $element;
  }
  // Clone the entity and restore old value, in case you want to show an
  // executed transition.
  if ($entity->{$field_name}->value != $current_sid) {
    $entity = clone $entity;
    $entity->{$field_name}->value = $current_sid;
  }
  // Generate a renderable array for the field.
  // Use default language determination ($langcode = NULL).
  // First, add the 'current value' formatter for this field.
  // $list_display = $instance['display']['default']; .
  $list_display['type'] = 'list_default';
  $element = $entity->{$field_name}->view($list_display);

  // Make sure the current value is before the form. (which has weight = 0.005)
  $element['#weight'] = -0.005;
  return $element;
}

/**
 * Returns the allowed values for a field.
 *
 * This function is a residue for installations that have v8.x-1.0
 * The setting for options_allowed_values is still in the Field configuration
 * Saving the field data again does not remove the settings.
 * So, we copy code from options_allowed_values().
 *
 * @param \Drupal\Core\Field\FieldStorageDefinitionInterface $definition
 *   The field definition.
 *
 * @return mixed
 *   The allowed values.
 */
function workflow_state_allowed_values(FieldStorageDefinitionInterface $definition, FieldableEntityInterface $entity = NULL, &$cacheable = TRUE) {
  $wid = $definition->getSetting('workflow_type');
  $allowed_values = WorkflowState::loadMultiple([], $wid);

  return $allowed_values;
}
