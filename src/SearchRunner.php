<?php

declare(strict_types=1);

namespace Drupal\neo_search;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_settings\Plugin\SettingsInterface;
use Drupal\neo_search\Event\NeoSearchQueryEvent;
use Drupal\neo_search\Event\NeoSearchResultsEvent;
use Drupal\neo_search\Exception\FloodException;
use Drupal\neo_search\Exception\VariationNotFoundException;
use Drupal\neo_settings\SettingsRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Orchestrates quick search requests.
 *
 * Flow: resolve variation → normalize → length gate → cache lookup → flood →
 * query event → plugin → group → results event → render enrichment →
 * envelope → cache store.
 */
class SearchRunner {

  /**
   * The flood event name.
   */
  const FLOOD_NAME = 'neo_search.query';

  /**
   * The component rendered when a variation names none, or names a missing one.
   */
  const DEFAULT_COMPONENT = 'neo_search:search_quick';

  /**
   * Constructs the runner.
   */
  public function __construct(
    protected SettingsRepositoryInterface $settingsRepository,
    protected NeoSearchPluginManager $pluginManager,
    protected EventDispatcherInterface $eventDispatcher,
    protected CacheBackendInterface $cache,
    protected CacheContextsManager $cacheContextsManager,
    protected FloodInterface $flood,
    protected RendererInterface $renderer,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected LanguageManagerInterface $languageManager,
    protected AccountProxyInterface $currentUser,
    protected ComponentPluginManager $componentManager,
    protected ThemeManagerInterface $themeManager,
    protected LoggerInterface $logger,
    protected array $validCacheContexts = [],
  ) {}

