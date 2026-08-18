---
name: neo-search
description: Configure and extend neo_search — the quick search / typeahead that binds to text inputs and shows grouped results from a permission-aware JSON endpoint. Use when asked to add or tune a site quick search / autocomplete / typeahead / instant search, bind a search variation to an input, restyle or re-lay-out the quick-search panel / results dropdown (the search_quick SDC), group or reorder quick-search results, inject or alter results programmatically (NeoSearchQueryEvent / NeoSearchResultsEvent), add a NeoSearch backend plugin, or debug the /api/neo/search endpoint, its caching, or prefix matching. NOT for the full /search results page itself (that is a normal search_api view) and NOT for the Solr index/server configuration (search_api_solr).
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
  `NeoSearchResultsEvent` → optional view-mode rendering → **panel render** →
  envelope → **cache store**. Cache bin `neo_search`, CID includes the active
  theme and the resolved cache-context keys (default `user.permissions`,
  `user.node_grants:view`), entries are permanent and invalidated purely by
  cache tags collected from the results (per-entity tags, `{type}_list`,
  `search_api_list:{index}`, config tags).
- **The panel markup is Twig, not JS.** `SearchRunner::renderPanel()` renders
  the variation's `search_quick` SDC and puts the HTML in the envelope's `html`
  key; the client injects it verbatim. The envelope carries no per-item data —
  `{query, variation, total, empty, emptyMessage, html}` only. Default component
  `neo_search:search_quick` (`components/search_quick/`); a theme copy is
  selected per variation in **Display → Panel component**, and a missing id
  falls back to the default with a logged warning.
- **Two render routes, one setting.** `component` carries either an SDC id
  (`front:search_quick`) or a `neo_component` entity id (`search_quick`); they
  are told apart by the colon, since entity ids never have one. An entity id
  renders through that entity's `toRenderable()` with the runtime props unioned
  over it (`$props + $build['#props']`) — its scheme/access/config apply while
  the search results win every key they supply. An SDC id renders
  `#type: component` directly. A deleted or disabled entity falls back and logs. The component is `neo: true`, so it previews at
  `/admin/config/neo/alchemist/preview/<provider>:search_quick` and can back
  such an entity; the side effect is that it also shows in the "add component"
  picker, where it would only ever render its examples.
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
  collide). `panel.ts` builds **no** result markup: `render()` sets
  `listbox.innerHTML = envelope.html`, then collects `[role="option"]` and
  mints per-instance ids (server markup must not, since one cached fragment can
  land in two panels). `drupalSettings` carries behavior only — anything that
  shapes markup (columns, all-results label) is a component prop.

## Common tasks

**Provision the whole search stack on a new site** — `drush neo:search:setup
-y`. Detect-then-act and idempotent: chooses the search_api index, fixes index
footguns with consent (a datasource set to index no languages tracks zero
items; missing highlight processor = empty excerpts; missing bundle-property
fields = no type badges), generates a headless `search` view from
`install/views.search.template.yml` (full pager, `search_api_tag` cache,
exposed `?s=`, per-datasource type-label fields merged into a `nothing`
custom-text field), **copies the `search_list` and `search_quick` SDCs into the
theme** (shadcn-style — the site owns and restyles its copies; never
overwritten on re-run) and binds
`<theme>:search_list`, hands `/search` to an Alchemist-owned node
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

**Restyle or re-lay-out the panel** — do NOT reach for CSS overrides or edit
`panel.ts`. The theme already owns `<theme>/components/search_quick`: both
components declare `neo_install: true`, so neo_alchemist copies them into the
default theme when the module is installed (flipping `neo: false` to `neo: true`
in the copy). Edit that twig — it is wholly the theme's (Tailwind utilities,
house style — see `components/search_list/search_list.twig`).
`drush neo:alchemist:eject neo_search:search_quick --force` restores the module
version. Rules that matter:
- **The contract**: every selectable row needs `role="option"` and an `href`.
  The JS collects them in document order and drives them with the arrow keys;
  break it and keyboard navigation breaks. Nothing else is assumed — no class,
  no wrapper, no ordering.
