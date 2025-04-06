<?php

namespace Drupal\workflow\Plugin\views\field;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\field\EntityField;

/**
 * Displays the time slot per weekday/exception date.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("workflow_state")
 */
class WorkflowState extends EntityField {

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    // Disable entity link to WorkflowState from parent EntityField.
    $options['settings']['link'] = '';
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    // Hide the checkbox 'Link label to the referenced entity'.
    $form['settings']['link']['#type'] = 'hidden';

    // Override EntityReferenceLabelFormatter::settingsForm().
    $field = $this->getFieldDefinition();
    $formatters = $this->formatterPluginManager->getOptions($field->getType());
    $formatters = ['entity_reference_label' => $formatters['entity_reference_label']];

    $form['type']['#options'] = $formatters;
  }

}
