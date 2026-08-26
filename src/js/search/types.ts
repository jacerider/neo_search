/**
 * @file
 * Shared types for the neo_search frontend.
 */

export interface NeoSearchEnvelope {
  query: string;
  variation: string;
  total: number;
  empty: boolean;
  emptyMessage: string | null;
  /**
   * The panel body, rendered server-side by the search_quick component.
   *
   * Injected verbatim; the per-result data is deliberately not on the wire.
   * Every selectable row within carries role="option" and an href — that is the
   * whole contract between the component and this code.
   */
  html: string;
}

/**
 * Panel behavior only.
 *
 * Anything that shapes the results markup — the column count, the all-results
 * label — is a prop on the server-rendered search_quick component and is
 * deliberately absent here.
 */
export interface NeoSearchConfig {
  id: string;
  endpoint: string;
  selectors: string[];
  display: 'list' | 'cards';
  panelAnchor: string;
  /**
   * List display: align the panel to a wrapper around the input.
   *
   * The panel matches the wrapper's box instead of the bare input's, and the
   * wrapper carries `is-neo-search-open` while the panel shows, so a theme can
   * style it as a collar. The panel stays body-appended either way.
   */
  surround: boolean;
  /** Ancestor selector for the surround wrapper; '' means the closest form. */
  surroundAnchor: string;
  /** Published to the wrapper as --neo-search-overhang, for the theme's use. */
  surroundOverhang: number;
  minChars: number;
  maxChars: number;
  debounce: number;
  breakpoint: string;
}
