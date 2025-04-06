<?php

/**
 * @file
 * Provide Views data for workflow.module.
 *
 * @ingroup views_module_handlers
 */

use Drupal\field\FieldStorageConfigInterface;
use Drupal\workflow\WorkflowTransitionViewsData;

/**
 * Implements hook_field_views_data().
 */
function workflow_field_views_data(FieldStorageConfigInterface $field) {
  return WorkflowTransitionViewsData::fieldViewsData($field);
}

/**
 * Implements hook_views_data_alter().
 */
function workflow_views_data_alter(array &$data) {
  WorkflowTransitionViewsData::viewsDataAlter($data);
}
