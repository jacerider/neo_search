<?php

declare(strict_types=1);

namespace Drupal\neo_search;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Serialization\Yaml;

/**
 * Provisions the full site-search stack.
 *
 * Non-interactive building blocks for `drush neo:search:setup`: detection of
 * existing pieces, view generation from the shipped template, and the saved
 * component binding. search_api / views / neo_alchemist are declared module
 * dependencies; their classes are still kept out of the constructor so the
 * service stays cheap to instantiate.
 */
class SearchSetup {

  /**
   * Constructs the service.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected ModuleExtensionList $moduleExtensionList,
  ) {}

  /**
   * Finds views usable as the search source for an index.
   *
   * A view qualifies when its base table is the index's views table and its
   * default display has an exposed search_api_fulltext filter.
   *
   * @param string $indexId
   *   The search_api index id.
   *
   * @return array
   *   Keyed by view id: ['label', 'identifier', 'page_displays' =>
   *   [display_id => path]].
   */
  public function findSearchViews(string $indexId): array {
    $matches = [];
    /** @var \Drupal\views\ViewEntityInterface $view */
    foreach ($this->entityTypeManager->getStorage('view')->loadMultiple() as $view) {
      if (!$view->status() || $view->get('base_table') !== 'search_api_index_' . $indexId) {
        continue;
      }
      $displays = $view->get('display');
      $identifier = NULL;
      foreach (($displays['default']['display_options']['filters'] ?? []) as $filter) {
        if (($filter['plugin_id'] ?? '') === 'search_api_fulltext' && !empty($filter['exposed'])) {
          $identifier = $filter['expose']['identifier'] ?? NULL;
          break;
        }
      }
      if (!$identifier) {
        continue;
      }
      $pageDisplays = [];
      foreach ($displays as $displayId => $display) {
        if (($display['display_plugin'] ?? '') === 'page' && !empty($display['display_options']['path'])) {
          $pageDisplays[$displayId] = $display['display_options']['path'];
        }
      }
      $matches[$view->id()] = [
        'label' => (string) $view->label(),
        'identifier' => $identifier,
        'page_displays' => $pageDisplays,
      ];
    }
    return $matches;
  }

  /**
   * Builds the config values for the search view from the shipped template.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index the view queries.
   * @param string $viewId
   *   The id for the new view.
   *
   * @return array
   *   Values for View::create(). Type badges need no view fields — the
   *   binding maps them from bundle info via _entity:bundle_label_page.
   */
  public function buildViewValues($index, string $viewId): array {
    $template = $this->moduleExtensionList->getPath('neo_search') . '/install/views.search.template.yml';
    $yaml = strtr((string) file_get_contents($template), [
      '[VIEW_ID]' => $viewId,
      '[INDEX_ID]' => $index->id(),
    ]);
    return Yaml::decode($yaml);
  }

  /**
   * Ensures the saved search_list component binding exists and is wired.
   *
   * @param string $sdcId
   *   The SDC plugin id, e.g. "front:search_list" (theme-owned copy).
   * @param string $viewId
   *   The search view id.
   * @param string $filterIdentifier
   *   The exposed fulltext filter identifier.
   *
   * @return array
   *   ['status' => 'created'|'exists'|'updated', 'component' => Component].
   */
  public function ensureBinding(string $sdcId, string $viewId, string $filterIdentifier = 's'): array {
    $storage = $this->entityTypeManager->getStorage('neo_component');
    /** @var \Drupal\neo_alchemist\ComponentInterface|null $component */
    $component = $storage->load('search_list');
    if ($component) {
      $status = 'exists';
      $settings = $component->get('settings');
      $boundView = $settings['props']['items']['plugins']['items']['views']['settings']['view_id'] ?? NULL;
      if ($component->get('component') !== $sdcId || $boundView !== $viewId) {
        $component->set('component', $sdcId);
        $settings['props']['items']['plugins']['items']['views']['settings']['view_id'] = $viewId;
        $component->set('settings', $settings);
        $component->save();
        $status = 'updated';
      }
      return ['status' => $status, 'component' => $component];
    }

    // Two-save: the create branch derives schema/expression and the props
    // skeleton; bindings can only be injected on a second save.
    $component = $storage->create([
      'id' => 'search_list',
      'label' => 'Search | Collection',
      'description' => 'Flat site-search results: search input, type badges, highlighted excerpts, chips, count and pager — bound to the search view.',
      'group' => 'collection',
      'component' => $sdcId,
      'prop_editability' => 'locked',
      'status' => TRUE,
    ]);
    $component->save();

    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('search_list');
    $props = $component->get('settings')['props'] ?? [];
    $shapeFields = [
      'title' => ['field' => '_entity:label'],
      'link' => ['field' => '_entity:link:canonical'],
      // Type badges resolve per row from bundle info — the _page variants
      // convert the admin-facing "system" bundle to "Page" (label and icon).
      'type' => ['field' => '_entity:bundle_label_page'],
      'icon' => ['field' => '_entity:icon_page'],
      'excerpt' => ['field' => '_view:search_api_excerpt'],
    ];
    $props['items']['plugins']['items'] = [
      'views' => [
        'id' => 'views',
        'settings' => [
          'view_id' => $viewId,
          'view_display_id' => 'default',
          'view_entity_type_id' => 'node',
          'view_entity_bundle' => '',
          'view_items_offset' => 0,
          'view_arguments' => [],
          'view_arguments_sort' => FALSE,
          'shape_fields' => $shapeFields,
          'shape_published' => TRUE,
          'processing_mode' => 'block',
        ],
      ],
    ];
    $props['search_filter']['plugins']['search_filter'] = [
      'views_exposed_filter' => [
        'id' => 'views_exposed_filter',
        'settings' => ['context' => 'items', 'filter' => $filterIdentifier],
      ],
    ];
    $props['active_filters']['plugins']['active_filters'] = [
      'views_active_filters' => [
        'id' => 'views_active_filters',
        'settings' => ['context' => 'items'],
      ],
    ];
    $props['summary']['plugins']['summary'] = [
      'views_summary' => [
        'id' => 'views_summary',
        'settings' => ['context' => 'items'],
      ],
    ];
    $component->setSetting('props', $props);

    $slots = $component->get('settings')['slots'] ?? [];
    $slots['footer']['plugins'][\Drupal::service('uuid')->generate()] = [
      'plugin' => 'views_pager',
      'settings' => ['context' => 'items'],
    ];
    $component->setSetting('slots', $slots);
    $component->save();

    return ['status' => 'created', 'component' => $component];
  }

  /**
   * Builds the quick-search variation settings.
   */
  public function buildVariationSettings(string $selector, string $alias, string $filterIdentifier, ?string $component = NULL): array {
    $settings = [
      'selectors' => $selector,
      'min_chars' => 2,
      'group_by' => 'bundle',
      'all_results_type' => 'url',
      'all_results_url' => $alias . '?' . $filterIdentifier . '=[query]',
      'all_results_min' => 1,
    ];
    // Only when the theme actually owns a copy — otherwise the variation
    // inherits the base setting, which is the shipped default.
    if ($component) {
      $settings['component'] = $component;
    }
    return $settings;
  }

}
