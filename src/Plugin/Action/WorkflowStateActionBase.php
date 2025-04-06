<?php

namespace Drupal\workflow\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\workflow\Entity\Workflow;
use Drupal\workflow\Entity\WorkflowState;
use Drupal\workflow\Entity\WorkflowTransition;
use Drupal\workflow\Form\WorkflowTransitionForm;
use Drupal\workflow\Plugin\Field\FieldWidget\WorkflowDefaultWidget;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sets an entity to a new, given state.
 *
 * Example Annotation @ Action(
 *   id = "workflow_given_state_action",
 *   label = @Translation("Change a node to new Workflow state"),
 *   type = "workflow",
 * )
 */
abstract class WorkflowStateActionBase extends ConfigurableActionBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    return parent::calculateDependencies() + [
      'module' => ['workflow'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $configuration = parent::defaultConfiguration();
    $configuration += $this->configuration;
    $configuration += [
      'field_name' => '',
      'to_sid' => '',
      'comment' => "New state is set by a triggered Action.",
      'force' => 0,
    ];
    return $configuration;
  }

  /**
   * Gets the entity's transition that must be executed.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity for which a transition must be fetched.
   *
   * @return \Drupal\workflow\Entity\WorkflowTransitionInterface|null
   *   The Transition, if found, else NULL.
   */
  protected function getTransitionForExecution(EntityInterface $entity) {
    $user = workflow_current_user();

    if (!$entity) {
      \Drupal::logger('workflow_action')->notice('Unable to get current entity - entity is not defined.', []);
      return NULL;
    }

    // Get the entity type and numeric ID.
    $entity_id = $entity->id();
    if (!$entity_id) {
      \Drupal::logger('workflow_action')->notice('Unable to get current entity ID - entity is not yet saved.', []);
      return NULL;
    }

    // In 'after saving new content', the node is already saved. Avoid second insert.
    // @todo Clone?
    $entity->enforceIsNew(FALSE);

    $config = $this->configuration;
    $field_name = workflow_get_field_name($entity, $config['field_name']);
    $current_sid = workflow_node_current_state($entity, $field_name);
    if (!$current_sid) {
      \Drupal::logger('workflow_action')->notice('Unable to get current workflow state of entity %id.', ['%id' => $entity_id]);
      return NULL;
    }

    $to_sid = $config['to_sid'] ?? '';
    // Get the Comment. Parse the $comment variables.
    $comment_string = $this->configuration['comment'];
    $comment = $this->t($comment_string, [
      '%title' => $entity->label(),
      // "@" and "%" will automatically run check_plain().
      '%state' => workflow_get_sid_name($to_sid),
      '%user' => $user->getDisplayName(),
    ]);
    $force = $this->configuration['force'];

    $transition = WorkflowTransition::create([$current_sid, 'field_name' => $field_name]);
    $transition->setTargetEntity($entity);
    $transition->setValues($to_sid, $user->id(), \Drupal::time()->getRequestTime(), $comment);
    $transition->force($force);

    return $transition;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $element = [];

    // If we are on admin/config/system/actions and use CREATE AN ADVANCED ACTION
    // Then $context only contains:
    // - $context['actions_label'] = "Change workflow state of post to new state";
    // - $context['actions_type'] = "entity".
    //
    // If we are on a VBO action form, then $context only contains:
    // - $context['entity_type'] = "node";
    // - $context['view'] = "(Object) view";
    // - $context['settings'] = "[]".
    $config = $this->configuration;
    $field_name = $config['field_name'];
    $to_sid = $config['to_sid'];

    // @todo Support other entity types, not only Node.
    $entity_type_id = 'node';

    if (!$field_name) {
      $field_map = workflow_get_workflow_fields_by_entity_type($entity_type_id);
      /// Get the field name of the (arbitrary) first node type.
      $field_name = key($field_map);
      if (!$field_name) {
        // We are in problem.
      }
    }

    if ($field_name) {
      $fields = _workflow_info_fields($entity = NULL, $entity_type_id, '', $field_name);
      $field_config = reset($fields);
      $bundles = $field_config->getBundles();
      $entity_bundle = reset($bundles);
      $wid = $field_config ? $field_config->getSetting('workflow_type') : '';
      $state = $to_sid ? WorkflowState::load($to_sid) : NULL;
      // If user has changed field name, then reset the state.
      if ($wid <> ($state ? $state->getWorkflowId() : NULL)) {
        $workflow = Workflow::load($wid);
        $to_sid = $workflow->getCreationSid();
      }
    }

    // Create the helper entity.
    $entity_type_manager = \Drupal::service('entity_type.manager');
    // $entity = new Node([], $entity_type_id, $entity_bundle);
    $entity = $entity_type_manager->getStorage($entity_type_id)->create([
      'type' => $entity_bundle,
    ]);
    // Create the Transition with config data.
    $transition = WorkflowTransitionForm::getDefaultTransition($entity, $field_name);
    // Update Transition without using $transition->setValues().
    $transition->{'from_sid'}->set(0, $to_sid);
    $transition->{'to_sid'}->set(0, $to_sid);
    $transition->setComment($config['comment']);
    $transition->force($config['force']);
    $transition->setTargetEntity($entity);
    // Update targetEntity's itemList with the workflow field in two formats.
    $transition->updateEntity();
    $entity->setOwnerId($transition->getOwnerId());

    // Add the WorkflowTransitionForm element to the page.
    // Set/reset 'options' to Avoid Action Buttons, because that
    // removes the options box&more. No Buttons in config screens!
    // $workflow = $transition->getWorkflow();
    // $workflow->setSetting('options', 'select');
    // Option 1: call transitionElement() directly.
    // $element['#default_value'] = $transition;
    // $element = WorkflowTransitionElement::transitionElement($element, $form_state, $form);
    //
    // Option 2: call WorkflowTransitionForm() directly.
    // @todo Use Form/Widget, instead of transitionElement().
    //   $element = WorkflowTransitionForm::createInstance($entity, $field_name, [], $transition);
    //   $element = WorkflowTransitionForm::trimWorkflowTransitionForm($element, $transition);
    //   $element['#parents'] = [];
    //   Remove langcode to avoid error upon submit.
    //   InvalidArgumentException: The configuration property langcode.0 doesn't exist.
    //   unset($element['langcode']);
    //
    // Option 3: call WorkflowDefaultWidget via NodeFormDisplay.
    $element = WorkflowDefaultWidget::createInstance($transition);
    // Fetch the element from the widget.
    $element = $element[$field_name]['widget'][0];
    // Make adaptations for VBO-form.
    $element = [
      '#type' => 'details',
      '#collapsible' => TRUE,
      '#open' => TRUE,
    ] + $element;
    unset($element['#action']);
    //   Remove langcode to avoid error upon submit.
    //   InvalidArgumentException: The configuration property langcode.0 doesn't exist.
    unset($element['langcode']);
    $element['field_name']['#access'] = TRUE;
    $element['force']['#access'] = TRUE;
    $element['to_sid']['#description'] = $this->t('Please select the state that should be assigned when this action runs.');
    $element['to_sid']['#access'] = TRUE;
    $element['to_sid']['widget'][0]['target_id']['#access'] = TRUE;
    $element['comment']['#title'] = $this->t('Message');
    $element['comment']['#description'] = $this->t('This message will be written
      into the workflow history log when the action runs.
      You may include the following variables: %state, %title, %user.');

    $form['workflow_transition_action_config'] = $element;

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {

    // When using Widget/Form, read $input.
    $values = $form_state->getUserInput();
    // When using Element, read $values.
    // $values = $form_state->getValue('workflow_transition_action_config');
    // $values = $form_state->getValues();
    // @todo Use WorkflowDefaultWidget::massage/extractFormValues();
    // @todo Use copyFormValuesToTransition();
    $configuration = [
      'field_name' => $values['field_name'],
      'to_sid' => $values['to_sid'][0]['target_id'],
      'comment' => $values['comment'][0]['value'],
      'force' => $values['force'],
    ];
    $this->configuration = $configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    $access = AccessResult::allowed();
    return $return_as_object ? $access : $access->isAllowed();
  }

}
