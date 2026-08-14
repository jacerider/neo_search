<?php

declare(strict_types=1);

namespace Drupal\neo_search;

use Drupal\Core\Session\AccountInterface;

/**
 * A quick search request.
 */
final class SearchRequest {

  /**
   * Constructs a search request.
   *
   * @param string $query
   *   The normalized query (trimmed, whitespace collapsed, case preserved).
   * @param string $rawQuery
   *   The raw query as typed.
   * @param string $variationId
   *   The settings variation id (or the core instance id).
   * @param int $limit
   *   The maximum number of results.
   * @param string $langcode
   *   The interface langcode.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the search runs as. Plugins MUST NOT bypass access for
   *   this account.
   */
  public function __construct(
    public readonly string $query,
    public readonly string $rawQuery,
    public readonly string $variationId,
    public readonly int $limit,
    public readonly string $langcode,
    public readonly AccountInterface $account,
  ) {}

}
