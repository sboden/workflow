<?php

namespace Drupal\workflow\Entity;

use Drupal\user\UserInterface;

/**
 * Defines a common interface for Workflow*Transition* objects.
 *
 * @see \Drupal\workflow\Entity\WorkflowConfigTransition
 * @see \Drupal\workflow\Entity\WorkflowTransition
 * @see \Drupal\workflow\Entity\WorkflowScheduledTransition
 */
interface WorkflowConfigTransitionInterface {

  /**
   * Determines if the current transition between 2 states is allowed.
   *
   * This is checked in the following locations:
   * - in settings;
   * - in permissions;
   * - by permission hooks, implemented by other modules.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user to act upon.
   *   May have the custom WORKFLOW_ROLE_AUTHOR_RID role.
   * @param bool $force
   *   Indicates if the transition must be forced(E.g., by Cron, Rules).
   *
   * @return bool
   *   TRUE if OK, else FALSE.
   */
  public function isAllowed(UserInterface $user, $force = FALSE);

  /**
   * Returns the Workflow object of this object.
   *
   * @return Workflow
   *   Workflow object.
   */
  public function getWorkflow();

  /**
   * Returns the Workflow ID of this object.
   *
   * @return string
   *   Workflow ID.
   */
  public function getWorkflowId();

  /**
   * Returns the 'from' State object.
   *
   * @return \Drupal\workflow\Entity\WorkflowState
   *   A WorkflowState object.
   */
  public function getFromState();

  /**
   * Returns the 'to' State object.
   *
   * @return \Drupal\workflow\Entity\WorkflowState
   *   A WorkflowState object.
   */
  public function getToState();

  /**
   * Returns the 'from' State ID.
   *
   * @return int
   *   A WorkflowState ID.
   */
  public function getFromSid();

  /**
   * Returns the 'from' State object.
   *
   * @return int
   *   A WorkflowState ID.
   */
  public function getToSid();

  /**
   * Determines if the State changes by this Transition.
   *
   * @return bool
   *   TRUE if the From and To state ID's are different.
   */
  public function hasStateChange();

}