  /**
   * Runs a quick search.
   *
   * @param string $variationId
   *   The variation id (with or without the plugin id prefix).
   * @param string $rawQuery
   *   The raw query string.
   *
   * @return \Drupal\neo_search\SearchPayload
   *   The payload.
   *
   * @throws \Drupal\neo_search\Exception\VariationNotFoundException
   * @throws \Drupal\neo_search\Exception\FloodException
   */
  public function run(string $variationId, string $rawQuery): SearchPayload {
    $settings = $this->resolveVariation($variationId);
    $values = $settings->getValues();
    $settingsCacheability = (new CacheableMetadata())
      ->addCacheTags($this->getSettingsCacheTags($settings));

    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    $query = trim((string) preg_replace('/\s+/u', ' ', $rawQuery));
    $query = mb_substr($query, 0, max(1, (int) $values['max_chars']));

    // Length gate: never hits flood or the backend.
    if (mb_strlen($query) < (int) $values['min_chars']) {
      return new SearchPayload(
        $this->buildEnvelope($settings->id(), $query, [], 0, $values, NULL, $settingsCacheability),
        $settingsCacheability
      );
    }

    // Cache lookup. Context keys personalize the entry exactly like render
    // caching does: users sharing all listed contexts share an entry; users
    // differing in any (e.g. node grants) never collide.
    $contexts = array_values(array_unique(array_merge(
      ['languages:language_interface'],
      array_values($values['cache_contexts'] ?? [])
    )));
    // Configured contexts may reference providers of modules that are not
    // installed (e.g. user.node_grants without node); drop unknown ones.
    $contexts = array_values(array_filter($contexts, function (string $token): bool {
      [$root] = explode(':', $token, 2);
      return in_array($root, $this->validCacheContexts, TRUE);
    }));
    $contextKeys = $this->cacheContextsManager->convertTokensToKeys($contexts)->getKeys();
    $cid = implode(':', [
      'neo_search',
      $settings->id(),
      $langcode,
      // The envelope carries rendered markup, so it is theme-dependent: a theme
      // that ships its own search_quick copy produces different HTML for the
      // same query.
      $this->themeManager->getActiveTheme()->getName(),
      hash('sha256', mb_strtolower($query)),
      hash('sha256', implode(':', $contextKeys)),
    ]);
    if ($cached = $this->cache->get($cid)) {
      return new SearchPayload($cached->data['payload'], $cached->data['cacheability']);
    }

    // Flood control protects the expensive path only (cache hits are cheap).
    if (!empty($values['flood_enabled'])) {
      $threshold = (int) $values['flood_threshold'];
      $window = (int) $values['flood_window'];
      if (!$this->flood->isAllowed(self::FLOOD_NAME, $threshold, $window)) {
        throw new FloodException($window);
      }
      $this->flood->register(self::FLOOD_NAME, $window);
    }

    $request = new SearchRequest(
      query: $query,
      rawQuery: $rawQuery,
      variationId: $settings->id(),
      limit: max(1, (int) $values['limit']),
      langcode: $langcode,
      account: $this->currentUser->getAccount(),
    );

    $queryEvent = new NeoSearchQueryEvent($request, $values);
    $this->eventDispatcher->dispatch($queryEvent, NeoSearchQueryEvent::EVENT_NAME);
    $request = $queryEvent->request;

    if ($queryEvent->results !== NULL) {
      $results = $queryEvent->results;
    }
    else {
      /** @var \Drupal\neo_search\NeoSearchPluginInterface $plugin */
      $plugin = $this->pluginManager->createInstance($values['plugin'], $values['plugin_settings'] ?? []);
      $results = $plugin->search($request);
    }

    $groups = $this->groupResults($results, $values);
    $results->setGroups($groups);

    $resultsEvent = new NeoSearchResultsEvent($request, $results, $values);
    $this->eventDispatcher->dispatch($resultsEvent, NeoSearchResultsEvent::EVENT_NAME);
    $results = $resultsEvent->results;

    $cacheability = CacheableMetadata::createFromObject($results)
      ->merge(CacheableMetadata::createFromObject($resultsEvent))
      ->merge($settingsCacheability);
    $cacheability->addCacheContexts($contexts);
    // The envelope carries rendered markup, so it invalidates with everything
    // else that does. Without `rendered` the entry outlives a template change:
    // the neo_build dev watcher and `drush neo-cc` invalidate that tag but do
    // not touch this bin, so an edited search_quick.twig kept serving stale
    // HTML until a full `drush cr`.
    $cacheability->addCacheTags(['neo_search', 'rendered']);

    if (!empty($values['render_items'])) {
      $this->renderItems($results, $request, $values, $cacheability);
    }

    $groups = $results->getGroups();
    $total = 0;
    foreach ($groups as $group) {
      $total += count($group['items']);
    }

    $allResultsUrl = $this->buildAllResultsUrl($values, $request, $cacheability);
    $envelope = $this->buildEnvelope($settings->id(), $query, $groups, $total, $values, $allResultsUrl, $cacheability);

    $this->cache->set($cid, [
      'payload' => $envelope,
      'cacheability' => $cacheability,
    ], Cache::PERMANENT, $cacheability->getCacheTags());

    return new SearchPayload($envelope, $cacheability);
  }

  /**
   * Resolves a variation id to a settings plugin, throwing when unknown.
   *
   * @return \Drupal\neo_settings\Plugin\SettingsInterface
   *   The settings plugin.
   */
  protected function resolveVariation(string $variationId) {
    // ::get() is total and handles the short form, so this only has to supply
    // the failure policy the repository deliberately leaves to callers.
    return $this->settingsRepository->get($variationId, FALSE)
      ?? throw new VariationNotFoundException(sprintf('Unknown neo_search variation "%s".', $variationId));
  }

  /**
   * Gets the configured browser/proxy max age for a variation.
   */
  public function getHttpMaxAge(string $variationId): ?int {
    try {
      $settings = $this->resolveVariation($variationId);
    }
    catch (VariationNotFoundException) {
      return NULL;
    }
    return (int) $settings->getValue('http_max_age');
  }

  /**
   * Gets the config cache tags for a resolved settings instance.
   */
  public function getSettingsCacheTags(SettingsInterface $settings): array {
    $tags = ['config:neo_search.settings'];
    if ($settings->isVariation()) {
      $tags[] = 'config:neo_settings.variation.' . $settings->id();
    }
    return $tags;
  }

