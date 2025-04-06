<?php

namespace Drupal\workflow_devel\EventSubscriber;

use Drupal\workflow\Entity\WorkflowScheduledTransition;
use Drupal\workflow\Event\WorkflowEvents;
use Drupal\workflow\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reacts to changes on office_hours field values.
 */
class WorkflowDevelEventSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   *
   * How to use this code:
   * - Copy WorkflowDevelEventSubscriber.php
   * - Copy worklow_devel.serivces.yml, renaming the service.
   */
  public static function getSubscribedEvents(): array {
    $events[WorkflowEvents::PRE_TRANSITION][] = ['preUpdateProcess'];
    $events[WorkflowEvents::POST_TRANSITION][] = ['postUpdateProcess'];
    return $events;
  }

  /**
   * Called whenever the event is dispatched.
   *
   * @todo Implement EDIT and UPDATE events.
   *
   * @param WorkflowTransitionEvent $event
   *   The Event object.
   */
  public function preUpdateProcess(WorkflowTransitionEvent $event) {

    $transition = $event->getTransition();
    $comment = $transition->getComment();
    $comment .= "<br> Extra line added by workflow_devel module's EventSubscriber::preUpdateProcess function.";
    $transition->setComment($comment);
  }

  /**
   * @param WorkflowTransitionEvent $event
   *   The Event object.
   */
  public function postUpdateProcess(WorkflowTransitionEvent $event) {

    $from_sid = $event->getTransition()->getFromSid();
    $to_sid = $event->getTransition()->getToSid();

    switch ("$from_sid.$to_sid") {
      case "workflow_field_pending.workflow_field_approved" :
        // $this->onPendingTransition($event);
        break;
      case "workflow_field_open.workflow_field_closed":
        // $this->onOpenTransition($event);
        break;
      case "workflow_field_pending.workflow_field_closed":
        // $this->onApprovedTransition($event);
        break;
       default:
        $this->addScheduledEvent($event);
        break;
    }
  }

  /**
   * Example function to add an event.
   *
   * For using translations, see workflow_access_module file.
   *
   * @param WorkflowTransitionEvent $event
   *   The Event object.
   */
  protected function addScheduledEvent(WorkflowTransitionEvent $event) {

    // As an example, add a scheduled transition.
    $transition = $event->getTransition();
    $from_sid = $transition->getFromSid();
    $to_sid = $transition->getToSid();

    if ($from_sid == $to_sid) {
      $comment = 'This scheduled transition is added by WorkflowDevelEventSubscriber::postUpdateProcess. It will be executed in 30 minutes.';
      if($transition->isScheduled()) {
        // Avoid endless recursion.
        return;
      }
      if (0 == substr_compare($transition->getComment(), $comment, 0, 20)) {
        // Avoid endless recursion.
        return;
      }

      // Entity will expire after 30 minutes.
      $timestamp = time();
      $scheduled_at = strtotime('+30 minutes', $timestamp);
      $field_name = $transition->getFieldName();
      // Transition $uid can be cron user, or like that.
      $uid = $transition->getOwnerId();
      $entity = $transition->getTargetEntity();

      $scheduled_transition = WorkflowScheduledTransition::create([$from_sid, 'field_name' => $field_name]);
      $scheduled_transition->setTargetEntity($entity);
      $scheduled_transition->setValues($to_sid, $uid, $scheduled_at, $comment);
      // Since entity workflow status is not changed,
      // it is sufficient to just save() the transition.
      $scheduled_transition->save();
    }

  }

}
