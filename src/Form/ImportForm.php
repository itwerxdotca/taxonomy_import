<?php

namespace Drupal\taxonomy_import\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\taxonomy_import\Service\TaxonomyUtilsInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Contribute form.
 */
class ImportForm extends FormBase {

  use StringTranslationTrait;

  private const ALLOWED_MIME_TYPES = [
    'text/plain',
    'application/csv',
    'text/csv',
    'text/xml',
    'application/xml',
  ];

  private const CSV_MIME_TYPES = [
    'text/plain',
    'application/csv',
    'text/csv',
  ];

  /**
   * Config of Taxonomy import module.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * The vocabulary storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $vocabularyStorage;

  /**
   * The taxonomy utilities.
   *
   * @var \Drupal\taxonomy_import\Service\TaxonomyUtilsInterface
   */
  protected $taxonomyUtils;

  /**
   * {@inheritdoc}
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityStorageInterface $vocabulary_storage, TaxonomyUtilsInterface $taxonomy_utils) {
    $this->config = $config_factory->get('taxonomy_import.config');
    $this->vocabularyStorage = $vocabulary_storage;
    $this->taxonomyUtils = $taxonomy_utils;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager')->getStorage('taxonomy_vocabulary'),
      $container->get('taxonomy_import.term_utils')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'import_taxonomy_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $vocabularies = $this->vocabularyStorage->loadMultiple();
    $vocabulariesList = [];
    foreach ($vocabularies as $vid => $vocablary) {
      $vocabulariesList[$vid] = $vocablary->get('name');
    }

    $form['field_vocabulary_name'] = [
      '#type' => 'select',
      '#title' => $this->t('Vocabulary name'),
      '#options' => $vocabulariesList,
      '#attributes' => [
        'class' => ['vocab-name-select'],
      ],
      '#description' => $this->t('Select vocabulary!'),
    ];

    $form['import_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Import Mode'),
      '#options' => [
        'standard' => $this->t('Standard (name, parent, description)'),
        'canadian_cities' => $this->t('Canadian Cities (province as parent, city fields)'),
      ],
      '#default_value' => 'standard',
      '#description' => $this->t('Select the import mode based on your CSV structure.'),
    ];

    $form['import_behavior'] = [
      '#type' => 'select',
      '#title' => $this->t('Force new terms for every record of Source Data'),
      '#options' => [
        0 => $this->t('No, allow updating of existing records if term name matches'),
        1 => $this->t('Yes, create a new term for every record; do not update existing'),
      ],
      '#description' => $this->t('Select desired import behavior!'),
    ];

    $form['taxonomy_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Import file'),
      '#required' => TRUE,
      '#upload_validators'  => [
        'FileExtension' => ['extensions' => $this->config->get('file_extensions') ?? ImportFormSettings::DEFAULT_FILE_EXTENSION],
      ],
      '#upload_location' => 'public://taxonomy_files/',
      '#description' => $this->t('Upload a file to Import taxonomy!'),
    ];

    $form['actions']['#type'] = 'actions';
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $fileErrorMessage = $this->t('File was not provided or cannot be read.');

    $vid = $form_state->getValue('field_vocabulary_name');
    $ary = $form_state->getValue('taxonomy_file');
    $importBehavior = $form_state->getValue('import_behavior');
    $importMode = $form_state->getValue('import_mode');
    $fid = !empty($ary[0]) ? $ary[0] : NULL;

    if (!$vid) {
      $form_state->setErrorByName('field_vocabulary_name', $this->t('Vocabulary name was not provided.'));
      return;
    }

    if (!isset($importBehavior)) {
      $form_state->setErrorByName('import_behavior', $this->t('Import behavior was not provided.'));
      return;
    }

    $file = $fid ? \Drupal::entityTypeManager()->getStorage('file')->load($fid) : NULL;
    if (!$file) {
      $form_state->setErrorByName('taxonomy_file', $fileErrorMessage);
      return;
    }

    $filepath = $file->uri->value;
    $mimetype = $file->filemime->value;

    if (!$filepath) {
      $form_state->setErrorByName('taxonomy_file', $fileErrorMessage);
      return;
    }

    if (!in_array($mimetype, self::ALLOWED_MIME_TYPES)) {
      $form_state->setErrorByName('taxonomy_file', $this->t('File is not of a supported type.'));
      return;
    }

    $form_state->set('vid', $vid);
    $form_state->set('import_behavior', $importBehavior);
    $form_state->set('import_mode', $importMode);
    $form_state->set('filepath', $filepath);
    $form_state->set('is_csv', in_array($mimetype, self::CSV_MIME_TYPES));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $vid = $form_state->get('vid');
    $filepath = $form_state->get('filepath');
    $importBehavior = $form_state->getValue('import_behavior');
    $importMode = $form_state->getValue('import_mode');

    if ($form_state->get('is_csv')) {
      $rows = $this->readCsv($vid, $filepath, $importMode);
    }
    else {
      $rows = $this->readXml($vid, $filepath);
    }

    if (!$rows) {
      throw new \Exception($this->t('File @filepath contained no rows, please check the file.', ['@filepath' => $filepath]));
    }

    $this->taxonomyUtils->saveTerms($vid, $rows, $importBehavior);

    $url = $this->t('admin/structure/taxonomy/manage/:vid/overview', [':vid' => $vid]);
    $url = \Drupal::service('path.validator')->getUrlIfValid($url);

    if ($url) {
      $form_state->setRedirectUrl($url);
    }
  }

  /**
   * Function to read a CSV file.
   *
   * @param string $vid
   *   The vocabulary ID.
   * @param string $filepath
   *   The file path.
   * @param string $importMode
   *   The import mode (standard or canadian_cities).
   *
   * @return array
   *   Array of term data.
   *
   * @throws \Exception
   */
  protected function readCsv($vid, $filepath, $importMode = 'standard') {
    $handle = fopen($filepath, 'r');
    if (!$handle) {
      throw new \Exception($this->t('File @filepath cannot be opened.', ['@filepath' => $filepath]));
    }

    $items = [];

    // Read headers
    $headers = fgetcsv($handle);
    if (!$headers) {
      fclose($handle);
      throw new \Exception($this->t('File has no headers.'));
    }

    // Trim whitespace from headers
    $headers = array_map('trim', $headers);

    if ($importMode === 'canadian_cities') {
      // Process Canadian cities format
      while (($data = fgetcsv($handle)) !== FALSE) {
        if (empty($data[1])) { // Skip if name is empty
          continue;
        }

       // Build geolocation field for Geolocation module
       // Format: latitude and longitude as separate values
       $geolocation = [];
       if (!empty($data[8]) && !empty($data[9])) {
         $lat = (float) trim($data[8]);
         $lng = (float) trim($data[9]);

         // Validate coordinates are numeric
         if (is_numeric($lat) && is_numeric($lng)) {
           $geolocation = [
             'lat' => $lat,
             'lng' => $lng,
           ];
         }
       }

        $item = [
          'name' => trim($data[1]), // name (city/town)
          'parent' => !empty($data[3]) ? trim($data[3]) : '', // province
          'description' => '', // Leave description empty
        ];

        // Add custom fields - map each CSV column to its field
        if (!empty($data[0])) {
          $item['field_city_id'] = trim($data[0]); // id
        }
        if (!empty($data[2])) {
          $item['field_county'] = trim($data[2]); // county
        }
        if (!empty($data[4])) {
          $item['field_province_code'] = trim($data[4]); // province_code
        }
        if (!empty($data[5])) {
          $item['field_postcode_area'] = trim($data[5]); // postcode_area
        }
        if (!empty($data[6])) {
          $item['field_type'] = trim($data[6]); // type
        }
        if (!empty($data[7])) {
          $item['field_map_reference'] = trim($data[7]); // map_reference
        }
        if (!empty($geolocation)) {
          $item['field_geolocation'] = $geolocation; // ['lat' => latitude, 'lng' => longitude]
        }
        if (!empty($data[10])) {
          $item['field_census_division'] = trim($data[10]); // census_division
        }
        if (!empty($data[11])) {
          $item['field_area_code'] = trim($data[11]); // area_code
        }
        if (!empty($data[12])) {
          $item['field_timezone'] = trim($data[12]); // time_zone
        }

        $items[] = $item;
      }
    }
    else {
      // Standard import mode
      while (($data = fgetcsv($handle)) !== FALSE) {
        if (empty($data[0])) {
          continue;
        }

        $item = [
          'name' => $data[0],
          'parent' => !empty($data[1]) ? $data[1] : '',
          'description' => !empty($data[2]) ? $data[2] : '',
        ];

        // Add any additional columns as custom fields
        for ($i = 3; $i < count($headers); $i++) {
          if (!empty($data[$i]) && !empty($headers[$i])) {
            $item[$headers[$i]] = $data[$i];
          }
        }

        $items[] = $item;
      }
    }

    fclose($handle);
    return $items;
  }

  /**
   * Function to read an XML file.
   *
   * @param string $vid
   *   The vocabulary ID.
   * @param string $filepath
   *   The file path.
   *
   * @return array
   *   Array of term data.
   */
  protected function readXml($vid, $filepath) {
    $contents = file_get_contents($filepath);
    $rawItems = $contents ? simplexml_load_string($contents) : NULL;
    if (empty($rawItems)) {
      throw new \Exception($this->t('File @filepath cannot be opened.', ['@filepath' => $filepath]));
    }

    $items = [];
    foreach ($rawItems->children() as $item) {
      $item = (array) $item;
      if (empty($item['name'])) {
        continue;
      }

      $items[] = $item;
    }

    return $items;
  }

}
