/**
 * @file
 * Global augmentation for the neo_search frontend API.
 */

declare namespace drupal {

  export namespace Core {

    export interface INeoSearch {
      instances: Map<HTMLElement, unknown>;
      get(el: HTMLElement): unknown;
      closeAll(): void;
    }

  }

  export interface IDrupalStatic {

    neoSearch?: Core.INeoSearch;

  }

  export interface IDrupalSettings {

    neoLoader?: {
      markup?: string;
    };

    neoSearch?: {
      variations: Record<string, {
        endpoint: string;
        selectors: string[];
        display: 'list' | 'cards';
        panelAnchor: string;
        surround: boolean;
        surroundAnchor: string;
        surroundOverhang: number;
        minChars: number;
        maxChars: number;
        debounce: number;
        breakpoint: string;
      }>;
    };

  }
}
