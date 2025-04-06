<?php

/**
 * @file
 * Support migration of workflows from older versions.
 */

use Drupal\migrate\Plugin\MigrateSourceInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\workflow\Entity\WorkflowState;

/**
 * Implements hook_migrate_MIGRATION_ID_prepare_row().
 */
function workflow_migrate_upgrade_d7_workflow_state_prepare_row(Row $row, MigrateSourceInterface $source, MigrationInterface $migration) {
  if ('(creation)' == $row->getSourceProperty('name')) {
    $row->setSourceProperty('name', WORKFLOW_CREATION_STATE_NAME);
  }
}

/**
 * Implements hook_migrate_MIGRATION_ID_prepare_row().
 */
function workflow_migrate_upgrade_d7_workflow_transition_prepare_row(Row $row, MigrateSourceInterface $source, MigrationInterface $migration) {
  static $sid2wid = [];
  static $migration = NULL;

  $sid = $row->getSourceProperty('sid');

  if (!isset($sid2wid[$sid])) {
    if (!$migration) {
      $migrations = \Drupal::service('plugin.manager.migration')
        ->createInstances('upgrade_d7_workflow_state');
      $migration = reset($migrations);
    }
    if ($migration) {
      $sids = $migration
        ->getIdMap()
        ->lookupDestinationIds([$sid]);
      $new_sid = reset($sids);
      // Set source and target lookups.
      $sid2wid[$row->getSourceProperty('old_sid')] =
      $sid2wid[$sid] = WorkflowState::load($new_sid)->getWorkflowId();
    }
  }
  if (isset($sid2wid[$sid])) {
    $row->setSourceProperty('wid', $sid2wid[$sid]);
  }
}

/**
 * Implements hook_migrate_MIGRATION_ID_prepare_row().
 */
function workflow_migrate_upgrade_d7_workflow_scheduled_transition_prepare_row(Row $row, MigrateSourceInterface $source, MigrationInterface $migration) {
  workflow_migrate_upgrade_d7_workflow_transition_prepare_row($row, $source, $migration);
}
