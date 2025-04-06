<?php

namespace Drupal\workflow\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\workflow\Element\WorkflowTransitionElement;
use Drupal\workflow\Entity\Workflow;
use Drupal\workflow\Entity\WorkflowTransitionInterface;
use Drupal\workflow\Form\WorkflowTransitionForm;

/**
 * Plugin implementation of the 'workflow_default' widget.
 *
 * @FieldWidget(
 *   id = "workflow_default",
 *   label = @Translation("Workflow Transition form"),
 *   field_types = {"workflow"},
 * )
 */
class WorkflowDefaultWidget extends WidgetBase {

  /**
   * Generates a widget.
   *
   * @param \Drupal\workflow\Entity\WorkflowTransitionInterface $transition
   *   A WorkflowTransition.
   *
   * @return array
   *   A render element, with key = field name.
   */
  public static function createInstance(WorkflowTransitionInterface $transition) : array {
    $element = [];

    $entity_type_manager = \Drupal::service('entity_type.manager');
    $entity = $transition->getTargetEntity();
    $entity_type_id = $entity->getEntityTypeId();
    $entity_bundle = $entity->bundle();
    $view_mode = 'default';
    $field_name = $transition->getFieldName();

    /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $form_display */
    $entity_form_display = $entity_type_manager->getStorage('entity_form_display');
    $dummy_form['#parents'] = [];
    $form_state = new FormState();
    $form_display = $entity_form_display->load("$entity_type_id.$entity_bundle.$view_mode");
    // $form_state_clone = clone $form_state;
    // $form_state_clone->set('entity', $entity);
    // $form_state_clone->set('form_display', $form_display);
    // $widget_fields = [$field_name];
    // foreach ($form_display->getComponents() as $name => $component) {
    //   if (in_array($name, $widget_fields)) {
    if ($widget = $form_display->getRenderer($field_name)) {
      $items = $entity->get($field_name);
      $items->filterEmptyItems();
      $element[$field_name] = $widget->form($items, $dummy_form, $form_state);
    }
    //   }
    // }
    return $element;
  }

  /**
   * {@inheritdoc}
   *
   * Be careful: Widget may be shown in very different places. Test carefully!!
   *  - On a entity add/edit page;
   *  - On a entity preview page;
   *  - On a entity view page;
   *  - On a entity 'workflow history' tab;
   *  - On a comment display, in the comment history;
   *  - On a comment form, below the comment history.
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {

    $wid = $this->getFieldSetting('workflow_type');
    if (!$workflow = Workflow::load($wid)) {
      // @todo Add error message.
      return $element;
    }

    if ($this->isDefaultValueWidget($form_state)) {
      // On the Field settings page, User may not set a default value
      // (this is done by the Workflow module).
      return [];
    }

    /** @var \Drupal\workflow\Plugin\Field\FieldType\WorkflowItem $item */
    $item = $items[$delta];
    /** @var \Drupal\field\Entity\FieldConfig $field_config */
    $field_config = $item->getFieldDefinition();
    /** @var \Drupal\field\Entity\FieldStorageConfig $field_storage */
    $field_storage = $field_config->getFieldStorageDefinition();

    $entity = $item->getEntity();
    $field_name = $field_storage->getName();
    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
    $transition = WorkflowTransitionForm::getDefaultTransition($entity, $field_name);

    // To prepare the widget, use the Form, in order to get extra fields.
    $form_state_additions = [
      'input' => $form_state->getUserInput(),
      'values' => $form_state->getValues(),
      'triggering_element' => $form_state->getTriggeringElement(),
    ];
    $workflow_form = WorkflowTransitionForm::createInstance($entity, $field_name, $form_state_additions, $transition);
    $element = WorkflowTransitionForm::trimWorkflowTransitionForm($workflow_form, $transition);

