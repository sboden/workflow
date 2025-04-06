<?php

/**
 * @file
 * Hooks provided by the workflow module.
 */

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\UserInterface;
use Drupal\workflow\Entity\WorkflowConfigTransition;
use Drupal\workflow\Entity\WorkflowState;
use Drupal\workflow\Entity\WorkflowTransitionInterface;

/**
 * Allows to add extra operations to ListBuilder::getDefaultOperations().
 *
 * For ListBuilders of Workflow, WorkflowState, WorkflowTransition.
 *
 * @param string $op
 *   'top_actions': Allow modules to insert their own front page action links.
 *   'operations': Allow modules to insert their own workflow operations.
 *   'workflow':  Allow modules to insert workflow operations.
 *   'state':  Allow modules to insert state operations.
 * @param \Drupal\Core\Entity\EntityInterface|null $entity
 *   The current workflow/state/transition object.
 *
 * @return array
 *   The new actions, to be added to the entity list.
 */
function hook_workflow_operations($op, EntityInterface $entity = NULL) {
  $operations = [];

  switch ($op) {
    case 'top_actions':
      // As of D8, below hook_workflow_operations is removed, in favour of core hooks.
      // @see workflow.links.action.yml for an example top action.
      return $operations;

    case 'operations':
      break;

    case 'workflow':
      // This example adds an operation to the 'operations column' of the Workflow List.
      /** @var \Drupal\workflow\Entity\WorkflowInterface $workflow */
      $workflow = $entity;

      $moduleHandler = \Drupal::service('module_handler');
      if ($moduleHandler->moduleExists('workflow_access')) {
        workflow_access_workflow_operations($op, $workflow);
      }

      return $operations;

    case 'state':
      /** @var \Drupal\workflow\Entity\WorkflowState $state */
      $state = $entity;
      break;

    case 'workflow_transition':
      // As of D8, below hook_workflow_operations is removed,
      // in favour of core hooks.
      // @see EntityListBuilder::getOperations, workflow_operations, workflow.api.php.

      // Your module may add operations to the Entity list.
      /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
      $transition = $entity;
      break;

    default:
      break;
  }
  return $operations;
}

/**
 * Allows reacting on several events.
 *
 * NOTE: This hook may reside in the implementing module
 * or in a module.workflow.inc file.
 *
 * @param string $op
 *   The current workflow operation.
 *   E.g., 'transition pre', 'transition post'.
 * @param \Drupal\workflow\Entity\WorkflowTransitionInterface $transition
 *   The transition, that contains all of the above.
 * @param \Drupal\user\UserInterface $user
 *   The user.
 *
 * @return bool|void
 *   The return value, depending on $op.
 */
function hook_workflow($op, WorkflowTransitionInterface $transition, UserInterface $user) {
  switch ($op) {
    case 'transition permitted':
      // As of version 8.x-1.x,
      // this operation is never called to check if transition is permitted.
      // This was called in the following situations:
      // case 1. when building a widget with list of available transitions;
      // case 2. when executing a transition, just before the 'transition pre';
      // case 3. when showing a 'revert state' link in a Views display.
      // Your module's implementation may return FALSE here and disallow
      // the execution, or avoid the presentation of the new State.
      // This may be user-dependent.
      // As of version 8.x-1.x:
      // case 1: use hook_workflow_permitted_state_transitions_alter(),
      // case 2: use the 'transition pre' operation,
      // case 3: use the 'transition pre' operation.
      return TRUE;

    case 'transition revert':
      // Hook is called when showing the Transition Revert form.
      // Implement this hook if you need to control this.
      // If you return FALSE here, you will veto the transition.

      // workflow_debug(__FILE__, __FUNCTION__, __LINE__, $op, '');
      return TRUE;

    case 'transition pre':
      // The workflow module does nothing during this operation.
      // Implement this hook if you need to change/do something BEFORE anything
      // is saved to the database.
      // If you return FALSE here, you will veto the transition.

      // workflow_debug(__FILE__, __FUNCTION__, __LINE__, $op, '');
      return TRUE;

    case 'transition post':
      // This is a duplicate of D8 hook_entity_* event after saving the entity.
      // @see https://api.drupal.org/api/drupal/includes%21module.inc/group/hooks/7
      // workflow_debug(__FILE__, __FUNCTION__, __LINE__, $op, '');
      $user = $transition->getOwner();
      return TRUE;

    case 'transition delete':
    case 'state delete':
    case 'workflow delete':
      // These hooks are removed in D8, in favour of the core hooks:
      // - workflow_entity_predelete(EntityInterface $entity)
      // - workflow_entity_delete(EntityInterface $entity)
      // See examples at the bottom of this file.
      return TRUE;
  }

  return TRUE;
}

