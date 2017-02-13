<?php

namespace Drupal\thunder_article;

use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeForm;

/**
 * Base for handler for node edit forms.
 */
class ThunderNodeForm extends NodeForm {

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $element = parent::actions($form, $form_state);

    if (!empty($element['publish']['#access'])) {
      $element['save_continue'] = $element['publish'];
      $element['save_continue']['#value'] = t('Save and continue');
      $element['save_continue']['#weight'] = min($element['publish']['#weight'], $element['unpublish']['#weight']) - 1;

      $node = $this->entity;

      // If unpublish comes before publish, then we should also not publish.
      if ($node->isNew() || $element['unpublish']['#weight'] < $element['publish']['#weight']) {
        $element['save_continue']['#published_status'] = FALSE;
      }

      if ($this->moduleHandler->moduleExists('inline_entity_form')) {
        $widget_state = $form_state->get('inline_entity_form');
        if (!is_null($widget_state)) {
          // @codingStandardsIgnoreStart
          \Drupal\inline_entity_form\ElementSubmit::addCallback($element['save_continue'], $form);
          // @codingStandardsIgnoreEnd
        }
      }

      if ($this->moduleHandler->moduleExists('content_lock')) {
        // Check if we must lock this entity.
        /** @var \Drupal\content_lock\ContentLock\ContentLock $lock_service */
        $lock_service = \Drupal::service('content_lock');
        if ($lock_service->isLockable($node)) {
          // We act only on edit form, not for a creation of a new entity.
          if (!$node->isNew()) {
            $element['save_continue']['#submit'][] = 'content_lock_form_submit';

            $user = \Drupal::currentUser();
            // We lock the content if it is currently edited by another user.
            if (!$lock_service->locking($node->id(), $user->id(), 'node')) {
              $form['#disabled'] = TRUE;

              // Do not allow deletion, publishing, or unpublishing if locked.
              if (isset($element['save_continue'])) {
                unset($element['save_continue']);
              }
            }
          }
        }
      }

    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {

    parent::save($form, $form_state);

    if (in_array('save_continue', $form_state->getTriggeringElement()['#parents'])) {

      $options = [];
      $query = $this->getRequest()->query;
      if ($query->has('destination')) {
        $options['query']['destination'] = $query->get('destination');
        $query->remove('destination');
      }

      $form_state->setRedirect('entity.node.edit_form', ['node' => $this->entity->id()], $options);
    }
  }

}
