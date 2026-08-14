<?php

declare(strict_types=1);

namespace Drupal\neo_search;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\neo_search\Attribute\NeoSearch;

/**
 * NeoSearch backend plugin manager.
 */
final class NeoSearchPluginManager extends DefaultPluginManager {

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/NeoSearch', $namespaces, $module_handler, NeoSearchPluginInterface::class, NeoSearch::class);
    $this->alterInfo('neo_search_info');
    $this->setCacheBackend($cache_backend, 'neo_search_plugins');
  }

  /**
   * Gets an option list of available plugins.
   *
   * @return array
   *   Plugin labels keyed by plugin id, limited to available plugins.
   */
  public function getAvailableOptions(): array {
    $options = [];
    foreach ($this->getDefinitions() as $id => $definition) {
      /** @var \Drupal\neo_search\NeoSearchPluginInterface $plugin */
      $plugin = $this->createInstance($id);
      if ($plugin->isAvailable()) {
        $options[$id] = (string) $definition['label'];
      }
    }
    asort($options);
    return $options;
  }

}
