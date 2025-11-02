<?php

namespace Drupal\taxonomy_import\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Our utils.
 */
class TaxonomyUtils implements TaxonomyUtilsInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * OQUtils constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public function loadTerm($vid, $name) {
    $ary = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
      'vid' => $vid,
      'name' => $name,
    ]);

    return !empty($ary) ? reset($ary) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function updateTerm($vid, $term, $parentId, $description, $rowData, $termCustomFields) {
    $needsSave = FALSE;

    if ($parentId) {
      $parentIds = $this->getTermParentIds($term);
      if (!in_array($parentId, $parentIds)) {
        $parentIds[] = $parentId;
        $term->set('parent', $parentIds);
        $needsSave = TRUE;
      }
    }

    if ($term->getDescription() != $description) {
      $term->setDescription($description);
      $needsSave = TRUE;
    }

    // Update custom fields
    foreach ($termCustomFields as $fieldName) {
      if (isset($rowData[$fieldName]) && $term->hasField($fieldName)) {
        $currentValue = $term->get($fieldName)->value;
        if ($currentValue != $rowData[$fieldName]) {
          $term->set($fieldName, $rowData[$fieldName]);
          $needsSave = TRUE;
        }
      }
    }

    return $needsSave ? $term->save() : TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function createVocabulary($vocabularyName) {
    // Converting to machine name.
    $machine_readable = strtolower($vocabularyName);
    // Vocabulary machine name.
    $vid = preg_replace('@[^a-z0-9_]+@', '_', $machine_readable);
    // Creating new vocabulary with the field value.
    $vocabularies = Vocabulary::loadMultiple();

    if (isset($vocabularies[$vid])) {
      return $vocabularies[$vid];
    }

    $vocabulary = Vocabulary::create([
      'vid' => $vid,
      'machine_name' => $vid,
      'name' => $vocabularyName,
      'description' => '',
    ]);

    return $vocabulary->save() ? $vocabulary : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function createTerm($vid, $name, $parentId, $description, $rowData, $termCustomFields) {
    $termData = [
      'parent' => [$parentId],
      'name' => $name,
      'description' => $description,
      'vid' => $vid,
    ];

    $term = $this->entityTypeManager->getStorage('taxonomy_term')->create($termData);

    // Set custom fields
    foreach ($termCustomFields as $fieldName) {
      if (isset($rowData[$fieldName]) && $term->hasField($fieldName)) {
        $term->set($fieldName, $rowData[$fieldName]);
      }
    }

    return $term->save();
  }

  /**
   * {@inheritdoc}
   */
  public function getTermParentIds($term) {
    $ret = [];
    foreach ($term->get('parent') as $par) {
      $temp = $par->get('entity');
      if (!$temp) {
        continue;
      }

      $temp = $temp->getTarget();
      if (!$temp) {
        continue;
      }

      $temp = $temp->getValue();
      if (!$temp) {
        continue;
      }

      $ret[] = $temp->id();
    }

    return $ret;
  }

  /**
   * {@inheritdoc}
   */
  public function saveTerms($vid, $rows, $forceNewTerms = TRUE) {
    // First pass: identify parent terms
    $parentTerms = [];
    foreach ($rows as $row) {
      if (!empty($row['parent'])) {
        $parentTerms[$row['parent']] = $row['parent'];
      }
    }

    // Create parent terms first
    foreach ($parentTerms as $parentName) {
      $existingParent = $this->loadTerm($vid, $parentName);
      if (!$existingParent) {
        \Drupal::logger('taxonomy_import')->info('Creating parent term: @name', ['@name' => $parentName]);
        $this->createTerm($vid, $parentName, 0, '', [], []);
      }
    }

    // Second pass: create/update all terms
    foreach ($rows as $row) {
      $term = $this->loadTerm($vid, $row['name']);

      // Find parent ID
      $parentId = 0;
      if (!empty($row['parent'])) {
        $parents = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
          'name' => $row['parent'],
          'vid' => $vid,
        ]);

        if (count($parents) > 0) {
          $parentId = key($parents);
          \Drupal::logger('taxonomy_import')->debug('Parent term found: %name (tid: %tid)', [
            '%name' => $row['parent'],
            '%tid' => $parentId,
          ]);
        }
      }

      // Identify custom fields (exclude system fields)
      $systemKeys = ['name', 'parent', 'description'];
      $customFields = [];
      foreach (array_keys($row) as $key) {
        if (!in_array($key, $systemKeys)) {
          $customFields[] = $key;
        }
      }

      // Verify custom fields exist in vocabulary
      $vocabularyFields = \Drupal::service('entity_field.manager')
        ->getFieldDefinitions('taxonomy_term', $vid);

      $validCustomFields = [];
      $missingFields = [];

      foreach ($customFields as $fieldName) {
        if (isset($vocabularyFields[$fieldName])) {
          $validCustomFields[] = $fieldName;
        }
        else {
          $missingFields[] = $fieldName;
        }
      }

      if (!empty($missingFields)) {
        \Drupal::logger('taxonomy_import')->warning('Fields not found in vocabulary @vid: @fields', [
          '@vid' => $vid,
          '@fields' => implode(', ', $missingFields),
        ]);
      }

      // Create or update term
      if ($term && !$forceNewTerms) {
        $this->updateTerm($vid, $term, $parentId, $row['description'] ?? '', $row, $validCustomFields);
      }
      else {
        $this->createTerm($vid, $row['name'], $parentId, $row['description'] ?? '', $row, $validCustomFields);
      }
    }
  }

}
