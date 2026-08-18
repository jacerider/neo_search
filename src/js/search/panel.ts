import type { NeoSearchConfig, NeoSearchEnvelope } from './types';

/**
 * The results panel: DOM building, placement, and option bookkeeping.
 *
 * The panel is appended to document.body — never next to the input — so it
 * survives overflow-hidden / animated ancestors (e.g. a sliding header
 * search container).
 */
export default class NeoSearchPanel {

  protected static counter = 0;

  input: HTMLInputElement;
  config: NeoSearchConfig;
  id: string;
  element: HTMLElement | null = null;
  listbox: HTMLElement | null = null;
  announcer: HTMLElement | null = null;
  options: HTMLElement[] = [];
  isOpen = false;
  protected repositionFrame = 0;
  protected onReposition = () => {
    if (this.repositionFrame) {
      return;
    }
    this.repositionFrame = requestAnimationFrame(() => {
      this.repositionFrame = 0;
      this.reposition();
    });
  };

  constructor(input: HTMLInputElement, config: NeoSearchConfig) {
    this.input = input;
    this.config = config;
    this.id = `neo-search-panel-${++NeoSearchPanel.counter}`;
  }

  /**
   * Creates the panel skeleton on first use.
   */
  protected ensureElement(): HTMLElement {
    if (this.element) {
      return this.element;
    }
    const element = document.createElement('div');
    element.className = `neo-search-panel neo-search-panel--${this.config.display}`;
    element.id = this.id;
    element.hidden = true;
    // Keep focus on the input while interacting with the panel; links still
    // receive their click.
    element.addEventListener('pointerdown', (e) => e.preventDefault());

    const listbox = document.createElement('div');
    listbox.className = 'neo-search-panel__listbox';
    listbox.setAttribute('role', 'listbox');
    listbox.id = `${this.id}-listbox`;
    element.appendChild(listbox);

    const announcer = document.createElement('div');
    announcer.className = 'visually-hidden';
    announcer.setAttribute('aria-live', 'polite');
    element.appendChild(announcer);

    document.body.appendChild(element);
    this.element = element;
    this.listbox = listbox;
    this.announcer = announcer;
    return element;
  }

  /**
   * Builds a loader node: the site-configured neo_loader throbber, if present.
   *
   * neo_loader publishes the fully rendered, color-resolved markup in
   * drupalSettings.neoLoader.markup and attaches its CSS page-wide — consumed
   * here with no module coupling. The wrapper div and its inline style are
   * load-bearing (the animation colors from --loader-text). Deliberately NOT
   * using Drupal.behaviors.neoLoader.show()/hide() or .ajax-progress* classes:
   * hide() is document-global and core AJAX would tear our loader out.
   */
  protected buildLoaderNode(): HTMLElement {
    const markup = drupalSettings.neoLoader?.markup;
    if (typeof markup === 'string' && markup) {
      const host = document.createElement('div');
      host.innerHTML = markup;
      const loader = host.firstElementChild;
      if (loader instanceof HTMLElement) {
        loader.setAttribute('aria-hidden', 'true');
        return loader;
      }
    }
    const spinner = document.createElement('div');
    spinner.className = 'neo-search-panel__spinner';
    spinner.setAttribute('aria-hidden', 'true');
    return spinner;
  }

  /**
   * Shows the loading state.
   *
   * Empty panel: a centered loader block, panel opened. Panel with results:
   * results stay visible, dimmed under a small overlay — no layout jump.
   */
  showLoading(): void {
    const element = this.ensureElement();
    const listbox = this.listbox as HTMLElement;
    if (listbox.querySelector('.neo-search-panel__loading')) {
      return;
    }
    listbox.setAttribute('aria-busy', 'true');
    if (this.announcer) {
      this.announcer.textContent = Drupal.t('Searching…');
    }
    if (!this.options.length && !listbox.childElementCount) {
      const block = document.createElement('div');
      block.className = 'neo-search-panel__loading';
      block.setAttribute('role', 'presentation');
      block.appendChild(this.buildLoaderNode());
      const label = document.createElement('span');
      label.textContent = Drupal.t('Searching…');
      block.appendChild(label);
      listbox.appendChild(block);
      this.open();
      return;
    }
    if (!element.querySelector('.neo-search-panel__loading-overlay')) {
      element.classList.add('is-loading');
      const overlay = document.createElement('div');
      overlay.className = 'neo-search-panel__loading-overlay';
      overlay.setAttribute('role', 'presentation');
      overlay.appendChild(this.buildLoaderNode());
      element.appendChild(overlay);
    }
  }

