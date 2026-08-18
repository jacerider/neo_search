<?php

namespace Drupal\neo_search\Settings;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\neo_search\NeoSearchPluginManager;
use Drupal\neo_search\SearchRunner;
use Drupal\neo_settings\Plugin\SettingsBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Module settings.
 *
 * @Settings(
 *   id = "neo_search",
 *   label = @Translation("Search"),
 *   config_name = "neo_search.settings",
 *   menu_title = @Translation("Search"),
 *   route = "/admin/config/neo/search",
 *   admin_permission = "administer neo_search",
 *   variation_allow = true,
 *   variation_label = "selector",
 *   variation_label_plural = "selectors",
 *   variation_conditions = false,
 *   variation_ordering = false,
 * )
 */
class SearchSettings extends SettingsBase {

  /**
   * Variable-shape values that must merge as-is, never key-intersected.
   *
   * @var array
   */
  protected $strictParents = [
    ['plugin_settings'],
    ['groups'],
    ['view_modes'],
    ['cache_contexts'],
  ];

  /**
   * The neo search plugin manager.
   */
  protected NeoSearchPluginManager $searchPluginManager;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity display repository.
   */
  protected EntityDisplayRepositoryInterface $entityDisplayRepository;

  /**
   * The module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The single directory component plugin manager.
   */
  protected ComponentPluginManager $componentManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MessengerInterface $messenger,
    FormBuilderInterface $form_builder,
    NeoSearchPluginManager $search_plugin_manager,
    EntityTypeManagerInterface $entity_type_manager,
    EntityDisplayRepositoryInterface $entity_display_repository,
    ModuleHandlerInterface $module_handler,
    ComponentPluginManager $component_manager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $messenger, $form_builder);
    $this->searchPluginManager = $search_plugin_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityDisplayRepository = $entity_display_repository;
    $this->moduleHandler = $module_handler;
    $this->componentManager = $component_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('messenger'),
      $container->get('form_builder'),
      $container->get('plugin.manager.neo_search'),
      $container->get('entity_type.manager'),
      $container->get('entity_display.repository'),
      $container->get('module_handler'),
      $container->get('plugin.manager.sdc')
    );
  }

  /**
   * {@inheritdoc}
   *
   * Instance settings are settings that are set both in the base form and the
   * variation form. They are editable in both forms and the values are merged
   * together.
   */
  protected function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $parents = $form['#parents'];
    $selector = $this->getInputSelector($form);

    $form['tabs'] = [
      '#type' => 'vertical_tabs',
    ];
    $group = implode('][', $parents) . '][tabs';

    // -- Binding.
    $form['binding'] = [
      '#type' => 'details',
      '#title' => $this->t('Binding'),
      '#group' => $group,
      '#parents' => $parents,
    ];
    $form['binding']['selectors'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Input selectors'),
      '#description' => $this->t('CSS selectors of the text inputs this search binds to. One per line. Example: <code>#site-search</code>'),
      '#default_value' => $this->getValue('selectors'),
      '#rows' => 2,
    ];
    $form['binding']['breakpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Breakpoint'),
      '#description' => $this->t('Optional media query that must match for the quick search to activate. Example: <code>(min-width: 1024px)</code>. Leave empty for always.'),
      '#default_value' => $this->getValue('breakpoint'),
    ];

    // -- Backend.
    $form['backend'] = [
      '#type' => 'details',
      '#title' => $this->t('Backend'),
      '#group' => $group,
      '#parents' => $parents,
    ];
    $pluginOptions = $this->searchPluginManager->getAvailableOptions();
    // The settings form is embedded as a subform; values live on the complete
    // form state (a SubformState has no #parents of its own during build).
    $completeState = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
    $selectedPluginId = $completeState->getValue(array_merge($parents, ['plugin'])) ?: $this->getValue('plugin');
    if (!isset($pluginOptions[$selectedPluginId])) {
      $selectedPluginId = key($pluginOptions);
    }
    $form['backend']['plugin'] = [
      '#type' => 'select',
      '#title' => $this->t('Search plugin'),
      '#options' => $pluginOptions,
      '#default_value' => $selectedPluginId,
      '#required' => TRUE,
      '#ajax' => [
        'wrapper' => 'neo-search-plugin-settings',
        'callback' => [__CLASS__, 'ajaxPluginChange'],
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Loading plugin settings...'),
        ],
      ],
    ];
    $form['backend']['plugin_settings'] = [
      '#type' => 'container',
      '#prefix' => '<div id="neo-search-plugin-settings">',
      '#suffix' => '</div>',
      '#parents' => array_merge($parents, ['plugin_settings']),
    ];
    if ($selectedPluginId) {
      // Only reuse stored plugin settings when they belong to the selected
      // plugin; a freshly switched plugin starts from its defaults.
      $pluginConfig = $selectedPluginId === $this->getValue('plugin') ? ($this->getValue('plugin_settings') ?: []) : [];
      /** @var \Drupal\neo_search\NeoSearchPluginInterface $plugin */
      $plugin = $this->searchPluginManager->createInstance($selectedPluginId, $pluginConfig);
      $form['backend']['plugin_settings'] += $plugin->buildConfigurationForm([], $form_state);
    }

    // -- Results.
    $form['results'] = [
      '#type' => 'details',
      '#title' => $this->t('Results'),
      '#group' => $group,
      '#parents' => $parents,
    ];
    $form['results']['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum results'),
      '#min' => 1,
      '#max' => 100,
      '#default_value' => $this->getValue('limit'),
    ];
    $form['results']['group_by'] = [
      '#type' => 'radios',
      '#title' => $this->t('Group results by'),
      '#options' => [
        'none' => $this->t('No grouping'),
        'bundle' => $this->t('Bundle (content type)'),
        'entity_type' => $this->t('Entity type'),
      ],
      '#default_value' => $this->getValue('group_by'),
    ];
    $form['results']['group_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum results per group'),
      '#min' => 0,
      '#description' => $this->t('0 for no per-group limit.'),
      '#default_value' => $this->getValue('group_limit'),
    ];

    $form['results']['groups'] = $this->buildGroupsTable($parents);
    $form['results']['groups_other'] = [
      '#type' => 'select',
      '#title' => $this->t('Unmapped results'),
      '#description' => $this->t('What happens to results whose source key is not mapped to a group above.'),
      '#options' => [
        'visible' => $this->t('Show in automatic groups'),
        'hidden' => $this->t('Hide'),
      ],
      '#default_value' => $this->getValue('groups_other'),
    ];

    // -- Display.
    $form['display'] = [
      '#type' => 'details',
      '#title' => $this->t('Display'),
      '#group' => $group,
      '#parents' => $parents,
    ];
    $form['display']['display'] = [
      '#type' => 'radios',
      '#title' => $this->t('Display style'),
      '#options' => [
        'list' => $this->t('List — dropdown anchored to the input'),
        'cards' => $this->t('Cards — full-width panel of grouped cards'),
      ],
      '#default_value' => $this->getValue('display'),
    ];
    $form['display']['columns'] = [
      '#type' => 'number',
      '#title' => $this->t('Card columns'),
      '#min' => 1,
      '#max' => 6,
      '#default_value' => $this->getValue('columns'),
      '#states' => [
        'visible' => [
          ':input[name="' . $selector . '[display]"]' => ['value' => 'cards'],
        ],
      ],
    ];
    $form['display']['component'] = [
      '#type' => 'select',
      '#title' => $this->t('Panel component'),
      '#description' => $this->t('What renders the results panel. Pick a <em>component</em> to render its twig directly, or a <em>component instance</em> to go through an Alchemist entity so its configuration — colour scheme, access rules — applies as well. The results themselves come from the request either way. Your theme owns its copy of the twig; edits take effect after a cache rebuild.'),
      '#options' => $this->buildComponentOptions(),
      '#default_value' => $this->getValue('component') ?: SearchRunner::DEFAULT_COMPONENT,
    ];
    $form['display']['panel_anchor'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Panel anchor selector'),
      '#description' => $this->t('CSS selector of the element the full-width panel attaches below (e.g. <code>header</code>). Leave empty to anchor to the input itself.'),
      '#default_value' => $this->getValue('panel_anchor'),
      '#states' => [
        'visible' => [
          ':input[name="' . $selector . '[display]"]' => ['value' => 'cards'],
        ],
      ],
    ];
    $form['display']['min_chars'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum characters'),
      '#min' => 1,
      '#max' => 10,
      '#default_value' => $this->getValue('min_chars'),
    ];
    $form['display']['max_chars'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum characters'),
      '#min' => 8,
      '#max' => 512,
      '#default_value' => $this->getValue('max_chars'),
    ];
    $form['display']['debounce'] = [
      '#type' => 'number',
      '#title' => $this->t('Debounce (ms)'),
      '#min' => 0,
      '#max' => 2000,
      '#default_value' => $this->getValue('debounce'),
    ];
    $form['display']['render_items'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Render items with view modes'),
      '#description' => $this->t('Include rendered entity markup per result. The view modes used here should not depend on their own javascript libraries.'),
      '#default_value' => $this->getValue('render_items'),
    ];
    $form['display']['view_modes'] = $this->buildViewModes($parents, $selector);

    // -- All results link.
    $form['all_results'] = [
      '#type' => 'details',
      '#title' => $this->t('All results link'),
      '#group' => $group,
      '#parents' => $parents,
    ];
    $form['all_results']['all_results_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Link type'),
      '#options' => [
        'none' => $this->t('None'),
        'view' => $this->t('Search view — the URL and query key are read from the view'),
        'url' => $this->t('Custom URL'),
      ],
      '#default_value' => $this->getValue('all_results_type'),
    ];
    $viewOptions = $this->getSearchViewOptions();
    $form['all_results']['all_results_view'] = [
      '#type' => 'select',
      '#title' => $this->t('Search view'),
      '#options' => $viewOptions,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $this->getValue('all_results_view'),
      '#access' => !empty($viewOptions),
      '#states' => [
        'visible' => [
          ':input[name="' . $selector . '[all_results_type]"]' => ['value' => 'view'],
        ],
      ],
    ];
    $form['all_results']['all_results_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom URL'),
      '#description' => $this->t('Use <code>[query]</code> for the search text. Example: <code>/search?s=[query]</code>'),
      '#default_value' => $this->getValue('all_results_url'),
      '#states' => [
        'visible' => [
          ':input[name="' . $selector . '[all_results_type]"]' => ['value' => 'url'],
        ],
      ],
    ];
    $form['all_results']['all_results_min'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum results'),
      '#description' => $this->t('Only show the link when at least this many results are found.'),
      '#min' => 0,
      '#default_value' => $this->getValue('all_results_min'),
      '#states' => [
        'invisible' => [
          ':input[name="' . $selector . '[all_results_type]"]' => ['value' => 'none'],
        ],
      ],
    ];

    // -- Texts.
    $form['texts'] = [
      '#type' => 'details',
      '#title' => $this->t('Texts'),
      '#group' => $group,
      '#parents' => $parents,
    ];
    $form['texts']['empty_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Empty message'),
      '#default_value' => $this->getValue('empty_message'),
    ];
    $form['texts']['results_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Results label'),
      '#description' => $this->t('Shown above card results. Use <code>[query]</code> for the search text. Leave empty to hide.'),
      '#default_value' => $this->getValue('results_label'),
    ];
    $form['texts']['all_results_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('All results label'),
      '#default_value' => $this->getValue('all_results_label'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Base settings are only editable in the base form.
   */
  protected function buildBaseForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildBaseForm($form, $form_state);

    $form['protection'] = [
      '#type' => 'details',
      '#title' => $this->t('Protection & caching'),
      '#open' => FALSE,
      '#parents' => $form['#parents'],
    ];
    $form['protection']['flood_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Flood control'),
      '#description' => $this->t('Limit uncached search queries per client IP.'),
      '#default_value' => $this->getValue('flood_enabled'),
    ];
    $form['protection']['flood_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Flood threshold'),
      '#description' => $this->t('Maximum uncached queries per window.'),
      '#min' => 1,
      '#default_value' => $this->getValue('flood_threshold'),
    ];
    $form['protection']['flood_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Flood window (seconds)'),
      '#min' => 1,
      '#default_value' => $this->getValue('flood_window'),
    ];
    $form['protection']['http_max_age'] = [
      '#type' => 'number',
      '#title' => $this->t('Browser cache max age (seconds)'),
      '#description' => $this->t('How long browsers (and, for anonymous users, proxies) may reuse a response. Server-side invalidation is cache-tag driven and unaffected.'),
      '#min' => 0,
      '#default_value' => $this->getValue('http_max_age'),
    ];

    return $form;
  }

  /**
   * Builds the group overrides table.
   */
  protected function buildGroupsTable(array $parents): array {
    $table = [
      '#type' => 'table',
      '#caption' => $this->t('Group overrides'),
      '#header' => [
        $this->t('Label'),
        $this->t('Machine id'),
        $this->t('Mapped keys'),
        $this->t('Hidden'),
        $this->t('Weight'),
      ],
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'neo-search-group-weight',
        ],
      ],
      '#parents' => array_merge($parents, ['groups']),
      '#prefix' => '<p class="form-item__description">' . $this->t('Rename, reorder, merge, or hide result groups. "Mapped keys" is a comma-separated list of source keys (bundle or entity type ids, depending on grouping) collected into the group. Rows without a label are ignored.') . '</p>',
    ];

    $groups = $this->getValue('groups') ?: [];
    // Always render two blank rows for adding new groups.
    $rows = array_values($groups);
    $rows[] = [];
    $rows[] = [];

    foreach ($rows as $delta => $row) {
      $table[$delta]['#attributes']['class'][] = 'draggable';
      $table[$delta]['#weight'] = $row['weight'] ?? $delta;
      $table[$delta]['label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Label'),
        '#title_display' => 'invisible',
        '#default_value' => $row['label'] ?? '',
        '#size' => 24,
      ];
      $table[$delta]['id'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Machine id'),
        '#title_display' => 'invisible',
        '#description' => NULL,
        '#default_value' => $row['id'] ?? '',
        '#size' => 16,
        '#placeholder' => $this->t('auto'),
      ];
      $table[$delta]['map'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Mapped keys'),
        '#title_display' => 'invisible',
        '#default_value' => implode(', ', $row['map'] ?? []),
        '#size' => 32,
        '#placeholder' => $this->t('e.g. page, insight'),
      ];
      $table[$delta]['hidden'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Hidden'),
        '#title_display' => 'invisible',
        '#default_value' => !empty($row['hidden']),
      ];
      $table[$delta]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Weight'),
        '#title_display' => 'invisible',
        '#default_value' => $row['weight'] ?? $delta,
        '#attributes' => ['class' => ['neo-search-group-weight']],
      ];
    }

    return $table;
  }

  /**
   * Builds the panel component options.
   *
   * One select carries both kinds because they are mutually exclusive — the
   * panel is rendered by a component or through a component instance, never
   * both. The two are told apart by shape rather than a second config key: an
   * SDC plugin id is always "provider:name", a neo_component entity id never
   * contains a colon. See SearchRunner::renderPanel().
   *
   * Scoped to search_quick, the machine name SearchRunner supplies props for.
   * A configured value that no longer resolves is still listed, so saving the
   * form cannot silently discard it.
   */
  protected function buildComponentOptions(): array {
    $components = [];
    foreach (array_keys($this->componentManager->getDefinitions()) as $id) {
      if (str_ends_with((string) $id, ':search_quick')) {
        $components[$id] = $id === SearchRunner::DEFAULT_COMPONENT
          ? $this->t('@id — shipped default', ['@id' => $id])
          : $id;
      }
    }

    $entities = [];
    /** @var \Drupal\neo_alchemist\ComponentInterface $entity */
    foreach ($this->entityTypeManager->getStorage('neo_component')->loadMultiple() as $id => $entity) {
      if (str_ends_with((string) $entity->get('component'), ':search_quick')) {
        $entities[$id] = $entity->label() ?: $id;
      }
    }
    asort($entities);

    $options = [];
    if ($entities) {
      $options[(string) $this->t('Component Instances')] = $entities;
    }
    $options[(string) $this->t('Components')] = $components;

    $current = trim((string) $this->getValue('component'));
    if ($current !== '' && !isset($components[$current]) && !isset($entities[$current])) {
      $options[$current] = $this->t('@id — missing, falls back to the shipped default', ['@id' => $current]);
    }
    return $options;
  }

  /**
   * Builds the per-entity-type view mode selects.
   */
  protected function buildViewModes(array $parents, string $selector): array {
    $element = [
      '#type' => 'container',
      '#parents' => array_merge($parents, ['view_modes']),
      '#states' => [
        'visible' => [
          ':input[name="' . $selector . '[render_items]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $current = $this->getValue('view_modes') ?: [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if (!$definition instanceof ContentEntityTypeInterface || !$definition->hasViewBuilderClass()) {
        continue;
      }
      $options = $this->entityDisplayRepository->getViewModeOptions($id);
      $element[$id] = [
        '#type' => 'select',
        '#title' => $this->t('@type view mode', ['@type' => $definition->getLabel()]),
        '#options' => $options,
        '#empty_option' => $this->t('- Do not render -'),
        '#default_value' => $current[$id] ?? '',
      ];
    }
    return $element;
  }

  /**
   * Gets an option list of views usable as an all-results target.
   *
   * A view qualifies when a display has a path and an exposed
   * search_api_fulltext filter.
   */
  protected function getSearchViewOptions(): array {
    $options = [];
    if (!$this->moduleHandler->moduleExists('views') || !$this->moduleHandler->moduleExists('search_api')) {
      return $options;
    }
    /** @var \Drupal\views\ViewEntityInterface $view */
    foreach ($this->entityTypeManager->getStorage('view')->loadMultiple() as $view) {
      if (!$view->status()) {
        continue;
      }
      $displays = $view->get('display');
      $defaultFilters = $displays['default']['display_options']['filters'] ?? [];
      foreach ($displays as $displayId => $display) {
        $path = $display['display_options']['path'] ?? NULL;
        if (!$path) {
          continue;
        }
        $filters = ($display['display_options']['filters'] ?? []) ?: $defaultFilters;
        foreach ($filters as $filter) {
          if (($filter['plugin_id'] ?? '') === 'search_api_fulltext' && !empty($filter['exposed'])) {
            $options[$view->id() . ':' . $displayId] = $view->label() . ' (' . ($display['display_title'] ?? $displayId) . ')';
            break;
          }
        }
      }
    }
    asort($options);
    return $options;
  }

  /**
   * Ajax handler for the plugin select.
   */
  public static function ajaxPluginChange(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $parents = array_slice($trigger['#array_parents'], 0, -1);
    $element = NestedArray::getValue($form, $parents);
    return $element['plugin_settings'];
  }

  /**
   * {@inheritdoc}
   */
  protected function extractFormValues($values, array $form, FormStateInterface $form_state) {
    $values = parent::extractFormValues($values, $form, $form_state);

    // Normalize the group overrides table.
    if (isset($values['groups']) && is_array($values['groups'])) {
      $groups = [];
      foreach ($values['groups'] as $row) {
        if (!is_array($row) || trim((string) ($row['label'] ?? '')) === '') {
          continue;
        }
        $label = trim((string) $row['label']);
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
          $id = strtolower((string) preg_replace(['/[^a-zA-Z0-9_]+/', '/_+/'], '_', $label));
          $id = trim($id, '_');
        }
        $map = array_values(array_filter(array_map('trim', explode(',', (string) ($row['map'] ?? '')))));
        $groups[] = [
          'id' => $id,
          'label' => $label,
          'map' => $map,
          'weight' => (int) ($row['weight'] ?? 0),
          'hidden' => !empty($row['hidden']),
        ];
      }
      usort($groups, fn (array $a, array $b) => $a['weight'] <=> $b['weight']);
      $values['groups'] = $groups;
    }

    // Only persist plugin settings the selected plugin understands, and give
    // the plugin its PluginFormInterface submit pass (e.g. to turn a
    // comma-separated textfield or raw checkbox values into clean arrays).
    if (!empty($values['plugin'])) {
      /** @var \Drupal\neo_search\NeoSearchPluginInterface $plugin */
      $plugin = $this->searchPluginManager->createInstance($values['plugin']);
      $submitted = is_array($values['plugin_settings'] ?? NULL) ? $values['plugin_settings'] : [];
      $subformState = new FormState();
      $subformState->setValues($submitted);
      $subform = [];
      $plugin->submitConfigurationForm($subform, $subformState);
      $submitted = $subformState->getValues();
      $defaults = $plugin->defaultConfiguration();
      $values['plugin_settings'] = $defaults ? array_intersect_key($submitted, $defaults) + $defaults : $submitted;
    }

    // Drop entity types without a chosen view mode.
    if (isset($values['view_modes']) && is_array($values['view_modes'])) {
      $values['view_modes'] = array_filter($values['view_modes']);
    }

    return $values;
  }

  /**
   * Gets the input name selector for #states.
   */
  protected function getInputSelector(array $form): string {
    $parents = $form['#parents'];
    $first = array_shift($parents);
    return $first . ($parents ? '[' . implode('][', $parents) . ']' : '');
  }

}
