<?php

namespace Drupal\workflow\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Defines a common interface for Workflow*Transition* objects.
 *
 * @see \Drupal\workflow\Entity\WorkflowConfigTransition
 * @see \Drupal\workflow\Entity\WorkflowTransition
 * @see \Drupal\workflow\Entity\WorkflowScheduledTransition
 */
interface WorkflowTransitionInterface extends WorkflowConfigTransitionInterface, FieldableEntityInterface, EntityOwnerInterface {

  /**
   * Creates a WorkflowTransition or WorkflowScheduledTransition object.
   *
   * @param array $values
   *   Keyed list of values.
   *   $values[0] may contain a State object or State ID.
   *
   * @return \Drupal\workflow\Entity\WorkflowTransitionInterface|null
   *   The Transition object.
   */
  public static function create(array $values = []);

  /**
   * Creates a duplicate of the Transition, of the given type.
   *
   * @return \Drupal\workflow\Entity\WorkflowTransitionInterface
   *   A clone of $this with all identifiers unset, so saving it inserts a new
   *   entity into the storage system.
   */
 public function createDuplicate($new_class_name = WorkflowTransition::class);
  /**
   * Load (Scheduled) WorkflowTransitions, most recent first.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param int $entity_id
   *   An entity ID.
   * @param int[] $revision_ids
   *   Optional. A list of entity revision ID's.
   * @param string $field_name
   *   Optional. Can be NULL, if you want to load any field.
   * @param string $langcode
   *   Optional. Can be empty, if you want to load any language.
   * @param string $sort
   *   Optional sort order {'ASC'|'DESC'}.
   * @param string $transition_type
   *   The type of the transition to be fetched.
   *
   * @return \Drupal\workflow\Entity\WorkflowTransitionInterface
   *   Object representing one row from the {workflow_transition_history} table.
   */
  public static function loadByProperties($entity_type_id, $entity_id, array $revision_ids = [], $field_name = '', $langcode = '', $sort = 'ASC', $transition_type = '');

  /**
   * Given an entity, get all transitions for it.
   *
   * Since this may return a lot of data, a limit is included to allow for only one result.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param int[] $entity_ids
   *   A (possibly empty) list of entity ID's.
   * @param int[] $revision_ids
   *   Optional. A list of entity revision ID's.
   * @param string $field_name
   *   Optional. Can be NULL, if you want to load any field.
   * @param string $langcode
   *   Optional. Can be empty, if you want to load any language.
   * @param int $limit
   *   Optional. Can be NULL, if you want to load all transitions.
   * @param string $sort
   *   Optional sort order {'ASC'|'DESC'}.
   * @param string $transition_type
   *   The type of the transition to be fetched.
   *
   * @return WorkflowTransitionInterface[]
   *   An array of transitions.
   */
  public static function loadMultipleByProperties($entity_type_id, array $entity_ids, array $revision_ids = [], $field_name = '', $langcode = '', $limit = NULL, $sort = 'ASC', $transition_type = '');

  /**
   * Helper function for __construct.
   *
   * Usage:
   *   $transition = WorkflowTransition::create([$current_sid, 'field_name' => $field_name]);
   *   $transition->setTargetEntity($entity);
   *   $transition->setValues($new_sid, $user->id(), REQUEST_TIME, $comment);
   *
   * @param string $to_sid
   *   The new State ID.
   * @param int $uid
   *   The user ID.
   * @param int $timestamp
   *   The unix timestamp.
   * @param string $comment
   *   The comment.
   * @param bool $force_create
   *   An indicator, to force the execution of the Transition.
   *
   * @return \Drupal\workflow\Entity\WorkflowTransitionInterface
   *   The Transition itself.
   */
  public function setValues($to_sid, $uid = NULL, $timestamp = NULL, $comment = '', $force_create = FALSE);

  /**
   * Sets the Entity, that is added to the Transition.
   *
   * Also sets all dependent fields, that will be saved in tables {workflow_transition_*}.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The Entity ID or the Entity object, to add to the Transition.
   *
   * @return \Drupal\workflow\Entity\WorkflowTransitionInterface
   *   The Transition itself.
   */
  public function setTargetEntity(EntityInterface $entity);

  /**
   * Returns the entity to which the workflow is attached.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity to which the workflow is attached.
   */
  public function getTargetEntity();
  /**
   * Returns the ID of the entity to which the workflow is attached.
   *
   * @return int
   *   The ID of the entity to which the workflow is attached.
   */
  public function getTargetEntityId();

  /**
   * Returns the type of the entity to which the workflow is attached.
   *
   * @return string
   *   An entity type.
   */
  public function getTargetEntityTypeId();

  /**
   * {@inheritdoc}
   */
  public function getFromState();

  /**
   * {@inheritdoc}
   */
  public function getToState();

