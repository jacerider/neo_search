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
  minChars: number;
  maxChars: number;
  debounce: number;
  breakpoint: string;
}
