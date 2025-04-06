<?php

namespace Drupal\workflow\Element;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\FormElement;
use Drupal\workflow\Entity\Workflow;

/**
 * Provides a form element for the WorkflowTransitionForm and ~Widget.
 *
 * @see \Drupal\Core\Render\Element\FormElement
 * @see https://www.drupal.org/node/169815 "Creating Custom Elements"
 *
 * @FormElement("workflow_transition_timestamp")
 */
class WorkflowTransitionTimestamp extends FormElement {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = static::class;
    return [
      '#input' => TRUE,
      // '#return_value' => 1,
      '#process' => [
        [$class, 'processTimestamp'],
        [$class, 'processAjaxForm'],
      ],
      // '#element_validate' => [
      //   [$class, 'validateTimestamp'],
      // ],
      // '#title_display' => 'after',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function valueCallback(&$element, $input, FormStateInterface $form_state) {
    $timestamp = \Drupal::time()->getRequestTime();

    if (!$input) {
      // Massage, normalize value after pressing Form button.
      // $element is also updated via reference.
      return $timestamp;
    }

    // Fetch $timestamp from widget for scheduled transitions.
    $scheduled = (bool) $input['scheduled'] ?? '0';
    if ($scheduled) {
      $schedule_values = $input['date_time'];
      // Fetch the (scheduled) timestamp to change the state.
      // Override $timestamp.
      $scheduled_date_time = implode(' ', [
        $schedule_values['workflow_scheduled_date'],
        $schedule_values['workflow_scheduled_hour'],
        // $schedule_values['workflow_scheduled_timezone'],
      ]);
      $timezone = $schedule_values['workflow_scheduled_timezone'];
      $old_timezone = date_default_timezone_get();
      date_default_timezone_set($timezone);
      $timestamp = strtotime($scheduled_date_time);
      date_default_timezone_set($old_timezone);
      if (!$timestamp) {
        // Time should have been validated in form/widget.
        $timestamp = \Drupal::time()->getRequestTime();
      }
    }

    return $timestamp;
  }

  /**
   * Generate an element.
   *
   * This function is referenced in the Annotation for this class.
   *
   * @param array $element
   *   The element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $complete_form
   *   The form.
   *
   * @return array
   *   The Workflow element.
   */
  public static function processTimestamp(array &$element, FormStateInterface $form_state, array &$complete_form) {

    /*
     * Input.
     */

     // A Transition object must have been set explicitly.
    /** @var \Drupal\workflow\Entity\WorkflowTransitionInterface $transition */
    $transition = $element['#default_value'];
    /** @var \Drupal\Core\Session\AccountInterface $user */
    $user = \Drupal::currentUser();

    /*
     * Derived input.
     */
    $field_name = $transition->getFieldName();
    // Workflow might be empty on Action/VBO configuration.
    $wid = $transition->getWorkflowId();
    $workflow = $transition->getWorkflow();
    $workflow_settings = $workflow ? $workflow->getSettings() : Workflow::defaultSettings();

    // Display scheduling form if user has permission.
    // Not shown on new entity (not supported by workflow module, because that
    // leaves the entity in the (creation) state until scheduling time.)
    // Not shown when editing existing transition.
    $add_schedule = $workflow_settings['schedule_enable'];
    if ($add_schedule
      && !$transition->isExecuted()
      && $user->hasPermission("schedule $wid workflow_transition")
      ) {
      // @todo D8: check below code: form on VBO.
      // workflow_debug(__FILE__, __FUNCTION__, __LINE__);
      $step = $form_state ? $form_state->getValue('step') : NULL;
      if ($step == 'views_bulk_operations_config_form') {
        // @todo test D8: On VBO Bulk 'modify entity values' form,
        // leave field settings.
        $add_schedule = TRUE;
      }
      else {
        // ... and cannot be shown on a Content add page (no $entity_id),
        // ...but can be shown on a VBO 'set workflow state to..'page (no entity).
        $entity = $transition->getTargetEntity();
        $add_schedule = !($entity && !$entity->id());
      }
    }

    /*
     * Output: generate the element.
     */

    // Display scheduling form under certain conditions.
    if ($add_schedule) {
      $timezone = $user->getTimeZone();
      if (empty($timezone)) {
        $timezone = \Drupal::config('system.date')->get('timezone.default');
      }

      $timezone_options = array_combine(timezone_identifiers_list(), timezone_identifiers_list());
      $is_scheduled = $transition->isScheduled();
      $timestamp = $transition->getTimestamp();

      $hours = $is_scheduled
      ? \Drupal::service('date.formatter')->format($timestamp, 'custom', 'H:i', $timezone)
      : '00:00';
      // Define class for '#states' behaviour.
      // Fetch the form ID. This is unique for each entity, to allow multiple form per page (Views, etc.).
      // Make it uniquer by adding the field name, or else the scheduling of
      // multiple workflow_fields is not independent of each other.
      // If we are indeed on a Transition form (so, not a Node Form with widget)
      // then change the form id, too.
      $form_id = $form_state ? $form_state->getFormObject()->getFormId() : self::getFormId();
      // @todo Align with WorkflowTransitionForm->getFormId().
      $class_identifier = Html::getClass('scheduled_' . Html::getUniqueId($form_id) . '-' . $field_name);
      $element['scheduled'] = [
        '#type' => 'radios',
        '#title' => t('Schedule'),
        '#options' => [
          '0' => t('Immediately'),
          '1' => t('Schedule for state change'),
        ],
        '#default_value' => (string) $is_scheduled,
        '#attributes' => [
          // 'id' => 'scheduled_' . $form_id,
          'class' => [$class_identifier],
        ],
      ];
      $element['date_time'] = [
        '#type' => 'details', // 'container',
        '#open' => TRUE, // Controls the HTML5 'open' attribute. Defaults to FALSE.
        '#attributes' => ['class' => ['container-inline']],
        '#prefix' => '<div style="margin-left: 1em;">',
        '#suffix' => '</div>',
        '#states' => [
          'visible' => ['input.' . $class_identifier => ['value' => '1']],
        ],
      ];
      $element['date_time']['workflow_scheduled_date'] = [
        '#type' => 'date',
        '#prefix' => t('At'),
        '#default_value' => implode('-', [
          'year' => date('Y', $timestamp),
          'month' => date('m', $timestamp),
          'day' => date('d', $timestamp),
        ]),
      ];
      $element['date_time']['workflow_scheduled_hour'] = [
        '#type' => 'textfield',
        '#title' => t('Time'),
        '#maxlength' => 7,
        '#size' => 6,
        '#default_value' => $hours,
        '#element_validate' => ['_workflow_transition_form_element_validate_time'], // @todo D8: this is not called.
      ];
      $element['date_time']['workflow_scheduled_timezone'] = [
        '#type' => $workflow_settings['schedule_timezone'] ? 'select' : 'hidden',
        '#title' => t('Time zone'),
        '#options' => $timezone_options,
        '#default_value' => [$timezone => $timezone],
      ];
      $element['date_time']['workflow_scheduled_help'] = [
        '#type' => 'item',
        '#prefix' => '<br />',
        '#description' => t('Please enter a time. If no time is included,
          the default will be midnight on the specified date.
          The current time is: @time.', [
            '@time' => \Drupal::service('date.formatter')
              ->format(\Drupal::time()->getRequestTime(), 'custom', 'H:i', $timezone),
          ]
        ),
      ];
    }

    return $element;
  }

}
