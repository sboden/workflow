<?php

namespace Drupal\workflow\Plugin\migrate\source\d7;

use Drupal\migrate\Row;
use Drupal\migrate_drupal\Plugin\migrate\source\d7\FieldableEntity;

/**
 * Drupal 7 workflow transitions configuration source from database.
 *
 * @MigrateSource(
 *   id = "d7_workflow_config_transition",
 *   source_module = "workflow"
 * )
 */
class WorkflowConfigTransition extends FieldableEntity {

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $roles = $row->getSourceProperty('roles');
    $row->setSourceProperty('roles', unserialize($roles));
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    return $this->select('workflow_transitions', 'wt')
      ->fields('wt')
      ->condition('wt.tid', 0, '>')
      ->where('wt.sid <> wt.target_sid');
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [
      'tid' => $this->t('Workflow Transition ID'),
      'name' => $this->t('Machine readable name of this transition'),
      'label' => $this->t('Human readable name of this transition'),
      'sid' => $this->t('Starting workflow state'),
      'target_sid' => $this->t('Target workflow state'),
      'roles' => $this->t('The roles that are permitted to perform this transition'),
    ];
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'tid' => [
        'type' => 'integer',
        'alias' => 'wt',
      ],
    ];
  }

}
