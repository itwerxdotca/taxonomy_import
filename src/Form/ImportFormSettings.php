<?php

namespace Drupal\taxonomy_import\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Contribute form.
 */
class ImportFormSettings extends ConfigFormBase {

  /**
   * The default File extensions.
   *
   * @var string
   */
  const DEFAULT_FILE_EXTENSION = 'csv xml';

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'taxonomy_import.config',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'taxonomy_import_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('taxonomy_import.config');
    $form['file_extensions'] = [
      '#type' => 'textfield',
      '#size' => 40,
      '#title' => $this->t('Allowed file extensions'),
      '#required' => TRUE,
      '#default_value' => $config->get('file_extensions') ?? static::DEFAULT_FILE_EXTENSION,
      '#description' => $this->t('Separate extensions with a space (e.g., "csv xml").'),
    ];

    $form['file_size_info'] = [
      '#type' => 'item',
      '#markup' => $this->t('<p><strong>Note:</strong> File upload size limits are managed by Drupal core and your PHP configuration. To change the maximum upload size, adjust your <code>php.ini</code> settings (<code>upload_max_filesize</code> and <code>post_max_size</code>) or your Drupal file upload settings.</p>'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('taxonomy_import.config')
      ->set('file_extensions', $form_state->getValue('file_extensions'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