- Do **not** mint ids in the twig (the fragment is cached and may be injected
  into more than one panel); use `aria-label` over `aria-labelledby`.
- `excerpt` / `rendered` arrive as `Markup` (already Xss-filtered / pipeline
  output) so they print without `|raw`; `label` is plain text — never `|raw`.
- A new `components/` dir or theme copy needs `drush neo:build && npm run
  deploy` before its Tailwind classes compile (neo_alchemist's build subscriber
  adds `components/**/*.{yml,twig}` as a source for every front-end extension).
- The module sources carry `neo: false` **by design** — that is what keeps them
  out of the component picker while staying renderable as SearchRunner's
  fallback. `neo:alchemist:validate` reports them as source templates; do not
  "fix" them to `neo: true` or every site gets two identical picker entries.
- **Editing the twig needs `drush cr`** — the rendered panel is cached in the
  `neo_search` bin.
- Only the chrome is still CSS: `.neo-search-panel*` in `src/css/search.css`
  (positioning, listbox host, loading overlay, spinner).
- `{{ attributes }}` must stay on the single root element — it carries the
  scheme class and anything a component entity adds.

**Recolor or gate the panel without touching markup** — create a `neo_component`
entity for `<theme>:search_quick` at `/admin/config/neo/alchemist/add` and
select it in **Display → Panel component** (component instances and plain
components share that one select). Its scheme prop recolors the
whole panel (every color utility inside re-scopes), and access rules apply.
Runtime results always win over whatever the entity's value providers resolve,
so bindings on the data props are pointless — the entity is for styling and
policy, not content.

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
  must not require bespoke JS. The same holds for the `search_quick` component:
  `#attached` is dropped, so it must declare no libraries of its own.
- Twig edits not showing up → `drush cr` (the panel HTML is cached). New
  classes not applying → `drush neo:build && npm run deploy`.
- Keyboard navigation dead after a theme edit → the markup lost `role="option"`
  or `href` on its rows.
- Rendering an SDC validates **every** module-provided component definition, so
  one invalid component anywhere breaks the panel. This is why the Alchemist-typed
  `search_list` test fixture lives in its own `neo_search_page_test` module.

## Files

- Turnkey setup: `src/SearchSetup.php`, `src/Drush/Commands/NeoSearchSetupCommands.php`,
  `install/views.search.template.yml`
- Components (`neo: false` + `neo_install: true`, copied into the theme at
  install by neo_alchemist): `components/search_quick/`, `components/search_list/`
- Rename migration: `neo_search.install`
- Runner/envelope: `src/SearchRunner.php`, `src/SearchPayload.php`
- Value objects: `src/SearchRequest.php`, `src/SearchResultItem.php`,
  `src/SearchResultSet.php`
- Events: `src/Event/NeoSearchQueryEvent.php`, `src/Event/NeoSearchResultsEvent.php`
- Backends: `src/Plugin/NeoSearch/SearchApiSearch.php`,
  `src/Plugin/NeoSearch/EntityLabelSearch.php`
- Settings: `src/Settings/SearchSettings.php`, `config/schema/neo_search.schema.yml`
- Frontend: `src/js/search.ts`, `src/js/search/instance.ts`,
  `src/js/search/panel.ts`, `src/css/search.css`
- Tests: `tests/src/Kernel/` (runner, component entity, setup), and
  `tests/src/Nightwatch/Tests/searchQuickSmokeTest.js` — the browser guard for
  the JS↔Twig contract (`ddev nightwatch neo_search`). Run it after touching
  the component or the panel JS; it asserts the `role="option"` + `href`
  contract, the client-minted ids, arrow-key traversal with
  `aria-activedescendant`, wrap-around, Enter navigation and the empty state.
- Endpoint: `src/Controller/SearchController.php`, `neo_search.routing.yml`
- Attach/settings emission: `neo_search.module`
