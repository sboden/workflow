<?php

namespace Drupal\workflow;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Defines a class to build a listing of Workflow entities.
 *
 * @see \Drupal\workflow\Entity\Workflow
 */
class WorkflowListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   *
   * Building the header and content lines for the contact list.
   *
   * Calling the parent::buildHeader() adds a column for the possible actions
   * and inserts the 'edit' and 'delete' links as defined for the entity type.
   */
  public function buildHeader() {
    $header['id'] = $this->t('ID');
    $header['label'] = $this->t('Label');
    $header['status'] = $this->t('Status');

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $workflow) {
    $row['id'] = $workflow->id();
    $row['label'] = $workflow->label();
    // @todo $workflow->getStatus();
    $row['status'] = '';

    return $row + parent::buildRow($workflow);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $workflow) {
    $operations = parent::getDefaultOperations($workflow);

    /** @var \Drupal\workflow\Entity\WorkflowInterface $workflow */
    // Do not delete a Workflow if it contains content.
    if (!$workflow->isDeletable()) {
      unset($operations['delete']);
    }

    $wid = $workflow->id();
    $operations['states'] = [
      'title' => t('Manage workflow states'),
      'weight' => 40,
      'url' => Url::fromRoute('entity.workflow_state.collection', ['workflow_type' => $wid]),
    ];
    $operations['transitions'] = [
      'title' => t('Manage workflow transitions'),
      'weight' => 40,
      'url' => Url::fromRoute('entity.workflow_transition.collection', ['workflow_type' => $wid]),
    ];
    $operations['transition labels'] = [
      'title' => t('Manage workflow transition labels'),
      'weight' => 40,
      'url' => Url::fromRoute('entity.workflow_transition_label.collection', ['workflow_type' => $wid]),
    ];

    /*
     * Allow modules to insert their own workflow operations to the list.
     */
    // This is what EntityListBuilder::getOperations() does:
    // $operations = $this->getDefaultOperations($workflow);
    // $operations += $this->moduleHandler()->invokeAll('entity_operation', [$workflow]);
    // $this->moduleHandler->alter('entity_operation', $operations, $workflow);

    // In D8, the interface of below hook_workflow_operations has changed a bit.
    // @see EntityListBuilder::getOperations, workflow_operations, workflow.api.php.
    $operations += $this->moduleHandler()->invokeAll('workflow_operations', ['workflow', $workflow]);

    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();

    /*
     * Allow modules to insert their own top_action links to the list, like cleanup module.
     *
     * This is not done anymore via the workflow hook.
     * Instead, for an example:
     *   @see workflow.links.action.yml
     *   @see workflow.api.php under 'hook_workflow_operations'.
     */
    // $top_actions = \Drupal::moduleHandler()
    // ->invokeAll('workflow_operations', ['top_actions', NULL]);
    // $top_actions_args = [
    //   'links' => $top_actions,
    //   'attributes' => ['class' => ['inline', 'action-links']],
    // ];
    return $build;
  }

}
