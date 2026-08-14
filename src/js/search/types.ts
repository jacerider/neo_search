/**
 * @file
 * Shared types for the neo_search frontend.
 */

export interface NeoSearchItem {
  id: string;
  label: string;
  url: string;
  entityType: string | null;
  bundle: string | null;
  typeLabel: string | null;
  excerpt: string | null;
  rendered: string | null;
  extra: Record<string, unknown>;
}

export interface NeoSearchGroup {
  id: string;
  label: string;
  items: NeoSearchItem[];
}

export interface NeoSearchEnvelope {
  query: string;
  variation: string;
  total: number;
  empty: boolean;
  groups: NeoSearchGroup[];
  allResultsUrl: string | null;
  resultsLabel: string | null;
  emptyMessage: string | null;
}

export interface NeoSearchTexts {
  allResults: string;
}

export interface NeoSearchConfig {
  id: string;
  endpoint: string;
  selectors: string[];
  display: 'list' | 'cards';
  columns: number;
  panelAnchor: string;
  minChars: number;
  maxChars: number;
  debounce: number;
  breakpoint: string;
  texts: NeoSearchTexts;
}
