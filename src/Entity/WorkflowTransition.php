<?php

namespace Drupal\workflow\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Language\Language;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\workflow\Event\WorkflowEvents;
use Drupal\workflow\Event\WorkflowTransitionEvent;
use Drupal\workflow\WorkflowTypeAttributeTrait;

/**
 * Implements an actual, executed, Transition.
 *
 * If a transition is executed, the new state is saved in the Field.
 * If a transition is saved, it is saved in table {workflow_transition_history}.
 *
 * @ContentEntityType(
 *   id = "workflow_transition",
 *   label = @Translation("Workflow transition"),
 *   label_singular = @Translation("Workflow transition"),
 *   label_plural = @Translation("Workflow transitions"),
 *   label_count = @PluralTranslation(
 *     singular = "@count Workflow transition",
 *     plural = "@count Workflow transitions",
 *   ),
 *   bundle_label = @Translation("Workflow type"),
 *   module = "workflow",
 *   translatable = FALSE,
 *   handlers = {
 *     "access" = "Drupal\workflow\WorkflowAccessControlHandler",
 *     "list_builder" = "Drupal\workflow\WorkflowTransitionListBuilder",
 *     "form" = {
 *        "add" = "Drupal\workflow\Form\WorkflowTransitionForm",
 *        "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *        "edit" = "Drupal\workflow\Form\WorkflowTransitionForm",
 *        "revert" = "Drupal\workflow\Form\WorkflowTransitionRevertForm",
 *      },
 *     "views_data" = "Drupal\workflow\WorkflowTransitionViewsData",
 *   },
 *   base_table = "workflow_transition_history",
 *   entity_keys = {
 *     "id" = "hid",
 *     "bundle" = "wid",
 *     "langcode" = "langcode",
 *   },
 *   permission_granularity = "bundle",
 *   bundle_entity_type = "workflow_type",
 *   field_ui_base_route = "entity.workflow_type.edit_form",
 *   links = {
 *     "canonical" = "/workflow_transition/{workflow_transition}",
 *     "delete-form" = "/workflow_transition/{workflow_transition}/delete",
 *     "edit-form" = "/workflow_transition/{workflow_transition}/edit",
 *     "revert-form" = "/workflow_transition/{workflow_transition}/revert",
 *   },
 * )
 */
class WorkflowTransition extends ContentEntityBase implements WorkflowTransitionInterface {

  /*
   * Adds the messenger trait.
   */
  use MessengerTrait;

  /*
   * Adds the translation trait.
   */
  use StringTranslationTrait;

  /*
   * Adds variables and get/set methods for Workflow property.
   */
  use WorkflowTypeAttributeTrait;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /*
   * Transition data: are provided via baseFieldDefinitions().
   */

  /*
   * Cache data.
   */

  /**
   * The target entity object.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   *
   * @usage Use WorkflowTransition->getTargetEntity() to fetch this.
   */
  protected $entity = NULL;

  /**
   * The user object.
   *
   * @var \Drupal\user\UserInterface
   *
   * @usage Use WorkflowTransition->getOwner() to fetch this.
   */
  protected $user = NULL;

  /**
   * Extra data: describe the state of the transition.
   *
   * @var bool
   */
  protected $isScheduled = FALSE;

  /**
   * Extra data: describe the state of the transition.
   *
   * @var bool
   */
  protected $isExecuted = FALSE;

  /**
   * Extra data: describe the state of the transition.
   *
   * @var bool
   */
  protected $isForced = FALSE;

  /**
   * Entity class functions.
   */

