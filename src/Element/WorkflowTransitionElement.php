<?php

namespace Drupal\workflow\Element;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\FormElement;
use Drupal\workflow\Entity\Workflow;
use Drupal\workflow\Entity\WorkflowTransitionInterface;

/**
 * Provides a form element for the WorkflowTransitionForm and ~Widget.
 *
 * Properties:
 * - #return_value: The value to return when the checkbox is checked.
 *
 * @see \Drupal\Core\Render\Element\FormElement
 * @see https://www.drupal.org/node/169815 "Creating Custom Elements"
 *
 * @FormElement("workflow_transition")
 */
class WorkflowTransitionElement extends FormElement {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = static::class;
    return [
      '#input' => TRUE,
      '#return_value' => 1,
      '#process' => [
        [$class, 'processTransition'],
        [$class, 'processAjaxForm'],
        // [$class, 'processGroup'],
      ],
      '#element_validate' => [
        [$class, 'validateTransition'],
      ],
      // @todo D11 removed #pre_render callback array{class-string<static(Drupal\workflow\Element\WorkflowTransitionElement)>, 'preRenderTransition'} at key '0' is not callable.
      // '#pre_render' => [
      //   [$class, 'preRenderTransition'],
      // ],
      // '#theme' => 'input__checkbox',
      // '#theme' => 'input__textfield',
      '#theme_wrappers' => ['form_element'],
      // '#title_display' => 'after',
    ];
  }

  /**
   * Generate an element.
   *
   * This function is referenced in the Annotation for this class.
   *
   * @param array $element
   *   The element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $complete_form
   *   The form.
   *
   * @return array
   *   The Workflow element
   */
  public static function processTransition(array &$element, FormStateInterface $form_state, array &$complete_form) {
    workflow_debug(__FILE__, __FUNCTION__, __LINE__); // @todo D8:  test this snippet.
    return self::transitionElement($element, $form_state, $complete_form);
  }

  /**
   * Generate an element.
   *
   * This function is an internal function, to be reused in:
   * - TransitionElement,
   * - TransitionDefaultWidget.
   *
   * @param array $element
   *   Reference to the form element.
   * @param \Drupal\Core\Form\FormStateInterface|null $form_state
   *   The form state.
   * @param array $complete_form
   *   The form.
   *
   * @return array
   *   The form element $element.
   *
   * @usage:
   *   @example $element['#default_value'] = $transition;
   *   @example $element += WorkflowTransitionElement::transitionElement($element, $form_state, $form);
   */
  public static function transitionElement(array &$element, FormStateInterface|NULL $form_state, array &$complete_form) {

    /*
     * Input.
     */
    // A Transition object must have been set explicitly.
    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
    $transition = $element['#default_value'];
    /** @var \Drupal\Core\Session\AccountInterface $user */
    $user = \Drupal::currentUser();

    /*
     * Derived input.
     */
    $field_name = $transition->getFieldName();
    // Workflow might be empty on Action/VBO configuration.
    $wid = $transition->getWorkflowId();
    $workflow = $transition->getWorkflow();
    $workflow_settings = $workflow ? $workflow->getSettings() : Workflow::defaultSettings();
    $label = $workflow ? $workflow->label() : '';
    $force = $transition->isForced();
    $entity = $transition->getTargetEntity();
    $entity_id = $entity ? $entity->id() : NULL;
    $entity_type_id = $entity ? $entity->getEntityTypeId() : '';

    if ($transition->isExecuted()) {
      // We are editing an existing/executed/not-scheduled transition.
      // Only the comments may be changed!
      $current_sid = $from_sid = $transition->getFromSid();
      // The states may not be changed anymore.
      $to_state = $transition->getToState();
      $options = [$to_state->id() => $to_state->label()];
      // We need the widget to edit the comment.
      $show_widget = TRUE;
      $default_value = $transition->getToSid();
    }
    elseif ($entity) {
      // Normal situation: adding a new transition on an new/existing entity.
      //
      // Get the scheduling info, only when updating an existing entity.
      // This may change the $default_value on the Form.
      // Technically you could have more than one scheduled transition, but
      // this will only add the soonest one.
      // @todo Read the history with an explicit langcode?
      $current_sid = $from_sid = $transition->getFromSid();
      $current_state = $from_state = $transition->getFromState();
      $options = ($current_state) ? $current_state->getOptions($entity, $field_name, $user, FALSE) : [];
      $show_widget = ($from_state) ? $from_state->showWidget($entity, $field_name, $user, FALSE) : [];
      $default_value = ($from_state && $from_state->isCreationState()) ? $workflow->getFirstSid($entity, $field_name, $user, FALSE) : $from_sid;
      $default_value = ($transition->isScheduled()) ? $transition->getToSid() : $default_value;
    }
    elseif (!$entity) {
      // Sometimes, no entity is given. We encountered the following cases:
      // - D7: the Field settings page,
      // - D7/D8: the VBO action form;
      // - D7/D8: the Advance Action form on admin/config/system/actions;
      // If so, show all options for the given workflow(s).
      $temp_state = $transition->getFromState() ?? $transition->getToState();
      $options = ($temp_state)
        ? $temp_state->getOptions($entity, $field_name, $user, FALSE)
        : workflow_get_workflow_state_names($wid, $grouped = TRUE);
      $show_widget = TRUE;
      $current_sid = $transition->getToSid();
      $default_value = $from_sid = $transition->getToSid();
    }
    else {
      // We are in trouble! A message is already set in workflow_node_current_state().
      $options = [];
      $current_sid = 0;
      $show_widget = FALSE;
      $default_value = FALSE;
    }

    // The help text is not available for container. Let's add it to the
    // To State box. N.B. it is empty on Workflow Tab, Node View page.
    // @see www.drupal.org/project/workflow/issues/3217214
    $options_type = $workflow_settings['options'];
    $help_text = $element['#description'] ?? '';
    unset($element['#description']);

    // Get the weight of 1 subfield, set standard subfield order.
    $field_weight = $element['timestamp']['#weight'] ?? $element['#weight'] ?? 0;

    /*
     * Output: generate the element.
     */

    // Save the current value of the entity in the form, for later Workflow-module specific references.
    // We add prefix, since #tree == FALSE.
    $element['_workflow_transition'] = [
      '#type' => 'value',
      '#value' => $transition,
    ];

    $element['#tree'] = TRUE;
    // Add class following node-form pattern (both on form and container).
    $element['#attributes']['class'][] = 'workflow-transition-' . $wid . '-container';
    $element['#attributes']['class'][] = 'workflow-transition-container';

    if (!$show_widget) {
      // Show no widget, but formatter.
      $element['from_sid'] = workflow_state_formatter($entity, $field_name, $current_sid);
      $element['to_sid']['#type'] = 'value';
      $element['to_sid']['#value'] = $current_sid;
      $element['to_sid']['#weight'] = $field_weight;
      // Add options, in case action buttons need them.
      $element['to_sid']['#options'] = $options;
      $element['comment']['#type'] = 'value';
      $element['comment']['#value'] = '';
      $element['comment']['#weight'] = $field_weight;

      return $element; // <-- exit.
    }

    // Prepare a UI wrapper. This might be a fieldset.
    // It will be overridden in WorkflowTransitionForm.
    $element = [
      '#type' => $workflow_settings['fieldset'] ? 'details' : 'container',
      '#collapsible' => ($workflow_settings['fieldset'] != 0),
      '#open' => ($workflow_settings['fieldset'] != 2),
    ] + $element;

    $element['field_name'] = [
      '#type' => 'select',
      '#title' => t('Field name'),
      '#description' => t('Choose the field name.'),
      '#options' => workflow_get_workflow_field_names(NULL, $entity_type_id),
      '#default_value' => $field_name,
      '#access' => FALSE, // Only show on VBO/Actions screen.
      '#required' => TRUE,
      '#weight' => $field_weight - 10,
    ];

    if ($entity) {
      // $entity may be empty in VBO.
      // Decide if we show either a widget or a formatter.
      // Add a state formatter before the rest of the form,
      // when transition is scheduled or widget is hidden.
      // Also no widget if the only option is the current sid.
      if ($transition->isScheduled() || $transition->isExecuted()) {
        $element['from_sid'] = workflow_state_formatter($entity, $field_name, $current_sid);
      }
    }

    // Add the 'options' widget.
    // It may be replaced later if 'Action buttons' are chosen.
    // This overrides BaseFieldDefinition. @todo Apply for form and widget.
    // @todo Repair $workflow->'name_as_title': no container if no details (schedule/comment).
    $attribute_name = 'to_sid';
    $attribute_key = 'target_id';
    $element[$attribute_name]['widget'][0][$attribute_key] = [
      // Avoid error with grouped options when workflow not set.
      '#type' => ($wid) ? $options_type : 'select',
      '#title' => (!$workflow_settings['name_as_title'] && !$transition->isExecuted())
        ? t('Change @name state', ['@name' => $label])
        : t('Change state'),
      '#description' => $help_text,
      '#access' => TRUE,
      '#options' => $options,
      '#default_value' => $default_value,
      '#weight' => $field_weight,
      // Remove autocomplete settings from BaseFieldDefinitions().
      '#maxlength' => 999,
      '#size' => 0,
    ] + ($element[$attribute_name]['widget'][0][$attribute_key] ?? []);

    if (_workflow_use_action_buttons($options_type)) {
      // In WorkflowTransitionForm, a default 'Submit' button is added there.
      // In Entity Form, workflow_form_alter() adds button per permitted state.
      // Performance: inform workflow_form_alter() to do its job.
      //
      // Make sure the '#type' is not set to the invalid 'buttons' value.
      // It will be replaced by action buttons, but sometimes, the select box
      // is still shown.
      // @see workflow_form_alter().
      $element[$attribute_name]['widget'][0][$attribute_key] = [
        '#type' => 'select',
        '#access' => FALSE,
      ] + ($element[$attribute_name]['widget'][0][$attribute_key] ?? []);
    }

    // Display scheduling form under certain conditions.
    $attribute_name = 'timestamp';
    $attribute_key = 'value';
    $element[$attribute_name]['widget'][0][$attribute_key] = [
      '#type' => 'workflow_transition_timestamp',
      '#default_value' => $transition,
      // '#default_value' => new DrupalDateTime('2000-01-01 00:00:00'),
    ] + ($element[$attribute_name]['widget'][0][$attribute_key] ?? []);

    // Show comment, when both Field and Instance allow this.
    // This overrides BaseFieldDefinition.
    $attribute_name = 'comment';
    $attribute_key = 'value';
    $element[$attribute_name]['widget'][0][$attribute_key] = [
      '#type' => 'textarea',
      '#title' => t('Comment'),
      '#description' => t('Briefly describe the changes you have made.'),
      '#access' => $workflow_settings['comment_log_node'] != '0', // Align with action buttons.
      '#default_value' => $transition->getComment(),
      '#weight' => $field_weight,
      '#required' => $workflow_settings['comment_log_node'] == '2',
      '#rows' => 2, //@todo Use correct field setting UI.
    ] + ($element[$attribute_name]['widget'][0][$attribute_key] ?? []);

    $element['force'] = [
      '#type' => 'checkbox',
      '#title' => t('Force transition'),
      '#description' => t('If this box is checked, the new state will be
        assigned even if workflow permissions disallow it.'),
      '#access' => FALSE, // Only show on VBO/Actions screen.
      '#default_value' => $force,
      '#weight' => $field_weight + 10,
    ];

    return $element;
  }

  /**
   * Returns a unique string identifying the form.
   *
   * @return string
   *   The form ID.
   */
  protected static function getFormId() {
    return 'workflow_transition_form'; // @todo D8-port: add $form_id for widget and History tab.
  }

  /**
   * Implements ContentEntityForm::copyFormValuesToEntity().
   *
   * This is called from:
   * - WorkflowTransitionForm::copyFormValuesToEntity(),
   * - WorkflowDefaultWidget.
   *
   * N.B. in contrary to ContentEntityForm::copyFormValuesToEntity(),
   * - parameter 1 is returned as result, to be able to create a new Transition object.
   * - parameter 3 is not $form_state (from Form), but an $item array (from Widget).
   *
   * @param \Drupal\Core\Entity\EntityInterface $transition
   *   The transition object.
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $values
   *   The field item.
   *
   * @return \Drupal\workflow\Entity\WorkflowTransitionInterface
   *   A new Transition object.
   */
  public static function copyFormValuesToTransition(EntityInterface $transition, array $form, FormStateInterface $form_state, array $values) {
    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */

    // @todo #2287057: verify if submit() really is only used for UI. If not, $user must be passed.
    $user = workflow_current_user();

    // Get user input from element.
    $field_name = $transition->getFieldName();
    $uid = $user->id();
    $force = FALSE;

    // Read value from form input, else widget values.
    $action_values = _workflow_transition_form_get_triggering_button($form_state);
    $to_sid = $action_values['to_sid'] ?? $values['to_sid'][0]['target_id'];
    // Note: when editing existing Transition, user may still change comments.
    $comment = $values['comment'][0]['value'] ?? '';
    // @todo Why is 'timestamp' empty at create Node - when is it unset?
    $timestamp_values = $values['timestamp'][0]['value'] ?? ['scheduled' => false];
    $is_scheduled = (bool) $timestamp_values['scheduled'];
    $timestamp = WorkflowTransitionTimestamp::valueCallback($timestamp_values, $timestamp_values, $form_state);

    if (!isset($to_sid)) {
      $entity_id = $transition->getTargetEntityId();
      \Drupal::messenger()->addError(t('Error: content @id has no workflow attached. The data is not saved.', ['@id' => $entity_id]));
      // The new state is still the previous state.
      return $transition;
    }

    // @todo D8: add below exception.
    // Extract the data from $values, depending on the type of widget.
    // @todo D8: use massageFormValues($values, $form, $form_state).
    /*
    $old_sid = workflow_node_previous_state($entity, $entity_type, $field_name);
    if (!$old_sid) {
      // At this moment, $old_sid should have a value. If the content does not
      // have a state yet, old_sid contains '(creation)' state. But if the
      // content is not associated to a workflow, old_sid is now 0. This may
      // happen in workflow_vbo, if you assign a state to non-relevant nodes.
      $entity_id = entity_id($entity_type, $entity);
      \Drupal::messenger()->addError(t('Error: content @id has no workflow
        attached. The data is not saved.', ['@id' => $entity_id]));
      // The new state is still the previous state.
      $new_sid = $old_sid;
      return $new_sid;
    }
     */

    /*
     * Process.
     */

    $transition->setValues($to_sid, $uid, $timestamp, $comment);
    if (!$transition->isExecuted()) {
      $transition->schedule($is_scheduled);
      $transition->force($force);
    }
    // Add the attached fields to the transition.
    // Caveat: This works automatically on a Workflow Form,
    // but only with a hack on a widget.
    // @todo This line seems necessary for node edit, not for node view.
    // @todo Support 'attached fields' in ScheduledTransition.
    $attached_fields = $transition->getAttachedFields();
    /** @var \Drupal\Core\Field\Entity\BaseFieldOverride $field */
    foreach ($attached_fields as $field_name => $field) {
      if (isset($values[$field_name])) {
        $transition->{$field_name} = $values[$field_name];
      }

      // #2899025 For each field, let other modules modify the copied values,
      // as a workaround for not-supported field types.
      $input ??= $form_state->getUserInput();
      $context = [
        'field' => $field,
        'field_name' => $field_name,
        'user_input' => $input[$field_name] ?? [],
        'item' => $values,
      ];
      \Drupal::moduleHandler()->alter('copy_form_values_to_transition_field', $transition, $context);
    }

    // Update targetEntity's itemList with the workflow field in two formats.
    $transition->updateEntity();

    // Update form_state, so core can update entity as well.
    $to_sid = $transition->getToSid();
    $form_state->setValue(['to_sid', 0, 'target_id'], $to_sid);

    return $transition;
  }

}
