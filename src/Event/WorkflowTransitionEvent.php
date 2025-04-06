<?php

namespace Drupal\workflow\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\workflow\Entity\WorkflowTransitionInterface;

/**
 * Defines the workflow transition event.
 */
class WorkflowTransitionEvent extends Event {

  /**
   * The (scheduled or executed) transition.
   *
   * @var \Drupal\workflow\Entity\WorkflowTransitionInterface
   */
  protected $transition;

  /**
   * Constructs a new WorkflowTransitionEvent object.
   *
   * @param \Drupal\workflow\Entity\WorkflowTransitionInterface $transition
   *   The transition.
   */
  public function __construct(WorkflowTransitionInterface $transition) {
    $this->transition = $transition;
  }

  /**
   * Gets the transition.
   *
   * @return \Drupal\workflow\Entity\WorkflowTransitionInterface
   *   The transition.
   */
  public function getTransition() {
    return $this->transition;
  }

}