  /**
   * Creates a new entity.
   *
   * No arguments passed, when loading from DB.
   * All arguments must be passed, when creating an object programmatically.
   * One argument $entity may be passed, only to directly call delete() afterwards.
   *
   * {@inheritdoc}
   *
   * @see entity_create()
   */
  public function __construct(array $values = [], $entity_type_id = 'workflow_transition', $bundle = FALSE, array $translations = []) {
    parent::__construct($values, $entity_type_id, $bundle, $translations);
    $this->eventDispatcher = \Drupal::service('event_dispatcher');
    // This transition is not scheduled.
    $this->isScheduled = FALSE;
    // This transition is not executed, if it has no hid, yet, upon load.
    $this->isExecuted = ($this->id() > 0);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(array $values = []) {
    $state = $values[0] ?? NULL;
    switch (TRUE) {
      // First paramter must be State object or State ID.
      case is_string($state):
        $state = WorkflowState::load($state);
      case $state instanceof WorkflowState:
        /** @var \Drupal\workflow\Entity\WorkflowState $state */
        $values['wid'] = $state ? $state->getWorkflowId() : '';
        $values['from_sid'] = $state ? $state->id() : '';
        // Add default values.
        // @todo Use $uid = workflow_current_user()->id();
        $uid = \Drupal::currentUser()->id();
        $values += [
          'timestamp' => \Drupal::time()->getRequestTime(),
          'uid' => $uid,
        ];
        return parent::create($values);

      default:
        return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createDuplicate($new_class_name = WorkflowTransition::class) {
    $field_name = $this->getFieldName();
    $from_sid = $this->getFromSid();

    $duplicate = $new_class_name::create([$from_sid, 'field_name' => $field_name]);
    $duplicate->setTargetEntity($this->getTargetEntity());
    $duplicate->setValues($this->getToSid(), $this->getOwnerId(), $this->getTimestamp(), $this->getComment());
    $duplicate->force($this->isForced());
    return $duplicate;
  }

  /**
   * {@inheritdoc}
   */
  public function setValues($to_sid, $uid = NULL, $timestamp = NULL, $comment = '', $force_create = FALSE) {
    // Normally, the values are passed in an array, and set in parent::__construct, but we do it ourselves.
    $uid = $uid ?? workflow_current_user()->id();
    $from_sid = $this->getFromSid();

    $this->set('to_sid', $to_sid);
    $this->setOwnerId($uid);
    $this->setTimestamp($timestamp ?? \Drupal::time()->getRequestTime());
    $this->setComment($comment);

    // If constructor is called with new() and arguments.
    if (!$from_sid && !$to_sid && !$this->getTargetEntity()) {
      // If constructor is called without arguments, e.g., loading from db.
    }
    elseif ($from_sid && $this->getTargetEntity()) {
      // Caveat: upon entity_delete, $to_sid is '0'.
      // If constructor is called with new() and arguments.
    }
    elseif (!$from_sid) {
      // Not all parameters are passed programmatically.
      if (!$force_create) {
        $this->messenger()->addError(
          $this->t('Wrong call to constructor Workflow*Transition(%from_sid to %to_sid)',
            ['%from_sid' => $from_sid, '%to_sid' => $to_sid]));
      }
    }

    return $this;
  }

  /**
   * CRUD functions.
   */

  /**
   * Saves the entity.
   *
   * Mostly, you'd better use WorkflowTransitionInterface::execute().
   *
   * {@inheritdoc}
   */
  public function save() {

    if ($this->isScheduled()
      && $this::class == WorkflowTransition::class) {
      // Convert/cast/wrap Transition to ScheduledTransition.
      $transition = $this->createDuplicate(WorkflowScheduledTransition::class);
      $result = $transition->save();
      return $result;
    }

    // @todo $entity->revision_id is NOT SET when coming from node/XX/edit !!
    $entity = $this->getTargetEntity();
    $entity->getRevisionId();

    // Set Target Entity, to be used by Rules.
    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $reference */
    if ($reference = $this->get('entity_id')->first()) {
      $reference->set('entity', $entity);
    }

    $this->dispatchEvent(WorkflowEvents::PRE_TRANSITION);

    if ($this->isEmpty()) {
      // Empty transition.
      $result = SAVED_UPDATED;
    }
    elseif ($this->id()) {
      // Update the transition. It already exists.
      $result = parent::save();
    }
    elseif ($this->isScheduled()
    || $this->getEntityTypeId() == 'workflow_scheduled_transition') {
      // Avoid custom actions for subclass WorkflowScheduledTransition.
      $result = parent::save();
    }
    else {
      // Insert an executed transition.
      $entity = $this->getTargetEntity();
      $field_name = $this->getFieldName();

      WorkflowManager::deleteTransitionsOfEntity($entity, 'workflow_scheduled_transition', $field_name);

      // Insert the transition, unless it has already been inserted.
      // Note: this might be outdated due to code improvements.
      // @todo Allow a scheduled transition per revision.
      // @todo Allow a state per language version (langcode).
      $same_transition = self::loadByProperties($entity->getEntityTypeId(), $entity->id(), [], $field_name);
      if ($same_transition &&
        $same_transition->getTimestamp() == \Drupal::time()->getRequestTime() &&
        $same_transition->getToSid() == $this->getToSid()) {
        $result = SAVED_UPDATED;
      }
      $result = parent::save();
    }

    $this->dispatchEvent(WorkflowEvents::POST_TRANSITION);
    \Drupal::moduleHandler()->invokeAll('workflow', ['transition post', $this, $this->getOwner()]);
    $this->addPostSaveMessage();

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function dispatchEvent($event_name) {
    $transition_event = new WorkflowTransitionEvent($this);
    $this->eventDispatcher->dispatch($transition_event, $event_name);
  }

  /**
   * Generates a message after the Transition has been saved.
   */
  protected function addPostSaveMessage() {
    if ($this->isExecuted() && $this->hasStateChange()) {
      // Register state change with watchdog.
      if (!empty($this->getWorkflow()->getSetting('watchdog_log'))) {
        $message = $this->getEntityTypeId() == 'workflow_scheduled_transition'
          ? 'Scheduled state change of @entity_type_label %entity_label to %sid2 executed'
          : 'State of @entity_type_label %entity_label set to %sid2';
        $this->logError($message, 'notice');
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function loadByProperties($entity_type_id, $entity_id, array $revision_ids = [], $field_name = '', $langcode = '', $sort = 'ASC', $transition_type = 'workflow_transition') {
    $limit = 1;
    $transitions = self::loadMultipleByProperties($entity_type_id, [$entity_id], $revision_ids, $field_name, $langcode, $limit, $sort, $transition_type);
    if ($transitions) {
      $transition = reset($transitions);
      return $transition;
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function loadMultipleByProperties($entity_type_id, array $entity_ids, array $revision_ids = [], $field_name = '', $langcode = '', $limit = NULL, $sort = 'ASC', $transition_type = 'workflow_transition') {

    /** @var \Drupal\Core\Entity\Query\QueryInterface $query */
    $query = \Drupal::entityQuery($transition_type)
      ->condition('entity_type', $entity_type_id)
      ->accessCheck(FALSE)
      ->sort('timestamp', $sort)
      ->addTag($transition_type);
    if (!empty($entity_ids)) {
      $query->condition('entity_id', $entity_ids, 'IN');
    }
    if (!empty($revision_ids)) {
      $query->condition('revision_id', $entity_ids, 'IN');
    }
    if ($field_name != '') {
      $query->condition('field_name', $field_name, '=');
    }
    if ($langcode != '') {
      $query->condition('langcode', $langcode, '=');
    }
    if ($limit) {
      $query->range(0, $limit);
    }
    if ($transition_type == 'workflow_transition') {
      $query->sort('hid', 'DESC');
    }
    $ids = $query->execute();
    $transitions = $ids ? self::loadMultiple($ids) : [];
    return $transitions;
  }

  /**
   * Implementing interface WorkflowTransitionInterface - properties.
   */

  /**
   * Determines if the Transition is valid and can be executed.
   *
   * @todo Add to isAllowed() ?
   * @todo Add checks to WorkflowTransitionElement ?
   *
   * @return bool
   *   TRUE is the Transition is OK, else FALSE.
   */
  public function isValid() {
    $valid = TRUE;
    // Load the entity, if not already loaded.
    // This also sets the (empty) $revision_id in Scheduled Transitions.
    $entity = $this->getTargetEntity();

    if (!$entity) {
      // @todo There is a watchdog error, but no UI-error. Is this OK?
      $message = 'User tried to execute a Transition without an entity.';
      $this->logError($message);
      $valid = FALSE;
    }
    elseif (!$this->getFromState()) {
      // @todo The page is not correctly refreshed after this error.
      $message = $this->t('You tried to set a Workflow State, but
        the entity is not relevant. Please contact your system administrator.');
      $this->messenger()->addError($message);
      $message = 'Setting a non-relevant Entity from state %sid1 to %sid2';
      $this->logError($message);
      $valid = FALSE;
    }

    return $valid;
  }

  /**
   * Check if all fields in the Transition are empty.
   *
   * @return bool
   *   TRUE if the Transition is empty.
   */
  protected function isEmpty() {
    if ($this->getToSid() != $this->getFromSid()) {
      return FALSE;
    }
    if ($this->getComment()) {
      return FALSE;
    }
    $attached_fields = $this->getAttachedFields();
    foreach ($attached_fields as $field_name => $field) {
      if (isset($this->{$field_name}) && !$this->{$field_name}->isEmpty()) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function isAllowed(UserInterface $user, $force = FALSE) {
    if ($force) {
      // $force allows Rules to cause transition.
      return TRUE;
    }

    // N.B. Keep aligned between WorkflowState, ~Transition, ~HistoryAccess.
    /*
     * Get user's permissions.
     */
    $type_id = $this->getWorkflowId();
    if ($user->hasPermission("bypass $type_id workflow_transition access")) {
      // Superuser is special (might be cron).
      // And $force allows Rules to cause transition.
      return TRUE;
    }
    // Determine if user is owner of the entity.
    $is_owner = WorkflowManager::isOwner($user, $this->getTargetEntity());
    if ($is_owner) {
      $user->addRole(WORKFLOW_ROLE_AUTHOR_RID);
    }

    /*
     * Get the object and its permissions.
     */
    $config_transitions = $this->getWorkflow()->getTransitionsByStateId($this->getFromSid(), $this->getToSid());

    /*
     * Determine if user has Access.
     */
    $result = FALSE;
    foreach ($config_transitions as $config_transition) {
      $result = $result || $config_transition->isAllowed($user, $force);
    }

    if ($result == FALSE) {
      // @todo There is a watchdog error, but no UI-error. Is this OK?
      $message = $this->t('Attempt to go to nonexistent transition (from %sid1 to %sid2)');
      $this->logError($message);
    }

    return $result;
  }

  /**
   * Determines if the State changes by this Transition.
   *
   * @return bool
   *   TRUE if from and to State ID's are different.
   */
  public function hasStateChange() {
    if ($this->from_sid->target_id == $this->to_sid->target_id) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function execute($force = FALSE) {
    // Load the entity, if not already loaded.
    // This also sets the (empty) $revision_id in Scheduled Transitions.
    $entity = $this->getTargetEntity();
    // Load explicit User object (not via $transition) for adding Role later.
    /** @var \Drupal\user\UserInterface $user */
    $user = $this->getOwner();
    $from_sid = $this->getFromSid();
    $to_sid = $this->getToSid();
    $field_name = $this->getFieldName();
    $comment = $this->getComment();
    // Create a label to identify this transition,
    // even upon insert, when id() is not set, yet.
    $label = $from_sid . '-' . $to_sid;

    static $static_info = NULL;

    $entity_id = $entity->id();
    // For non-default revisions, there is no way of executing the same transition twice in one call.
    // Set a random identifier since we won't be needing to access this variable later.
    if ($entity instanceof RevisionableInterface) {
      /** @var \Drupal\Core\Entity\RevisionableInterface $entity */
      if (!$entity->isDefaultRevision()) {
        $entity_id = $entity_id . $entity->getRevisionId();
      }
    }

    if (isset($static_info[$entity_id][$field_name][$label]) && !$this->isEmpty()) {
      // Error: this Transition is already executed.
      // On the development machine, execute() is called twice, when
      // on an Edit Page, the entity has a scheduled transition, and
      // user changes it to 'immediately'.
      // Why does this happen?? ( BTW. This happens with every submit.)
      // Remedies:
      // - search root cause of second call.
      // - try adapting code of transition->save() to avoid second record.
      // - avoid executing twice.
      $message = 'Transition is executed twice in a call. The second call for
        @entity_type %entity_id is not executed.';
      $this->logError($message);

      // Return the result of the last call.
      return $static_info[$entity_id][$field_name][$label]; // <-- exit !!!
    }

    // OK. Prepare for next round. Do not set last_sid!!
    $static_info[$entity_id][$field_name][$label] = $from_sid;

    // Make sure $force is set in the transition, too.
    if ($force) {
      $this->force($force);
    }
    $force = $this->isForced();

    // Store the transition(s), so it can be easily fetched later on.
    // This is a.o. used in:
    // - hook_entity_update to trigger 'transition post',
    // - hook workflow_access_node_access_records.
    $entity->workflow_transitions[$field_name] = $this;

    if (!$this->isValid()) {
      return $from_sid;  // <-- exit !!!
    }

    // @todo Move below code to $this->isAllowed().
    // If the state has changed, check the permissions.
    // No need to check if Comments or attached fields are filled.
    if ($this->hasStateChange()) {
      // Make sure this transition is allowed by workflow module Admin UI.
      if (!$force) {
        $user->addRole(WORKFLOW_ROLE_AUTHOR_RID);
      }
      if (!$this->isAllowed($user, $force)) {
        $message = 'User %user not allowed to go from state %sid1 to %sid2';
        $this->logError($message);
        return FALSE;  // <-- exit !!!
      }

      // Make sure this transition is valid and allowed for the current user.
      // Invoke a callback indicating a transition is about to occur.
      // Modules may veto the transition by returning FALSE.
      // (Even if $force is TRUE, but they shouldn't do that.)
      // P.S. The D7 hook_workflow 'transition permitted' is removed,
      // in favour of below hook_workflow 'transition pre'.
      $permitted = \Drupal::moduleHandler()->invokeAll('workflow', ['transition pre', $this, $user]);
      // Stop if a module says so.
      if (in_array(FALSE, $permitted, TRUE)) {
        // @todo There is a watchdog error, but no UI-error. Is this OK?
        $message = 'Transition vetoed by module.';
        $this->logError($message, 'notice');
        return FALSE;  // <-- exit !!!
      }
    }

    /*
     * Output: process the transition.
     */
    if ($this->isScheduled()) {
      // Log the transition in {workflow_transition_scheduled}.
      $this->save();
    }
    else {
      // The transition is allowed, but not scheduled.
      // Let other modules modify the comment.
      // The transition (in context) contains all relevant data.
      $context = ['transition' => $this];
      \Drupal::moduleHandler()->alter('workflow_comment', $comment, $context);
      $this->setComment($comment);

      $this->isExecuted = TRUE;

      if (!$this->isEmpty()) {
        // Save the transition in {workflow_transition_history}.
        $this->save();
      }
    }

    // Save value in static from top of this function.
    $static_info[$entity_id][$field_name][$label] = $to_sid;

    return $to_sid;
  }

  /**
   * {@inheritdoc}
   */
  public function executeAndUpdateEntity($force = FALSE) {
    $entity = $this->getTargetEntity();
    $to_sid = $this->getToSid();

    // Generate error and stop if transition has no new State.
    if (!$to_sid) {
      $t_args = [
        '%sid2' => $this->getToState()->label(),
        '%entity_label' => $entity->label(),
      ];
      $message = "Transition is not executed for %entity_label, since 'To' state %sid2 is invalid.";
      $this->logError($message);
      $this->messenger()->addError($this->t($message, $t_args));

      return $this->getFromSid();
    }

    // Save the (scheduled) transition.
    $do_update_entity = (!$this->isScheduled() && !$this->isExecuted());
    if ($do_update_entity) {
      // Update targetEntity's itemList with the workflow field in two formats.
      $this->updateEntity();
      $entity->save();
    }
    elseif ($this->isScheduled()) {
      $this->save();
      $to_sid = $this->getFromSid();
    }
    else {
      // We create a new transition, or update an existing one.
      // Do not update the entity itself.
      // Validate transition, save in history table and delete from schedule table.
      $to_sid = $this->execute($force);
    }

    return $to_sid;
  }

  /**
   * {@inheritdoc}
   */
  public function updateEntity() {
    // Update the workflow field of the entity in two formats.
    $entity = $this->getTargetEntity();
    $field_name = $this->getFieldName();
    $to_sid = $this->getToSid();
    // N.B. Align the following functions:
    // - WorkflowDefaultWidget::massageFormValues();
    // - WorkflowManager::executeTransition().

    $items = $entity->{$field_name};
    // $items->filterEmptyItems();
    // $items->value = $to_sid;
    // $entity->{$field_name}->value = $to_sid;
    $items->setValue($to_sid);
    $items->__set('_workflow_transition', $this);

    // Populate the entity changed timestamp when the option is checked.
    if ($this->getWorkflow()->getSetting('always_update_entity')) {
      // Copied from EntiyFormDisplay::updateChangedTime(EntityInterface $entity) {
      if ($entity instanceof EntityChangedInterface) {
        // $entity->setChangedTime($this->time->getRequestTime());
        $entity->setChangedTime($this->getTimestamp());
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setTargetEntity(EntityInterface $entity) {
    $this->entity_type = '';
    $this->entity_id = '';
    $this->revision_id = '';
    $this->delta = 0; // Only single value is supported.
    $this->langcode = Language::LANGCODE_NOT_SPECIFIED;

    // If Transition is added via CommentForm, use the Commented Entity.
    if ($entity && $entity->getEntityTypeId() == 'comment') {
      /** @var \Drupal\comment\CommentInterface $entity */
      $entity = $entity->getCommentedEntity();
    }

    if ($entity) {
      $this->entity = $entity;
      /** @var \Drupal\Core\Entity\RevisionableContentEntityBase $entity */
      $this->entity_type = $entity->getEntityTypeId();
      $this->entity_id = $entity->id();
      $this->revision_id = $entity->getRevisionId();
      $this->delta = 0; // Only single value is supported.
      $this->langcode = $entity->language()->getId();
    }

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntity() {
    // Use an explicit property, in case of adding new entities.
    if (isset($this->entity)) {
      return $this->entity;
    }
    // @todo D8: the following line only returns Node, not Term.
    /* return $this->entity = $this->get('entity_id')->entity; */

    $entity_type_id = $this->getTargetEntityTypeId();
    if ($id = $this->getTargetEntityId()) {
      $this->entity = \Drupal::entityTypeManager()->getStorage($entity_type_id)->load($id);
    }
    return $this->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityId() {
    return $this->get('entity_id')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityTypeId() {
    return $this->get('entity_type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldName() {
    return $this->get('field_name')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getLangcode() {
    return $this->getTargetEntity()->language()->getId();

  }

  /**
   * {@inheritdoc}
   */
  public function getFromState() {
    $sid = $this->getFromSid();
    return $sid ? WorkflowState::load($sid) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getToState() {
    $sid = $this->getToSid();
    return $sid ? WorkflowState::load($sid) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getFromSid() {
    $sid = $this->{'from_sid'}->target_id;
    return $sid;
  }

  /**
   * {@inheritdoc}
   */
  public function getToSid() {
    $sid = $this->{'to_sid'}->target_id;
    return $sid;
  }

  /**
   * {@inheritdoc}
   */
  public function getComment() {
    return $this->get('comment')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setComment($value) {
    $this->set('comment', $value);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getTimestamp() {
    return $this->get('timestamp')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getTimestampFormatted() {
    $timestamp = $this->getTimestamp();
    return \Drupal::service('date.formatter')->format($timestamp);
  }

  /**
   * {@inheritdoc}
   */
  public function setTimestamp($value) {
    $this->set('timestamp', $value);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isScheduled() {
    return $this->isScheduled;
  }

  /**
   * {@inheritdoc}
   */
  public function isRevertable() {
    // Some states are useless to revert.
    if (!$this->hasStateChange()) {
      return FALSE;
    }
    // Some states are not fit to revert to.
    $from_state = $this->getFromState();
    if (!$from_state
      || !$from_state->isActive()
      || $from_state->isCreationState()) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function schedule($schedule = TRUE) {
    $this->isScheduled = $schedule;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setExecuted($isExecuted = TRUE) {
    $this->isExecuted = $isExecuted;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isExecuted() {
    return (bool) $this->isExecuted;
  }

  /**
   * {@inheritdoc}
   */
  public function isForced() {
    return (bool) $this->isForced;
  }

  /**
   * {@inheritdoc}
   */
  public function force($force = TRUE) {
    $this->isForced = $force;
    return $this;
  }

  /**
   * Implementing interface EntityOwnerInterface. Copied from Comment.php.
   */

  /**
   * {@inheritdoc}
   */
  public function getOwner() {
    /** @var \Drupal\user\UserInterface $user */
    $user = $this->get('uid')->entity;
    if (!$user || $user->isAnonymous()) {
      $user = User::getAnonymousUser();
      $user->setUsername(\Drupal::config('user.settings')->get('anonymous'));
    }
    return $user;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwnerId() {
    return $this->get('uid')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwnerId($uid) {
    $this->set('uid', $uid);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setOwner(UserInterface $account) {
    $this->set('uid', $account->id());
    return $this;
  }

  /**
   * Implementing interface FieldableEntityInterface extends EntityInterface.
   */

  /**
   * Get additional fields of workflow(_scheduled)_transition.
   *
   * {@inheritdoc}
   */
  public function getFieldDefinitions() {
    return parent::getFieldDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function getAttachedFields() {

    $entity_field_manager = \Drupal::service('entity_field.manager');

    $entity_type_id = $this->getEntityTypeId();
    $entity_type_id = 'workflow_transition';
    $bundle = $this->bundle();

    // Determine the fields added by Field UI.
    // $extra_fields = $this->entityFieldManager->getExtraFields($entity_type_id, $bundle);
    // $base_fields = $this->entityFieldManager->getBaseFieldDefinitions($entity_type_id, $bundle);
    $fields = $entity_field_manager->getFieldDefinitions($entity_type_id, $bundle);
    $attached_fields = array_filter($fields, function ($field) {
      return ($field instanceof FieldConfig);
    });

    return $attached_fields;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = [];

    $fields['hid'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Transition ID'))
      ->setDescription(t('The transition ID.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

//    $fields['wid'] = BaseFieldDefinition::create('string')
    $fields['wid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Workflow Type'))
      ->setDescription(t('The workflow type the transition relates to.'))
      ->setSetting('target_type', 'workflow_type')
      ->setRequired(TRUE)
      ->setTranslatable(FALSE)
      ->setRevisionable(FALSE)
//      ->setSetting('max_length', 32)
//      ->setDisplayOptions('view', [
//        'label' => 'hidden',
//        'type' => 'string',
//        'weight' => -5,
//      ])
//      ->setDisplayOptions('form', [
//        'type' => 'string_textfield',
//        'weight' => -5,
//      ])
//      ->setDisplayConfigurable('form', TRUE)
    ;

    $fields['entity_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Entity type'))
      ->setDescription(t('The Entity type this transition belongs to.'))
      ->setSetting('is_ascii', TRUE)
      ->setSetting('max_length', EntityTypeInterface::ID_MAX_LENGTH)
      ->setReadOnly(TRUE);

    $fields['entity_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Entity ID'))
      ->setDescription(t('The Entity ID this record is for.'))
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['revision_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Revision ID'))
      ->setDescription(t('The current version identifier.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['field_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Field name'))
      ->setDescription(t('The name of the field the transition relates to.'))
      ->setRequired(TRUE)
      ->setTranslatable(FALSE)
      ->setRevisionable(FALSE)
      ->setSetting('max_length', 32);
//      ->setDisplayConfigurable('form', FALSE)
//      ->setDisplayOptions('form', [
//        'type' => 'string_textfield',
//        'weight' => -5,
//      ])
//      ->setDisplayConfigurable('view', FALSE)
//      ->setDisplayOptions('view', [
//        'label' => 'hidden',
//        'type' => 'string',
//        'weight' => -5,
//      ]);

    $fields['langcode'] = BaseFieldDefinition::create('language')
      ->setLabel(t('Language'))
      ->setDescription(t('The entity language code.'))
      ->setTranslatable(TRUE)
      ->setDisplayOptions('view', [
        'region' => 'hidden',
      ])
      ->setDisplayOptions('form', [
        'type' => 'language_select',
        'weight' => 2,
      ]);

    $fields['delta'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Delta'))
      ->setDescription(t('The sequence number for this data item, used for multi-value fields.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['from_sid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('From state'))
      ->setDescription(t('The {workflow_states}.sid the entity started as.'))
      ->setSetting('target_type', 'workflow_state')
      ->setReadOnly(TRUE);

    $fields['to_sid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('To state'))
      ->setDescription(t('The {workflow_states}.sid the entity transitioned to.'))
      ->setSetting('target_type', 'workflow_state')
      ->setDisplayOptions('form', [
        'type' => 'select',
//        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setReadOnly(TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('User ID'))
      ->setDescription(t('The user ID of the transition author.'))
      ->setTranslatable(TRUE)
      ->setSetting('target_type', 'user')
      ->setDefaultValue(0)
//      ->setQueryable(FALSE)
//      ->setSetting('handler', 'default')
//      ->setDefaultValueCallback('Drupal\node\Entity\Node::getCurrentUserId')
//      ->setTranslatable(TRUE)
//      ->setDisplayOptions('view', [
//        'label' => 'hidden',
//        'type' => 'author',
//        'weight' => 0,
//      ])
//      ->setDisplayOptions('form', [
//        'type' => 'entity_reference_autocomplete',
//        'weight' => 5,
//        'settings' => [
//          'match_operator' => 'CONTAINS',
//          'size' => '60',
//          'placeholder' => '',
//        ],
//      ])
//      ->setDisplayConfigurable('form', TRUE),
      ->setRevisionable(TRUE);

    $fields['timestamp'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Timestamp'))
      ->setDescription(t('The time that the current transition was executed.'))
//      ->setQueryable(FALSE)
//      ->setTranslatable(TRUE)
//      ->setDisplayOptions('view', [
//        'label' => 'hidden',
//        'type' => 'timestamp',
//        'weight' => 0,
//      ])
// @todo D8: activate this. Test with both Form and Widget.
      ->setDisplayOptions('form', [
        'type' => 'workflow_transition_timestamp',
        // 'type' => 'datetime_timestamp',
        // 'label' => 'hidden',
//        'weight' => -100,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setRevisionable(TRUE);

    $fields['comment'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Log message'))
      ->setDescription(t('The comment explaining this transition.'))
      ->setRevisionable(TRUE)
      ->setTranslatable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'textarea',
        // 'weight' => 25,
        'settings' => [
          //@todo: Why shows 'Manage fields' 5 rows in the beginning, not 2?
          'rows' => 2,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE);

    return $fields;
  }

  /**
   * Generate a Watchdog error.
   *
   * @param string $message
   *   The message.
   * @param string $type
   *   The message type {'error' | 'notice'}.
   * @param string $from_sid
   *   The old State ID.
   * @param string $to_sid
   *   The new State ID.
   */
  public function logError($message, $type = 'error', $from_sid = '', $to_sid = '') {

    // Prepare an array of arguments for error messages.
    $entity = $this->getTargetEntity();
    $t_args = [
      /** @var \Drupal\user\UserInterface $user */
      '%user' => ($user = $this->getOwner()) ? $user->getDisplayName() : '',
      '%sid1' => ($from_sid || !$this->getFromState()) ? $from_sid : $this->getFromState()->label(),
      '%sid2' => ($to_sid || !$this->getToState()) ? $to_sid : $this->getToState()->label(),
      '%entity_id' => $this->getTargetEntityId(),
      '%entity_label' => $entity ? $entity->label() : '',
      '@entity_type' => $entity ? $entity->getEntityTypeId() : '',
      '@entity_type_label' => $entity ? $entity->getEntityType()->getLabel() : '',
      'link' => ($this->getTargetEntityId() && $this->getTargetEntity()->hasLinkTemplate('canonical')) ? $this->getTargetEntity()->toLink($this->t('View'))->toString() : '',
    ];
    ($type == 'error') ? \Drupal::logger('workflow')->error($message, $t_args)
      : \Drupal::logger('workflow')->notice($message, $t_args);
  }

  /**
   * {@inheritdoc}
   */
  public function dpm($function = '') {
    $transition = $this;
    $entity = $transition->getTargetEntity();
    $time = \Drupal::service('date.formatter')->format($transition->getTimestamp());
    // Do this extensive $user_name lines, for some troubles with Action.
    $user = $transition->getOwner();
    $user_name = ($user) ? $user->getAccountName() : 'unknown username';
    $t_string = $this->getEntityTypeId() . ' ' . $this->id() . ' for workflow_type <i>' . $this->getWorkflowId() . '</i> ' . ($function ? ("in function '$function'") : '');
    $output[] = 'Entity type/id/vid = ' . $this->getTargetEntityTypeId() . '/' . (($entity) ? ($entity->bundle() . '/' . $entity->id() . '/' . $entity->getRevisionId()) : '___/0');
    $output[] = 'Field   = ' . $transition->getFieldName();
    $output[] = 'From/To = ' . $transition->getFromSid() . ' > ' . $transition->getToSid() . ' @ ' . $time;
    $output[] = 'Comment = ' . $user_name . ' says: ' . $transition->getComment();
    $output[] = 'Forced  = ' . ($transition->isForced() ? 'yes' : 'no') . '; ' . 'Scheduled = ' . ($transition->isScheduled() ? 'yes' : 'no');
    if (function_exists('dpm')) {// In Workflow->dpm().
      dpm($output, $t_string);   // In Workflow->dpm().
    }                            // In Workflow->dpm().
  }

}