    return $element;
  }

   /**
   * {@inheritdoc}
   */
  public function extractFormValues(FieldItemListInterface $items, array $form, FormStateInterface $form_state) {
    // parent::extractFormValues($items, $form, $form_state);
    // Override WidgetBase::extractFormValues() since
    // it extracts field values without respecting #tree = TRUE.
    // So, the following function massageFormValues has nothing to do.
    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
    $transition = $form_state->getValue('_workflow_transition');

    $values = ['transition' =>
      // $form_state->getValues()
      $form_state->getUserInput()
      + ['#default_value' => $transition],
    ];

    // Let the widget massage the submitted values.
    $values = $this->massageFormValues($values, $form, $form_state);
    // Make sure the targetEntity is set correctly.
    $is_new = $transition->getTargetEntity()->isNew();
    if ($is_new){
      // For some reason this is not OK for inserting Nodes, so update $items.
      $to_sid = $transition->getToSid();
      $items->setValue($to_sid);
      $items->__set('_workflow_transition', $transition);
    }
    // Update the entity in a 'normal' situation.
    // Update targetEntity's itemList with the workflow field in two formats.
    $transition->updateEntity();
  }

  /**
   * {@inheritdoc}
   *
   * Implements workflow_transition() -> WorkflowDefaultWidget::submit().
   *
   * This is called from function _workflow_form_submit($form, &$form_state)
   * It is a replacement of function workflow_transition($entity, $to_sid, $force, $field)
   * It performs the following actions;
   * - save a scheduled action
   * - update history
   * - restore the normal $items for the field.
   *
   * @todo Remove update of {node_form} table. (separate task, because it has features, too.)
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    // @todo #2287057: verify if submit() really is only used for UI.
    // If not, $user must be passed.
    $user = workflow_current_user();

    // Set the new value.
    // Beware: We presume cardinality = 1 !!
    // The widget form element type has transformed the value to a
    // WorkflowTransition object at this point. We need to convert it
    // back to the regular 'value' string format.
    foreach ($values as &$item) {
      if (!empty($item)) {
        // Use a proprietary version of copyFormValuesToEntity().
        /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
        $transition = $item['#default_value'];
        $transition = WorkflowTransitionElement::copyFormValuesToTransition($transition, $form, $form_state, $item);

        // Try to execute the transition. Return $from_sid when error.
        if (!$transition) {
          // This should not be possible (perhaps when testing/developing).
          $this->messenger()->addError($this->t('Error: the transition from %from_sid to %to_sid could not be generated.'));
          // The current value is still the previous state.
          $to_sid = $from_sid = 0;
        }
        else {
          // The transition may be scheduled or not. Save the result, and
          // rely upon hook workflow_entity_insert/update($entity) in
          // file workflow.module to save/execute the transition.

          // - validate option; add hook to let other modules change comment.
          // - add to history; add to watchdog
          // Return the new State ID. (Execution may fail and return the old Sid.)

          $force = FALSE; // @todo D8-port: add to form for usage in VBO.

          // Now, save/execute the transition.
          // $entity = $transition->getTargetEntity();
          $from_sid = $transition->getFromSid();
          $force = $force || $transition->isForced();

          if (!$transition->isAllowed($user, $force)) {
            // Transition is not allowed.
            $to_sid = $from_sid;
          }
          else {
            // If Entity is inserted, the Id is not yet known.
            // So we can't yet save the transition right now, but must rely on
            // function/hook workflow_entity_insert($entity) in file workflow.module.
            // $to_sid = $transition->execute($force);

            // If Entity is updated, to stay in sync with insert, we rely on
            // function/hook workflow_entity_update($entity) in file workflow.module.
            // $to_sid = $transition->execute($force);
            $to_sid = $transition->getToSid();
          }
        }

        // Now the data is captured in the Transition, and before calling the
        // Execution, restore the default values.
        //
        // N.B. Align the following functions:
        // - WorkflowDefaultWidget::massageFormValues();
        // - WorkflowManager::executeTransition().
        // Set the transition back, to be used in hook_entity_update().
        // Set the value at the proper location.
        if ($transition && $transition->isScheduled()) {
          $item['value'] = $from_sid;
        }
        else {
          $item['value'] = $to_sid;
        }
      }
    }
    return $values;
  }

}
