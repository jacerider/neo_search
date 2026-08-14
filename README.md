# Neo | Search

Configurable quick search / typeahead bound to text inputs. As a user types,
results are fetched from a permission-aware JSON endpoint and rendered below
the input — either as a dropdown list anchored to the input or as a full-width
panel of grouped cards.

The module also ships the **`list_search`** Alchemist component (a flat
search-results page body) as an install scaffold, plus a turnkey provisioning
command for the full search stack. The setup command copies the component
into your theme (shadcn-style) so each site fully owns and can restyle its
markup — an existing theme copy is never overwritten.

## Full search page (turnkey)

```
drush neo:search:setup -y
```

One command provisions everything a site's search story needs, detecting what
already exists (re-running is a no-op): the search_api index is chosen (or
prompted), index footguns are fixed with consent (datasources set to index no
languages, missing highlight processor, missing bundle-property fields for
type badges), a headless `search` view is generated from a shipped template
(full pager, tag-based cache, exposed `?s=` fulltext filter, per-datasource
type-label fields merged for the component's badge), the `list_search`
component is copied into your theme (customize it at
`<theme>/components/list_search`; re-runs never touch your copy), its saved
binding is created against `<theme>:list_search`, an Alchemist-owned node
takes `/search` (via
`neo:alchemist:views-page` when a views page display owns the path), the
quick-search variation is created, and permissions (`use neo_search`, plus
`view vocabulary labels` when terms are indexed — vocabulary labels are
access-checked when badges render) are granted. Site builders then style the
page through the node's normal Layout editor.

## Configuration

`/admin/config/neo/search` — base settings plus named **variations** (the
neo_settings pattern). Each variation is one quick search instance:

- **Binding**: CSS selectors of the inputs to enhance (one per line), an
  optional `matchMedia` breakpoint.
- **Backend**: a `NeoSearch` plugin — `search_api` (pick index, fulltext
  fields, datasources, bundles, match mode, excerpts) or `entity_label`
  (dependency-free entity query fallback).
- **Results**: limit, group-by (none / bundle / entity type), per-group limit,
  and a **group overrides table** to rename, reorder, merge (map several
  source keys into one group), or hide groups without code.
- **Display**: `list` (anchored dropdown) or `cards` (full-width mega panel
  under a configurable anchor element such as `header`), columns, min/max
  characters, debounce, optional per-entity-type view-mode rendering.
- **All results link**: derived from a search view (path and exposed filter
  identifier are read from the view — nothing hardcoded), or a custom URL
  with a `[query]` token.

Grant the **Use Neo Search quick search** permission to roles that may query
the endpoint (typically anonymous + authenticated).

## Endpoint

`GET /api/neo/search/{variation}?q={text}` → JSON envelope:

```json
{
  "query": "well per",
  "variation": "neo_search_header",
  "total": 7,
  "empty": false,
  "groups": [
    {
      "id": "services",
      "label": "Services",
      "items": [
        {
          "id": "node:12",
          "label": "Well Permits",
          "url": "/services/well-permits",
          "entityType": "node",
          "bundle": "service",
          "typeLabel": "Service",
          "excerpt": "…<strong>well</strong>…",
          "rendered": null,
          "extra": {}
        }
      ]
    }
  ],
  "allResultsUrl": "/search?s=well%20per",
  "resultsLabel": "Showing results for \"well per\"",
  "emptyMessage": null
}
```

## Altering results programmatically

Two events (see `Drupal\neo_search\Event`):

- `NeoSearchQueryEvent` (`neo_search_query`) — before the backend runs. Swap
  the request or set a result set to skip the backend entirely.
- `NeoSearchResultsEvent` (`neo_search_results`) — after grouping, before
  caching. Inject, remove, or reorder items and groups. Add any cacheability
  your changes depend on.

```php
public function onResults(NeoSearchResultsEvent $event): void {
  $item = new SearchResultItem(id: 'custom:faq', label: 'FAQs', url: '/faq');
  $event->results->addItem($item);
  $groups = $event->results->getGroups();
  $groups[] = ['id' => 'suggested', 'label' => 'Suggested', 'weight' => 50, 'items' => [$item]];
  $event->results->setGroups($groups);
  $event->addCacheTags(['my_module:suggestions']);
}
```

Custom backends: a `#[NeoSearch]` attribute plugin in `Plugin/NeoSearch`
implementing `NeoSearchPluginInterface`. Search API queries are tagged
`neo_search` and `neo_search_{variation}` for `hook_search_api_query_TAG_alter()`.

## Prefix matching ("appl" finds "apple")

Solr matches whole analyzed tokens, so partial words return nothing by
default. The `search_api` plugin's **prefix** match mode switches the query
to the `direct` parse mode and rewrites the last term to
`+term1 +(last OR last*)` — an edismax expression that works against a stock
Solr schema (including Pantheon Solr) with no reindex.

Wildcard queries bypass analysis (no stemming/boost on the wildcard part).
For best relevance on large sites, add an edge-ngram field type to the index
(e.g. `text_edge_und`) and index the title into it; then `direct` matching is
unnecessary and scoring stays natural.

## Frontend

The `neo_search/search` library binds automatically to configured selectors
(no markup changes needed). The panel is appended to `document.body`, so it
works inside overflow-hidden or animated containers.

- ARIA combobox pattern: `role=combobox`, `aria-expanded`,
  `aria-activedescendant`, wrap-around arrow-key navigation, `aria-live`
  result announcements.
- Escape closes the panel first; a second press reaches ancestors (e.g. a
  header slide-out toggle).
- Per-instance debounce, `AbortController`, an in-memory result memo, and
  silent backoff on `429`.
- A loading state when a fetch takes longer than ~300ms: the panel shows the
  site's configured neo_loader throbber (consumed from
  `drupalSettings.neoLoader.markup` — no dependency; a built-in spinner is the
  fallback). First searches open the panel with a centered "Searching…"
  block; refetches dim the existing results under a small overlay.
- CustomEvents on the input (all bubble): `neo-search:request`,
  `neo-search:results`, `neo-search:open`, `neo-search:close`,
  `neo-search:error`, and cancellable `neo-search:select`.
- JS API: `Drupal.neoSearch.get(inputEl)`, `Drupal.neoSearch.closeAll()`.

Theming: `.neo-search-panel*` classes in `src/css/search.css`; override the
CSS custom properties (`--neo-search-z`, `--neo-search-max-height`,
`--neo-search-columns`) or the classes in your theme.

Note: when per-item view-mode rendering is enabled, the rendered markup's
asset libraries are **not** shipped with the JSON payload — use view modes
that need no bespoke JS, or attach those libraries globally.

## Drush

`drush neo:search:query <variation> <text> [--uid=N]` — run a search from the
CLI and print the envelope plus its cache tags/contexts.
