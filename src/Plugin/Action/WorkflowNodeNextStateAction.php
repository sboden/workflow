<?php

namespace Drupal\workflow\Plugin\Action;

use Drupal\Core\Form\FormStateInterface;

/**
 * Sets an entity to the next state.
 *
 * The only change is the 'type' in the Annotation, so it works on Nodes,
 * and can be seen on admin/content page.
 *
 * @Action(
 *   id = "workflow_node_next_state_action",
 *   label = @Translation("Change entity to next Workflow state"),
 *   type = "node",
 * )
 */
class WorkflowNodeNextStateAction extends WorkflowStateActionBase {

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    return [
      'module' => ['workflow'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Remove to_sid. User can't set it, since we want a dynamic 'next' state.
    unset($form['workflow_transition_action_config']['to_sid']);
    $form['workflow_transition_action_config']['field_name']['#access'] = TRUE;
    // Allow any workflow, for multiple entity types.
    $form['workflow_transition_action_config']['field_name']['#required'] = FALSE;
    $form['workflow_transition_action_config']['field_name']['#options']
      += ['' => '-- Any --'];
    $form['workflow_transition_action_config']['field_name']['#description']
      = $this->t('Choose the field name. May be left empty.');

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function execute($object = NULL) {

    if (!$transition = $this->getTransitionForExecution($object)) {
      $this->messenger()->addWarning(
        $this->t('The entity %label is not valid for this action.',
          ['%label' => $object ? $object->label() : ''])
      );
      return;
    }

    $field_name = $this->configuration['field_name'];
    $comment = $this->configuration['comment'];
    $force = $this->configuration['force'];

    if ($field_name && ($field_name <> $transition->getFieldName())) {
      $this->messenger()->addWarning(
        $this->t('The entity %label is not valid for this action. Wrong field name.',
          ['%label' => $object ? $object->label() : ''])
      );
      return;
    }

    /*
     * Set the new next state.
     */
    $entity = $transition->getTargetEntity();
    $user = $transition->getOwner();
    $to_sid = $transition->getWorkflow()->getNextSid($entity, $field_name, $user, $force);

    // Add actual data.
    $transition->to_sid = $to_sid;
    $transition->setComment($comment);
    $transition->force($force);

    // Fire the transition.
    $transition->executeAndUpdateEntity($force);
  }

}