  /**
   * Clears the loading state.
   */
  hideLoading(): void {
    if (!this.element) {
      return;
    }
    this.element.classList.remove('is-loading');
    this.element.querySelector('.neo-search-panel__loading-overlay')?.remove();
    this.listbox?.querySelector('.neo-search-panel__loading')?.remove();
    this.listbox?.removeAttribute('aria-busy');
  }

  /**
   * Renders an envelope into the panel.
   *
   * The body is built server-side by the search_quick component — layout,
   * grouping and every class live in that twig, not here. All this has to do is
   * inject it and take ownership of the option bookkeeping the component cannot
   * do: ids have to be minted client-side because one cached fragment may be
   * injected into more than one panel on the page.
   */
  render(envelope: NeoSearchEnvelope): void {
    this.ensureElement();
    this.hideLoading();
    const listbox = this.listbox as HTMLElement;
    // Server-rendered by Drupal's render pipeline; trusted.
    listbox.innerHTML = envelope.html || '';

    this.options = Array.from(listbox.querySelectorAll<HTMLElement>('[role="option"]'));
    this.options.forEach((option, index) => {
      option.id = `${this.id}-option-${index}`;
      option.setAttribute('aria-selected', 'false');
      option.tabIndex = -1;
    });

    if (this.announcer) {
      this.announcer.textContent = envelope.empty
        ? (envelope.emptyMessage || '')
        : Drupal.formatPlural(envelope.total, '1 result available', '@count results available');
    }
  }

  /**
   * Opens the panel.
   */
  open(): void {
    const element = this.ensureElement();
    if (this.isOpen) {
      this.reposition();
      return;
    }
    element.hidden = false;
    this.isOpen = true;
    this.reposition();
    window.addEventListener('resize', this.onReposition, { passive: true });
    window.addEventListener('scroll', this.onReposition, { passive: true, capture: true });
  }

  /**
   * Closes the panel.
   */
  close(): void {
    if (!this.isOpen || !this.element) {
      return;
    }
    this.element.hidden = true;
    this.isOpen = false;
    window.removeEventListener('resize', this.onReposition);
    window.removeEventListener('scroll', this.onReposition, { capture: true });
  }

  /**
   * Positions the panel below its anchor.
   */
  reposition(): void {
    if (!this.element || !this.isOpen) {
      return;
    }
    const element = this.element;
    if (this.config.display === 'cards') {
      // Horizontal placement comes from the stylesheet (Drupal displacement
      // variables); only the vertical position is computed.
      const anchor = (this.config.panelAnchor && document.querySelector(this.config.panelAnchor)) || this.input;
      const rect = anchor.getBoundingClientRect();
      element.style.top = `${rect.bottom + window.scrollY}px`;
      return;
    }
    const displace = this.getDisplaceOffsets();
    const rect = this.input.getBoundingClientRect();
    element.style.minWidth = `${rect.width}px`;
    element.style.maxWidth = `${Math.max(280, window.innerWidth - displace.left - displace.right - 16)}px`;
    element.style.top = `${rect.bottom + window.scrollY}px`;
    let left = rect.left + window.scrollX;
    // Keep the panel inside the displacement-free viewport; right-align to
    // the input if needed.
    const width = element.offsetWidth;
    const minLeft = window.scrollX + displace.left + 8;
    const maxRight = window.scrollX + window.innerWidth - displace.right - 8;
    if (left + width > maxRight) {
      left = Math.max(minLeft, rect.right + window.scrollX - width);
    }
    element.style.left = `${Math.max(minLeft, left)}px`;
    element.style.right = 'auto';
  }

  /**
   * Reads Drupal's displacement offsets (fixed toolbar, off-canvas, …).
   */
  protected getDisplaceOffsets(): { top: number; right: number; left: number } {
    const style = getComputedStyle(document.documentElement);
    const read = (name: string): number => parseFloat(style.getPropertyValue(name)) || 0;
    return {
      top: read('--drupal-displace-offset-top'),
      right: read('--drupal-displace-offset-right'),
      left: read('--drupal-displace-offset-left'),
    };
  }

  /**
   * Removes the panel from the document.
   */
  destroy(): void {
    this.close();
    this.element?.remove();
    this.element = null;
    this.listbox = null;
    this.announcer = null;
    this.options = [];
  }

}