  /**
   * Groups the result set per settings, applying the group overrides table.
   *
   * @return array
   *   Groups: ['id', 'label', 'weight', 'items' => SearchResultItem[]].
   */
  protected function groupResults(SearchResultSet $results, array $values): array {
    $items = array_values($results->getItems());
    usort($items, fn (SearchResultItem $a, SearchResultItem $b) => $a->weight <=> $b->weight);

    // Derive a source key per item.
    $groupBy = $values['group_by'] ?? 'none';
    $sourceKeys = [];
    foreach ($items as $index => $item) {
      $sourceKeys[$index] = $item->groupId ?? match ($groupBy) {
        'bundle' => $item->bundle ?? $item->entityTypeId ?? 'other',
        'entity_type' => $item->entityTypeId ?? 'other',
        default => '',
      };
    }

    // Configured override groups claim their mapped source keys.
    $groups = [];
    $claimed = [];
    $overrides = $values['groups'] ?? [];
    usort($overrides, fn (array $a, array $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
    foreach ($overrides as $override) {
      $map = $override['map'] ?? [];
      $matched = [];
      foreach ($items as $index => $item) {
        if (isset($claimed[$index])) {
          continue;
        }
        if (in_array($sourceKeys[$index], $map, TRUE) || $sourceKeys[$index] === $override['id']) {
          $claimed[$index] = TRUE;
          $matched[] = $item;
        }
      }
      if (!empty($override['hidden'])) {
        continue;
      }
      if ($matched) {
        $groups[] = [
          'id' => $override['id'],
          'label' => (string) $override['label'],
          'weight' => (int) ($override['weight'] ?? 0),
          'items' => $matched,
        ];
      }
    }

    // Remaining items form auto groups in order of first appearance.
    if (($values['groups_other'] ?? 'visible') === 'visible') {
      $auto = [];
      foreach ($items as $index => $item) {
        if (isset($claimed[$index])) {
          continue;
        }
        $key = $sourceKeys[$index];
        $auto[$key]['id'] = $key === '' ? 'results' : $key;
        $auto[$key]['label'] = $this->deriveGroupLabel($key, $item, $groupBy);
        $auto[$key]['weight'] = count($overrides) + count($auto);
        $auto[$key]['items'][] = $item;
      }
      foreach ($auto as $group) {
        $groups[] = $group;
      }
    }

    $limit = (int) ($values['group_limit'] ?? 0);
    if ($limit > 0) {
      foreach ($groups as &$group) {
        $group['items'] = array_slice($group['items'], 0, $limit);
      }
    }

    return $groups;
  }

  /**
   * Derives a human label for an auto group.
   */
  protected function deriveGroupLabel(string $key, SearchResultItem $item, string $groupBy): string {
    if ($key === '') {
      return '';
    }
    if ($groupBy === 'bundle' && $item->entityTypeId) {
      $info = $this->bundleInfo->getBundleInfo($item->entityTypeId);
      if (isset($info[$key])) {
        return (string) $info[$key]['label'];
      }
    }
    if ($groupBy === 'entity_type') {
      $definition = $this->entityTypeManager->getDefinition($key, FALSE);
      if ($definition) {
        return (string) $definition->getLabel();
      }
    }
    return Html::escape(ucfirst(str_replace(['_', '-'], ' ', $key)));
  }

  /**
   * Renders each entity-backed item in its configured view mode.
   */
  protected function renderItems(SearchResultSet $results, SearchRequest $request, array $values, CacheableMetadata $cacheability): void {
    $viewModes = $values['view_modes'] ?? [];
    foreach ($results->getGroups() as $group) {
      foreach ($group['items'] as $item) {
        if (!$item->entityTypeId || !$item->entityId || empty($viewModes[$item->entityTypeId])) {
          continue;
        }
        $storage = $this->entityTypeManager->getStorage($item->entityTypeId);
        $entity = $storage->load($item->entityId);
        if (!$entity || !$entity->access('view', $request->account)) {
          continue;
        }
        $build = $this->entityTypeManager->getViewBuilder($item->entityTypeId)
          ->view($entity, $viewModes[$item->entityTypeId], $request->langcode);
        $html = (string) $this->renderer->renderInIsolation($build);
        // Cacheability bubbles into the response; #attached is deliberately
        // dropped from the payload — view modes used here must not depend on
        // bespoke libraries (attach them globally if needed). ::merge() clones
        // rather than mutates, so the accumulator has to be refined in place.
        $cacheability->addCacheableDependency(BubbleableMetadata::createFromRenderArray($build));
        $item->rendered = $html;
      }
    }
  }

  /**
   * Builds the all-results URL, if configured.
   */
  protected function buildAllResultsUrl(array $values, SearchRequest $request, CacheableMetadata $cacheability): ?string {
    $type = $values['all_results_type'] ?? 'none';
    if ($type === 'url' && !empty($values['all_results_url'])) {
      return str_replace('[query]', rawurlencode($request->rawQuery), $values['all_results_url']);
    }
    if ($type === 'view' && !empty($values['all_results_view']) && str_contains($values['all_results_view'], ':')) {
      [$viewId, $displayId] = explode(':', $values['all_results_view'], 2);
      $storage = $this->entityTypeManager->getStorage('view');
      /** @var \Drupal\views\ViewEntityInterface|null $view */
      $view = $storage ? $storage->load($viewId) : NULL;
      if (!$view) {
        return NULL;
      }
      $cacheability->addCacheableDependency($view);
      $display = $view->getDisplay($displayId);
      $default = $view->getDisplay('default');
      $path = $display['display_options']['path'] ?? NULL;
      if (!$path) {
        return NULL;
      }
      $filters = ($display['display_options']['filters'] ?? []) ?: ($default['display_options']['filters'] ?? []);
      $identifier = NULL;
      foreach ($filters as $filter) {
        if (($filter['plugin_id'] ?? '') === 'search_api_fulltext' && !empty($filter['exposed'])) {
          $identifier = $filter['expose']['identifier'] ?? NULL;
          break;
        }
      }
      if (!$identifier) {
        return NULL;
      }
      return '/' . ltrim($path, '/') . '?' . rawurlencode($identifier) . '=' . rawurlencode($request->rawQuery);
    }
    return NULL;
  }

  /**
   * Builds the JSON envelope.
   *
   * The panel markup travels as one pre-rendered fragment: the client injects
   * it and re-indexes the [role=option] elements, so the per-item data does not
   * need to be on the wire as well. What stays is what the JS itself reads —
   * the aria-live announcer needs the count and the empty message.
   */
  protected function buildEnvelope(string $settingsId, string $query, array $groups, int $total, array $values, ?string $allResultsUrl, CacheableMetadata $cacheability): array {
    $empty = $total === 0;
    $emptyMessage = $empty ? (string) $values['empty_message'] : NULL;
    return [
      'query' => $query,
      'variation' => $settingsId,
      'total' => $total,
      'empty' => $empty,
      'emptyMessage' => $emptyMessage,
      'html' => $this->renderPanel($query, $groups, $total, $values, $allResultsUrl, $emptyMessage, $cacheability),
    ];
  }

  /**
   * Renders the panel body through the variation's search_quick component.
   *
   * Two routes to the same markup, chosen by the one `component` setting. A
   * component instance renders through its neo_component entity, so its
   * configuration — colour scheme, access rules, anything wired on the manage
   * screen — applies, and the search results are laid over the top because they
   * are request data its value providers can know nothing about. A plain
   * component renders the SDC directly, the zero-configuration default.
   */
  protected function renderPanel(string $query, array $groups, int $total, array $values, ?string $allResultsUrl, ?string $emptyMessage, CacheableMetadata $cacheability): string {
    $groupProps = [];
    foreach ($groups as $group) {
      $groupProps[] = [
        'id' => (string) $group['id'],
        'label' => (string) $group['label'],
        'items' => array_map(fn (SearchResultItem $item) => $item->toProps(), array_values($group['items'])),
      ];
    }
    $showAll = $allResultsUrl && $total >= (int) ($values['all_results_min'] ?? 0);
    $props = [
      'display' => (string) ($values['display'] ?? 'list'),
      'columns' => (int) ($values['columns'] ?? 3),
      'query' => $query,
      'total' => $total,
      'empty' => $total === 0,
      'groups' => $groupProps,
      'all_results_url' => $showAll ? $allResultsUrl : NULL,
      'all_results_label' => (string) ($values['all_results_label'] ?? ''),
      'results_label' => $total > 0 ? str_replace('[query]', $query, (string) $values['results_label']) : NULL,
      'empty_message' => $emptyMessage,
    ];

    $entity = $this->resolveComponentEntity($values, $cacheability);
    if ($entity) {
      $build = $entity->toRenderable();
      // Union, not merge: our keys win, and every prop the entity resolved that
      // we do not supply — `attributes` carrying the scheme class, neoUuid, any
      // style prop — survives untouched.
      $build['#props'] = $props + ($build['#props'] ?? []);
    }
    else {
      $build = [
        '#type' => 'component',
        '#component' => $this->resolveComponentId($values),
        '#props' => $props,
      ];
    }

    $html = (string) $this->renderer->renderInIsolation($build);
    // Same contract as ::renderItems() — cacheability bubbles into the
    // response, #attached does not. A search_quick component must not declare
    // its own libraries; the panel's assets are attached page-wide.
    $cacheability->addCacheableDependency(BubbleableMetadata::createFromRenderArray($build));
    return $html;
  }

  /**
   * Resolves the configured component instance, if the setting names one.
   *
   * An SDC plugin id is always "provider:name" and a config entity id never
   * contains a colon, so the one setting can carry either without a second key
   * to keep in sync. Returns NULL for "render the component directly", which
   * covers a plain component id, an instance deleted since, and a disabled one.
   * Only the deleted case is worth a log line; the others are deliberate.
   */
  protected function resolveComponentEntity(array $values, CacheableMetadata $cacheability): ?ComponentInterface {
    $id = trim((string) ($values['component'] ?? ''));
    if ($id === '' || str_contains($id, ':')) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('neo_component');
    // The list is cheap to depend on and makes a newly created entity show up
    // without waiting for the search cache to expire.
    $cacheability->addCacheTags($storage->getEntityType()->getListCacheTags());
    /** @var \Drupal\neo_alchemist\ComponentInterface|null $entity */
    $entity = $storage->load($id);
    if (!$entity) {
      $this->logger->warning('The neo_search component instance "@id" does not exist; rendering "@fallback" instead.', [
        '@id' => $id,
        '@fallback' => self::DEFAULT_COMPONENT,
      ]);
      return NULL;
    }
    $cacheability->addCacheableDependency($entity);
    return $entity->status() ? $entity : NULL;
  }

  /**
   * Resolves the component to render, falling back when it has gone missing.
   *
   * A theme copy can be deleted or renamed long after the variation was
   * configured; a quick search that silently reverts to the shipped default is
   * a better failure than a fatal on every keystroke.
   */
  protected function resolveComponentId(array $values): string {
    $id = trim((string) ($values['component'] ?? ''));
    // A colon-free value named a component instance, which
    // resolveComponentEntity() has already handled by the time this runs.
    if ($id === '' || !str_contains($id, ':') || $id === self::DEFAULT_COMPONENT) {
      return self::DEFAULT_COMPONENT;
    }
    if ($this->componentManager->hasDefinition($id)) {
      return $id;
    }
    $this->logger->warning('The neo_search component "@id" does not exist; falling back to "@fallback".', [
      '@id' => $id,
      '@fallback' => self::DEFAULT_COMPONENT,
    ]);
    return self::DEFAULT_COMPONENT;
  }

}