  /**
   * {@inheritdoc}
   */
  public function getFromSid();

  /**
   * {@inheritdoc}
   */
  public function getToSid();

  /**
   * Get the comment of the Transition.
   *
   * @return string
   *   The comment.
   */
  public function getComment();

  /**
   * Sets the comment of the Transition.
   *
   * @param string $value
   *   The new comment.
   *
   * @return \Drupal\workflow\Entity\WorkflowTransitionInterface
   *   The Transition itself.
   */
  public function setComment($value);

  /**
   * Get the field_name for which the Transition is valid.
   *
   * @return string
   *   The field_name, that is added to the Transition.
   */
  public function getFieldName();

  /**
   * Get the language code for which the Transition is valid.
   *
   * @return string
   *   $langcode
   *
   * @todo OK?? Shouldn't we use entity's language() method for langcode?
   */
  public function getLangcode();

  /**
   * Returns the time on which the transitions was or will be executed.
   *
   * @return int
   *   The unix timestamp.
   */
  public function getTimestamp();

  /**
   * Returns the human-readable time.
   *
   * @return string
   *   The formatted timestamp.
   */
  public function getTimestampFormatted();

  /**
   * Returns the time on which the transitions was or will be executed.
   *
   * @param int $value
   *   The new timestamp.
   *
   * @return \Drupal\workflow\Entity\WorkflowTransitionInterface
   *   The Transition itself.
   */
  public function setTimestamp($value);

  /**
   * Execute a transition (change state of an entity).
   *
   * A Scheduled Transition shall only be saved, unless the
   * 'schedule' property is set.
   *
   * @param bool $force
   *   If set to TRUE, workflow permissions will be ignored.
   *
   * @return string
   *   New state ID. If execution failed, old state ID is returned,
   *
   * @usage
   *   $transition->schedule(FALSE);
   *   $to_sid = $transition->execute(TRUE);
   */
  public function execute($force = FALSE);

  /**
   * Executes a transition (change state of an entity), from OUTSIDE the entity.
   *
   * Use $transition->executeAndUpdateEntity() to start a State Change from
   *   outside an entity, e.g., workflow_cron().
   * Use $transition->execute() to start a State Change from within an entity.
   *
   * A Scheduled Transition ($transition->isScheduled() == TRUE) will be
   *   un-scheduled and saved in the history table.
   *   The entity will not be updated.
   * If $transition->isScheduled() == FALSE, the Transition will be
   *   removed from the {workflow_transition_scheduled} table (if necessary),
   *   and added to {workflow_transition_history} table.
   *   Then the entity wil be updated to reflect the new status.
   *
   * @param bool $force
   *   If set to TRUE, workflow permissions will be ignored.
   *
   * @return string
   *   The resulting WorkflowState id.
   *
   * @usage
   *   $to_sid = $transition->->executeAndUpdateEntity($force);
   *
   * @see workflow_execute_transition()
   */
  public function executeAndUpdateEntity($force = FALSE);

  /**
   * Updates the entity's workflow field with value and transition.
   */
  public function updateEntity();

  /**
   * Returns if this is an Executed Transition.
   *
   * @return bool
   *   TRUE if the execution may be prohibited, somehow.
   */
  public function isExecuted();

  /**
   * Set the 'isExecuted' property.
   *
   * @param bool $isExecuted
   *   TRUE if the Transition is already executed, else FALSE.
   *
   * @return \Drupal\workflow\Entity\WorkflowTransitionInterface
   *   The Transition itself.
   */
  public function setExecuted($isExecuted = TRUE);

  /**
   * Returns if this is a revertable Transition on the History tab.
   *
   * @return bool
   *   TRUE or FALSE.
   */
  public function isRevertable();

  /**
   * Sets the Transition to be scheduled or not.
   *
   * @param bool $schedule
   *   TRUE if scheduled, else FALSE.
   *
   * @return \Drupal\workflow\Entity\WorkflowTransitionInterface
   *   The Transition itself.
   */
  public function schedule($schedule = TRUE);

  /**
   * Returns if this is a Scheduled Transition.
   *
   * @return bool
   *   TRUE if scheduled, else FALSE.
   */
  public function isScheduled();

  /**
   * A transition may be forced skipping checks.
   *
   * @return bool
   *   TRUE if the transition is forced. (Allow not-configured transitions).
   */
  public function isForced();

  /**
   * Set if a transition must be executed.
   *
   * Even if transition is invalid or user not authorized.
   *
   * @param bool $force
   *   TRUE if the execution may be prohibited, somehow.
   *
   * @return object
   *   The transition itself.
   */
  public function force($force = TRUE);

  /**
   * Helper/debugging function. Shows simple contents of Transition.
   *
   * @param string $function
   *   Optional, the name of the calling function.
   */
  public function dpm($function = '');

}
