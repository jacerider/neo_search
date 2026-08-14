<?php

declare(strict_types=1);

namespace Drupal\neo_search;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Plugin\PluginFormInterface;

/**
 * Interface for neo_search backend plugins.
 */
interface NeoSearchPluginInterface extends PluginInspectionInterface, ConfigurableInterface, PluginFormInterface {

  /**
   * Executes the search as the request's account.
   *
   * Implementations MUST respect access for the request account — results the
   * account cannot view must not be returned, and access checks must never be
   * bypassed.
   *
   * @param \Drupal\neo_search\SearchRequest $request
   *   The search request.
   *
   * @return \Drupal\neo_search\SearchResultSet
   *   The result set, including any cacheability the search depends on.
   */
  public function search(SearchRequest $request): SearchResultSet;

  /**
   * Whether this plugin is usable (its soft dependencies are met).
   */
  public function isAvailable(): bool;

}
