/**
 * @file
 * Smoke tests for the quick-search panel against the running site.
 *
 * These deliberately do NOT call drupalInstall(): a throwaway site has no
 * search index, no variation and no content to match, which is most of what
 * makes this path worth testing. Running against the site as it stands tests
 * the configuration people actually use.
 *
 * What they guard is the seam this feature introduced. The panel body is no
 * longer built in TypeScript — the server renders the search_quick component
 * and the client injects that HTML wholesale, then takes over the option
 * bookkeeping the markup cannot do for itself (ids are minted client-side
 * because one cached fragment may land in more than one panel). The contract
 * between the two halves is exactly: every selectable row carries role="option"
 * and an href, in traversal order. A theme that owns its copy of the component
 * can change everything else, so these tests assert the contract and the
 * behaviour it powers, never the markup around it.
 *
 * Each case re-opens from a fresh page load rather than inheriting the previous
 * one's state, so a failure points at the behaviour it names.
 */

// The header renders the input inside an Alpine slide-out, collapsed to zero
// width until its button is clicked — so the input has to be revealed before it
// can be typed into.
const TOGGLE = 'form[role="search"] button[aria-label="Search"]';
const INPUT = '#site-search';
const PANEL = '.neo-search-panel';
const OPTION = '.neo-search-panel [role="option"]';

// Matches content on this site; min_chars is 2, so anything shorter never
// reaches the endpoint.
const QUERY = 'water';

// The id shape panel.ts mints per panel. Hoisted out of the assertion because
// an inline regex literal reads as division to the Drupal JS sniffs.
const OPTION_ID = /-option-\d+$/;

/**
 * Load the front page, reveal the search input and type a query.
 *
 * @param {object} browser
 *   The Nightwatch browser object.
 *
 * @return {object}
 *   The browser object.
 */
function search(browser) {
  return browser
    .drupalRelativeURL('/')
    .waitForElementVisible(TOGGLE, 5000)
    .click(TOGGLE)
    .waitForElementVisible(INPUT, 5000)
    .setValue(INPUT, QUERY)
    .waitForElementVisible(PANEL, 10000)
    .waitForElementVisible(OPTION, 10000);
}

/**
 * Read the option ids, hrefs and aria-selected state in document order.
 *
 * @param {object} browser
 *   The Nightwatch browser object.
 * @param {Function} callback
 *   Receives the array of option descriptors.
 *
 * @return {object}
 *   The browser object.
 */
function readOptions(browser, callback) {
  return browser.execute(
    function () {
      return Array.prototype.map.call(
        document.querySelectorAll('.neo-search-panel [role="option"]'),
        function (el) {
          return {
            id: el.id,
            href: el.getAttribute('href'),
            selected: el.getAttribute('aria-selected'),
            active: el.classList.contains('is-active'),
            tabIndex: el.tabIndex,
          };
        },
      );
    },
    [],
    (result) => callback(result.value),
  );
}

