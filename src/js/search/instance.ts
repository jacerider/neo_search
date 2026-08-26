import NeoSearchPanel from './panel';
import type { NeoSearchConfig, NeoSearchEnvelope } from './types';

/**
 * A quick search bound to one input: fetching, state, and keyboard handling.
 */
export default class NeoSearchInstance {

  input: HTMLInputElement;
  config: NeoSearchConfig;
  panel: NeoSearchPanel;
  protected memo = new Map<string, NeoSearchEnvelope>();
  protected abort: AbortController | null = null;
  protected debounceTimer = 0;
  protected loaderTimer = 0;
  protected backoffUntil = 0;
  protected activeIndex = -1;
  protected enabled = true;
  protected media: MediaQueryList | null = null;
  protected onMediaChange = (e: MediaQueryListEvent) => {
    this.enabled = e.matches;
    if (!this.enabled) {
      this.close();
    }
  };

  constructor(input: HTMLInputElement, config: NeoSearchConfig) {
    this.input = input;
    this.config = config;
    this.panel = new NeoSearchPanel(input, config);

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', `${this.panel.id}-listbox`);
    input.autocomplete = 'off';

    if (config.breakpoint && window.matchMedia) {
      this.media = window.matchMedia(config.breakpoint);
      this.enabled = this.media.matches;
      this.media.addEventListener('change', this.onMediaChange);
    }

    input.addEventListener('input', this.onInput);
    input.addEventListener('keydown', this.onKeydown);
    input.addEventListener('focus', this.onFocus);
    input.addEventListener('click', this.onClick);
    input.addEventListener('focusout', this.onFocusout);
  }

  protected onInput = (): void => {
    if (!this.enabled) {
      return;
    }
    const query = this.normalize(this.input.value);
    window.clearTimeout(this.debounceTimer);
    if (query.length < this.config.minChars) {
      this.close();
      return;
    }
    this.debounceTimer = window.setTimeout(() => this.fetchResults(query), this.config.debounce);
  };

  protected onFocus = (): void => {
    this.reopen();
  };

  /**
   * Reopens the panel on a click in an input that already holds focus.
   *
   * Focus is not enough of a trigger on its own: Escape (and Tab back into the
   * same field) closes the panel without moving focus, so the next click fires
   * no focus event and the results for an unchanged value stay unreachable
   * until the value itself changes. Clicking a combobox is expected to bring
   * its list back.
   */
  protected onClick = (): void => {
    this.reopen();
  };

  /**
   * Shows the results already held for the current value, if there are any.
   *
   * Memo-only by design — reopening is a display concern, so it must never put
   * a request on the wire for a value the user has not just typed.
   */
  protected reopen(): void {
    if (!this.enabled || this.panel.isOpen) {
      return;
    }
    const query = this.normalize(this.input.value);
    if (query.length < this.config.minChars) {
      return;
    }
    const cached = this.memo.get(query.toLowerCase());
    if (cached) {
      this.show(cached);
    }
  }

  protected onFocusout = (e: FocusEvent): void => {
    const related = e.relatedTarget as Node | null;
    if (related && this.panel.element?.contains(related)) {
      return;
    }
    this.close();
  };

  protected onKeydown = (e: KeyboardEvent): void => {
    if (!this.panel.isOpen) {
      // The keyboard counterpart of onClick: after Escape the input still holds
      // focus, so ArrowDown is the only gesture left that should bring the list
      // back. Everything else stays inert while closed.
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        this.reopen();
      }
      return;
    }
    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        this.setActive(this.activeIndex + 1);
        break;

      case 'ArrowUp': {
        e.preventDefault();
        // activeIndex is -1 while nothing is selected. Stepping straight to -2
        // would wrap to count - 2 — the second-to-last option — so start from
        // one past the end instead, which lands on the last as the combobox
        // pattern expects.
        const from = this.activeIndex < 0 ? this.panel.options.length : this.activeIndex;
        this.setActive(from - 1);
        break;
      }

      case 'Enter': {
        const option = this.panel.options[this.activeIndex];
        if (option) {
          e.preventDefault();
          this.selectOption(option);
        }
        // Without an active option the surrounding form submits normally.
        break;
      }

      case 'Escape':
        // First press closes the panel only; ancestors (e.g. an Alpine
        // slide-out) get the next one.
        e.preventDefault();
        e.stopPropagation();
        this.close();
        break;

