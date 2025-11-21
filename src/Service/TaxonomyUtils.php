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
   * Cache for parent term lookups.
   *
   * @var array
   */
  protected $parentCache = [];

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
   * Load a term by name and parent context.
   *
   * @param string $vid
   *   The vocabulary ID.
   * @param string $name
   *   The term name.
   * @param int $parentId
   *   The parent term ID (0 for root-level terms).
   *
   * @return \Drupal\taxonomy\Entity\Term|null
   *   The term object or NULL if not found.
   */
  public function loadTermByNameAndParent($vid, $name, $parentId = 0) {
    // Create a cache key
    $cacheKey = "{$vid}:{$name}:{$parentId}";

    if (isset($this->parentCache[$cacheKey])) {
      return $this->parentCache[$cacheKey];
    }

    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
      'vid' => $vid,
      'name' => $name,
    ]);

    if (empty($terms)) {
      $this->parentCache[$cacheKey] = NULL;
      return NULL;
    }

    // If only one term, check if it has the right parent
    if (count($terms) === 1) {
      $term = reset($terms);
      $termParentIds = $this->getTermParentIds($term);

      // Check if this term has the correct parent context
      if ($parentId === 0 && empty($termParentIds)) {
        // Root-level term matches
        $this->parentCache[$cacheKey] = $term;
        return $term;
      }
      elseif (in_array($parentId, $termParentIds)) {
        // Parent matches
        $this->parentCache[$cacheKey] = $term;
        return $term;
      }
      else {
        // Term exists but has different parent - treat as not found
        $this->parentCache[$cacheKey] = NULL;
        return NULL;
      }
    }

    // Multiple terms with same name - filter by parent
    foreach ($terms as $term) {
      $termParentIds = $this->getTermParentIds($term);

      // Check if this term has the correct parent
      if ($parentId === 0 && empty($termParentIds)) {
        // Looking for root-level term
        $this->parentCache[$cacheKey] = $term;
        return $term;
      }
      elseif (in_array($parentId, $termParentIds)) {
        // Found term with matching parent
        $this->parentCache[$cacheKey] = $term;
        return $term;
      }
    }

    // No term found with correct parent context
    $this->parentCache[$cacheKey] = NULL;
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function updateTerm($vid, $term, $parentId, $description, $rowData, $termCustomFields) {
    $needsSave = FALSE;

    // CRITICAL FIX: Replace parent entirely, don't append
    $currentParentIds = $this->getTermParentIds($term);
    $newParentIds = $parentId ? [$parentId] : [0];

    // Check if parent needs updating
    if ($currentParentIds !== $newParentIds) {
      $term->set('parent', $newParentIds);
      $needsSave = TRUE;
      \Drupal::logger('taxonomy_import')->debug('Updating parent for term @name from @old to @new', [
        '@name' => $term->getName(),
        '@old' => implode(',', $currentParentIds),
        '@new' => implode(',', $newParentIds),
      ]);
    }

    if ($term->getDescription() != $description) {
      $term->setDescription($description);
      $needsSave = TRUE;
    }

    // Update custom fields
    foreach ($termCustomFields as $fieldName) {
      if (!isset($rowData[$fieldName]) || !$term->hasField($fieldName)) {
        continue;
      }

      // Special handling for geolocation fields
      if ($fieldName === 'field_geolocation') {
        if (!is_array($rowData[$fieldName]) || !isset($rowData[$fieldName]['lat']) || !isset($rowData[$fieldName]['lng'])) {
          continue;
        }

        $currentValue = $term->get($fieldName)->getValue();
        $newValue = [
          'lat' => $rowData[$fieldName]['lat'],
          'lng' => $rowData[$fieldName]['lng'],
        ];

        if (empty($currentValue) ||
            !isset($currentValue[0]['lat']) ||
            !isset($currentValue[0]['lng']) ||
            $currentValue[0]['lat'] != $newValue['lat'] ||
            $currentValue[0]['lng'] != $newValue['lng']) {
          $term->set($fieldName, $newValue);
          $needsSave = TRUE;
        }
      }
      else {
        // For regular text fields
        $fieldItem = $term->get($fieldName);
        $currentValue = NULL;

        if (!$fieldItem->isEmpty()) {
          $currentValue = $fieldItem->value;
        }

        if ($currentValue != $rowData[$fieldName]) {
          $term->set($fieldName, $rowData[$fieldName]);
          $needsSave = TRUE;
        }
      }
    }

    if ($needsSave) {
      try {
        return $term->save();
      }
      catch (\Exception $e) {
        \Drupal::logger('taxonomy_import')->error('Error saving term @name: @message', [
          '@name' => $term->getName(),
          '@message' => $e->getMessage(),
        ]);
        // Wait briefly and retry once
        usleep(100000); // 100ms
        try {
          return $term->save();
        }
        catch (\Exception $e2) {
          throw $e2;
        }
      }
    }

    return TRUE;
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

    try {
      $result = $term->save();
      \Drupal::logger('taxonomy_import')->debug('Created term @name (tid: @tid) with parent @parent', [
        '@name' => $name,
        '@tid' => $term->id(),
        '@parent' => $parentId,
      ]);
      return $result;
    }
    catch (\Exception $e) {
      \Drupal::logger('taxonomy_import')->error('Error creating term @name: @message', [
        '@name' => $name,
        '@message' => $e->getMessage(),
      ]);
      // Wait briefly and retry once
      usleep(100000); // 100ms
      try {
        return $term->save();
      }
      catch (\Exception $e2) {
        throw $e2;
      }
    }
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
    // Clear the parent cache for this import
    $this->parentCache = [];

    // First pass: identify and create parent terms
    $parentTerms = [];
    foreach ($rows as $row) {
      if (!empty($row['parent'])) {
        $parentTerms[$row['parent']] = $row['parent'];
      }
    }

    // Create parent terms first (these should be root-level terms)
    \Drupal::logger('taxonomy_import')->info('Creating @count parent terms', ['@count' => count($parentTerms)]);

    foreach ($parentTerms as $parentName) {
      $existingParent = $this->loadTermByNameAndParent($vid, $parentName, 0);
      if (!$existingParent) {
        \Drupal::logger('taxonomy_import')->info('Creating parent term: @name', ['@name' => $parentName]);
        $this->createTerm($vid, $parentName, 0, '', [], []);
        // Small delay to prevent deadlocks
        usleep(10000); // 10ms
      }
      else {
        \Drupal::logger('taxonomy_import')->debug('Parent term already exists: @name (tid: @tid)', [
          '@name' => $parentName,
          '@tid' => $existingParent->id(),
        ]);
      }
    }

    // Reload parent cache after creating parents
    $this->parentCache = [];

    // Second pass: process child terms
    $processed = 0;
    $created = 0;
    $updated = 0;
    $total = count($rows);

    \Drupal::logger('taxonomy_import')->info('Processing @total child terms', ['@total' => $total]);

    foreach ($rows as $row) {
      $processed++;

      // Find parent ID if parent is specified
      $parentId = 0;
      if (!empty($row['parent'])) {
        // Load the parent term (should be root-level for provinces)
        $parentTerm = $this->loadTermByNameAndParent($vid, $row['parent'], 0);

        if ($parentTerm) {
          $parentId = $parentTerm->id();
        }
        else {
          \Drupal::logger('taxonomy_import')->warning('Parent term not found for child @name: @parent', [
            '@name' => $row['name'],
            '@parent' => $row['parent'],
          ]);
          continue; // Skip this term if parent not found
        }
      }

      // CRITICAL: Look for term with EXACT parent context
      // If forceNewTerms is TRUE, always create new
      // If forceNewTerms is FALSE, only update if term exists with this exact parent
      $term = NULL;
      if (!$forceNewTerms) {
        $term = $this->loadTermByNameAndParent($vid, $row['name'], $parentId);
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
      try {
        if ($term) {
          // Term exists with this exact parent - update it
          $this->updateTerm($vid, $term, $parentId, $row['description'] ?? '', $row, $validCustomFields);
          $updated++;
          \Drupal::logger('taxonomy_import')->debug('Updated existing term: @name under parent @parent_id', [
            '@name' => $row['name'],
            '@parent_id' => $parentId,
          ]);
        }
        else {
          // Create new term with specific parent
          $this->createTerm($vid, $row['name'], $parentId, $row['description'] ?? '', $row, $validCustomFields);
          $created++;
          \Drupal::logger('taxonomy_import')->debug('Created new term: @name under parent @parent_id', [
            '@name' => $row['name'],
            '@parent_id' => $parentId,
          ]);
        }

        // Add small delay every 50 terms to prevent deadlocks
        if ($processed % 50 === 0) {
          usleep(50000); // 50ms
          \Drupal::logger('taxonomy_import')->info('Progress: @processed of @total terms (@created created, @updated updated)', [
            '@processed' => $processed,
            '@total' => $total,
            '@created' => $created,
            '@updated' => $updated,
          ]);
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('taxonomy_import')->error('Failed to process term @name with parent @parent: @message', [
          '@name' => $row['name'],
          '@parent' => $row['parent'] ?? 'none',
          '@message' => $e->getMessage(),
        ]);
        // Continue with next term instead of failing entire import
      }
    }

    \Drupal::logger('taxonomy_import')->info('Import complete: @created created, @updated updated out of @total total', [
      '@created' => $created,
      '@updated' => $updated,
      '@total' => $total,
    ]);
  }

}
