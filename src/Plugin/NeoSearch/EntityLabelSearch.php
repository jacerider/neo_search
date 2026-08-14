<?php

declare(strict_types=1);

namespace Drupal\neo_search\Plugin\NeoSearch;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_search\Attribute\NeoSearch;
use Drupal\neo_search\NeoSearchPluginBase;
use Drupal\neo_search\SearchRequest;
use Drupal\neo_search\SearchResultItem;
use Drupal\neo_search\SearchResultSet;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Searches entity labels via an entity query.
 *
 * Dependency-free fallback backend. Slower and less capable than a Search API
 * backend, but works on any site.
 */
#[NeoSearch(
  id: 'entity_label',
  label: new TranslatableMarkup('Entity label'),
  description: new TranslatableMarkup('Substring match on entity labels via an entity query. No additional modules required.'),
)]
class EntityLabelSearch extends NeoSearchPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The bundle info service.
   */
  protected EntityTypeBundleInfoInterface $bundleInfo;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->bundleInfo = $container->get('entity_type.bundle.info');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'entity_type' => 'node',
      'bundles' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if ($definition instanceof ContentEntityTypeInterface && $definition->hasKey('label') && $definition->hasLinkTemplate('canonical')) {
        $options[$id] = (string) $definition->getLabel();
      }
    }
    asort($options);
    $entity_type = $this->configuration['entity_type'];
    $form['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity type'),
      '#options' => $options,
      '#default_value' => $entity_type,
    ];
    $bundle_options = [];
    if (isset($options[$entity_type])) {
      foreach ($this->bundleInfo->getBundleInfo($entity_type) as $bundle => $info) {
        $bundle_options[$bundle] = (string) $info['label'];
      }
    }
    if ($bundle_options) {
      $form['bundles'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Bundles'),
        '#description' => $this->t('Limit to these bundles. Leave empty for all.'),
        '#options' => $bundle_options,
        '#default_value' => $this->configuration['bundles'],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function search(SearchRequest $request): SearchResultSet {
    $results = new SearchResultSet();
    $entity_type_id = $this->configuration['entity_type'];
    $definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if (!$definition || !$definition->hasKey('label')) {
      return $results;
    }

    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $query = $storage->getQuery()
      ->condition($definition->getKey('label'), $request->query, 'CONTAINS')
      ->accessCheck(TRUE)
      ->range(0, $request->limit)
      ->sort($definition->getKey('label'));
    if ($definition->hasKey('published')) {
      $query->condition($definition->getKey('published'), 1);
    }
    $bundles = array_filter($this->configuration['bundles']);
    if ($bundles && $definition->hasKey('bundle')) {
      $query->condition($definition->getKey('bundle'), array_values($bundles), 'IN');
    }
    if ($definition->isTranslatable()) {
      $results->addCacheContexts(['languages:language_content']);
    }

    $ids = $query->execute();
    $bundle_info = $this->bundleInfo->getBundleInfo($entity_type_id);
    foreach ($ids ? $storage->loadMultiple($ids) : [] as $entity) {
      if ($entity->hasTranslation($request->langcode)) {
        $entity = $entity->getTranslation($request->langcode);
      }
      // Entity query access checking can miss entity-level nuances; verify.
      if (!$entity->access('view', $request->account)) {
        continue;
      }
      $item = new SearchResultItem(
        id: $entity_type_id . ':' . $entity->id(),
        label: (string) $entity->label(),
        url: $entity->toUrl()->toString(),
        entityTypeId: $entity_type_id,
        entityId: (string) $entity->id(),
        bundle: $entity->bundle(),
      );
      $item->typeLabel = isset($bundle_info[$entity->bundle()]) ? (string) $bundle_info[$entity->bundle()]['label'] : NULL;
      $results->addItem($item);
      $results->addCacheableDependency($entity);
    }

    $results->addCacheTags([$entity_type_id . '_list']);
    return $results;
  }

}
