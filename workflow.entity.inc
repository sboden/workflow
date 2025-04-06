<?php

/**
 * @file
 * Implements entity hooks.
 */

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\workflow\Controller\WorkflowTransitionListController;
use Drupal\workflow\Entity\WorkflowManager;
use Drupal\workflow\WorkflowPermissions;

/**********************************************************************
 * CRUD hooks.
 */

/**
 * Implements hook_entity_insert().
 */
function workflow_entity_insert(EntityInterface $entity) {
  _workflow_execute_transitions($entity);
}

/**
 * Implements hook_entity_update().
 */
function workflow_entity_update(EntityInterface $entity) {
  _workflow_execute_transitions($entity);
}

/**
 * Implements hook_entity_delete().
 *
 * Delete the corresponding workflow table records.
 */
function workflow_entity_delete(EntityInterface $entity) {
  WorkflowManager::entityDelete($entity);
}

/**
 * Implements hook_entity_operation for workflow_transition.
 *
 * Core hooks: Change the operations column in a Entity list.
 * Adds a 'revert' operation.
 *
 * @see EntityListBuilder::getOperations()
 */
function workflow_entity_operation(EntityInterface $entity) {
  $operations = [];

  // Check correct entity type.
  if (in_array($entity->getEntityTypeId(), ['workflow_transition'])) {
    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $entity */
    $operations = WorkflowTransitionListController::addRevertOperation($entity);
  }

  return $operations;
}

/**
 * Implements hook_user_cancel().
 *
 * Update tables for deleted account, move account to user 0 (anon.)
 * ALERT: This may cause previously non-Anonymous posts to suddenly
 * be accessible to Anonymous.
 *
 * @see hook_user_cancel()
 */
function workflow_user_cancel($edit, AccountInterface $account, $method) {
  WorkflowManager::cancelUser($edit, $account, $method);
}

/**
 * Implements hook_user_delete().
 *
 * @todo Hook hook_user_delete does not exist. hook_ENTITY_TYPE_delete?
 */
function workflow_user_delete($account) {
  WorkflowManager::deleteUser($account);
}

/**
 * Implements hook_ENTITY_TYPE_insert() for 'workflow_type'.
 *
 * Is called when adding a new Workflow type.
 */
function workflow_workflow_type_insert(EntityInterface $entity) {
  $permissions_manager = new WorkflowPermissions();
  $permissions_manager->changeRolePermissions($entity, TRUE);
}

/**
 * Implements hook_ENTITY_TYPE_predelete() for 'workflow_type'.
 *
 * Is called when deleting a new Workflow type.
 */
function workflow_workflow_type_predelete(EntityInterface $entity) {
  $permissions_manager = new WorkflowPermissions();
  $permissions_manager->changeRolePermissions($entity, FALSE);
}

/**
 * Execute transitions. If prohibited, restore original field value.
 *
 * - insert: use hook_insert(), to have the Entity ID determined when saving transitions.
 * - update: use hook_presave() to revert executions,
 * - so, do not use hook_update().
 *
 * @param \Drupal\Core\Entity\EntityInterface $entity
 *   An Entity.
 */
function _workflow_execute_transitions(EntityInterface $entity) {
  // Execute/save the transitions from the widgets in the entity form.
  WorkflowManager::executeTransitionsOfEntity($entity);
}
