<?php

namespace Drupal\workflow\EventSubscriber;

use Drupal\workflow\Event\WorkflowEvents;
use Drupal\workflow\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reacts to changes on Workflowtransitions.
 */
class WorkflowTransitionSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    $events[WorkflowEvents::PRE_TRANSITION][] = ['preTransition'];
    $events[WorkflowEvents::POST_TRANSITION][] = ['postTransition'];

    return $events;
  }

  /**
   * Performs an action before the transition is executed.
   *
   * @param \Drupal\workflow\Event\WorkflowTransitionEvent $event
   *   The event with the transition as an attribute.
   */
  public function preTransition(WorkflowTransitionEvent $event) {
    // $transition = $event->getTransition();
  }

  /**
   * Performs an action after the transition is executed.
   *
   * @param \Drupal\workflow\Event\WorkflowTransitionEvent $event
   *   The event with the transition as an attribute.
   */
  public function postTransition(WorkflowTransitionEvent $event) {
    // Some example code.
    switch ($event->getTransition()->getToSid()) {
      case "permit_status_workflow_pending":
        // $this->onPendingTransition($event);
        break;
      case "permit_status_workflow_open":
        // $this->onOpenTransition($event);
        break;
      case "permit_status_workflow_approved":
        // $this->onApprovedTransition($event);
        break;
      case "permit_status_workflow_declined":
        // $this->onDeclinedTransition($event);
        break;
    }
  }

}