/**
 * Allow other modules to change the user comment when saving a state change.
 *
 * @param string $comment
 *   The comment of the current state transition.
 * @param array $context
 *   'transition' - The current transition itself.
 */
function hook_workflow_comment_alter(&$comment, array &$context) {
  // workflow_debug(__FILE__, __FUNCTION__, __LINE__, '', '');

  /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
  $transition = $context['transition'];
  // $comment = $transition->getOwner()->getUsername() . ' says: ' . $comment;
}

/**
 * Allows other modules to add Operations to the most recent history change.
 *
 * E.g., Workflow Revert implements an 'undo' operation.
 * In D8, hook_workflow_history_alter() is removed, in favour
 * of ListBuilder::getDefaultOperations
 * and hook_workflow_operations('workflow_transition').
 *
 * @param array $variables
 *   The current workflow history information as an array.
 *   'old_sid' - The state ID of the previous state.
 *   'old_state_name' - The state name of the previous state.
 *   'sid' - The state ID of the current state.
 *   'state_name' - The state name of the current state.
 *   'history' - The row from the workflow_transition_history table.
 *   'transition' - a WorkflowTransition object, containing all of the above.
 */
function hook_workflow_history_alter(array &$variables) {
  // workflow_debug(__FILE__, __FUNCTION__, __LINE__, '', '');

  // The Workflow module does nothing with this hook.
  // For an example implementation, see the Workflow Revert add-on.
}

/**
 * Allows adding/removing of allowed target states, change labels, etc.
 *
 * It is invoked in WorkflowState::getOptions().
 *
 * @param array $transitions
 *   An array of allowed transitions from the current state (as provided in
 *   $context). They are already filtered by the settings in Admin UI.
 * @param array $context
 *   An array of relevant objects. Currently:
 *    @code
 *    $context = [
 *      'user' => $user,
 *      'workflow' => $workflow,
 *      'state' => $current_state,
 *      'force' => $force,
 *    ];
 *   @endcode
 */
function hook_workflow_permitted_state_transitions_alter(array &$transitions, array $context) {
  // workflow_debug(__FILE__, __FUNCTION__, __LINE__, '', '');

  // User may have the custom role AUTHOR.
  $user = $context['user'];
  // The following could be fetched from each transition.
  $workflow = $context['workflow'];
  $current_state = $context['state'];
  // The following could be fetched from the $user and $transition objects.
  $force = $context['force'];

  // Implement here own permission logic.
  foreach ($transitions as $key => $transition) {
    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition*/
    if (!$transition->isAllowed($user, $force)) {
      // unset($transitions[$key]);
    }
  }

  // This example creates a new custom target state.
  $values = [
    // Fixed values for new transition.
    'wid' => $context['workflow']->id(),
    'from_sid' => $context['state']->id(),
    // Custom values for new transition.
    // The ID must be an integer, due to db-table constraints.
    'to_sid' => '998',
    'label' => 'go to my new fantasy state',
  ];
  $new_transition = WorkflowConfigTransition::create($values);
  // $transitions[] = $new_transition;
}

/**********************************************************************
 * Hooks defined by core Form API: hooks to to alter the Workflow Form/Widget.
 */

/**
 * Implements field_widget_single_element_WIDGET_TYPE_form_alter() for 'workflow_default'.
 *
 * Better use hook_form_workflow_transition_form_alter.
 */
