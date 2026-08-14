<?php

declare(strict_types=1);

namespace Drupal\neo_search\Plugin\NeoSearch;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Utility\Error;
use Drupal\neo_search\Attribute\NeoSearch;
use Drupal\neo_search\NeoSearchPluginBase;
use Drupal\neo_search\SearchRequest;
use Drupal\neo_search\SearchResultItem;
use Drupal\neo_search\SearchResultSet;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Searches a Search API index.
 *
 * The query runs as the current user with no access bypass. Because not every
 * index enables the content_access processor, every hit is additionally
 * access-checked after load before it is returned.
 */
#[NeoSearch(
  id: 'search_api',
  label: new TranslatableMarkup('Search API'),
  description: new TranslatableMarkup('Query a Search API index (Solr, database, …).'),
)]
class SearchApiSearch extends NeoSearchPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The bundle info service.
   */
  protected EntityTypeBundleInfoInterface $bundleInfo;

  /**
   * The module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The search_api query helper, when search_api is installed.
   *
   * @var \Drupal\search_api\Utility\QueryHelperInterface|null
   */
  protected $queryHelper;

  /**
   * The search_api parse mode manager, when search_api is installed.
   *
   * @var \Drupal\search_api\ParseMode\ParseModePluginManager|null
   */
  protected $parseModeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->bundleInfo = $container->get('entity_type.bundle.info');
    $instance->moduleHandler = $container->get('module_handler');
    $instance->queryHelper = $container->has('search_api.query_helper') ? $container->get('search_api.query_helper') : NULL;
    $instance->parseModeManager = $container->has('plugin.manager.search_api.parse_mode') ? $container->get('plugin.manager.search_api.parse_mode') : NULL;
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->moduleHandler->moduleExists('search_api');
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'index' => '',
      'fulltext_fields' => [],
      'datasources' => [],
      'bundles' => [],
      'match_mode' => 'prefix',
      'highlight' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Normalizes the multi-value keys so downstream code always sees clean
   * lists — historical config may hold a comma-string (bundles) or a raw
   * checkbox map with '0' for unchecked options.
   */
  public function setConfiguration(array $configuration) {
    parent::setConfiguration($configuration);
    if (is_string($this->configuration['bundles'])) {
      $this->configuration['bundles'] = array_values(array_filter(array_map('trim', explode(',', $this->configuration['bundles']))));
    }
    foreach (['fulltext_fields', 'datasources', 'bundles'] as $key) {
      $clean = [];
      foreach ((array) $this->configuration[$key] as $k => $v) {
        if (is_int($k)) {
          if (is_string($v) && $v !== '' && $v !== '0') {
            $clean[] = $v;
          }
        }
        elseif (!empty($v)) {
          $clean[] = $k;
        }
      }
      $this->configuration[$key] = $clean;
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $indexes = [];
    foreach ($this->entityTypeManager->getStorage('search_api_index')->loadMultiple() as $index) {
      $indexes[$index->id()] = (string) $index->label();
    }
    $form['index'] = [
      '#type' => 'select',
      '#title' => $this->t('Index'),
      '#options' => $indexes,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $this->configuration['index'],
      '#required' => TRUE,
      '#description' => $this->t('Field and datasource options below reflect the saved index; save after switching the index to update them.'),
    ];

    /** @var \Drupal\search_api\IndexInterface|null $index */
    $index = $this->configuration['index']
      ? $this->entityTypeManager->getStorage('search_api_index')->load($this->configuration['index'])
      : NULL;
    if ($index) {
      $fulltext = [];
      foreach ($index->getFulltextFields() as $fieldId) {
        $field = $index->getField($fieldId);
        $fulltext[$fieldId] = $field ? (string) $field->getLabel() : $fieldId;
      }
      $form['fulltext_fields'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Fulltext fields'),
        '#description' => $this->t('Limit matching to these fields. Leave empty for all fulltext fields.'),
        '#options' => $fulltext,
        '#default_value' => $this->configuration['fulltext_fields'],
      ];
      $datasources = [];
      foreach ($index->getDatasources() as $datasourceId => $datasource) {
        $datasources[$datasourceId] = (string) $datasource->label();
      }
      if (count($datasources) > 1) {
        $form['datasources'] = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Datasources'),
          '#description' => $this->t('Limit results to these datasources. Leave empty for all.'),
          '#options' => $datasources,
          '#default_value' => $this->configuration['datasources'],
        ];
      }
    }

    $form['bundles'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bundles'),
      '#description' => $this->t('Comma-separated bundle ids to limit results to. Requires the index to have a field on the bundle property (e.g. "Content type"). Leave empty for all.'),
      '#default_value' => implode(', ', array_filter((array) $this->configuration['bundles'], 'is_string')),
    ];
    $form['match_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Match mode'),
      '#options' => [
        'direct' => $this->t('Direct — terms match whole indexed tokens'),
        'prefix' => $this->t('Prefix — the last term also matches as a prefix (Solr only; lets "appl" find "apple")'),
      ],
      '#default_value' => $this->configuration['match_mode'],
    ];
    $form['highlight'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Excerpts'),
      '#description' => $this->t("Pass through the index highlight processor's excerpt when available."),
      '#default_value' => $this->configuration['highlight'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    if (isset($values['bundles']) && is_string($values['bundles'])) {
      $form_state->setValue('bundles', array_values(array_filter(array_map('trim', explode(',', $values['bundles'])))));
    }
    // Checkboxes submit unchecked options as '0'; keep only checked keys.
    foreach (['fulltext_fields', 'datasources'] as $key) {
      if (isset($values[$key]) && is_array($values[$key])) {
        $form_state->setValue($key, array_values(array_filter(array_keys(array_filter($values[$key])), 'is_string')));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function search(SearchRequest $request): SearchResultSet {
    $results = new SearchResultSet();
    if (!$this->queryHelper || !$this->configuration['index']) {
      return $results;
    }
    /** @var \Drupal\search_api\IndexInterface|null $index */
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($this->configuration['index']);
    if (!$index || !$index->status()) {
      return $results;
    }

    $query = $this->queryHelper->createQuery($index);
    $query->keys($this->prepareKeys($query, $index, $request->query));
    $fields = array_values(array_filter($this->configuration['fulltext_fields']));
    if ($fields) {
      $query->setFulltextFields($fields);
    }
    $datasources = array_values(array_filter($this->configuration['datasources']));
    if ($datasources) {
      $query->addCondition('search_api_datasource', $datasources, 'IN');
    }
    $bundles = array_values(array_filter($this->configuration['bundles']));
    if ($bundles && ($bundleField = $this->findBundleField($index))) {
      $query->addCondition($bundleField, $bundles, 'IN');
    }
    $query->range(0, $request->limit);
    $query->addTag('neo_search');
    $query->addTag('neo_search_' . $request->variationId);
    $query->setOption('neo_search_match_mode', $this->configuration['match_mode']);

    try {
      $resultSet = $query->execute();
    }
    catch (\Exception $e) {
      Error::logException(\Drupal::logger('neo_search'), $e);
      return $results;
    }

    $results->addCacheTags([
      'search_api_list:' . $index->id(),
      'config:search_api.index.' . $index->id(),
    ]);
    $listTags = [];

    foreach ($resultSet as $item) {
      try {
        $object = $item->getOriginalObject();
      }
      catch (\Exception) {
        continue;
      }
      $entity = $object?->getValue();
      if (!$entity instanceof ContentEntityInterface) {
        continue;
      }
      if ($entity->hasTranslation($request->langcode)) {
        $entity = $entity->getTranslation($request->langcode);
      }
      // The belt-and-braces access guarantee: indexes without the
      // content_access processor return unfiltered hits.
      if (!$entity->access('view', $request->account)) {
        continue;
      }
      $resultItem = new SearchResultItem(
        id: $entity->getEntityTypeId() . ':' . $entity->id(),
        label: (string) $entity->label(),
        url: $entity->toUrl()->toString(),
        entityTypeId: $entity->getEntityTypeId(),
        entityId: (string) $entity->id(),
        bundle: $entity->bundle(),
        weight: -(float) ($item->getScore() ?? 0),
      );
      $bundleInfo = $this->bundleInfo->getBundleInfo($entity->getEntityTypeId());
      $resultItem->typeLabel = isset($bundleInfo[$entity->bundle()]) ? (string) $bundleInfo[$entity->bundle()]['label'] : NULL;
      if (!empty($this->configuration['highlight']) && ($excerpt = $item->getExcerpt())) {
        $resultItem->excerpt = Xss::filter($excerpt, ['mark', 'strong', 'em']);
      }
      $results->addItem($resultItem);
      $results->addCacheableDependency($entity);
      $listTags[$entity->getEntityTypeId() . '_list'] = TRUE;
    }

    // List tags cover adds/deletes that per-entity tags cannot.
    foreach ($index->getDatasources() as $datasource) {
      if ($entityTypeId = $datasource->getEntityTypeId()) {
        $listTags[$entityTypeId . '_list'] = TRUE;
      }
    }
    $results->addCacheTags(array_keys($listTags));

    return $results;
  }

  /**
   * Prepares the query keys, applying prefix matching on Solr backends.
   *
   * Prefix mode switches to the "direct" parse mode so an edismax expression
   * reaches Solr with the configured query fields intact, and rewrites the
   * last term to also match as a wildcard prefix. Wildcard terms bypass
   * analysis, so the wildcard variant is lowercased to match indexed tokens.
   *
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The search_api query.
   * @param \Drupal\search_api\IndexInterface $index
   *   The index.
   * @param string $keys
   *   The normalized user input.
   *
   * @return string
   *   The keys to set on the query.
   */
  protected function prepareKeys($query, $index, string $keys): string {
    if ($this->configuration['match_mode'] !== 'prefix' || !$this->parseModeManager) {
      return $keys;
    }
    try {
      $backendId = $index->getServerInstance()?->getBackend()->getPluginId() ?? '';
    }
    catch (\Exception) {
      return $keys;
    }
    if (!str_starts_with($backendId, 'search_api_solr')) {
      return $keys;
    }
    // The expression is user input headed for a lenient query parser; keep
    // only word characters per term so no Lucene syntax survives.
    $terms = array_values(array_filter(array_map(
      fn (string $term): string => (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $term),
      preg_split('/\s+/u', $keys) ?: []
    ), fn (string $term): bool => $term !== ''));
    if (!$terms) {
      return $keys;
    }
    $query->setParseMode($this->parseModeManager->createInstance('direct'));
    $last = array_pop($terms);
    $parts = array_map(fn (string $term): string => '+' . $term, $terms);
    $parts[] = '+(' . $last . ' OR ' . mb_strtolower($last) . '*)';
    return implode(' ', $parts);
  }

  /**
   * Finds an indexed field whose property path is a datasource bundle key.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index.
   */
  protected function findBundleField($index): ?string {
    foreach ($index->getFields() as $fieldId => $field) {
      $datasource = $field->getDatasource();
      if (!$datasource || !($entityTypeId = $datasource->getEntityTypeId())) {
        continue;
      }
      $definition = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);
      if ($definition && $definition->getKey('bundle') && $field->getPropertyPath() === $definition->getKey('bundle')) {
        return $fieldId;
      }
    }
    return NULL;
  }

}