      case 'Tab':
        this.close();
        break;
    }
  };

  /**
   * Normalizes a query the same way the server does.
   */
  protected normalize(value: string): string {
    return value.trim().replace(/\s+/g, ' ').substring(0, this.config.maxChars);
  }

  /**
   * Fetches results for a query, aborting any in-flight request.
   */
  protected fetchResults(query: string): void {
    const key = query.toLowerCase();
    const cached = this.memo.get(key);
    if (cached) {
      this.show(cached);
      return;
    }
    if (Date.now() < this.backoffUntil) {
      return;
    }
    this.abort?.abort();
    this.abort = new AbortController();
    // Loading feedback only when the network is actually slow: memo hits
    // render synchronously above, and fast responses cancel this before it
    // ever shows — no flicker.
    window.clearTimeout(this.loaderTimer);
    this.loaderTimer = window.setTimeout(() => this.panel.showLoading(), 300);
    this.input.dispatchEvent(new CustomEvent('neo-search:request', { bubbles: true, detail: { query } }));
    fetch(`${this.config.endpoint}?q=${encodeURIComponent(query)}`, {
      signal: this.abort.signal,
      headers: { Accept: 'application/json' },
    })
      .then((response) => {
        if (response.status === 429) {
          const retry = parseInt(response.headers.get('Retry-After') || '10', 10);
          this.backoffUntil = Date.now() + retry * 1000;
          throw new Error('rate-limited');
        }
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`);
        }
        return response.json() as Promise<NeoSearchEnvelope>;
      })
      .then((envelope) => {
        window.clearTimeout(this.loaderTimer);
        this.panel.hideLoading();
        if (this.memo.size > 50) {
          const oldest = this.memo.keys().next().value;
          if (oldest !== undefined) {
            this.memo.delete(oldest);
          }
        }
        this.memo.set(key, envelope);
        // Only show if the input still matches the response.
        if (this.normalize(this.input.value).toLowerCase() === key) {
          this.show(envelope);
        }
      })
      .catch((error: Error) => {
        window.clearTimeout(this.loaderTimer);
        this.panel.hideLoading();
        if (error.name === 'AbortError') {
          return;
        }
        this.input.dispatchEvent(new CustomEvent('neo-search:error', { bubbles: true, detail: { error } }));
      });
  }

  /**
   * Renders and opens the panel for an envelope.
   */
  protected show(envelope: NeoSearchEnvelope): void {
    this.panel.render(envelope);
    this.activeIndex = -1;
    this.input.removeAttribute('aria-activedescendant');
    if (!this.panel.isOpen) {
      this.panel.open();
      this.input.setAttribute('aria-expanded', 'true');
      this.input.dispatchEvent(new CustomEvent('neo-search:open', { bubbles: true }));
    }
    else {
      this.panel.reposition();
    }
    this.input.dispatchEvent(new CustomEvent('neo-search:results', { bubbles: true, detail: { envelope } }));
  }

  /**
   * Closes the panel.
   */
  close(): void {
    window.clearTimeout(this.debounceTimer);
    window.clearTimeout(this.loaderTimer);
    this.panel.hideLoading();
    this.abort?.abort();
    if (this.panel.isOpen) {
      this.panel.close();
      this.input.setAttribute('aria-expanded', 'false');
      this.input.removeAttribute('aria-activedescendant');
      this.activeIndex = -1;
      this.input.dispatchEvent(new CustomEvent('neo-search:close', { bubbles: true }));
    }
  }

  /**
   * Moves the active option, wrapping at both ends.
   */
  protected setActive(index: number): void {
    const options = this.panel.options;
    if (!options.length) {
      return;
    }
    const count = options.length;
    const next = ((index % count) + count) % count;
    options.forEach((option, i) => {
      option.classList.toggle('is-active', i === next);
      option.setAttribute('aria-selected', i === next ? 'true' : 'false');
    });
    this.activeIndex = next;
    const active = options[next];
    this.input.setAttribute('aria-activedescendant', active.id);
    active.scrollIntoView({ block: 'nearest' });
  }

  /**
   * Selects an option: cancellable event, then navigation.
   */
  selectOption(option: HTMLElement): void {
    const url = option.getAttribute('href') || '';
    const allowed = this.input.dispatchEvent(new CustomEvent('neo-search:select', {
      bubbles: true,
      cancelable: true,
      detail: { url, option },
    }));
    if (allowed && url) {
      window.location.assign(url);
    }
  }

  /**
   * Detaches listeners and removes the panel.
   */
  destroy(): void {
    this.close();
    this.media?.removeEventListener('change', this.onMediaChange);
    this.input.removeEventListener('input', this.onInput);
    this.input.removeEventListener('keydown', this.onKeydown);
    this.input.removeEventListener('focus', this.onFocus);
    this.input.removeEventListener('click', this.onClick);
    this.input.removeEventListener('focusout', this.onFocusout);
    this.panel.destroy();
  }

}
