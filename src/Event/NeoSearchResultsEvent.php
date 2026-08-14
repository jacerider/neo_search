<?php

declare(strict_types=1);

namespace Drupal\neo_search\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;
use Drupal\neo_search\SearchRequest;
use Drupal\neo_search\SearchResultSet;

/**
 * Event fired after a quick search has executed and results are grouped.
 *
 * Subscribers may inject, remove, or reorder items and groups on the result
 * set, and must add any cacheability their changes depend on (either to the
 * result set or to this event — both are merged into the cached response).
 */
class NeoSearchResultsEvent extends Event implements RefinableCacheableDependencyInterface {

  use RefinableCacheableDependencyTrait;

  const EVENT_NAME = 'neo_search_results';

  /**
   * Constructs the event.
   *
   * @param \Drupal\neo_search\SearchRequest $request
   *   The search request.
   * @param \Drupal\neo_search\SearchResultSet $results
   *   The mutable result set, with groups computed.
   * @param array $settings
   *   The resolved variation settings values.
   */
  public function __construct(
    public readonly SearchRequest $request,
    public SearchResultSet $results,
    public readonly array $settings,
  ) {}

  /**
   * Gets the result set.
   */
  public function getResults(): SearchResultSet {
    return $this->results;
  }

}
