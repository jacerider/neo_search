import NeoSearchInstance from './search/instance';

/**
 * @file
 * Binds configured quick search variations to their inputs.
 */

(function (Drupal, drupalSettings, once) {

  const instances = new Map<HTMLElement, NeoSearchInstance>();

  Drupal.behaviors.neoSearch = {
    attach: (context: HTMLElement) => {
      const variations = drupalSettings.neoSearch?.variations || {};
      Object.entries(variations).forEach(([id, variation]) => {
        variation.selectors.forEach((selector) => {
          once('neo-search', selector, context).forEach((el) => {
            if (el instanceof HTMLInputElement) {
              instances.set(el, new NeoSearchInstance(el, { ...variation, id }));
            }
          });
        });
      });
    },
    detach: (context: HTMLElement, _settings?: drupal.IDrupalSettings, trigger?: string) => {
      if (trigger !== 'unload') {
        return;
      }
      instances.forEach((instance, el) => {
        if (context.contains(el)) {
          instance.destroy();
          instances.delete(el);
          // core/once also accepts an element in place of a selector.
          once.remove('neo-search', el as unknown as string);
        }
      });
    },
  };

  Drupal.neoSearch = {
    instances,
    get: (el: HTMLElement) => instances.get(el),
    closeAll: () => instances.forEach((instance) => instance.close()),
  };

})(Drupal, drupalSettings, once);
