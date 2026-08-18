<?php

declare(strict_types=1);

namespace Drupal\neo_search\Drush\Commands;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\neo_search\SearchSetup;
use Drupal\pathauto\PathautoState;
use Drupal\user\Entity\Role;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drush\Drush;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turnkey provisioning for the full site-search stack.
 *
 * One command sets up everything the neo suite's search story needs on a
 * fresh site: a headless search view over the site's index (with
 * per-datasource type-label fields), the saved search_list component binding,
 * an Alchemist-owned /search node, the quick-search variation, and the
 * permissions. Every step detects existing state first — re-running on a
 * provisioned site is a no-op narration.
 */
final class NeoSearchSetupCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'neo_search.setup')]
    private readonly SearchSetup $setup,
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'entity_field.manager')]
    private readonly EntityFieldManagerInterface $entityFieldManager,
    #[Autowire(service: 'module_handler')]
    private readonly ModuleHandlerInterface $moduleHandler,
    #[Autowire(service: 'uuid')]
    private readonly UuidInterface $uuid,
  ) {
    parent::__construct();
  }

  /**
   * Set up the full site-search stack (view, page, binding, quick search).
   */
  #[CLI\Command(name: 'neo:search:setup', aliases: ['neo-search-setup'])]
  #[CLI\Option(name: 'index', description: 'The search_api index id (defaults to the only/first index, prompted).')]
  #[CLI\Option(name: 'view', description: 'The view id to create when none qualifies (defaults to "search").')]
  #[CLI\Option(name: 'alias', description: 'The search page path (defaults to "/search").')]
  #[CLI\Option(name: 'bundle', description: 'Node bundle for the search page (defaults to "system").')]
  #[CLI\Option(name: 'selector', description: 'CSS selector of the quick-search input (defaults to "#site-search").')]
  #[CLI\Option(name: 'theme', description: 'Theme that receives the search_list component copy (defaults to the default theme).')]
  #[CLI\Option(name: 'skip-page', description: 'Skip the search page node step.')]
  #[CLI\Option(name: 'skip-variation', description: 'Skip the quick-search variation step.')]
  #[CLI\Usage(name: 'drush neo:search:setup -y', description: 'Provision everything with defaults, unattended.')]
  public function setup(
    array $options = [
      'index' => NULL,
      'view' => 'search',
      'alias' => '/search',
      'bundle' => 'system',
      'selector' => '#site-search',
      'theme' => NULL,
      'skip-page' => FALSE,
      'skip-variation' => FALSE,
    ],
  ): int {
    // -- 1. Preflight.
    $missing = array_filter(
      ['search_api', 'views', 'neo_alchemist', 'node', 'path_alias'],
      fn (string $module): bool => !$this->moduleHandler->moduleExists($module)
    );
    if ($missing) {
      $this->io()->error('Missing required modules. Run: drush en ' . implode(' ', $missing) . ' -y');
      return self::EXIT_FAILURE;
    }

    // -- 2. Index.
    $indexes = $this->entityTypeManager->getStorage('search_api_index')->loadMultiple();
    if (!$indexes) {
      $this->io()->error('No search_api index exists. Create one (with a fulltext title field) first, then re-run.');
      return self::EXIT_FAILURE;
    }
    $indexId = $options['index'];
    if ($indexId && !isset($indexes[$indexId])) {
      $this->io()->error(sprintf('Index "%s" does not exist. Available: %s', $indexId, implode(', ', array_keys($indexes))));
      return self::EXIT_FAILURE;
    }
    if (!$indexId) {
      $indexId = count($indexes) === 1
        ? array_key_first($indexes)
        : $this->io()->choice('Which index should the search use?', array_map(fn ($i) => (string) $i->label(), $indexes), array_key_first($indexes));
    }
    /** @var \Drupal\search_api\IndexInterface $index */
    $index = $indexes[$indexId];
    $this->io()->section(sprintf('Index: %s (%s)', $index->label(), $indexId));

    // -- 3. Index readiness (advisory, each prompted).
    $reindexNeeded = FALSE;
    foreach ($index->getDatasources() as $datasourceId => $datasource) {
      $config = $datasource->getConfiguration();
      if (isset($config['languages']) && empty($config['languages']['default']) && empty($config['languages']['selected'])) {
        $this->io()->warning(sprintf('Datasource "%s" is set to index ONLY the selected languages, with none selected — zero items will ever be tracked.', $datasourceId));
        if ($this->io()->confirm(sprintf('Fix "%s" to index all languages?', $datasourceId))) {
          $config['languages'] = ['default' => TRUE, 'selected' => []];
          $datasource->setConfiguration($config);
          $index->save();
          $reindexNeeded = TRUE;
          $this->io()->success('Fixed. The tracker must be rebuilt (see summary).');
        }
      }
    }
    if (!isset($index->getProcessors()['highlight'])) {
      if ($this->io()->confirm('The "highlight" processor is not enabled — excerpts will be empty. Enable it?')) {
        $processor = \Drupal::service('search_api.plugin_helper')->createProcessorPlugin($index, 'highlight', ['excerpt' => TRUE]);
        $index->addProcessor($processor);
        $index->save();
        $this->io()->success('Highlight processor enabled (query-time; no reindex needed).');
      }
      else {
        $this->io()->note('Skipped — result excerpts will render empty.');
      }
    }
    // Type badges need no indexed fields: the binding maps them from bundle
    // info via _entity:bundle_label_page / _entity:icon_page.
    // -- 4. View.
    $existing = $this->setup->findSearchViews($indexId);
    $filterIdentifier = 's';
    if ($existing) {
      // Several views can qualify (result-list components bind their own
      // views on the same index). Preference order: the view the saved
      // binding already uses → the --view option's id → a prompt.
      $viewId = NULL;
      $binding = $this->entityTypeManager->getStorage('neo_component')->load('search_list');
      $boundView = $binding?->get('settings')['props']['items']['plugins']['items']['views']['settings']['view_id'] ?? NULL;
      if ($boundView && isset($existing[$boundView])) {
        $viewId = $boundView;
      }
      elseif (isset($existing[$options['view']])) {
        $viewId = $options['view'];
      }
      elseif (count($existing) === 1) {
        $viewId = array_key_first($existing);
      }
      else {
        $viewId = $this->io()->choice('Several views qualify as the search source — which one?', array_combine(array_keys($existing), array_map(fn (array $info): string => $info['label'], $existing)), array_key_first($existing));
      }
      $filterIdentifier = $existing[$viewId]['identifier'];
      $this->io()->text(sprintf('View: reusing "%s" (exposed filter "?%s=").', $viewId, $filterIdentifier));
    }
    else {
      $viewId = $options['view'];
      if ($this->entityTypeManager->getStorage('view')->load($viewId)) {
        $viewId = $viewId . '_' . $indexId;
        $this->io()->note(sprintf('View id "%s" is taken by a non-qualifying view; using "%s".', $options['view'], $viewId));
      }
      if (!$this->io()->confirm(sprintf('Create search view "%s" over index "%s"?', $viewId, $indexId))) {
        $this->io()->error('A search view is required. Aborting.');
        return self::EXIT_FAILURE;
      }
      $values = $this->setup->buildViewValues($index, $viewId);
      $this->entityTypeManager->getStorage('view')->create($values)->save();
      $this->io()->success(sprintf('Created view "%s" (headless — no page display; full pager; tag-based cache).', $viewId));
    }

    // -- 5. Component: copy the shipped SDC into the theme (site-owned,
    // shadcn-style — an existing copy is never touched).
    $theme = $options['theme'] ?: (string) \Drupal::config('system.theme')->get('default');
    if (!\Drupal::service('theme_handler')->themeExists($theme)) {
      $this->io()->error(sprintf('Theme "%s" is not installed — re-run with --theme=<installed theme>.', $theme));
      return self::EXIT_FAILURE;
    }
    // Both SDCs declare `neo_install: true`, so installing this module already
    // ejected them. Re-run here for the --theme case and to catch a copy that
    // was deleted since; it is idempotent and clears its own caches.
    foreach (\Drupal::service('neo_alchemist.theme_component_installer')->installAll($theme) as $id => $status) {
      $this->io()->text(match ($status) {
        'installed' => sprintf('Component: copied %s into "%s".', $id, $theme),
        'exists' => sprintf('Component: "%s" already has %s — leaving your copy untouched.', $theme, $id),
        default => sprintf('Component: could not install %s — check the log.', $id),
      });
    }
    $sdcId = $theme . ':search_list';

    // -- Binding.
    $result = $this->setup->ensureBinding($sdcId, $viewId, $filterIdentifier);
    $this->io()->text(sprintf('Component binding "search_list": %s.', $result['status']));

    // -- 6. Page.
    $alias = '/' . ltrim((string) $options['alias'], '/');
    if (empty($options['skip-page'])) {
      $this->provisionPage($alias, (string) $options['bundle'], $viewId, $existing[$viewId]['page_displays'] ?? []);
    }

    // -- 7. Variation + permissions.
    if (empty($options['skip-variation'])) {
      $this->provisionVariation((string) $options['selector'], $alias, $filterIdentifier, $indexId, $theme . ':search_quick');
    }
    $this->provisionPermissions();

    // -- 8. Summary.
    $this->io()->section('Summary');
    $notes = [
      sprintf('Search page: %s', $alias),
      sprintf('Customize the results markup at %s/components/search_list (your theme owns the copy).', \Drupal::service('extension.list.theme')->getPath($theme)),
      sprintf('Customize the quick-search panel at %s/components/search_quick — keep role="option" and href on every selectable row or keyboard navigation breaks. Run drush cr after editing.', \Drupal::service('extension.list.theme')->getPath($theme)),
      'Restore either component from its module source with: drush neo:alchemist:eject <id> --force',
      'Style the page via the node\'s Layout tab (component "search_list", locked & provider-bound).',
      'Quick search settings: /admin/config/neo/search',
      'Compile assets if this is the first neo_search install on this site: drush neo:build && npm run deploy',
      'Export config when satisfied: drush config:export',
    ];
    if ($reindexNeeded) {
      array_unshift($notes, sprintf('REINDEX REQUIRED: drush search-api:rebuild-tracker %s && drush search-api:index %s', $indexId, $indexId));
    }
    $this->io()->listing($notes);
    return self::EXIT_SUCCESS;
  }

  /**
   * Ensures a node owns the search page path.
   */
  protected function provisionPage(string $alias, string $bundle, string $viewId, array $pageDisplays): void {
    // An existing views page display on the target path → full conversion via
    // the alchemist command (validation, display removal, diagnostics).
    foreach ($pageDisplays as $displayId => $path) {
      if ('/' . ltrim($path, '/') === $alias) {
        $this->io()->text(sprintf('Page: views display %s:%s owns %s — converting via neo:alchemist:views-page.', $viewId, $displayId, $alias));
        $process = Drush::drush(Drush::aliasManager()->getSelf(), 'neo:alchemist:views-page', [$viewId . ':' . $displayId], ['component' => 'search_list']);
        $process->mustRun($process->showRealtime());
        return;
      }
    }

    $aliasStorage = $this->entityTypeManager->getStorage('path_alias');
    $existing = $aliasStorage->loadByProperties(['alias' => $alias]);
    if ($existing) {
      $aliasEntity = reset($existing);
      $this->io()->text(sprintf('Page: alias %s already exists (%s) — leaving it alone.', $alias, $aliasEntity->getPath()));
      if (preg_match('#^/node/(\d+)$#', $aliasEntity->getPath(), $m)) {
        $node = $this->entityTypeManager->getStorage('node')->load($m[1]);
        $seeded = FALSE;
        if ($node) {
          // The tree column holds a JSON string keyed by component entity id.
          foreach ($node->getFieldDefinitions() as $name => $definition) {
            if ($definition->getType() === 'neo_component_tree' && !$node->get($name)->isEmpty()) {
              $value = $node->get($name)->first()->getValue();
              $tree = is_string($value['tree'] ?? NULL) ? $value['tree'] : json_encode($value['tree'] ?? []);
              $seeded = $seeded || str_contains($tree, 'search_list');
            }
          }
        }
        $this->io()->{$seeded ? 'text' : 'warning'}($seeded
          ? 'The aliased node already places search_list.'
          : 'The aliased node does not appear to place the search_list component — add it via the node\'s Layout tab.');
      }
      return;
    }

    if (!$this->entityTypeManager->getStorage('node_type')->load($bundle)) {
      $this->io()->error(sprintf('Node bundle "%s" does not exist — re-run with --bundle=<bundle carrying a component tree field>.', $bundle));
      return;
    }
    $fieldName = NULL;
    foreach ($this->entityFieldManager->getFieldDefinitions('node', $bundle) as $name => $definition) {
      if ($definition->getType() === 'neo_component_tree') {
        $fieldName = $name;
        break;
      }
    }
    if (!$fieldName) {
      $this->io()->error(sprintf('Bundle "%s" has no component tree field.', $bundle));
      return;
    }
    if (!$this->io()->confirm(sprintf('Create a published %s node at %s seeded with search_list?', $bundle, $alias))) {
      return;
    }
    $pathValue = ['alias' => $alias];
    if ($this->moduleHandler->moduleExists('pathauto') && class_exists(PathautoState::class)) {
      $pathValue['pathauto'] = PathautoState::SKIP;
    }
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => $bundle,
      'title' => 'Search',
      'status' => 1,
      'uid' => 1,
      'path' => $pathValue,
    ]);
    /** @var \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item */
    $item = $node->get($fieldName)->appendItem([]);
    $item->addComponent($this->uuid->generate(), 'search_list');
    $node->save();
    $this->io()->success(sprintf('Created node %d at %s.', $node->id(), $alias));
  }

  /**
   * Ensures a quick-search variation bound to the site's input exists.
   */
  protected function provisionVariation(string $selector, string $alias, string $filterIdentifier, string $indexId, ?string $component = NULL): void {
    $storage = $this->entityTypeManager->getStorage('neo_settings');
    foreach ($storage->loadByProperties(['plugin' => 'neo_search']) as $variation) {
      if (trim((string) ($variation->get('settings')['selectors'] ?? '')) !== '') {
        $this->io()->text(sprintf('Quick search: variation "%s" already binds selectors — skipping.', $variation->id()));
        return;
      }
    }
    // Point the base settings at the chosen index when still on defaults.
    $config = \Drupal::configFactory()->getEditable('neo_search.settings');
    if ($config->get('plugin') !== 'search_api') {
      $config
        ->set('plugin', 'search_api')
        ->set('plugin_settings', [
          'index' => $indexId,
          'fulltext_fields' => [],
          'datasources' => [],
          'bundles' => [],
          'match_mode' => 'prefix',
          'highlight' => TRUE,
        ])
        ->set('group_by', 'bundle')
        ->save();
      $this->io()->text('Quick search: base settings pointed at the search_api backend.');
    }
    $selector = $this->io()->ask('CSS selector of the header search input', $selector);
    if (!$this->io()->confirm(sprintf('Create quick-search variation "neo_search_header" bound to "%s"?', $selector))) {
      return;
    }
    $storage->create([
      'id' => 'neo_search_header',
      'label' => 'Header',
      'plugin' => 'neo_search',
      'status' => TRUE,
      'weight' => 0,
      'visibility' => [],
      'settings' => $this->setup->buildVariationSettings($selector, $alias, $filterIdentifier, $component),
    ])->save();
    $this->io()->success('Variation created. Group the result cards at /admin/config/neo/search/variations.');
  }

  /**
   * Offers the permission grants the search stack needs.
   */
  protected function provisionPermissions(): void {
    // Type badges read bundle-info labels (no access check), so only the
    // endpoint permission is needed.
    $permissions = ['use neo_search'];
    foreach (['anonymous', 'authenticated'] as $roleId) {
      $role = Role::load($roleId);
      if (!$role) {
        continue;
      }
      $needed = array_values(array_filter($permissions, fn (string $p): bool => !$role->hasPermission($p)));
      if ($needed && $this->io()->confirm(sprintf('Grant %s to the %s role?', implode(' + ', $needed), $roleId))) {
        foreach ($needed as $permission) {
          $role->grantPermission($permission);
        }
        $role->save();
      }
    }
  }

}