module.exports = {
  '@tags': ['neo_search', 'neo'],

  after(browser) {
    browser.end();
  },

  'the page serves compiled assets, not the dev server': (browser) => {
    browser
      .drupalRelativeURL('/')
      .waitForElementPresent('body', 5000)
      .assert.neoAssetsBuilt();
  },

  /**
   * The server-rendered fragment actually reaches the DOM.
   *
   * The envelope carries no per-item data any more, so if injection regressed
   * there is nothing for the client to fall back to — the panel would open
   * empty rather than degrade.
   */
  'typing renders server-built results into the panel': (browser) => {
    search(browser)
      .assert.visible(PANEL, 'The results panel opened.')
      .assert.attributeEquals(
        INPUT,
        'aria-expanded',
        'true',
        'The combobox reports itself expanded.',
      );

    readOptions(browser, (options) => {
      browser.assert.ok(
        options.length > 0,
        `The panel rendered ${options.length} options.`,
      );
    });
  },

  /**
   * The contract, asserted directly.
   *
   * A theme owns its copy of search_quick.twig and may restructure it freely;
   * these two attributes are the only things it may not drop. Everything below
   * this test depends on them, so this is the one that explains a cascade.
   */
  'every option carries an href and a client-minted id': (browser) => {
    search(browser);

    readOptions(browser, (options) => {
      options.forEach((option, index) => {
        browser.assert.ok(
          !!option.href,
          `Option ${index} has an href to navigate to.`,
        );
        // panel.ts mints these after injection; the markup must not, since one
        // cached fragment can be injected into two panels on the same page.
        browser.assert.ok(
          OPTION_ID.test(option.id),
          `Option ${index} received a panel scoped id: ${option.id}`,
        );
        browser.assert.strictEqual(
          option.selected,
          'false',
          `Option ${index} starts unselected.`,
        );
        browser.assert.strictEqual(
          option.tabIndex,
          -1,
          `Option ${index} is skipped by tab order.`,
        );
      });
    });
  },

  /**
   * Arrow keys walk the options the client collected from the markup.
   *
   * This is the whole reason the option re-index exists: instance.ts drives
   * panel.options by index, and aria-activedescendant has to name the id of the
   * element it landed on. Server-rendered markup that lost its role="option"
   * would leave that array empty and the arrows inert.
   */
  'arrow keys move the active option and aria-activedescendant follows': (browser) => {
    search(browser).neoPressKey(browser.Keys.ARROW_DOWN);

    readOptions(browser, (options) => {
      browser.assert.strictEqual(
        options[0].selected,
        'true',
        'The first option became selected.',
      );
      browser.assert.ok(
        options[0].active,
        'The first option got the is-active hook.',
      );
      browser.assert.attributeEquals(
        INPUT,
        'aria-activedescendant',
        options[0].id,
        'The combobox points at the first option.',
      );
    });

    browser.neoPressKey(browser.Keys.ARROW_DOWN);

    readOptions(browser, (options) => {
      browser.assert.strictEqual(
        options[0].selected,
        'false',
        'The first option was deselected.',
      );
      browser.assert.strictEqual(
        options[1].selected,
        'true',
        'The second option became selected.',
      );
      browser.assert.attributeEquals(
        INPUT,
        'aria-activedescendant',
        options[1].id,
        'The combobox moved to the second option.',
      );
    });
  },

  /**
   * Wrap-around, which only works if the collected array is complete.
   *
   * ArrowUp from nothing selected lands on the last option — an off-by-one in
   * the re-index (or a stray non-option element picked up by the selector)
   * shows up here and nowhere else.
   */
  'arrow up from the top wraps to the last option': (browser) => {
    search(browser).neoPressKey(browser.Keys.ARROW_UP);

    readOptions(browser, (options) => {
      const last = options[options.length - 1];
      browser.assert.strictEqual(
        last.selected,
        'true',
        'The last option became selected.',
      );
      browser.assert.attributeEquals(
        INPUT,
        'aria-activedescendant',
        last.id,
        'The combobox wrapped to the last option.',
      );
    });
  },

  /**
   * Enter navigates to the active option's href.
   *
   * selectOption() reads the href straight off the element, so this closes the
   * loop from "the component rendered a link" to "the user got there".
   */
  'enter navigates to the active option': (browser) => {
    search(browser).neoPressKey(browser.Keys.ARROW_DOWN);

    readOptions(browser, (options) => {
      const target = options[0].href;
      browser
        .neoPressKey(browser.Keys.ENTER)
        .waitForElementNotPresent(PANEL, 5000)
        .url((current) => {
          browser.assert.ok(
            current.value.includes(target),
            `Landed on the selected result: ${target}`,
          );
        });
    });
  },

  /**
   * Escape closes the panel first; the header slide-out only gets the second.
   *
   * The panel is appended to document.body, so this also proves the close path
   * still resets the combobox state it set on a completely separate element.
   */
  'escape closes the panel before the header slide-out': (browser) => {
    search(browser)
      .neoPressKey(browser.Keys.ESCAPE)
      .waitForElementNotVisible(PANEL, 5000)
      .assert.attributeEquals(
        INPUT,
        'aria-expanded',
        'false',
        'The combobox reports itself collapsed.',
      )
      .assert.visible(INPUT, 'The header slide-out survived the first Escape.');
  },

  /**
   * A query with no matches renders the configured empty message, not options.
   *
   * The empty branch lives in the component now, so it is the one piece of
   * panel content the client never sees data for.
   */
  'a query with no results renders the empty state': (browser) => {
    browser
      .drupalRelativeURL('/')
      .waitForElementVisible(TOGGLE, 5000)
      .click(TOGGLE)
      .waitForElementVisible(INPUT, 5000)
      .setValue(INPUT, 'zzzqqqxyzzy')
      .waitForElementVisible(PANEL, 10000)
      .assert.elementCount(OPTION, 0);
  },
};