function hook_field_widget_single_element_workflow_default_form_alter(&$element, FormStateInterface $form_state, $context) {
  // A hook specific for the 'workflow_default' widget.
  // The name is specified in the annotation of WorkflowDefaultWidget.

  // A widget on an entity form.
  if ('workflow_default' != $context['widget']->getPluginId()) {
    // This can never happen.
    return;
  }

  // The $transition object contains all you need.
  /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
  $transition = $element['#default_value'] ?? NULL;
  if (!$transition) {
    return;
  }

  // An example of customizing/overriding the workflow widget.
  // Beware: until now, you must do this twice: on the widget and on the form.
  $uid = $transition->getOwnerId();
  if ($uid == 10) {
    \Drupal::messenger()->addWarning('(Test/Devel message) For you, user 1,
      the scheduling is disabled, and commenting is required.');
    // Let us prohibit scheduling for user 1.
    $element['timestamp']['#access'] = FALSE;
    // Let us require commenting for user 1.
    if ($element['comment']['#access'] ?? FALSE) {
      $element['comment']['#required'] = TRUE;
    }
  }
}

/**
 * Implements hook_form_BASE_FORM_ID_alter() for 'workflow_transition_form'.
 *
 * Use this hook to alter the form.
 * It is only suited if you only use View Page or Workflow Tab.
 * If you change the state on the Entity Edit page (form), you need the hook
 * hook_form_alter(). See below for more info.
 */
function hook_form_workflow_transition_form_alter(&$form, FormStateInterface $form_state, $form_id) {

  // The $transition object contains all you need.
  /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
  $transition = $form['#default_value'];

  // An example of customizing/overriding the workflow widget.
  // Beware: until now, you must do this twice: on the widget and on the form.
  $uid = $transition->getOwnerId();
  if ($uid == 10) {
    \Drupal::messenger()->addWarning('(Test/Devel message) For you, user 1,
      the scheduling is disabled, and commenting is required.');
    // Let us prohibit scheduling for user 1.
    $form['timestamp']['#access'] = FALSE;
    // Let us require commenting for user 1.
    if ($form['comment']['#access'] ?? FALSE) {
      $form['comment']['#required'] = TRUE;
    }
  }

  // Get the Entity.
  $entity = $transition->getTargetEntity();
  if ($entity) {
    $entity_type = $entity->getEntityTypeId();
    $entity_bundle = $entity->bundle();

    // Get the current State ID.
    $sid = workflow_node_current_state($entity, $transition->getFieldName());
    $sid = $transition->getFromSid();
    // Get the State object, if needed.
    $state = WorkflowState::load($sid);

    // Change the form, depending on the state ID. @todo Update D8 machine name.
    if ($entity_type == 'node' && $entity_bundle == 'MY_NODE_TYPE') {
      switch ($sid) {
        case 'state_name2':
          // Change form element, form validate and form submit for state '2'.
          break;

        case 'state_name3':
          // Change form element, form validate and form submit for state '3'.
          break;
      }
    }
  }

}

/**
 * Implements hook_form_alter().
 */
function hook_form_alter(&$form, FormStateInterface $form_state, $form_id) {
  if (substr($form_id, 0, 8) == 'workflow') {
    // workflow_debug(__FILE__, __FUNCTION__, __LINE__, $form_id, '');
  }
}

/**
 * Implements hook_copy_form_values_to_transition_field_alter().
 *
 * See #2899025 'Attached field type 'file' not working on WorkflowTransition'.
 */
function hook_copy_form_values_to_transition_field_alter(EntityInterface $entity, $context) {
  /** @var \Drupal\Core\Field\Entity\BaseFieldOverride $field */
  $field = $context['field'];
  $field_name = $context['field_name'];
  $user_input = $context['user_input'];

  // Workaround for issue 2899025, but works only with entity_browser module.
  // @see https://www.drupal.org/project/workflow/issues/2899025
  // Issue 'Attached field type 'file' not working on WorkflowTransition'
  if ($field->getType() == 'file' && !empty($user_input['current'])) {
    workflow_debug(__FILE__, __FUNCTION__, __LINE__, '', '');
    // Avoid inserting two references to the same file.
    // Workaround for issue #2926094 'Avoid calling the WorkflowTransitionElement twice on a form'.
    $entity->{$field_name} = [];
    foreach ($user_input['current'] as $target_id => $values) {
      $data = [
        "target_id" => $target_id,
        "description" => $values["meta"]["description"],
      ];

      $entity->{$field_name}[] = $data;
    }
  }
}

/**
 * Hooks defined by core Entity: hook_entity_CRUD.
 *
 * Instead of using hook_entity_OPERATION, better use hook_ENTITY_TYPE_OPERATION.
 *
 * See hook_entity_create(), hook_entity_update(), etc.
 * See hook_ENTITY_TYPE_create(), hook_ENTITY_TYPE_update(), etc.
 */
function hook_entity_predelete(EntityInterface $entity) {
  if (substr($entity->getEntityTypeId(), 0, 8) == 'workflow') {
    // workflow_debug(__FILE__, __FUNCTION__, __LINE__, 'pre-delete', $entity->getEntityTypeId());
  }
  switch ($entity->getEntityTypeId()) {
    case 'workflow_config_transition':
    case 'workflow_state':
    case 'workflow_type':
      // Better use hook_ENTITY_TYPE_OPERATION.
      // E.g., hook_workflow_type_predelete.
      break;
  }
}

function hook_entity_delete(EntityInterface $entity) {
  if (substr($entity->getEntityTypeId(), 0, 8) == 'workflow') {
    // workflow_debug(__FILE__, __FUNCTION__, __LINE__, 'delete', $entity->getEntityTypeId());
  }
}

function hook_workflow_type_delete(EntityInterface $entity) {
  // workflow_debug(__FILE__, __FUNCTION__, __LINE__, 'delete', $entity->getEntityTypeId());
}

function hook_workflow_config_transition_delete(EntityInterface $entity) {
  // workflow_debug(__FILE__, __FUNCTION__, __LINE__, 'delete', $entity->getEntityTypeId());
}

function hook_workflow_state_delete(EntityInterface $entity) {
  // workflow_debug(__FILE__, __FUNCTION__, __LINE__, 'delete', $entity->getEntityTypeId());
}
