<?php

declare(strict_types=1);

namespace Drupal\neo_search\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\neo_search\SearchRequest;
use Drupal\neo_search\SearchResultSet;

/**
 * Event fired before a quick search is executed.
 *
 * Subscribers may swap the request (it is an immutable value object) or set
 * a result set to short-circuit the backend plugin entirely.
 */
class NeoSearchQueryEvent extends Event {

  const EVENT_NAME = 'neo_search_query';

  /**
   * Constructs the event.
   *
   * @param \Drupal\neo_search\SearchRequest $request
   *   The search request.
   * @param array $settings
   *   The resolved variation settings values.
   * @param \Drupal\neo_search\SearchResultSet|null $results
   *   Pre-populated results. When a subscriber sets this, the backend plugin
   *   is skipped.
   */
  public function __construct(
    public SearchRequest $request,
    public readonly array $settings,
    public ?SearchResultSet $results = NULL,
  ) {}

  /**
   * Replaces the request.
   */
  public function setRequest(SearchRequest $request): static {
    $this->request = $request;
    return $this;
  }

  /**
   * Sets results, short-circuiting the backend plugin.
   */
  public function setResults(SearchResultSet $results): static {
    $this->results = $results;
    return $this;
  }

}
