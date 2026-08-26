# Neo | Search

Configurable quick search / typeahead bound to text inputs. As a user types,
results are fetched from a permission-aware JSON endpoint and rendered below
the input — either as a dropdown list anchored to the input or as a full-width
panel of grouped cards.

Both the quick-search panel and the full results page get their markup from
single directory components, so layout and styling live in Twig rather than in
JavaScript or an override stylesheet:

- **`search_quick`** — the typeahead panel body, rendered server-side per query.
- **`search_list`** — a flat search-results page body, placed by an editor.

Both declare `neo_install: true`, so **installing this module copies them into
your default theme and points the quick search at that copy** — you are editing
your own markup from the first request. Your copy is never overwritten;
`drush neo:alchemist:eject <id> --force` restores the module's version if you
want to start over. See [Theming](#theming).

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
type-label fields merged for the component's badge), the theme copies of
`search_list` and `search_quick` are confirmed (they land at install; re-runs
never touch them), the `search_list`
saved binding is created against `<theme>:search_list`, an Alchemist-owned node
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
  characters, debounce, optional per-entity-type view-mode rendering, and the
  **panel component** that renders the results (see [Theming](#theming)).
- **Surround the input** (`list` only): align the panel to a wrapper *around*
  the input instead of the input itself, so the two read as one surface — see
  [Surrounding the input](#surrounding-the-input).
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
  "emptyMessage": null,
  "html": "<div>…<a href=\"/services/well-permits\" role=\"option\">…</a>…</div>"
}
```

`html` is the panel body, rendered server-side through the variation's
`search_quick` component and injected verbatim by the client. The per-result
data is deliberately not on the wire — the markup is the contract, and it is
already cache-tag invalidated server-side and browser-cached via the
variation's max-age. To reach the result data, subscribe to
`NeoSearchResultsEvent` server-side rather than parsing the response.

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
- While the panel is open its placement follows the anchor from a
  requestAnimationFrame loop, so it stays put through anything that moves the
  field without firing scroll or resize — a shrink-on-scroll header animating
  its height after the last scroll event being the common one.
- A loading state when a fetch takes longer than ~300ms: the panel shows the
  site's configured neo_loader throbber (consumed from
  `drupalSettings.neoLoader.markup` — no dependency; a built-in spinner is the
  fallback). First searches open the panel with a centered "Searching…"
  block; refetches dim the existing results under a small overlay.
- CustomEvents on the input (all bubble): `neo-search:request`,
  `neo-search:results`, `neo-search:open`, `neo-search:close`,
  `neo-search:error`, and cancellable `neo-search:select`.
- JS API: `Drupal.neoSearch.get(inputEl)`, `Drupal.neoSearch.closeAll()`.

Note: when per-item view-mode rendering is enabled, the rendered markup's
asset libraries are **not** shipped with the JSON payload — use view modes
that need no bespoke JS, or attach those libraries globally.

## Surrounding the input

By default the `list` panel hangs off the `<input>`'s own box, which leaves it
narrower and indented whenever the field sits inside a styled wrapper — a
rounded pill, an icon, a submit button. **Display → Surround the input** points
the panel at that wrapper instead:

- the panel matches the wrapper's box exactly (`left` and `width`, not a
  min-width the content can outgrow), and starts at its bottom edge;
- the wrapper gets `is-neo-search-open` while the panel is open — including
  during the loading state, which opens the panel without firing
  `neo-search:open`;
- the wrapper gets `--neo-search-overhang` from the **collar cushion** setting,
  so the theme's padding has one source of truth;
- the panel drops its top border and top radius (`.neo-search-panel--surround`).

**Surround wrapper selector** is resolved as the closest matching *ancestor* of
each bound input — unlike the cards **panel anchor**, which is looked up
document-wide. Several inputs can share a variation, and `querySelector` would
hand them all the first match. Empty means the input's closest `<form>`.

The theme draws the collar itself, because the panel is appended to `<body>`
and cannot paint behind an input that sits in a stacking context (a sticky,
z-indexed header region is the usual case). Give the wrapper padding for the
cushion and a surface that matches the panel — border on three sides, no bottom
edge, top corners rounded — shown only when `is-neo-search-open` is present.
Reserve the cushion as real padding rather than a negative-inset overlay, or it
will cover whatever sits beside the field.

If the wrapper lives inside a scheme scope (a dark header, say) while the panel
does not, the two will not match: reset the collar's scope so `bg-default`
resolves the same way it does at body level.

## Theming

The panel splits cleanly in two.

**The chrome is JS + CSS.** The positioned wrapper, the `[role=listbox]` host,
the aria-live announcer and the loading overlay are behavior, styled by
`.neo-search-panel*` in `src/css/search.css`. Override those classes or the CSS
custom properties (`--neo-search-z`, `--neo-search-max-height`) in your theme.

**The results are Twig.** Everything inside the listbox — the results label,
groups, items, empty state and all-results link — comes from the `search_quick`
single directory component, rendered server-side. Your theme already owns a copy
at `<theme>/components/search_quick` — it landed when the module was installed.
Edit it, then:

```
drush cr && npm run deploy
```

The markup is entirely yours: reorder, add thumbnails, wrap items in cards, use
whatever Tailwind you like. Point a variation at a different component under
**Display → Panel component**; the module's own `neo_search:search_quick` stays
available as a fallback if the theme copy is ever deleted.

The theme copy is `neo: true`, so it also works in the Alchemist preview at
`/admin/config/neo/alchemist/preview/front:search_quick` — edit props, flip the
colour scheme, check it at three widths, all against its `examples`. (The module
source is `neo: false` on purpose, so it stays out of the picker; preview the
theme copy, not `neo_search:search_quick`.)

### Rendering through a component instance

**Display → Panel component** lists two kinds of choice, because they are
mutually exclusive:

- a **component** (`front:search_quick`) renders that twig directly — the
  default, and what installing the module selects for you;
- a **component instance** routes through an Alchemist `neo_component` entity, so
  its configuration — colour scheme, access rules, anything wired on its manage
  screen — applies as well. Create one at `/admin/config/neo/alchemist/add`.

The search results come from the request either way; an instance
contributes its styling and policy, not its data, and anything its value
providers resolve for the results props is overwritten.

The two are told apart by shape rather than a second setting: an SDC id is
always `provider:name`, a component instance id never contains a colon. Deleting or
disabling an instance falls back to the shipped component and logs, so
this can never take the quick search down.

Two things to know:

- **The contract**: every selectable row must carry `role="option"` and an
  `href`. After injection the JS collects `[role="option"]` in document order,
  mints the ids, and drives them with the arrow keys. Break that and keyboard
  navigation breaks; nothing else is assumed.
- **The rendered panel is cached**, so run `drush cr` after editing the twig.

An ejected copy is a fork: later improvements to the shipped component will not
reach it. If the configured component goes missing the panel falls back to
`neo_search:search_quick` and logs a warning rather than failing.

After customizing, run `ddev nightwatch neo_search` — the browser suite asserts
the contract, arrow-key traversal, `aria-activedescendant`, wrap-around, Enter
navigation and the empty state, so a restructure that breaks keyboard access
fails a test instead of shipping.

## Drush

- `drush neo:search:query <variation> <text> [--uid=N]` — run a search from the
  CLI and print the envelope plus its cache tags/contexts.
- `drush neo:alchemist:eject [id] [--theme=] [--force]` — re-copy a module
  component into a theme (neo_alchemist; runs automatically at install).
- `drush neo:search:setup` — provision the full search stack (see above).
