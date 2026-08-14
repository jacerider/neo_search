---
name: neo-search
description: Configure and extend neo_search — the quick search / typeahead that binds to text inputs and shows grouped results from a permission-aware JSON endpoint. Use when asked to add or tune a site quick search / autocomplete / typeahead / instant search, bind a search variation to an input, group or reorder quick-search results, inject or alter results programmatically (NeoSearchQueryEvent / NeoSearchResultsEvent), add a NeoSearch backend plugin, or debug the /api/neo/search endpoint, its caching, or prefix matching. NOT for the full /search results page itself (that is a normal search_api view) and NOT for the Solr index/server configuration (search_api_solr).
allowed-tools: Read, Write, Edit, Glob, Grep, Bash
---

# Neo Search

Module: [web/modules/contrib/neo_search/](web/modules/contrib/neo_search/)

Quick search / typeahead: as a user types into a bound input, results are
fetched from `GET /api/neo/search/{variation}?q=…` and shown below the input —
a dropdown `list` anchored to the input, or a full-width `cards` panel of
grouped results under a configured anchor element (e.g. `header`).

## Architecture in one pass

- `SearchRunner` (`neo_search.runner`) orchestrates: resolve variation →
  normalize query → min-length gate → **cache lookup** → flood control →
  `NeoSearchQueryEvent` → backend plugin → grouping (+ overrides table) →
  `NeoSearchResultsEvent` → optional view-mode rendering → envelope →
  **cache store**. Cache bin `neo_search`, CID includes the resolved
  cache-context keys (default `user.permissions`, `user.node_grants:view`),
  entries are permanent and invalidated purely by cache tags collected from
  the results (per-entity tags, `{type}_list`, `search_api_list:{index}`,
  config tags).
- Backends are `#[NeoSearch]` attribute plugins in `Plugin/NeoSearch`
  implementing `NeoSearchPluginInterface::search(SearchRequest): SearchResultSet`.
  Shipped: `search_api` (default; post-load `$entity->access('view')`
  backstop, works without the content_access processor) and `entity_label`
  (dependency-free entity query).
- Config is a neo_settings plugin (`SearchSettings`, admin at
  `/admin/config/neo/search`); each **variation** = one search instance
  (selectors, backend + settings, grouping + overrides, display, texts).
  Stored as `neo_settings.variation.neo_search_*`.
- Frontend (`src/js/search.ts` → `search/instance.ts` + `search/panel.ts`)
  binds by the selectors in `drupalSettings.neoSearch.variations`; the panel
  is appended to `document.body` (immune to overflow-hidden ancestors). ARIA
  combobox, wrap-around arrows, two-step Escape, debounce + AbortController +
  memo Map, silent 429 backoff. Fetches slower than ~300ms show a loading
  state (the site's neo_loader throbber via `drupalSettings.neoLoader.markup`,
  soft-consumed with a built-in spinner fallback — never neo_loader's
  `show()/hide()` API or `.ajax-progress` classes, whose global hide would
  collide).

## Common tasks

**Provision the whole search stack on a new site** — `drush neo:search:setup
-y`. Detect-then-act and idempotent: chooses the search_api index, fixes index
footguns with consent (a datasource set to index no languages tracks zero
items; missing highlight processor = empty excerpts; missing bundle-property
fields = no type badges), generates a headless `search` view from
`install/views.search.template.yml` (full pager, `search_api_tag` cache,
exposed `?s=`, per-datasource type-label fields merged into a `nothing`
custom-text field), **copies the `list_search` SDC from
`install/components/list_search` into the theme** (shadcn-style — the site
owns and restyles its copy; never overwritten on re-run) and binds
`<theme>:list_search`, hands `/search` to an Alchemist-owned node
(delegating to `neo:alchemist:views-page` when a views page display owns the
path), creates the quick-search variation, and grants `use neo_search` (+
`view vocabulary labels` when terms are indexed — vocabulary label rendering
is access-checked). Building blocks live in the `neo_search.setup` service
(`src/SearchSetup.php`) and are kernel-tested.

**Bind a quick search to an input** — create a variation at
`/admin/config/neo/search/variations/add`: selectors `#site-search`, backend
`search_api` (index, match mode `prefix`), group_by `bundle`, display `cards`,
panel anchor `header`, all-results = the search view. Grant `use neo_search`
to anonymous + authenticated. No markup changes are needed.

**Group results into custom cards** — the variation's *Group overrides* table
maps source keys (bundle ids when grouping by bundle) into named groups, with
label/weight/hidden per group; `groups_other` controls unmapped keys.

**Inject or alter results in code** — subscribe to
`NeoSearchResultsEvent::EVENT_NAME` (`neo_search_results`): mutate
`$event->results` (`addItem`, `removeItem`, `setGroups`) and add cacheability
for what you injected (`$event->addCacheTags(...)`). Pre-search alterations
(or replacing the backend entirely): `NeoSearchQueryEvent`. Search API
queries are also tagged `neo_search` / `neo_search_{variation}`.

**Add a backend** — `#[NeoSearch(id: 'my_backend', label: ...)]` class in
`src/Plugin/NeoSearch/` extending `NeoSearchPluginBase`; never bypass access
for `$request->account`; add cache tags/contexts on the returned
`SearchResultSet` covering everything the results depend on.

**React on the client** — CustomEvents on the input (bubbling):
`neo-search:request/results/open/close/error` and cancellable
`neo-search:select` (preventDefault to stop navigation).
`Drupal.neoSearch.get(inputEl)` returns the instance.

## Debugging

- `ddev drush neo:search:query <variation> <text> [--uid=N]` prints the JSON
  envelope plus cache tags/contexts — the fastest way to check matching,
  grouping, and access without a browser.
- Empty results for partial words on Solr → match mode is `direct`, or the
  fulltext field's analyzer has no stemming; set match mode `prefix`
  (rewrites the last term to `+(term OR term*)` via the `direct` parse mode),
  or add an edge-ngram field for proper relevance.
- Stale results → check the entry's tags in the `cache_neo_search` bin; the
  backend must add list tags for adds/deletes (`{entity_type}_list`).
- 403 from the endpoint → the `use neo_search` permission is missing on the
  role. 404 → unknown variation id (use the full id, e.g.
  `neo_search_header`, or the short suffix `header`).
- Per-item `rendered` HTML ships no asset libraries — view modes used there
  must not require bespoke JS.

## Files

- Turnkey setup: `src/SearchSetup.php`, `src/Drush/Commands/NeoSearchSetupCommands.php`,
  `install/views.search.template.yml`
- Results-page component scaffold (copied into the theme by setup):
  `install/components/list_search/`
- Runner/envelope: `src/SearchRunner.php`, `src/SearchPayload.php`
- Value objects: `src/SearchRequest.php`, `src/SearchResultItem.php`,
  `src/SearchResultSet.php`
- Events: `src/Event/NeoSearchQueryEvent.php`, `src/Event/NeoSearchResultsEvent.php`
- Backends: `src/Plugin/NeoSearch/SearchApiSearch.php`,
  `src/Plugin/NeoSearch/EntityLabelSearch.php`
- Settings: `src/Settings/SearchSettings.php`, `config/schema/neo_search.schema.yml`
- Frontend: `src/js/search.ts`, `src/js/search/instance.ts`,
  `src/js/search/panel.ts`, `src/css/search.css`
- Endpoint: `src/Controller/SearchController.php`, `neo_search.routing.yml`
- Attach/settings emission: `neo_search.module`
