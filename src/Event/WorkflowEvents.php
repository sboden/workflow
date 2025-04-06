<?php

namespace Drupal\workflow\Event;

/**
 * Defines events for the workflow module.
 *
 * @see \Drupal\workflow\Event\WorkflowPostTransitionEvent
 */
final class WorkflowEvents {

  /**
   * Name of the event fired before a transition is executed.
   *
   * @Event
   *
   * @see \Drupal\workflow\Event\WorkflowTransitionEvent
   *
   * @var string
   */
  const PRE_TRANSITION = 'workflow.pre_transition';

  /**
   * Name of the event fired after a transition is executed.
   *
   * @Event
   *
   * @see \Drupal\workflow\Event\WorkflowTransitionEvent
   *
   * @var string
   */
  const POST_TRANSITION = 'workflow.post_transition';

}
