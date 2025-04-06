<?php

namespace Drupal\workflow;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\user\PermissionHandlerInterface;
use Drupal\workflow\Entity\Workflow;
use Drupal\workflow\Entity\WorkflowInterface;

/**
 * Provides dynamic permissions for workflows of different types.
 */
class WorkflowPermissions implements PermissionHandlerInterface {

  use StringTranslationTrait;

  /**
   * A permissions callback.
   *
   * @see workflow.permissions.yml
   *
   * @param \Drupal\workflow\Entity\WorkflowInterface $workflow
   *   (Optional) workflow object.

   * @return array
   *   An array of permissions per workflow type.
   *   @see \Drupal\user\PermissionHandlerInterface::getPermissions()
   */
  public function getPermissions(WorkflowInterface $workflow = NULL) {
    $perms = [];
    // Generate workflow permissions for all workflow types.
    foreach (Workflow::loadMultiple($workflow ? [$workflow->id()] : NULL) as $type) {
      $perms += $this->buildPermissions($type);
    }
    return $perms;
  }

  /**
   * {@inheritdoc}
   *
   * Returns a list of workflow permissions for a given workflow type.
   *
   * @param \Drupal\workflow\Entity\WorkflowInterface $workflow
   *   The workflow object.
   *
   * @return array
   *   An associative array of permission names and descriptions.
   */
  protected function buildPermissions(WorkflowInterface $workflow) {
    $type_id = $workflow->id();
    $type_params = ['%type_name' => $workflow->label()];

    return [
      // D7->D8-Conversion of the 'User 1 is special' permission (@see NodePermissions::bypass node access).
      "bypass $type_id workflow_transition access" => [
        'title' => $this->t('%type_name: Bypass transition access control', $type_params),
        'description' => $this->t('View, edit and delete all transitions regardless of permission restrictions.'),
        'restrict access' => TRUE,
        'dependencies' => [
          'config' => ["workflow.workflow.$type_id"],
        ],
      ],
      // D7->D8-Conversion of 'participate in workflow' permission to "create $type_id workflow_transition" (@see NodePermissions::create content).
      "create $type_id workflow_transition" => [
        'title' => $this->t('%type_name: Participate in workflow', $type_params),
        'description' => $this->t("<i>Warning: For better control, <b>uncheck
          'Authenticated user', manage permissions per separate role,
          and re-enable 'Authenticated user'.</b></i>
          Role is enabled to create state transitions. (Determines
          transition-specific permission on the workflow admin page.)"),
        'dependencies' => [
          'config' => ["workflow.workflow.$type_id"],
        ],
      ],
      // D7->D8-Conversion of 'schedule workflow transitions' permission to "schedule $type_id transition" (@see NodePermissions::create content).
      "schedule $type_id workflow_transition" => [
        'title' => $this->t('%type_name: Schedule state transition', $type_params),
        'description' => $this->t('Role is enabled to schedule state transitions.'),
        'dependencies' => [
          'config' => ["workflow.workflow.$type_id"],
        ],
      ],
      // D7->D8-Conversion of 'workflow history' permission on Workflow settings to "access $type_id overview" (@see NodePermissions::access content overview).
      "access own $type_id workflow_transion overview" => [
        'title' => $this->t('%type_name: Access Workflow history tab of own content', $type_params),
        'description' => $this->t('Role is enabled to view the "Workflow state transition history" tab on own entity.'),
        'dependencies' => [
          'config' => ["workflow.workflow.$type_id"],
        ],
      ],
      "access any $type_id workflow_transion overview" => [
        'title' => $this->t('%type_name: Access Workflow history tab of any content', $type_params),
        'description' => $this->t('Role is enabled to view the "Workflow state transition history" tab on any entity.'),
        'dependencies' => [
          'config' => ["workflow.workflow.$type_id"],
        ],
      ],
      // D7->D8-Conversion of 'show workflow transition form' permission. @see #1893724.
      "access $type_id workflow_transition form" => [
        'title' => $this->t('%type_name: Access the Workflow state transition form on entity view page', $type_params),
        'description' => $this->t('Role is enabled to view a "Workflow state transition" block/widget and add a state transition on the entity page.'),
        'dependencies' => [
          'config' => ["workflow.workflow.$type_id"],
        ],
      ],
      // D7->D8-Conversion of 'edit workflow comment' to "edit own/any $type_id transition"
      "edit own $type_id workflow_transition" => [
        'title' => $this->t('%type_name: Edit own comments', $type_params),
        'description' => $this->t('Edit the comment of own executed state transitions.'),
        'restrict access' => TRUE,
        'dependencies' => [
          'config' => ["workflow.workflow.$type_id"],
        ],
      ],
      "edit any $type_id workflow_transition" => [
        'title' => $this->t('%type_name: Edit any comments', $type_params),
        'description' => $this->t('Edit the comment of any executed state transitions.'),
        'restrict access' => TRUE,
        'dependencies' => [
          'config' => ["workflow.workflow.$type_id"],
        ],
      ],
      // Workflow module has no 'delete' permissions.
      /*
      "delete own $type_id workflow_transition" => [
        'title' => $this->t('%type_name: Delete own content', $type_params),
      ],
      "delete any $type_id workflow_transition" => [
        'title' => $this->t('%type_name: Delete any content', $type_params),
      ],
       */
      // D7->D8-Conversion of 'revert workflow' permission to "revert any/own $type_id transition".
      "revert own $type_id workflow_transition" => [
        'title' => $this->t('%type_name: Revert own state transition', $type_params),
        'description' => $this->t('Allow user to revert own last executed state transition on entity.'),
        'restrict access' => TRUE,
        'dependencies' => [
          'config' => ["workflow.workflow.$type_id"],
        ],
      ],
      "revert any $type_id workflow_transition" => [
        'title' => $this->t('%type_name: Revert any state transition', $type_params),
        'description' => $this->t('Allow user to revert any last executed state transition on entity.'),
        'restrict access' => TRUE,
        'dependencies' => [
          'config' => ["workflow.workflow.$type_id"],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function moduleProvidesPermissions($module_name = 'workflow') {
    return TRUE;
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for 'workflow_type'.
   * Implements hook_ENTITY_TYPE_delete() for 'workflow_type'.
   *
   * Grant/Revoke all roles to participate in a Workflow by default.
   *
   * @param \Drupal\workflow\Entity\WorkflowInterface $workflow
   *   The workflow object.
   * @param bool $grant
   *   TRUE to grant, FALSE to revoke permissions to participate in workflow.
   */
  public function changeRolePermissions(WorkflowInterface $workflow, bool $grant) {
    $type_id = $workflow->id();
    $roles = workflow_get_user_role_names();
    unset($roles[WORKFLOW_ROLE_AUTHOR_RID]);

    foreach ($roles as $rid => $role) {
      if ($grant) {
        // Enable a default 'participate' permission for all roles.
        $permissions = ["create $type_id workflow_transition" => $grant];
        user_role_change_permissions($rid, $permissions);
      }
      else {
        // Disable all permissions for all roles.
        // Disable all permissions for all roles.
        $permissions = $this->getPermissions($workflow);
        $permissions = array_map(fn($permissions) => $grant, $permissions);

        user_role_change_permissions($rid, $permissions);
      }
    }
  }

}
